<?php

namespace Tests\Feature\Mesh;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * C-56 / #5248 (card 6ac4439b): a failed Mesh call is reported by its HTTP
 * status only, never by the MeshClientException message.
 *
 * That message is built by MeshWriteClient::request() from Guzzle's own, and
 * Guzzle's quotes the request URI and up to 120 chars of the response body.
 * So these tests drive the REAL client over a scripted Guzzle handler, which
 * produces exactly that message, rather than a mock throwing a tidy string.
 * Every failure body carries MARKER; each site must keep MARKER, the host and
 * the route out of what it reports, and must name the status (or say there
 * was none).
 *
 * Sites covered, one test each, in both failure modes (HTTP 503 and a
 * connect failure with no status):
 *   - mesh_remove_allow_rule: the findRuleById scope read at staging;
 *   - mesh_edit_allow_rule: the findRuleById scope read at staging;
 *   - mesh_edit_allow_rule: the confirming re-read after the PATCH;
 *   - the reaper's settle pass, PERMANENT and unexpired arms (last_error, log);
 *   - reapOne's list read (last_error, log);
 *   - reapOne's DELETE failure (log).
 *
 * Synthetic data only.
 */
class MeshVendorErrorStatusOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'SYNTHETIC-VENDOR-BODY-7f3a';

    private const HOST = 'mesh.example.test';

    private const TENANT = '9e3c1f0a-1b2c-4d5e-8f90-a1b2c3d4e5f6';

    private const RULE_ID = '0b1c2d3e-4f50-4a6b-8c7d-8e9fa0b1c2d3';

    /** What the failing call answers: 503 or 'connect' (no HTTP status). */
    private string $mode = '503';

    /** "GET list" | "PATCH" | "DELETE" — the request that fails; others answer. */
    private string $failOn = 'GET list';

    /** When true, the list read fails only once a PATCH has been sent. */
    private bool $failListAfterPatch = false;

    private bool $patched = false;

    /** When set, the list page reports this count instead of the real one. */
    private ?int $reportedCount = null;

    /** @var array<string, array<string, mixed>> */
    private array $upstream = [];

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Http::preventStrayRequests();
        Setting::setEncrypted('mesh_api_key', 'k');

        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message.' '.json_encode($e->context)];
        });
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['HTTP 503' => ['503'], 'connect failure, no status' => ['connect']];
    }

    // ---- the executor: findRuleById callers -------------------------------------

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Allow rule change']);

        return compact('client', 'ticket');
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ["{$name}:staged"], label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function args(array $fixture, array $extra = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'rule_id' => self::RULE_ID,
            'confirm_sender' => 'billing@vendor.example.test',
            'reason' => 'Synthetic reason long enough to be accepted by the verb.',
        ], $extra);
    }

    private function tracked(array $fixture): MeshAllowRule
    {
        return MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example.test',
            'comment' => 'PSA allow STATUSONLY',
            'mesh_rule_id' => self::RULE_ID,
            'expires_at' => now()->addDays(30)->startOfMinute(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_remove_scope_read_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $fixture = $this->fixture();

        $text = (string) $this->callTool('mesh_remove_allow_rule', $this->args($fixture))->json('result.content.0.text');

        $this->assertStringContainsString('scope could not be checked and nothing was removed', $text);
        $this->assertStatusOnly($text, 'remove tool output');
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_edit_scope_read_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $fixture = $this->fixture();
        $this->tracked($fixture);

        $text = (string) $this->callTool('mesh_edit_allow_rule', $this->args($fixture, [
            'expires_at' => now()->addDays(60)->startOfMinute()->toIso8601String(),
        ]))->json('result.content.0.text');

        $this->assertStringContainsString('scope could not be checked and nothing was changed', $text);
        $this->assertStatusOnly($text, 'edit tool output');
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_edit_confirming_reread_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failListAfterPatch = true;
        $this->seedRule('billing@vendor.example.test', 'PSA allow STATUSONLY');
        $this->bindClient();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);

        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $newExpiry = now()->addDays(60)->startOfMinute();
        $this->callTool('mesh_edit_allow_rule', $this->args($fixture, ['expires_at' => $newExpiry->toIso8601String()]))->assertOk();
        $run = TechnicianRun::where('action_type', 'mesh_stage_edit_allow_rule')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertTrue($this->patched, 'positive control: the PATCH was sent before the re-read failed');
        $this->assertTheVendorMessageCarriesTheLeak();

        $error = (string) session('error');
        $this->assertStringContainsString('could NOT be measured', $error);
        $this->assertStatusOnly($error, 'edit approval error');

        $audit = TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed_with_fault')->sole();
        $this->assertStatusOnly((string) $audit->summary, 'edit audit summary');
        $this->assertTrue($record->fresh()->expires_at->equalTo($newExpiry), 'the authoritative change is still kept');
    }

    // ---- the reaper (#5248) -----------------------------------------------------

    private function reaperRow(string $comment, ?\Illuminate\Support\Carbon $expiresAt, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'mesh_customer_id' => self::TENANT,
            'sender' => 'reap@sender.example.test',
            'comment' => $comment,
            'mesh_rule_id' => null,
            'expires_at' => $expiresAt,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
            'last_error' => null,
        ], $overrides));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_settle_pass_list_failure_reports_the_status_only_in_both_arms(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $permanent = $this->reaperRow('PSA allow PERMANENT1', null);
        $dated = $this->reaperRow('PSA allow UNEXPIRED1', now()->addMonths(3));

        app(MeshAllowRuleReaper::class)->reap();

        $permanent->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $permanent->state);
        $this->assertStringContainsString('upstream id of this PERMANENT rule', (string) $permanent->last_error);
        $this->assertStatusOnly((string) $permanent->last_error, 'settle PERMANENT last_error');

        $dated->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $dated->state);
        $this->assertStringContainsString('upstream id of this unexpired rule', (string) $dated->last_error);
        $this->assertStatusOnly((string) $dated->last_error, 'settle unexpired last_error');

        $log = $this->loggedBy('[MeshAllowRuleReaper]');
        $this->assertStringContainsString('upstream id of this PERMANENT rule', $log);
        $this->assertStringContainsString('upstream id of this unexpired rule', $log);
        $this->assertStatusOnly($log, 'settle log');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_reap_list_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $expired = $this->reaperRow('PSA allow EXPIRED001', now()->subDay());

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['failed']);
        $expired->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $expired->state);
        $this->assertStringContainsString("Could not read the tenant's rule list to resolve the upstream id: ", (string) $expired->last_error);
        $this->assertStatusOnly((string) $expired->last_error, 'reapOne last_error');

        $log = $this->loggedBy('[MeshAllowRuleReaper]');
        $this->assertStringContainsString('not reaped', $log);
        $this->assertStatusOnly($log, 'reapOne log');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_reap_delete_failure_logs_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'DELETE';
        $this->seedRule('reap@sender.example.test', 'PSA allow EXPIRED002');
        $this->bindClient();
        $this->reaperRow('PSA allow EXPIRED002', now()->subDay(), ['mesh_rule_id' => self::RULE_ID]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        // The DELETE never landed, so the rule is still readable: still a
        // failure, never a reap.
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['reaped']);

        $log = $this->loggedBy('[MeshAllowRuleReaper] DELETE failed');
        $this->assertStatusOnly($log, 'reapOne DELETE log');
        $this->assertStringContainsString('the DELETE', $log);
    }

    // ---- failures the client detects itself (G-14) ------------------------------

    /**
     * Every page answered HTTP 200, but the client itself refused the read
     * (rows seen fewer than the count Mesh reported). That message is written
     * by the PSA and quotes no vendor text, so it is reported as written —
     * never as a status-less transport failure that did not happen.
     */
    public function test_a_client_detected_list_failure_keeps_its_own_diagnosis(): void
    {
        $this->failOn = 'none';
        $this->reportedCount = 5;
        $this->seedRule('billing@vendor.example.test', 'PSA allow STATUSONLY');
        $this->bindClient();

        try {
            $this->app->make(MeshWriteClient::class)->listCustomerRules(self::TENANT);
            $this->fail('positive control: a short read against a larger count was expected to be refused');
        } catch (MeshClientException $e) {
            $this->assertSame(0, $e->getCode(), 'positive control: a client-detected failure carries no status');
            $this->assertStringContainsString('Mesh reported 5', $e->getMessage());
        }

        $fixture = $this->fixture();
        $text = (string) $this->callTool('mesh_remove_allow_rule', $this->args($fixture))->json('result.content.0.text');
        $this->assertStringContainsString('scope could not be checked and nothing was removed', $text);
        $this->assertStringContainsString('Mesh reported 5', $text);
        $this->assertStringNotContainsString('without an HTTP status', $text);
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');

        $expired = $this->reaperRow('PSA allow EXPIRED003', now()->subDay());
        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(1, $counts['failed']);
        $expired->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $expired->state);
        $this->assertStringContainsString("Could not read the tenant's rule list to resolve the upstream id: ", (string) $expired->last_error);
        $this->assertStringContainsString('Mesh reported 5', (string) $expired->last_error);
        $this->assertStringNotContainsString('without an HTTP status', (string) $expired->last_error);
    }

    /** Each arm of statusPhrase(), including the ones no scripted vendor reaches. */
    public function test_status_phrase_reduces_only_upstream_failures_to_their_status(): void
    {
        $status = new MeshClientException('Mesh API error: '.self::MARKER, 503);
        $this->assertSame('Mesh answered the DELETE with HTTP 503', $status->statusPhrase('the DELETE'));

        $wrapped = new MeshClientException('Mesh API error: cURL error 7 '.self::MARKER, 0);
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $wrapped->statusPhrase('the DELETE'));

        $chained = new MeshClientException(self::MARKER, 0, new \RuntimeException(self::MARKER));
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $chained->statusPhrase('the DELETE'));

        $uri = new MeshClientException('read of https://'.self::HOST.'/x refused', 0);
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $uri->statusPhrase('the DELETE'));

        $own = new MeshClientException('Mesh API key is not configured; nothing was sent.');
        $this->assertSame('Mesh API key is not configured; nothing was sent.', $own->statusPhrase('the DELETE'));
    }

    // ---- the scripted vendor ----------------------------------------------------

    private function failure(RequestInterface $request)
    {
        if ($this->mode === 'connect') {
            // errno 7 (couldn't connect) is one MeshWriteClient reads as
            // never-sent; its own message still names the URI and the marker.
            return Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to '.self::HOST.' '.self::MARKER.' for '.$request->getUri(),
                $request,
                null,
                ['errno' => 7],
            ));
        }

        return Create::promiseFor(new Response(503, ['Content-Type' => 'application/json'], json_encode(['detail' => self::MARKER])));
    }

    private function bindClient(): void
    {
        $handler = function (RequestInterface $request) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            $isList = $method === 'GET' && $path === 'api/rule-allows-blocks/';

            if ($isList && $this->failOn === 'GET list' && (! $this->failListAfterPatch || $this->patched)) {
                return $this->failure($request);
            }
            if ($method === $this->failOn) {
                return $this->failure($request);
            }

            if ($isList) {
                return Create::promiseFor(new Response(200, [], json_encode([
                    'count' => $this->reportedCount ?? count($this->upstream),
                    'next' => null,
                    'previous' => null,
                    'results' => array_values($this->upstream),
                ])));
            }

            $id = trim(substr($path, strlen('api/rule-allows-blocks/')), '/');

            if ($method === 'PATCH') {
                $this->patched = true;

                return Create::promiseFor(new Response(200, [], json_encode($this->upstream[$id] ?? [])));
            }
            if ($method === 'DELETE') {
                unset($this->upstream[$id]);

                return Create::promiseFor(new Response(200, [], '{}'));
            }

            return Create::promiseFor(isset($this->upstream[$id])
                ? new Response(200, [], json_encode($this->upstream[$id]))
                : new Response(404, [], '{"detail":"Not found."}'));
        };

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => HandlerStack::create($handler),
            'http_errors' => true,
        ]);

        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    private function seedRule(string $sender, string $comment): void
    {
        $this->upstream[self::RULE_ID] = [
            'id' => self::RULE_ID,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => true,
            'active' => true,
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => self::TENANT, 'name' => 'Tenant'],
            'date_expiry' => null,
        ];
    }

    // ---- assertions -------------------------------------------------------------

    /** Positive control: the client really produced a message carrying the leak. */
    private function assertTheVendorMessageCarriesTheLeak(): void
    {
        $client = $this->app->make(MeshWriteClient::class);
        try {
            $client->listCustomerRules(self::TENANT);
            $this->fail('the scripted list read was expected to fail');
        } catch (MeshClientException $e) {
            $this->assertStringContainsString(self::MARKER, $e->getMessage(), 'positive control: the raw message carries the vendor body');
            $this->assertStringContainsString(self::HOST, $e->getMessage(), 'positive control: the raw message carries the host');
        }
    }

    private function assertStatusOnly(string $text, string $where): void
    {
        $this->assertStringNotContainsString(self::MARKER, $text, "{$where}: vendor body leaked");
        $this->assertStringNotContainsString(self::HOST, $text, "{$where}: request host leaked");
        $this->assertStringNotContainsString('rule-allows-blocks', $text, "{$where}: request route leaked");
        $this->assertStringNotContainsString('Mesh API', $text, "{$where}: the exception message was used");

        if ($this->mode === '503') {
            $this->assertStringContainsString('with HTTP 503', $text, "{$where}: the status is the report");
        } else {
            $this->assertStringContainsString('failed without an HTTP status from Mesh', $text, "{$where}: a status-less failure says so");
            $this->assertStringNotContainsString('HTTP 0', $text, $where);
        }
    }

    /** Every record the named component wrote, at any level, joined. */
    private function loggedBy(string $prefix): string
    {
        $lines = array_filter($this->logged, fn (array $r) => str_contains($r['message'], $prefix));
        $this->assertNotSame([], $lines, "positive control: {$prefix} logged something");

        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $lines));
    }
}
