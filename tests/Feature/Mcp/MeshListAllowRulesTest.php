<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Card dxWX6IBS — mesh_list_allow_rules, the READ verb on the Mesh allow-rule
 * surface.
 *
 * The real MeshWriteClient runs over an injected Guzzle client whose routing
 * handler plays the partner-wide rule list (rows shaped as MeshWriteClientTest
 * and MeshWriteClient's docblock record them: tenant only in the nested
 * `customer.id`). Guzzle's history middleware records every request, so the
 * "no write is ever sent" assertions read what actually went on the wire.
 * All data is synthetic (example.test domains, made-up uuids).
 */
class MeshListAllowRulesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const OTHER_TENANT = '99999999-8888-7777-6666-555555555555';

    /** @var array<int, mixed> */
    private array $history = [];

    /** @var array<int, array<string, mixed>> */
    private array $upstream = [];

    /** When set, every request is answered with this status and body. */
    private ?array $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        Http::preventStrayRequests();

        Setting::setEncrypted('mesh_api_key', 'k');

        $handler = function (RequestInterface $request) {
            if ($this->failWith !== null) {
                [$status, $body] = $this->failWith;

                return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], $body));
            }
            $path = ltrim($request->getUri()->getPath(), '/');
            if ($request->getMethod() === 'GET' && $path === 'api/rule-allows-blocks/') {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'count' => count($this->upstream),
                    'results' => array_values($this->upstream),
                ])));
            }

            return Create::promiseFor(new Response(599, [], json_encode(['unrouted' => $request->getMethod().' '.$path])));
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://mesh.invalid/']);
        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    /** @return array<string, mixed> */
    private function row(string $id, string $sender, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'sender' => $sender,
            'comment' => 'Set up by hand',
            'ab' => true,
            'active' => true,
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => self::TENANT, 'name' => 'Tenant'],
            'created_by' => 'keyowner@example.test',
        ], $overrides);
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'mesh_list_allow_rules', 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function listOk(Client $client, array $extra = []): array
    {
        $response = $this->callTool($this->token(['mesh_list_allow_rules']), array_merge(['client_id' => $client->id], $extra));
        $response->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function errorText(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected a tool error');

        return (string) $response->json('result.content.0.text');
    }

    /** @return list<string> */
    private function methodsSent(): array
    {
        return array_map(fn (array $t): string => $t['request']->getMethod(), $this->history);
    }

    private function assertNoWriteSent(): void
    {
        foreach ($this->methodsSent() as $method) {
            $this->assertSame('GET', $method, 'a read verb sent a '.$method);
        }
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => self::TENANT]);
    }

    private function record(Client $client, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'client_id' => $client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example.test',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => null,
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ], $overrides));
    }

    private function stagedRun(Ticket $ticket): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'mesh_stage_add_allow_rule', 'content_hash' => str_repeat('b', 64),
            'state' => TechnicianRunState::Done,
        ]);
    }

    // ---- the list, the join, permanent vs expiry --------------------------------

    public function test_lists_the_tenants_rules_with_the_psa_join_and_permanent_mapping(): void
    {
        $client = $this->mappedClient();
        $ticket = Ticket::factory()->for($client)->create();
        $run = $this->stagedRun($ticket);

        // Tracked permanent: joined by rule id, null expiry => permanent.
        $this->record($client, [
            'sender' => 'billing@vendor.example.test', 'mesh_rule_id' => 'rule-perm',
            'ticket_id' => $ticket->id, 'technician_run_id' => $run->id, 'expires_at' => null,
        ]);
        // Tracked temporary: joined by rule id, the PSA expiry is reported.
        $this->record($client, [
            'sender' => 'news.example.test', 'comment' => 'PSA allow TEMPORARY1', 'mesh_rule_id' => 'rule-temp',
            'ticket_id' => $ticket->id, 'expires_at' => now()->addDays(10),
        ]);

        $this->upstream = [
            $this->row('rule-perm', 'Billing@Vendor.Example.Test', ['comment' => 'PSA allow ABCDEFGHIJ', 'date_modified' => '2026-09-30T10:00:00Z']),
            $this->row('rule-temp', 'news.example.test', ['comment' => 'PSA allow TEMPORARY1', 'date_expiry' => '2026-10-11T12:00:00Z']),
            // Foreign, with a displayed expiry Mesh does not act on: still permanent.
            $this->row('rule-foreign', 'sat.example.test', ['date_expiry' => '2026-10-02T00:00:00Z']),
            // Another tenant's rule must never appear.
            $this->row('rule-other', 'billing@vendor.example.test', ['customer' => ['id' => self::OTHER_TENANT, 'name' => 'Other']]),
            // A block rule is counted, not listed.
            $this->row('rule-block', 'spam.example.test', ['ab' => false]),
        ];

        $out = $this->listOk($client);
        $rules = collect($out['rules'])->keyBy('rule_id');

        $this->assertSame(['rule-perm', 'rule-temp', 'rule-foreign'], $rules->keys()->all());
        $this->assertSame(3, $out['rule_count']);
        $this->assertSame(1, $out['block_rules_not_listed']);

        $perm = $rules['rule-perm'];
        $this->assertSame('billing@vendor.example.test', $perm['sender']);
        $this->assertSame('address', $perm['scope']);
        $this->assertSame('allow', $perm['action']);
        $this->assertTrue($perm['permanent']);
        $this->assertNull($perm['expires_at']);
        $this->assertSame('PSA allow ABCDEFGHIJ', $perm['comment']);
        $this->assertTrue($perm['psa_created']);
        $this->assertSame('rule_id', $perm['psa_match']);
        $this->assertSame($ticket->id, $perm['ticket_id']);
        $this->assertSame($run->id, $perm['technician_run_id']);
        $this->assertSame(['date_modified' => '2026-09-30T10:00:00Z'], $perm['mesh_dates']);
        $this->assertNotNull($perm['psa_recorded_at']);

        $temp = $rules['rule-temp'];
        $this->assertFalse($temp['permanent']);
        $this->assertSame(now()->addDays(10)->toIso8601String(), $temp['expires_at']);
        $this->assertSame('domain', $temp['scope']);
        $this->assertTrue($temp['psa_created']);

        $foreign = $rules['rule-foreign'];
        $this->assertFalse($foreign['psa_created']);
        $this->assertTrue($foreign['permanent'], 'Mesh does not expire rules; an untracked rule is permanent whatever it displays');
        $this->assertSame('2026-10-02T00:00:00Z', $foreign['mesh_displayed_expiry']);
        $this->assertNull($foreign['ticket_id']);
        $this->assertNull($foreign['technician_run_id']);

        $this->assertSame([], $out['unsettled_psa_records']);
        $this->assertNoWriteSent();
        $this->assertNotEmpty($this->history, 'positive control: the list read reached the transport');
    }

    public function test_a_tracked_rule_whose_id_never_resolved_joins_by_sender_and_comment(): void
    {
        $client = $this->mappedClient();
        $record = $this->record($client, [
            'sender' => 'billing@vendor.example.test', 'comment' => 'PSA allow UNRESOLVED',
            'mesh_rule_id' => null, 'state' => MeshAllowRule::STATE_UNRESOLVED, 'scope_proved' => true,
        ]);
        $this->upstream = [$this->row('rule-u', 'billing@vendor.example.test', ['comment' => 'PSA allow UNRESOLVED'])];

        $out = $this->listOk($client);

        $this->assertTrue($out['rules'][0]['psa_created']);
        $this->assertSame('sender_and_comment', $out['rules'][0]['psa_match']);
        $this->assertSame($record->id, $out['rules'][0]['psa_record_id']);
        $this->assertCount(1, $out['unsettled_psa_records']);
        $this->assertTrue($out['unsettled_psa_records'][0]['seen_in_mesh_list']);
    }

    public function test_this_clients_records_under_another_tenant_are_listed_as_unsettled_but_never_joined(): void
    {
        $client = $this->mappedClient();
        // A scope fault: stored against the tenant Mesh attested, not the client's mapping. The approval brake still sees it.
        $scopeFault = $this->record($client, [
            'mesh_customer_id' => self::OTHER_TENANT, 'sender' => 'billing@vendor.example.test',
            'comment' => 'PSA allow STALE00001', 'mesh_rule_id' => null,
            'state' => MeshAllowRule::STATE_UNRESOLVED, 'scope_proved' => false, 'expires_at' => null,
        ]);
        // Stored against the client's previous tenant: the reaper works it there, not here.
        $previous = $this->record($client, [
            'mesh_customer_id' => self::OTHER_TENANT, 'sender' => 'news.example.test',
            'comment' => 'PSA allow STALE00002', 'mesh_rule_id' => 'rule-by-id',
            'state' => MeshAllowRule::STATE_REAP_FAILED, 'expires_at' => now()->subDay(),
        ]);
        $this->upstream = [
            $this->row('rule-copy', 'billing@vendor.example.test', ['comment' => 'PSA allow STALE00001']),
            $this->row('rule-by-id', 'news.example.test', ['comment' => 'PSA allow STALE00002']),
        ];

        $out = $this->listOk($client);
        $rules = collect($out['rules'])->keyBy('rule_id');

        $this->assertSame(['rule-copy', 'rule-by-id'], $rules->keys()->all(), 'positive control: both rules are listed');
        foreach ($rules as $id => $rule) {
            $this->assertFalse($rule['psa_created'], $id);
            $this->assertNull($rule['psa_match'], $id);
            $this->assertNull($rule['psa_record_id'], $id);
            $this->assertTrue($rule['permanent'], $id);
            $this->assertNull($rule['expires_at'], $id);
        }
        $unsettled = collect($out['unsettled_psa_records'])->keyBy('psa_record_id');
        $this->assertEqualsCanonicalizing([$scopeFault->id, $previous->id], $unsettled->keys()->all());
        foreach ($unsettled as $id => $entry) {
            $this->assertFalse($entry['stored_under_listed_tenant'], (string) $id);
            $this->assertFalse($entry['seen_in_mesh_list'], (string) $id);
            $this->assertSame($client->id, $entry['psa_client_id'], (string) $id);
        }
        $this->assertFalse($unsettled[$scopeFault->id]['scope_proved']);
    }

    public function test_a_record_another_client_stored_against_the_listed_tenant_is_joined_and_attributed(): void
    {
        // The tenant was remapped from $previous to $client; the reaper still works $previous's records on it.
        $previous = Client::factory()->create(['name' => 'Previous', 'mesh_customer_id' => self::OTHER_TENANT]);
        $client = $this->mappedClient();
        $timed = $this->record($previous, [
            'sender' => 'billing@vendor.example.test', 'comment' => 'PSA allow PREVIOUS01',
            'mesh_rule_id' => 'rule-prev', 'expires_at' => now()->addDays(3),
        ]);
        $pending = $this->record($previous, [
            'sender' => 'news.example.test', 'comment' => 'PSA allow PREVIOUS02',
            'state' => MeshAllowRule::STATE_UNRESOLVED,
        ]);
        $this->upstream = [$this->row('rule-prev', 'billing@vendor.example.test', ['comment' => 'PSA allow PREVIOUS01'])];

        $out = $this->listOk($client);

        $rule = $out['rules'][0];
        $this->assertSame('rule-prev', $rule['rule_id']);
        $this->assertTrue($rule['psa_created']);
        $this->assertSame('rule_id', $rule['psa_match']);
        $this->assertSame($timed->id, $rule['psa_record_id']);
        $this->assertSame($previous->id, $rule['psa_client_id']);
        $this->assertFalse($rule['permanent'], 'the reaper deletes it by the record\'s own tenant, whichever client holds the record');
        $this->assertSame(now()->addDays(3)->toIso8601String(), $rule['expires_at']);

        $this->assertSame([$pending->id], array_column($out['unsettled_psa_records'], 'psa_record_id'));
        $this->assertSame($previous->id, $out['unsettled_psa_records'][0]['psa_client_id']);
        $this->assertTrue($out['unsettled_psa_records'][0]['stored_under_listed_tenant']);
    }

    public function test_a_stored_last_error_is_reported_as_present_never_as_text(): void
    {
        $client = $this->mappedClient();
        $this->record($client, [
            'state' => MeshAllowRule::STATE_REAP_FAILED, 'mesh_rule_id' => 'rule-f', 'expires_at' => now()->subDay(),
            'last_error' => 'Mesh API error: Client error: DELETE https://mesh.invalid/api/rule-allows-blocks/rule-f/ resulted in 400 {"detail":"SECRET-BODY-MARKER"}',
        ]);
        $this->record($client, ['sender' => 'clean@vendor.example.test', 'comment' => 'PSA allow CLEAN00001', 'state' => MeshAllowRule::STATE_UNRESOLVED]);

        $out = $this->listOk($client);
        $unsettled = collect($out['unsettled_psa_records'])->keyBy('sender');
        $encoded = (string) json_encode($out, JSON_UNESCAPED_SLASHES);

        $this->assertCount(2, $unsettled, 'positive control: both records are listed');
        $this->assertStringNotContainsString('SECRET-BODY-MARKER', $encoded);
        $this->assertStringNotContainsString('mesh.invalid', $encoded);
        $this->assertStringNotContainsString('rule-allows-blocks', $encoded);
        $this->assertArrayNotHasKey('last_error', $unsettled['billing@vendor.example.test']);
        $this->assertTrue($unsettled['billing@vendor.example.test']['last_error_recorded']);
        $this->assertFalse($unsettled['clean@vendor.example.test']['last_error_recorded']);
    }

    // ---- sender filter -------------------------------------------------------------

    private function filterFixture(): Client
    {
        $client = $this->mappedClient();
        $this->upstream = [
            $this->row('r-addr', 'billing@vendor.example.test'),
            $this->row('r-addr2', 'ops@vendor.example.test'),
            $this->row('r-domain', 'vendor.example.test'),
            $this->row('r-sub', 'mail.vendor.example.test'),
            $this->row('r-other', 'billing@elsewhere.example.test'),
        ];

        return $client;
    }

    public function test_sender_filter_with_an_address_is_an_exact_case_insensitive_match(): void
    {
        $out = $this->listOk($this->filterFixture(), ['sender' => 'Billing@Vendor.Example.Test']);

        $this->assertSame(['r-addr'], array_column($out['rules'], 'rule_id'));
        $this->assertSame(['value' => 'billing@vendor.example.test', 'match' => 'exact_address'], $out['sender_filter']);
    }

    public function test_sender_filter_with_a_domain_matches_the_domain_rule_and_its_addresses_only(): void
    {
        $client = $this->filterFixture();
        foreach (['vendor.example.test', '@VENDOR.example.test'] as $input) {
            $out = $this->listOk($client, ['sender' => $input]);

            $this->assertSame(['r-addr', 'r-addr2', 'r-domain'], array_column($out['rules'], 'rule_id'), $input);
            $this->assertSame('domain', $out['sender_filter']['match']);
        }
    }

    public function test_sender_filter_that_matches_nothing_returns_no_rules(): void
    {
        $out = $this->listOk($this->filterFixture(), ['sender' => 'nobody@nowhere.example.test']);

        $this->assertSame([], $out['rules']);
        $this->assertSame(0, $out['rule_count']);
    }

    // ---- pending / failed PSA records ------------------------------------------

    public function test_unresolved_and_reap_failed_records_are_listed_separately_with_state(): void
    {
        $client = $this->mappedClient();
        $ticket = Ticket::factory()->for($client)->create();
        $run = $this->stagedRun($ticket);
        $other = Client::factory()->create(['mesh_customer_id' => self::OTHER_TENANT]);

        $unresolved = $this->record($client, [
            'sender' => 'pending@vendor.example.test', 'comment' => 'PSA allow PENDING001',
            'state' => MeshAllowRule::STATE_UNRESOLVED, 'ticket_id' => $ticket->id, 'technician_run_id' => $run->id,
            'last_error' => 'Upstream rule id unresolved',
        ]);
        $failed = $this->record($client, [
            'sender' => 'failed@vendor.example.test', 'comment' => 'PSA allow FAILED0001', 'mesh_rule_id' => 'rule-f',
            'state' => MeshAllowRule::STATE_REAP_FAILED, 'expires_at' => now()->subDay(),
        ]);
        // Settled states and other clients' rows are not unsettled for this client.
        $this->record($client, ['sender' => 'gone@vendor.example.test', 'state' => MeshAllowRule::STATE_REAPED, 'mesh_rule_id' => 'rule-g']);
        $this->record($client, ['sender' => 'removed@vendor.example.test', 'state' => MeshAllowRule::STATE_REMOVED, 'mesh_rule_id' => 'rule-r']);
        $this->record($other, ['sender' => 'theirs@vendor.example.test', 'state' => MeshAllowRule::STATE_UNRESOLVED, 'mesh_customer_id' => self::OTHER_TENANT]);

        $this->upstream = [$this->row('rule-f', 'failed@vendor.example.test', ['comment' => 'PSA allow FAILED0001'])];

        $out = $this->listOk($client);
        $unsettled = collect($out['unsettled_psa_records'])->keyBy('psa_record_id');

        $this->assertEqualsCanonicalizing([$unresolved->id, $failed->id], $unsettled->keys()->all());
        $this->assertSame('unresolved', $unsettled[$unresolved->id]['state']);
        $this->assertSame($ticket->id, $unsettled[$unresolved->id]['ticket_id']);
        $this->assertSame($run->id, $unsettled[$unresolved->id]['technician_run_id']);
        $this->assertFalse($unsettled[$unresolved->id]['seen_in_mesh_list']);
        $this->assertTrue($unsettled[$unresolved->id]['permanent']);
        $this->assertSame('reap_failed', $unsettled[$failed->id]['state']);
        $this->assertTrue($unsettled[$failed->id]['seen_in_mesh_list']);
        $this->assertFalse($unsettled[$failed->id]['permanent']);

        // The reap_failed rule is still live upstream and still reapable, so it is not permanent there either.
        $this->assertFalse($out['rules'][0]['permanent']);
        $this->assertSame('reap_failed', $out['rules'][0]['psa_state']);

        // The sender filter narrows this list too.
        $filtered = $this->listOk($client, ['sender' => 'pending@vendor.example.test']);
        $this->assertSame([$unresolved->id], array_column($filtered['unsettled_psa_records'], 'psa_record_id'));
    }

    // ---- refusals ----------------------------------------------------------------

    public function test_an_unmapped_client_is_refused_and_nothing_is_read(): void
    {
        $client = Client::factory()->create(['name' => 'Unmapped', 'mesh_customer_id' => null]);
        $this->upstream = [$this->row('rule-1', 'a@example.test')];

        $text = $this->errorText($this->callTool($this->token(['mesh_list_allow_rules']), ['client_id' => $client->id]));

        $this->assertStringContainsString('has no Mesh customer mapping', $text);
        $this->assertSame([], $this->history, 'no Mesh request for an unmapped client');
    }

    public function test_a_missing_client_id_is_refused(): void
    {
        $text = $this->errorText($this->callTool($this->token(['mesh_list_allow_rules']), []));

        $this->assertStringContainsString('client_id is required', $text);
        $this->assertSame([], $this->history);
    }

    public function test_an_unknown_argument_is_refused_at_the_executor_too(): void
    {
        $client = $this->mappedClient();
        $executor = app(StaffMeshAdminToolExecutor::class);

        $unknown = $executor->execute('mesh_list_allow_rules', ['state' => 'all'], $client->id, 'test');
        $this->assertStringContainsString('state (not a parameter of this tool)', $unknown['error']);

        $tenant = $executor->execute('mesh_list_allow_rules', ['customer_id' => self::OTHER_TENANT], $client->id, 'test');
        $this->assertStringContainsString('the Mesh tenant is derived from the PSA client', $tenant['error']);

        $this->assertSame([], $this->history, 'a refused call reads nothing');
    }

    public function test_an_unknown_argument_is_refused_over_mcp(): void
    {
        $client = $this->mappedClient();

        $text = $this->errorText($this->callTool($this->token(['mesh_list_allow_rules']), ['client_id' => $client->id, 'customer_id' => self::OTHER_TENANT]));

        $this->assertStringContainsString('customer_id', $text);
        $this->assertSame([], $this->history);
    }

    public function test_a_malformed_sender_filter_is_refused(): void
    {
        $client = $this->mappedClient();
        foreach (['', '*.example.test', 'a@b.example.test, c@d.example.test', '@'] as $bad) {
            $text = $this->errorText($this->callTool($this->token(['mesh_list_allow_rules']), ['client_id' => $client->id, 'sender' => $bad]));
            $this->assertStringContainsString('sender must', $text, var_export($bad, true));
        }
        $this->assertSame([], $this->history);
    }

    // ---- vendor failure ------------------------------------------------------------

    public function test_a_vendor_error_is_a_clean_error_with_no_body_or_secret(): void
    {
        $client = $this->mappedClient();
        foreach ([500, 403, 400] as $status) {
            $this->history = [];
            $this->failWith = [$status, json_encode(['detail' => 'SECRET-BODY-MARKER internal trace', 'errors' => ['SECRET-BODY-MARKER']])];

            $text = $this->errorText($this->callTool($this->token(['mesh_list_allow_rules']), ['client_id' => $client->id]));

            $this->assertStringContainsString("HTTP {$status}", $text);
            $this->assertStringContainsString('This is not an empty list', $text);
            $this->assertStringNotContainsString('SECRET-BODY-MARKER', $text, "status {$status}");
            $this->assertStringNotContainsString('mesh.invalid', $text);
            $this->assertStringNotContainsString('rule-allows-blocks', $text);
            $this->assertStringNotContainsString(self::TENANT, $text);
            $this->assertNoWriteSent();
        }
    }

    // ---- read-only, grants, no audit side effects ----------------------------------

    public function test_the_read_records_nothing_and_sends_no_write(): void
    {
        $client = $this->mappedClient();
        $this->upstream = [$this->row('rule-1', 'a@example.test')];

        $this->listOk($client);
        $this->listOk($client, ['sender' => 'example.test']);

        $this->assertSame(['GET', 'GET'], $this->methodsSent());
        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, TechnicianActionLog::count());
    }

    public function test_the_tool_is_read_only_not_stageable_and_needs_an_explicit_grant(): void
    {
        $name = 'mesh_list_allow_rules';

        $definition = collect(StaffMeshAdminToolExecutor::definitions())->firstWhere('name', $name);
        $this->assertNotNull($definition);
        $this->assertSame('object', $definition['input_schema']['type']);
        $this->assertSame(['sender'], array_keys($definition['input_schema']['properties']));
        $this->assertSame([], $definition['input_schema']['required']);
        $this->assertStringContainsString('READ-ONLY', $definition['description']);

        $this->assertTrue(StaffMeshAdminToolExecutor::handles($name));
        $this->assertTrue(StaffMeshAdminToolExecutor::requiresClient($name));
        $this->assertNotContains($name, array_values(StaffMeshAdminToolExecutor::stagedToDirectMap()));
        $this->assertFalse(McpToolModes::isStageable($name));
        $this->assertFalse(McpToolModes::isHeldOnly($name));
        $this->assertFalse(McpToolModes::isStagedAlias($name));
        $this->assertSame([$name, null], McpToolModes::parseGrantEntry($name), 'a grant is a plain name, with no staged/immediate mode');
        $this->assertContains($name, McpToolRegistry::allToolNames());

        $client = $this->mappedClient();
        $this->upstream = [$this->row('rule-1', 'a@example.test')];

        // The legacy full-surface token never inherits it.
        $legacy = $this->errorText($this->callTool(McpConfig::rotateStaffToken(), ['client_id' => $client->id]));
        $this->assertStringContainsString('Tool not allowed', $legacy);
        // Nor does a token granted the sibling Mesh writes.
        $siblings = $this->errorText($this->callTool($this->token(['mesh_add_allow_rule:staged', 'mesh_remove_allow_rule:staged', 'mesh_edit_allow_rule:staged']), ['client_id' => $client->id]));
        $this->assertStringContainsString('Tool not allowed', $siblings);
        $this->assertSame([], $this->history);

        // With the grant it runs immediately and stages nothing.
        $out = $this->listOk($client, ['sender' => 'a@example.test']);
        $this->assertSame('rule-1', $out['rules'][0]['rule_id']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /**
     * Card UtffkPs5 moved the verb from the allow-list writes tier to its own sensitive read
     * tier. Display only: the grant must stay explicit, so neither a token holding every Mesh
     * write plus Mesh's plain reads, nor the legacy full-surface token, gains it, and a grant
     * stored by NAME (how mcp_tokens.tools keeps grants; there are no group keys) still works
     * and still survives the token page's save validation.
     */
    public function test_the_read_tier_move_keeps_the_grant_explicit_and_honours_a_stored_name_grant(): void
    {
        $name = 'mesh_list_allow_rules';
        $client = $this->mappedClient();
        $this->upstream = [$this->row('rule-1', 'a@example.test')];

        $this->assertContains($name, array_column(McpToolRegistry::groups()['mesh_read']['tools'] ?? [], 'name'), 'it renders in the Mesh read group');

        $writesAndPlainReads = $this->token([
            'mesh_add_allow_rule:staged', 'mesh_remove_allow_rule:staged', 'mesh_edit_allow_rule:staged',
            'mesh_search_email_logs', 'mesh_get_email_events',
        ]);
        $this->assertStringContainsString('Tool not allowed', $this->errorText($this->callTool($writesAndPlainReads, ['client_id' => $client->id])));
        $this->assertStringContainsString('Tool not allowed', $this->errorText($this->callTool(McpConfig::rotateStaffToken(), ['client_id' => $client->id])));
        $this->assertNotContains($name, array_column($this->withHeaders(['Authorization' => 'Bearer '.McpConfig::rotateStaffToken()])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools') ?? [], 'name'), 'the legacy token must not list it');
        $this->assertSame([], $this->history, 'a refused call sends nothing upstream');

        // A grant held by name before the move: stored as the plain tool name.
        $plain = McpConfig::rotateStaffToken(allowedTools: [$name], label: 'pre-move-grant');
        $this->assertSame([$name], \App\Models\McpToken::query()->where('label', 'pre-move-grant')->value('tools'));
        $normalized = McpToolModes::normalizeGrantEntries([$name]);
        $this->assertSame([], $normalized['unknown'], 'the token page must still accept the stored name');
        $this->assertSame([$name], $normalized['entries']);

        $response = $this->callTool($plain, ['client_id' => $client->id]);
        $response->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('rule-1', json_decode((string) $response->json('result.content.0.text'), true)['rules'][0]['rule_id']);
    }

    public function test_the_granted_token_sees_it_in_tools_list_without_a_staged_parameter(): void
    {
        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['mesh_list_allow_rules'])])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'mesh_list_allow_rules');

        $this->assertNotNull($tool);
        $this->assertArrayNotHasKey('staged', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('sender', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('client_id', $tool['inputSchema']['properties']);
        $this->assertContains('client_id', $tool['inputSchema']['required']);
    }
}
