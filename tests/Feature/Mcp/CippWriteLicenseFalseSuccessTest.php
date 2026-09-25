<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ExecBulkLicense answers HTTP 200 whether or not it wrote. These tests run the
 * REAL CippRestWriteClient::send() under Http::fake — no mocked client — for
 * the person-keyed licence tools (cipp_assign_user_license /
 * cipp_remove_user_license) on the direct and staged paths, and for the
 * licence-target tool (cipp_assign_tenant_user_license) on both paths.
 *
 * Each failure arm asserts: no 'executed' audit row, one 'error' row, the
 * exact operator sentence, and that no upstream text reached the operator.
 */
class CippWriteLicenseFalseSuccessTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'acme.onmicrosoft.com';

    private const UPN = 'alex@acme.example';

    private const TARGET_UPN = 'contractor@acme.example';

    private const TARGET_OBJECT_ID = '7d1f0a2c-55aa-4bb1-9c33-77e1c0aa1234';

    private const SKU = 'sku-from-tenant-sync';

    private const UNKNOWN_TAIL = "was sent to CIPP but not confirmed; it may or may not have applied — verify the user's licences in CIPP before retrying.";

    /** Upstream text that must never reach the operator. */
    private const UPSTREAM_MARKER = 'UPSTREAM-DETAIL-7731';

    /** @var array<string, mixed>|string */
    private array|string $licenseBody = [];

    private int $licenseStatus = 200;

    private function configure(): User
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        // The real client, so send() and confirmLicenseWrite() both run.
        $this->app->instance(CippRestWriteClient::class, new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'client-1', 'client_secret' => 'secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']));

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/api/ListUsers')) {
                return Http::response([[
                    'id' => self::TARGET_OBJECT_ID, 'userPrincipalName' => self::TARGET_UPN,
                    'displayName' => 'Sam Contractor', 'accountEnabled' => true, 'mail' => self::TARGET_UPN,
                ]]);
            }
            if (str_contains($request->url(), '/api/ExecBulkLicense')) {
                return Http::response($this->licenseBody, $this->licenseStatus);
            }

            return Http::response('unexpected', 599);
        });

        return $actor;
    }

    /** @param array<string, mixed>|string $body */
    private function answer(int $status, array|string $body): void
    {
        $this->licenseStatus = $status;
        $this->licenseBody = $body;
    }

    private function licenceWrites(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/api/ExecBulkLicense'))->count();
    }

    /** @return array{client: Client, person: Person, ticket: Ticket, licenseType: LicenseType} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'cipp_tenant_domain' => self::TENANT]);
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Alex', 'last_name' => 'Acme', 'email' => self::UPN,
            'cipp_user_id' => 'user-123', 'cipp_upn' => self::UPN, 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $person->id, 'subject' => 'Licence change']);
        $licenseType = LicenseType::create([
            'name' => 'Business Premium', 'vendor' => 'cipp_m365',
            'vendor_sku_id' => self::SKU, 'is_active' => true,
        ]);
        License::create([
            'license_type_id' => $licenseType->id, 'client_id' => $client->id,
            'quantity' => 10, 'assigned_quantity' => 2,
            'vendor_ref' => self::SKU, 'status' => 'active', 'synced_at' => now(),
        ]);

        return compact('client', 'person', 'ticket', 'licenseType');
    }

    private function callTool(array $tools, string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function personArgs(array $f, bool $staged = false): array
    {
        return array_filter([
            'client_id' => $f['client']->id,
            'person_id' => $f['person']->id,
            'license_type_id' => $f['licenseType']->id,
            'confirm_upn' => self::UPN,
            'ticket_id' => $staged ? $f['ticket']->id : null,
            'reason' => 'Licence change after human review.',
        ], fn ($v) => $v !== null);
    }

    private function directPerson(string $tool, array $f): string
    {
        $response = $this->callTool([$tool], $tool, $this->personArgs($f));
        $response->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    private function stagedPerson(string $directTool, array $f, User $approver): \App\Services\Technician\TechnicianApprovalResult
    {
        $stageTool = str_replace('cipp_', 'cipp_stage_', $directTool);
        $staged = json_decode((string) $this->callTool([$stageTool], $stageTool, $this->personArgs($f, staged: true))
            ->json('result.content.0.text'), true);
        $this->assertTrue($staged['success'] ?? false, json_encode($staged));

        return app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);
    }

    private function targetArgs(array $f, bool $staged = false): array
    {
        return array_filter([
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'ticket_id' => $staged ? $f['ticket']->id : null,
            'reason' => 'Contractor needs a seat.',
        ], fn ($v) => $v !== null);
    }

    private function assertNotExecuted(string $message): void
    {
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count(), 'a refused licence write must never audit as executed');
        $this->assertSame(1, TechnicianActionLog::where('result_status', 'error')->count());
        $this->assertStringNotContainsString(self::UPSTREAM_MARKER, $message, 'upstream text must not reach the operator');
        $this->assertSame(1, $this->licenceWrites(), 'exactly one licence write left');
    }

    private function errorOf(string $body): string
    {
        return (string) (json_decode($body, true)['error'] ?? '');
    }

    private function unknown(string $tool, string $action): string
    {
        return "The licence {$action} for {$tool} ".self::UNKNOWN_TAIL;
    }

    private function notFound(string $tool, string $action): string
    {
        return "CIPP write failed for {$tool}; CIPP reported it could not identify the user and made no licence change, so the licence {$action} was not applied.";
    }

    private function failedToProcess(): array
    {
        return ['Results' => ['Failed to process bulk license operation for tenant acme.onmicrosoft.com. Error: '.self::UPSTREAM_MARKER]];
    }

    private function notFoundBody(): array
    {
        return ['Results' => ['User user-123 not found in tenant acme.onmicrosoft.com '.self::UPSTREAM_MARKER]];
    }

    private function successBody(): array
    {
        return ['Results' => ['Successfully set licenses for alex@acme.example. It may take 2–5 minutes before the changes become visible.']];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function personTools(): array
    {
        return [
            'assign' => ['cipp_assign_user_license', 'assignment'],
            'remove' => ['cipp_remove_user_license', 'removal'],
        ];
    }

    // ----- person-keyed, direct (executeDirect -> executeUpstream) -----

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_direct_failed_to_process_is_unknown_not_executed(string $tool, string $action): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->failedToProcess());

        $body = $this->directPerson($tool, $f);

        $this->assertSame($this->unknown($tool, $action), $this->errorOf($body));
        $this->assertNotExecuted($body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_direct_user_not_found_is_not_applied(string $tool, string $action): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->notFoundBody());

        $body = $this->directPerson($tool, $f);

        $this->assertSame($this->notFound($tool, $action), $this->errorOf($body));
        $this->assertNotExecuted($body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_direct_3xx_is_unknown_not_executed(string $tool, string $action): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(302, '');

        $body = $this->directPerson($tool, $f);

        $this->assertSame($this->unknown($tool, $action), $this->errorOf($body));
        $this->assertNotExecuted($body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_direct_success_line_still_executes(string $tool, string $action): void
    {
        // Positive control through the same wiring.
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->successBody());

        $result = json_decode($this->directPerson($tool, $f), true);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame('CIPP action executed.', $result['message']);
        $this->assertSame(1, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertSame(1, $this->licenceWrites());
    }

    public function test_person_direct_remove_no_change_says_nothing_was_removed(): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, ['Results' => ['No license changes needed for user alex@acme.example']]);

        $result = json_decode($this->directPerson('cipp_remove_user_license', $f), true);

        $this->assertTrue($result['success'] ?? false);
        $this->assertTrue($result['no_change'] ?? false);
        $this->assertSame('CIPP reported the user did not hold this licence, so nothing was removed.', $result['message']);
        $row = TechnicianActionLog::sole();
        $this->assertStringContainsString('nothing was removed', (string) $row->summary);
        $this->assertStringNotContainsString('executed:', (string) $row->summary);
    }

    // ----- person-keyed, staged (approveStagedRun -> executeUpstream) -----

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_staged_failed_to_process_is_unknown_not_executed(string $tool, string $action): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->failedToProcess());

        $result = $this->stagedPerson($tool, $f, $approver);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($this->unknown('cipp_stage_'.substr($tool, 5), $action), $result->message);
        $this->assertNotExecuted((string) $result->message);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('personTools')]
    public function test_person_staged_user_not_found_is_not_applied(string $tool, string $action): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->notFoundBody());

        $result = $this->stagedPerson($tool, $f, $approver);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($this->notFound('cipp_stage_'.substr($tool, 5), $action), $result->message);
        $this->assertNotExecuted((string) $result->message);
    }

    public function test_person_staged_remove_no_change_says_nothing_was_removed(): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $this->answer(200, ['Results' => ['No license changes needed for user alex@acme.example']]);

        $result = $this->stagedPerson('cipp_remove_user_license', $f, $approver);

        $this->assertSame('executed', $result->status);
        $this->assertSame('CIPP reported the user did not hold this licence, so nothing was removed.', $result->message);
        $this->assertStringContainsString('nothing was removed', (string) TechnicianActionLog::where('result_status', 'executed')->sole()->summary);
    }

    // ----- licence-target (executeLicenseTargetDirect / approveLicenseTargetStagedRun) -----

    public function test_target_direct_failed_to_process_is_unknown_not_executed(): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, $this->failedToProcess());

        $response = $this->callTool(['cipp_assign_tenant_user_license'], 'cipp_assign_tenant_user_license', $this->targetArgs($f));
        $body = (string) $response->json('result.content.0.text');

        $this->assertSame($this->unknown('cipp_assign_tenant_user_license', 'assignment'), $this->errorOf($body));
        $this->assertNotExecuted($body);
    }

    public function test_target_direct_usage_location_arm_names_the_patch(): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->answer(200, ['Results' => ['Failed to assign licenses for user contractor@acme.example after setting usage location: '.self::UPSTREAM_MARKER]]);

        $response = $this->callTool(['cipp_assign_tenant_user_license'], 'cipp_assign_tenant_user_license', $this->targetArgs($f));
        $body = (string) $response->json('result.content.0.text');

        $this->assertSame(
            $this->unknown('cipp_assign_tenant_user_license', 'assignment')." CIPP may already have set the user's usage location.",
            $this->errorOf($body)
        );
        $this->assertNotExecuted($body);
    }

    public function test_target_staged_3xx_is_unknown_not_executed(): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $staged = json_decode((string) $this->callTool(['cipp_stage_assign_tenant_user_license'], 'cipp_stage_assign_tenant_user_license', $this->targetArgs($f, staged: true))
            ->json('result.content.0.text'), true);
        $this->assertTrue($staged['success'] ?? false);
        $this->answer(301, ['Results' => ['Successfully set licenses for contractor@acme.example.']]);

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($this->unknown('cipp_stage_assign_tenant_user_license', 'assignment'), $result->message);
        $this->assertNotExecuted((string) $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::findOrFail($staged['run_id'])->state);
    }

    public function test_target_staged_user_not_found_is_not_applied(): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $staged = json_decode((string) $this->callTool(['cipp_stage_assign_tenant_user_license'], 'cipp_stage_assign_tenant_user_license', $this->targetArgs($f, staged: true))
            ->json('result.content.0.text'), true);
        $this->answer(200, $this->notFoundBody());

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($this->notFound('cipp_stage_assign_tenant_user_license', 'assignment'), $result->message);
        $this->assertNotExecuted((string) $result->message);
    }
}
