<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CippWriteAddDirectoryRoleTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE = '69091246-20e8-4a56-aa4d-066075b2a7a8';

    private const OTHER_ROLE = 'baf37b3a-610e-45da-9e62-d9d1e5e8914b';

    private const MESSAGE = 'Queued with CIPP; not yet verified. Confirm with cipp_list_roles.';

    private User $actor;

    private array $fixture;

    private array $vendorState;

    private int $baseTransactionLevel;

    private array $membershipReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->baseTransactionLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        Http::preventStrayRequests();
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'synthetic-tenant');
        Setting::setValue('cipp_client_id', 'synthetic-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-value');
        $this->actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'contoso.example']);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => 'User',
            'email' => 'jit.user@contoso.example',
            'cipp_upn' => 'jit.user@contoso.example',
            'cipp_user_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $person->id]);
        $this->fixture = compact('client', 'person', 'ticket');
        $this->fakeVendor();
        $this->app->instance(\App\Services\Cipp\CippRestWriteClient::class, new \App\Services\Cipp\CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'synthetic-tenant',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-value',
        ], \Illuminate\Support\Facades\Cache::store(), fn () => ['93.184.216.34']));
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/')) {
                if ($this->vendorState['tokenDown'] ?? false) {
                    return Http::response([], 500);
                }

                return Http::response(['access_token' => 'synthetic-access', 'expires_in' => 3600]);
            }
            if (str_starts_with($request->url(), 'https://cipp.example.test/api/ListRoles')) {
                $this->membershipReads[] = [
                    'transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel(),
                    'executing' => TechnicianRun::where('state', TechnicianRunState::Executing)->count(),
                ];
                if ($this->vendorState['tokenDownAfterRead'] ?? false) {
                    // The cached token is gone and cannot be renewed before the write.
                    \Illuminate\Support\Facades\Cache::flush();
                    $this->vendorState['tokenDown'] = true;
                }

                return Http::response($this->vendorState['roles'], $this->vendorState['readStatus']);
            }
            if ($request->url() === 'https://cipp.example.test/api/ExecJITAdmin') {
                $this->assertSame($this->baseTransactionLevel, \Illuminate\Support\Facades\DB::transactionLevel(), 'No application transaction may span ExecJITAdmin');
                if ($this->vendorState['stealClaim'] ?? false) {
                    // Another request takes the run while the POST is in flight.
                    TechnicianRun::where('state', TechnicianRunState::Executing)->update(['state' => TechnicianRunState::Denied->value]);
                }

                return Http::response($this->vendorState['reply'], $this->vendorState['writeStatus']);
            }
            throw new \LogicException('Unexpected synthetic request');
        });
    }

    private function arguments(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->fixture['client']->id,
            'person_id' => $this->fixture['person']->id,
            'ticket_id' => $this->fixture['ticket']->id,
            'confirm_upn' => $this->fixture['person']->cipp_upn,
            'role_template_id' => self::ROLE,
            'role_name' => 'Teams Administrator',
            'reason' => 'Temporary Teams administration for this synthetic request.',
            'staged' => true,
        ], $overrides);
    }

    // Producer: Invoke-ListRoles.ps1 at 5efb2f9d, lines 50–66.
    // Id deliberately differs from roleTemplateId: only the latter is authoritative.
    private function roles(array $members = []): array
    {
        return [
            ['Id' => 'synthetic-definition', 'roleTemplateId' => self::ROLE, 'DisplayName' => 'Teams Administrator', 'Members' => $members],
            ['Id' => 'synthetic-other-definition', 'roleTemplateId' => self::OTHER_ROLE, 'DisplayName' => 'Teams Communications Administrator', 'Members' => []],
        ];
    }

    // Producer: Invoke-ExecJITAdmin.ps1 at 5efb2f9d, lines 211–216, 359, 390–395.
    private function queued(): array
    {
        return ['Results' => [
            ['resultText' => 'User: jit.user@contoso.example', 'copyField' => 'jit.user@contoso.example', 'state' => 'success'],
            'Queued JIT Admin enable task for jit.user@contoso.example to run now',
            'Scheduling JIT Admin RemoveRoles task for jit.user@contoso.example',
        ]];
    }

    private function fakeVendor(mixed $roles = null, int $readStatus = 200, mixed $reply = null, int $writeStatus = 200): void
    {
        $this->vendorState = ['roles' => $roles ?? $this->roles(), 'readStatus' => $readStatus, 'reply' => $reply ?? $this->queued(), 'writeStatus' => $writeStatus];
    }

    private function callGrant(array $overrides = [], bool $bare = false): TestResponse
    {
        $credential = McpConfig::rotateStaffToken(
            allowedTools: [$bare ? 'cipp_add_directory_role' : 'cipp_stage_add_directory_role'],
            label: 'synthetic-jit',
        );

        return $this->withHeaders(['Authorization' => 'Bearer '.$credential])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'cipp_add_directory_role', 'arguments' => $this->arguments($overrides)],
        ]);
    }

    private function stage(array $overrides = []): TechnicianRun
    {
        $response = $this->callGrant($overrides);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertArrayHasKey('run_id', $payload);
        $run = TechnicianRun::findOrFail($payload['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    private function assertNoWrite(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/ExecJITAdmin'));
    }

    public function test_unknown_and_global_admin_roles_are_refused_before_http(): void
    {
        foreach (['ffffffff-ffff-ffff-ffff-ffffffffffff', '62e90394-69f5-4237-9190-012177145e10'] as $role) {
            $response = $this->callGrant(['role_template_id' => $role]);
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString('Global Administrator', (string) $response->json('result.content.0.text'));
            Http::assertNothingSent();
        }
    }

    public function test_bare_immediate_mode_is_held_only(): void
    {
        $response = $this->callGrant(['staged' => false], bare: true);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('held-only', (string) $response->json('result.content.0.text'));
        Http::assertNothingSent();
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_alias_grant_downgrades_immediate_mode_to_a_proposal(): void
    {
        $run = $this->stage(['staged' => false]);
        $this->assertSame('cipp_stage_add_directory_role', $run->action_type);
        $this->assertNoWrite();
    }

    public function test_staging_refuses_end_date_beyond_seven_days(): void
    {
        $response = $this->callGrant(['end_date' => now()->addDays(7)->addSecond()->toIso8601String()]);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('7 days', (string) $response->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    public function test_expired_end_date_is_refused_at_approval(): void
    {
        $run = $this->stage(['end_date' => now()->addHour()->toIso8601String()]);
        $this->travel(2)->hours();
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertNoWrite();
    }

    public function test_queue_receipt_is_not_an_executed_grant_and_request_body_is_pinned(): void
    {
        $run = $this->stage();
        $now = now()->timestamp;
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('outcome_unknown', $result->status);
        $this->assertSame(self::MESSAGE, $result->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'queued_unverified']);
        $this->assertDatabaseMissing('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'executed']);
        Http::assertSent(function ($request) use ($now) {
            if (! str_contains($request->url(), '/api/ExecJITAdmin')) {
                return false;
            }
            $this->assertSame('POST', $request->method());
            $this->assertSame('RemoveRoles', $request['ExpireAction']['value']);
            $this->assertFalse($request['UseTAP']);
            $this->assertSame([], $request['GroupMemberships']);
            $this->assertSame($this->fixture['person']->cipp_user_id, $request['existingUser']['value']);
            $this->assertSame(self::ROLE, $request['AdminRoles'][0]['value']);
            $this->assertSame($now - 60, $request['StartDate']);
            $this->assertSame($now + 8 * 3600, $request['EndDate']);
            $this->assertNotSame('create', $request['userAction']);

            return true;
        });
    }

    public function test_cockpit_approval_uses_error_not_success_channel(): void
    {
        $run = $this->stage();
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionMissing('success')
            ->assertSessionHas('error', self::MESSAGE);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('CIPP JIT role queue (unverified)', \App\Support\StagedActionLabels::humanLabel($run->action_type));
    }

    public function test_missing_expiry_confirmation_names_risk_and_is_not_queued(): void
    {
        $run = $this->stage();
        $this->fakeVendor(reply: ['Results' => ['Queued JIT Admin enable task for jit.user@contoso.example to run now']]);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertNotSame('executed', $result->status);
        $this->assertStringContainsString('enable queued; expiry NOT confirmed. Remove the role manually or check CIPP scheduled tasks.', $result->message);
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_unverified')->count());
        // The enable may be queued: the run must not reopen for a second ExecJITAdmin.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $again = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run->fresh(), $this->actor->id);
        $this->assertSame('already_handled', $again->status);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/api/ExecJITAdmin'))->count());
    }

    public function test_strict_membership_failures_refuse_at_staging_and_approval(): void
    {
        $cases = [
            [$this->roles([['id' => $this->fixture['person']->cipp_user_id, 'directoryScopeId' => '/administrativeUnits/synthetic']]), 200, 'already holds'],
            ['Failed to list roles for tenant synthetic', 500, 'could not verify current role membership'],
            [[], 200, 'could not verify current role membership'],
            [[['roleTemplateId' => self::ROLE]], 200, 'could not verify current role membership'],
        ];
        foreach ($cases as [$roles, $status, $message]) {
            $this->fakeVendor($roles, $status);
            $response = $this->callGrant();
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString($message, (string) $response->json('result.content.0.text'));
            $this->assertNoWrite();
            $this->fakeVendor();
            $run = $this->stage();
            $this->fakeVendor($roles, $status);
            $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
            $this->assertSame('gate_declined', $result->status);
            $this->assertStringContainsString($message, $result->message);
            $this->assertNoWrite();
            $run->fresh()->update(['state' => TechnicianRunState::Denied]);
        }
    }

    public function test_post_commit_membership_refusal_releases_claim_without_sending_jit(): void
    {
        $run = $this->stage();
        $this->fakeVendor($this->roles([['id' => $this->fixture['person']->cipp_user_id, 'directoryScopeId' => '/']]));

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('already holds', $result->message);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/ExecJITAdmin'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame([
            ['transaction_level' => $this->baseTransactionLevel, 'executing' => 0],
            ['transaction_level' => $this->baseTransactionLevel, 'executing' => 1],
        ], $this->membershipReads, 'Staging reads before its transaction; approval reads after committing its Executing claim.');
    }

    public function test_non_queue_replies_never_record_queued(): void
    {
        foreach ([
            [['Results' => ['JIT Admin request for jit.user@contoso.example submitted for approval.']], 200],
            [['Results' => ['You are not permitted to assign the following role(s): synthetic']], 403],
            [['Results' => ['Requested JIT Admin duration exceeds the maximum allowed duration']], 400],
            [['Results' => 'Queued JIT Admin enable task'], 200],
            [[], 200],
            [['Results' => ['Scheduling JIT Admin enable task for jit.user@contoso.example', 'Scheduling JIT Admin RemoveRoles task']], 200],
        ] as [$reply, $status]) {
            $this->fakeVendor();
            $run = $this->stage();
            $this->fakeVendor(reply: $reply, writeStatus: $status);
            $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
            $this->assertSame('gate_declined', $result->status);
            $this->assertStringContainsString('CIPP did not confirm the queue', $result->message);
            $this->assertStringContainsString('cipp_list_roles', $result->message);
            $this->assertDatabaseMissing('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'queued_unverified']);
            $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'A failure after the POST must not reopen the proposal.');
            $run->fresh()->update(['state' => TechnicianRunState::Denied]);
        }
    }

    public function test_approval_rechecks_cap_against_approval_clock(): void
    {
        $run = $this->stage(['end_date' => now()->addDays(7)->toIso8601String()]);
        $this->travel(-1)->hours();
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('7 days', $result->message);
        $this->assertNoWrite();
    }

    public function test_queued_window_blocks_across_tickets_then_allows_a_new_run(): void
    {
        $run = $this->stage();
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('outcome_unknown', $result->status);
        $otherTicket = Ticket::factory()->for($this->fixture['client'])->create();
        $response = $this->callGrant(['ticket_id' => $otherTicket->id]);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not yet verified', (string) $response->json('result.content.0.text'));
        $this->stage(['role_template_id' => self::OTHER_ROLE, 'role_name' => 'Teams Communications Administrator']);
        $this->travel(31)->minutes();
        $new = $this->stage();
        $this->assertNotSame($run->id, $new->id);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_brakes_are_rechecked_at_approval(): void
    {
        $run = $this->stage();
        $sibling = $run->replicate(['content_hash']);
        $sibling->content_hash = hash('sha256', 'synthetic-other-proposal');
        $sibling->save();
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('already awaiting approval', $result->message);
        $this->assertNoWrite();
        $sibling->update(['state' => TechnicianRunState::Denied]);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run->fresh(), $this->actor->id);
        $this->assertSame('outcome_unknown', $result->status);
        $sibling->update(['state' => TechnicianRunState::AwaitingApproval]);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($sibling->fresh(), $this->actor->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('not yet verified', $result->message);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/api/ExecJITAdmin'))->count());
    }

    public function test_pending_proposal_blocks_the_same_triple_across_tickets_but_not_another_role(): void
    {
        $this->stage();
        $otherTicket = Ticket::factory()->for($this->fixture['client'])->create();
        $response = $this->callGrant(['ticket_id' => $otherTicket->id]);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('already awaiting approval', (string) $response->json('result.content.0.text'));
        $this->stage(['ticket_id' => $otherTicket->id, 'role_template_id' => self::OTHER_ROLE, 'role_name' => 'Teams Communications Administrator']);
        $this->assertSame(2, TechnicianRun::count());
        $this->assertNoWrite();
    }

    public function test_failure_before_the_post_reopens_the_proposal_and_sends_nothing(): void
    {
        $run = $this->stage();
        $this->vendorState['tokenDownAfterRead'] = true;
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('CippWriteNotSentException', $result->message);
        $this->assertNoWrite();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_lost_claim_after_queue_reports_outcome_unknown_not_executed(): void
    {
        $run = $this->stage();
        $this->vendorState['stealClaim'] = true;
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('outcome_unknown', $result->status);
        $this->assertStringStartsWith(self::MESSAGE, (string) $result->message);
        $this->assertStringNotContainsStringIgnoringCase('executed', (string) $result->message);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'queued_unverified']);
        $this->assertDatabaseMissing('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'executed']);
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }
}
