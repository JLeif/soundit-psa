<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Mcp\PortalMcpToolDefinitions;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 3 (spec §7, §9 C1-C6): contract_id on every agent
 * ticket-creating tool, the refusal shape, the reported outcome, is_default on
 * list_client_contracts, and no contract_id on the portal. Synthetic data only.
 */
class ContractAtCreationToolsTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 'Optional. Must be an active contract of this ticket\'s client; omit it and the server uses the client\'s default contract, else its only active contract, else none — the response says which.';

    private Client $client;

    private Client $other;

    private Contract $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->client = Client::create(['name' => 'Synthetic Client M']);
        $this->other = Client::create(['name' => 'Synthetic Other M']);
        $this->foreign = $this->contract('Synthetic Foreign', [], $this->other);
    }

    private function contract(string $name, array $o = [], ?Client $client = null): Contract
    {
        return Contract::create(array_merge([
            'client_id' => ($client ?? $this->client)->id, 'name' => $name, 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ], $o));
    }

    private function callTool(array $tools, string $name, array $args): array
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]);
        $r->assertOk();

        return ['isError' => (bool) $r->json('result.isError')]
            + (json_decode((string) $r->json('result.content.0.text'), true) ?? ['raw' => $r->json('result.content.0.text')]);
    }

    private function createArgs(array $o = []): array
    {
        return array_merge([
            'client_id' => $this->client->id, 'subject' => 'Synthetic subject',
            'description' => 'Synthetic description', 'reason' => 'Synthetic reason',
        ], $o);
    }

    private function email(): Email
    {
        return Email::create([
            'direction' => EmailDirection::Inbound, 'from_address' => 'someone@example.test',
            'subject' => 'Synthetic email', 'body_text' => 'Synthetic', 'received_at' => now(),
            'client_id' => $this->client->id,
        ]);
    }

    private function phoneCall(string $uuid = 'synthetic-call-1'): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => $uuid, 'direction' => CallDirection::Inbound, 'from_number' => '+15555550123',
            'status' => CallStatus::Completed, 'started_at' => now(),
        ]);
        $call->client_id = $this->client->id;
        $call->save();

        return $call;
    }

    private function assistant(array $input): array
    {
        return (new AssistantToolExecutor(clientId: $this->client->id, userId: User::factory()->create()->id))
            ->execute('create_ticket', $input);
    }

    private function assertRefused(array $r, Contract|int $contract): void
    {
        $id = $contract instanceof Contract ? $contract->id : $contract;
        $this->assertSame('contract_not_allowed', $r['error_code'] ?? null, json_encode($r));
        $this->assertSame(
            "contract_id must be an ACTIVE contract of this ticket's client; contract {$id} is not. Call list_client_contracts for valid ids, or omit contract_id to use the client's default.",
            $r['error'],
        );
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Ticket::withTrashed()->count(), 'no ticket row');
        $this->assertSame(0, TechnicianActionLog::count(), 'no audit row');
    }

    // ── C1: another client's contract is refused on every creating surface ───

    public function test_c1_staff_create_ticket_refuses_a_foreign_contract(): void
    {
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $this->foreign->id]));
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
    }

    public function test_c1_assistant_create_ticket_refuses_a_foreign_contract(): void
    {
        $r = $this->assistant(['subject' => 'Synthetic', 'description' => 'Synthetic', 'contract_id' => $this->foreign->id]);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
    }

    public function test_c1_create_ticket_from_email_refuses_a_foreign_contract(): void
    {
        $email = $this->email();
        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $email->id, 'reason' => 'Synthetic', 'contract_id' => $this->foreign->id,
        ]);
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
        $this->assertNull($email->fresh()->ticket_id);
    }

    public function test_c1_create_ticket_from_call_refuses_a_foreign_contract(): void
    {
        $call = $this->phoneCall();
        $r = $this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $call->id, 'reason' => 'Synthetic', 'contract_id' => $this->foreign->id,
        ]);
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
        $this->assertNull($call->fresh()->ticket_id);
        $this->assertNull($call->fresh()->contract_id);
    }

    // ── C2: an inactive contract of the same client ─────────────────────────

    public function test_c2_inactive_contract_of_the_same_client_is_refused_on_every_tool(): void
    {
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);

        $this->assertRefused($this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $expired->id])), $expired);
        $this->assertRefused($this->assistant(['subject' => 'S', 'description' => 'D', 'contract_id' => $expired->id]), $expired);
        $this->assertRefused($this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic', 'contract_id' => $expired->id,
        ]), $expired);
        $this->assertRefused($this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $this->phoneCall()->id, 'reason' => 'Synthetic', 'contract_id' => $expired->id,
        ]), $expired);
        $this->assertNothingWritten();
    }

    // ── Validate before dedup; dedup hash unchanged ─────────────────────────

    public function test_create_ticket_validates_the_contract_before_dedup_and_the_hash_ignores_it(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');

        $first = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $a->id]));
        $this->assertTrue($first['success'] ?? false, json_encode($first));
        $hash = TechnicianActionLog::sole()->content_hash;
        $this->assertSame(
            app(\App\Services\Assistant\AssistantTicketCreator::class)->contentHashFromPayload(
                ['client_id' => $this->client->id, 'subject' => 'Synthetic subject', 'description' => 'Synthetic description']
            ),
            $hash,
        );

        // Same identity, a refused contract: refused, never an idempotent success.
        $bad = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $this->foreign->id]));
        $this->assertRefused($bad, $this->foreign);
        $this->assertArrayNotHasKey('idempotent', $bad);

        // Same identity, another valid contract: the dedup still answers.
        $dup = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $b->id]));
        $this->assertTrue($dup['idempotent'] ?? false, json_encode($dup));
        // The idempotent answer reports the existing ticket's contract, not the one asked for.
        $this->assertSame(['id' => $a->id, 'name' => 'Synthetic A', 'rule' => 'ticket'], $dup['contract']);
        $this->assertSame(1, Ticket::count());
        $this->assertSame($a->id, Ticket::sole()->contract_id);
    }

    // ── C3: outcome reported in every create response ───────────────────────

    public function test_c3_staff_create_ticket_reports_each_rule(): void
    {
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S none']));
        $this->assertNull($r['contract']);
        $this->assertSame('none', $r['contract_rule']);

        $only = $this->contract('Synthetic Only');
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S only']));
        $this->assertSame(['id' => $only->id, 'name' => 'Synthetic Only', 'rule' => 'only_active'], $r['contract']);
        $this->assertSame('only_active', $r['contract_rule']);

        $second = $this->contract('Synthetic Second');
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S amb']));
        $this->assertNull($r['contract']);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertSame([
            ['id' => $only->id, 'name' => 'Synthetic Only', 'type' => 'managed'],
            ['id' => $second->id, 'name' => 'Synthetic Second', 'type' => 'managed'],
        ], $r['candidate_contracts']);
        $this->assertNull(Ticket::where('subject', 'S amb')->value('contract_id'));

        Client::whereKey($this->client->id)->update(['default_contract_id' => $second->id]);
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S def']));
        $this->assertSame(['id' => $second->id, 'name' => 'Synthetic Second', 'rule' => 'client_default'], $r['contract']);
        $this->assertSame($second->id, Ticket::where('subject', 'S def')->value('contract_id'));

        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S pick', 'contract_id' => $only->id]));
        $this->assertSame(['id' => $only->id, 'name' => 'Synthetic Only', 'rule' => 'picked'], $r['contract']);
        $this->assertSame('picked', $r['contract_rule']);
        $this->assertArrayNotHasKey('candidate_contracts', $r);
    }

    public function test_c3_assistant_email_and_call_creates_report_the_outcome(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');

        $r = $this->assistant(['subject' => 'S', 'description' => 'D']);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertCount(2, $r['candidate_contracts']);

        $r = $this->assistant(['subject' => 'S2', 'description' => 'D', 'contract_id' => $b->id]);
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'picked'], $r['contract']);

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic', 'contract_id' => $a->id,
        ]);
        $this->assertSame(['id' => $a->id, 'name' => 'Synthetic A', 'rule' => 'picked'], $r['contract']);
        $this->assertSame($a->id, Ticket::find($r['ticket_id'])->contract_id);

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic',
        ]);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertCount(2, $r['candidate_contracts']);
    }

    public function test_create_ticket_from_call_stamps_the_picked_contract_on_the_call(): void
    {
        $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $call = $this->phoneCall();

        $r = $this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $call->id, 'reason' => 'Synthetic', 'contract_id' => $b->id,
        ]);

        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'picked'], $r['contract']);
        $this->assertSame($b->id, Ticket::find($r['ticket_id'])->contract_id);
        $this->assertSame($b->id, $call->fresh()->contract_id);
        $this->assertSame($b->id, $r['call_contract_id']);
    }

    // ── C4 (PR 2 pins the money side): the update response reports the contract ─

    public function test_c4_update_ticket_reports_the_contract_block(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'contract_id' => $a->id]);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => $b->id]);
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'picked'], $r['contract']);
        $this->assertSame('picked', $r['contract_rule']);
        $this->assertSame([], $r['entries_on_other_contracts']);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => null]);
        $this->assertNull($r['contract']);
        $this->assertSame('none', $r['contract_rule']);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => $this->foreign->id]);
        $this->assertSame('contract_not_allowed', $r['error_code']);
        $this->assertNull($ticket->fresh()->contract_id);
    }

    // ── list_client_contracts: is_default per row ──────────────────────────

    public function test_list_client_contracts_flags_the_live_default_only(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $r = $this->callTool(['list_client_contracts'], 'list_client_contracts', ['client_id' => $this->client->id]);
        $flags = array_column($r['contracts'], 'is_default', 'id');
        $this->assertSame([$a->id => false, $b->id => true, $expired->id => false], [$a->id => $flags[$a->id], $b->id => $flags[$b->id], $expired->id => $flags[$expired->id]]);

        // A stale (expired) default flags nothing.
        Client::whereKey($this->client->id)->update(['default_contract_id' => $expired->id]);
        $r = $this->callTool(['list_client_contracts'], 'list_client_contracts', ['client_id' => $this->client->id]);
        $this->assertSame([false, false, false], array_values(array_column($r['contracts'], 'is_default')));
    }

    // ── C5: the portal create_ticket has no contract_id ─────────────────────

    public function test_c5_portal_create_ticket_schema_has_no_contract_id(): void
    {
        $tool = collect(PortalMcpToolDefinitions::tools())->firstWhere('name', 'create_ticket');
        $this->assertNotNull($tool);
        $this->assertArrayNotHasKey('contract_id', $tool['input_schema']['properties']);
        $this->assertStringNotContainsString('contract', json_encode($tool));
    }

    // ── C6: the description line on every ticket-creating agent schema ──────

    /**
     * Every agent-facing tool that creates a ticket: names matching create_ticket
     * (create_ticket, create_ticket_from_*) and their staged twins, across the staff
     * MCP registry and the assistant definitions. A tool added later under such a name
     * without the line fails here. create_ticket_category creates a taxonomy node.
     *
     * @return array<string, array<string, mixed>>
     */
    private function creatingSchemas(): array
    {
        $tools = [];
        // The full schemas the staff MCP publishes (McpToolRegistry::groups() keeps only
        // names and descriptions), plus every staged twin.
        $staff = array_merge(
            \App\Support\McpToolSurface::liveGeneralToolDefinitions(),
            \App\Support\McpToolSurface::liveClientScopedToolDefinitions(),
            McpToolRegistry::psaActionTools(),
            McpToolRegistry::intakeManageTools(),
        );
        foreach ($staff as $tool) {
            $tools['staff:'.$tool['name']] = $tool;
        }
        foreach (AssistantToolDefinitions::getTools(true) as $tool) {
            $tools['assistant:'.$tool['name']] = $tool;
        }

        return array_filter($tools, fn (array $t, string $k) => preg_match('/(^|_)create_ticket(_from_[a-z]+)?$/', $t['name']) === 1, ARRAY_FILTER_USE_BOTH);
    }

    public function test_c6_every_ticket_creating_agent_schema_carries_the_contract_line(): void
    {
        $schemas = $this->creatingSchemas();
        $this->assertEqualsCanonicalizing(
            ['staff:create_ticket', 'staff:create_ticket_from_email', 'staff:create_ticket_from_call', 'assistant:create_ticket'],
            array_keys($schemas),
        );

        foreach ($schemas as $key => $tool) {
            $schema = $tool['input_schema'] ?? $tool['inputSchema'] ?? [];
            $this->assertArrayHasKey('contract_id', $schema['properties'] ?? [], $key);
            $this->assertSame(['integer', 'null'], $schema['properties']['contract_id']['type'], $key);
            $this->assertSame(self::LINE, $schema['properties']['contract_id']['description'], $key);
            $this->assertNotContains('contract_id', $schema['required'] ?? [], $key);
        }
    }

    public function test_c6_the_staff_tools_list_publishes_the_line(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['create_ticket', 'create_ticket_from_email', 'create_ticket_from_call']);
        $tools = collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->json('result.tools'))->keyBy('name');

        foreach (['create_ticket', 'create_ticket_from_email', 'create_ticket_from_call'] as $name) {
            $this->assertSame(self::LINE, $tools[$name]['inputSchema']['properties']['contract_id']['description'] ?? null, $name);
        }
    }
}
