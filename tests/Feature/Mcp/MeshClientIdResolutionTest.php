<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshReadTools;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6abdcac2, third integration: the Mesh read tools take client_id and resolve
 * the customer through the client's stored mapping (clients.mesh_customer_id), the
 * Huntress pattern from #4486 as applied to Tactical (#4548) and CIPP (#4577).
 *
 * Synthetic clients only: Alpha and Bravo (mapped), Unmapped. Mailboxes are
 * example.test addresses and every Mesh answer comes from a fake that records what
 * would have been sent upstream; no vendor is called. Row keys follow what PSA has
 * always read (a `list` envelope, Customer-ID / "Customer Id", Queue-Id).
 */
class MeshClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const ALPHA_KEY = 'aaaaaaaa-1111-4111-8111-000000000001';

    private const BRAVO_KEY = 'bbbbbbbb-2222-4222-8222-000000000002';

    private const ALPHA_QUEUE = 'a0a0a0a0-0000-4000-8000-00000000000a';

    private const BRAVO_QUEUE = 'b0b0b0b0-0000-4000-8000-00000000000b';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $sent = [];

    /** @var array<string, mixed> */
    public array $logsAnswer = [];

    /** @var array<string, mixed> */
    public array $eventsAnswer = [];

    private Client $alpha;

    private Client $bravo;

    private Client $unmapped;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('mesh_api_key', 'fixture-key-not-a-credential');
        Setting::setValue('mesh_enabled', '1');

        $this->alpha = Client::factory()->create(['name' => 'Alpha', 'mesh_customer_id' => self::ALPHA_KEY]);
        $this->bravo = Client::factory()->create(['name' => 'Bravo', 'mesh_customer_id' => self::BRAVO_KEY]);
        $this->unmapped = Client::factory()->create(['name' => 'Unmapped', 'mesh_customer_id' => null]);

        $this->logsAnswer = ['list' => [
            ['Customer-ID' => [self::ALPHA_KEY], 'Queue-Id' => self::ALPHA_QUEUE, 'To' => 'user@alpha.example.test', 'Subject' => 'alpha mail'],
            ['Customer-ID' => [self::BRAVO_KEY], 'Queue-Id' => self::BRAVO_QUEUE, 'To' => 'user@bravo.example.test', 'Subject' => 'bravo mail'],
        ]];
        $this->eventsAnswer = ['events' => [['stage' => 'delivered']]];

        $test = $this;
        $this->app->instance(MeshClient::class, new class($test) extends MeshClient
        {
            public function __construct(private MeshClientIdResolutionTest $test) {}

            public function get(string $endpoint, array $params = []): array
            {
                $this->test->sent[] = [$endpoint, $params];

                return str_contains($endpoint, 'events') ? $this->test->eventsAnswer : $this->test->logsAnswer;
            }
        });
    }

    /** @return array<string, mixed>|string the decoded tool result, or the raw boundary text */
    private function mcp(string $name, array $arguments): array|string
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [$name], label: 'opsbot');
        $text = (string) $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ])->json('result.content.0.text');

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : $text;
    }

    private function assistant(Client $client, string $tool, array $input): array
    {
        return (new AssistantToolExecutor(null, $client->id))->execute($tool, $input);
    }

    private function triage(Client $client, string $tool, array $input): array
    {
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        return (new TriageToolExecutor($ticket))->execute($tool, $input);
    }

    /** @return list<string> */
    private function endpointsSent(): array
    {
        return array_column($this->sent, 0);
    }

    private function subjects(array $out): array
    {
        return array_column($out['emails'] ?? [], 'Subject');
    }

    /** @return array<string, callable(Client, string, array): (array|string)> */
    private function surfaces(): array
    {
        return [
            'staff mcp' => fn (Client $c, string $tool, array $in) => $this->mcp($tool, ['client_id' => $c->id] + $in),
            'assistant' => fn (Client $c, string $tool, array $in) => $this->assistant($c, $tool, $in),
            'triage' => fn (Client $c, string $tool, array $in) => $this->triage($c, $tool, $in),
        ];
    }

    // ── the mapped client resolves through the key ──────────────────────────────

    public function test_a_mapped_clients_search_serves_only_its_own_rows_on_every_surface(): void
    {
        foreach ($this->surfaces() as $surface => $call) {
            $this->sent = [];
            $out = $call($this->alpha, 'mesh_search_email_logs', []);

            $this->assertIsArray($out, $surface);
            $this->assertSame(['alpha mail'], $this->subjects($out), $surface.': '.json_encode($out));
            $this->assertSame(1, $out['total'], $surface);
            $this->assertSame(1, $out['withheld_other_customers'], $surface);
            $this->assertStringContainsString('not proof that no such mail exists', $out['scope_note'], $surface);
            $this->assertSame(['api/emaillogs/'], $this->endpointsSent(), $surface);
        }
    }

    public function test_a_mailbox_filter_only_narrows_within_the_client(): void
    {
        $out = $this->mcp('mesh_search_email_logs', ['client_id' => $this->alpha->id, 'to' => 'user@bravo.example.test']);

        $this->assertSame('user@bravo.example.test', $this->sent[0][1]['to']);
        $this->assertNotContains('bravo mail', $this->subjects($out), json_encode($out));
        $this->assertSame(['alpha mail'], $this->subjects($out));
    }

    public function test_no_customer_selector_is_ever_sent_upstream(): void
    {
        $this->assistant($this->alpha, 'mesh_search_email_logs', ['to' => 'user@alpha.example.test', 'customer_id' => self::BRAVO_KEY, 'size' => 5]);

        $this->assertSame(['_from', '_size', 'start', 'end', 'to'], array_keys($this->sent[0][1]));
    }

    public function test_an_agent_typed_customer_id_is_ignored_when_the_client_is_mapped(): void
    {
        $out = $this->assistant($this->alpha, 'mesh_search_email_logs', ['customer_id' => self::BRAVO_KEY]);

        $this->assertSame(['alpha mail'], $this->subjects($out), json_encode($out));
    }

    public function test_staff_mcp_refuses_a_customer_id_argument_outright(): void
    {
        $out = $this->mcp('mesh_search_email_logs', ['client_id' => $this->alpha->id, 'customer_id' => self::BRAVO_KEY]);

        $this->assertIsString($out);
        $this->assertStringContainsString('Unsupported MCP argument(s): customer_id', $out);
        $this->assertSame([], $this->sent);
    }

    public function test_the_stored_key_matches_rows_trimmed_and_case_insensitively(): void
    {
        $this->alpha->update(['mesh_customer_id' => '  '.strtoupper(self::ALPHA_KEY).' ']);
        $this->logsAnswer = ['list' => [
            ['Customer Id' => self::ALPHA_KEY, 'Queue-Id' => self::ALPHA_QUEUE, 'Subject' => 'alpha mail'],
        ]];

        $out = $this->assistant($this->alpha->fresh(), 'mesh_search_email_logs', []);

        $this->assertSame(['alpha mail'], $this->subjects($out), json_encode($out));
        $this->assertArrayNotHasKey('scope_note', $out);
    }

    public function test_events_for_a_queue_id_this_clients_search_served_are_read_on_every_surface(): void
    {
        foreach ($this->surfaces() as $surface => $call) {
            $call($this->alpha, 'mesh_search_email_logs', []);
            $this->sent = [];

            $out = $call($this->alpha, 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

            $this->assertSame($this->eventsAnswer, $out, $surface);
            $this->assertSame([['api/emaillogs/events', ['queue_id' => self::ALPHA_QUEUE]]], $this->sent, $surface);
        }
    }

    // ── an unmapped client fails closed ─────────────────────────────────────

    public function test_an_unmapped_client_fails_closed_on_both_tools_and_every_surface(): void
    {
        foreach ($this->surfaces() as $surface => $call) {
            foreach (['mesh_search_email_logs' => [], 'mesh_get_email_events' => ['queue_id' => self::BRAVO_QUEUE]] as $tool => $in) {
                $this->sent = [];
                $out = $call($this->unmapped, $tool, $in);

                $this->assertIsArray($out, "{$surface} {$tool}");
                $this->assertStringContainsString('is not mapped to Mesh', $out['error'] ?? '', "{$surface} {$tool}: ".json_encode($out));
                $this->assertSame([], $this->sent, "{$surface} {$tool} called Mesh");
            }
        }
    }

    public function test_a_blank_mapping_is_unmapped(): void
    {
        $this->unmapped->update(['mesh_customer_id' => '   ']);

        $out = $this->assistant($this->unmapped->fresh(), 'mesh_search_email_logs', []);

        $this->assertStringContainsString('is not mapped to Mesh', $out['error'] ?? '');
        $this->assertSame([], $this->sent);
    }

    public function test_staff_mcp_without_client_id_fails_closed_on_both_tools(): void
    {
        foreach (['mesh_search_email_logs' => [], 'mesh_get_email_events' => ['queue_id' => self::BRAVO_QUEUE]] as $tool => $in) {
            foreach ([[], ['client_id' => 'garbage']] as $scope) {
                $out = $this->mcp($tool, $scope + $in);

                $this->assertStringContainsString('client_id is required', $out['error'] ?? '', $tool.': '.json_encode($out));
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_an_unmapped_client_named_like_a_mailbox_domain_gets_no_fallback(): void
    {
        $this->unmapped->update(['name' => 'alpha.example.test']);

        $out = $this->assistant($this->unmapped->fresh(), 'mesh_search_email_logs', ['to' => 'user@alpha.example.test']);

        $this->assertStringContainsString('is not mapped to Mesh', $out['error'] ?? '');
        $this->assertSame([], $this->sent);
    }

    // ── another client's customer fails closed ──────────────────────────────

    public function test_another_clients_queue_id_fails_closed_on_every_surface(): void
    {
        // Bravo's own search has served Bravo's queue id; Alpha's has not.
        $this->assistant($this->bravo, 'mesh_search_email_logs', []);

        foreach ($this->surfaces() as $surface => $call) {
            $call($this->alpha, 'mesh_search_email_logs', []);
            $this->sent = [];

            $out = $call($this->alpha, 'mesh_get_email_events', ['queue_id' => self::BRAVO_QUEUE]);

            $this->assertIsArray($out, $surface);
            $this->assertStringContainsString('was not returned by mesh_search_email_logs for PSA client '.$this->alpha->id, $out['error'] ?? '', $surface.': '.json_encode($out));
            $this->assertSame([], $this->sent, $surface.' asked Mesh for another client\'s events');
        }
    }

    public function test_a_queue_id_never_searched_fails_closed(): void
    {
        $out = $this->assistant($this->alpha, 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

        $this->assertStringContainsString('Search this client\'s logs first', $out['error'] ?? '', json_encode($out));
        $this->assertSame([], $this->sent);
    }

    public function test_a_queue_id_served_to_one_client_does_not_open_it_to_a_remapped_customer(): void
    {
        $this->assistant($this->alpha, 'mesh_search_email_logs', []);
        $this->alpha->update(['mesh_customer_id' => 'cccccccc-3333-4333-8333-000000000003']);
        $this->sent = [];

        $out = $this->assistant($this->alpha->fresh(), 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

        $this->assertStringContainsString('was not returned by mesh_search_email_logs', $out['error'] ?? '', json_encode($out));
        $this->assertSame([], $this->sent);
    }

    public function test_an_events_answer_naming_another_customer_is_withheld(): void
    {
        $this->assistant($this->alpha, 'mesh_search_email_logs', []);
        $this->eventsAnswer = ['events' => [['stage' => 'delivered', 'Customer-ID' => self::BRAVO_KEY]]];

        $out = $this->assistant($this->alpha, 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

        $this->assertStringContainsString('names a Mesh customer that is not PSA client '.$this->alpha->id, $out['error'] ?? '', json_encode($out));
        $this->assertStringNotContainsString(self::BRAVO_KEY, json_encode($out));
    }

    public function test_an_events_answer_naming_this_customer_is_served(): void
    {
        $this->assistant($this->alpha, 'mesh_search_email_logs', []);
        $this->eventsAnswer = ['events' => [['stage' => 'delivered', 'customer_id' => strtoupper(self::ALPHA_KEY)]]];

        $out = $this->assistant($this->alpha, 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

        $this->assertSame($this->eventsAnswer, $out);
    }

    public function test_a_customer_key_shared_with_another_client_fails_closed(): void
    {
        // The unique index compares raw strings, so case and padding let a duplicate in.
        $twin = Client::factory()->create(['name' => 'Twin', 'mesh_customer_id' => ' '.strtoupper(self::ALPHA_KEY)]);

        foreach ([$this->alpha, $twin] as $client) {
            foreach (['mesh_search_email_logs' => [], 'mesh_get_email_events' => ['queue_id' => self::ALPHA_QUEUE]] as $tool => $in) {
                $out = $this->assistant($client, $tool, $in);

                $this->assertStringContainsString('also mapped to another PSA client', $out['error'] ?? '', "{$client->name} {$tool}: ".json_encode($out));
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_resolve_refuses_a_client_object_that_is_not_the_client_id(): void
    {
        $this->assertSame(
            ['error' => "PSA client {$this->alpha->id} was not found."],
            MeshReadTools::resolve($this->bravo, $this->alpha->id),
        );
        $this->assertSame(mb_strtolower(self::ALPHA_KEY), MeshReadTools::resolve($this->alpha, $this->alpha->id));
    }

    // ── unknown envelopes fail closed ────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unknownSearchEnvelopes(): array
    {
        return [
            'no list key' => [['results' => [['Customer-ID' => [self::ALPHA_KEY]]]]],
            'empty body' => [[]],
            'list is not a list' => [['list' => ['Customer-ID' => self::ALPHA_KEY]]],
            'error body' => [['error' => 'Unauthorized', 'list' => []]],
        ];
    }

    #[DataProvider('unknownSearchEnvelopes')]
    public function test_an_unknown_search_envelope_fails_closed_not_as_no_mail(array $answer): void
    {
        $this->logsAnswer = $answer;

        $out = $this->assistant($this->alpha, 'mesh_search_email_logs', []);

        $this->assertStringContainsString('shape PSA does not recognise', $out['error'] ?? '', json_encode($out));
        $this->assertArrayNotHasKey('total', $out);
    }

    public function test_rows_without_any_customer_id_fail_closed(): void
    {
        $this->logsAnswer = ['list' => [['Queue-Id' => self::ALPHA_QUEUE, 'Subject' => 'whose?']]];

        $out = $this->assistant($this->alpha, 'mesh_search_email_logs', []);

        $this->assertStringContainsString('without a customer id', $out['error'] ?? '', json_encode($out));
    }

    public function test_an_empty_list_is_a_real_empty_answer(): void
    {
        $this->logsAnswer = ['list' => []];

        $out = $this->assistant($this->alpha, 'mesh_search_email_logs', []);

        $this->assertSame(['total' => 0, 'emails' => []], $out);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unusableEventsAnswers(): array
    {
        return [
            'empty body' => [[]],
            'error body' => [['error' => 'Missing queue_id']],
        ];
    }

    #[DataProvider('unusableEventsAnswers')]
    public function test_an_unusable_events_answer_fails_closed(array $answer): void
    {
        $this->assistant($this->alpha, 'mesh_search_email_logs', []);
        $this->eventsAnswer = $answer;

        $out = $this->assistant($this->alpha, 'mesh_get_email_events', ['queue_id' => self::ALPHA_QUEUE]);

        $this->assertStringContainsString('no usable events answer', $out['error'] ?? '', json_encode($out));
    }

    // ── descriptions ─────────────────────────────────────────────────────────

    public function test_the_descriptions_say_client_id_is_the_key_and_events_is_bound_to_search(): void
    {
        $tools = array_column(TriageToolDefinitions::meshTools(), null, 'name');

        foreach (['mesh_search_email_logs', 'mesh_get_email_events'] as $name) {
            $this->assertStringContainsString('client_id is the key', $tools[$name]['description'], $name);
            $this->assertStringContainsString('clients.mesh_customer_id', $tools[$name]['description'], $name);
            $this->assertStringContainsString('no customer, domain or mailbox fallback', $tools[$name]['description'], $name);
        }
        $this->assertStringContainsString('only narrow this client', $tools['mesh_search_email_logs']['description']);
        $this->assertStringContainsString('withheld_other_customers', $tools['mesh_search_email_logs']['description']);
        $this->assertStringContainsString('returned for the SAME client_id', $tools['mesh_get_email_events']['description']);
        $this->assertSame('string', $tools['mesh_get_email_events']['input_schema']['properties']['queue_id']['type']);
    }
}
