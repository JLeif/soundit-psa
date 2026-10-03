<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 2, ruling Q9: agents move time only through the held verb
 * move_time_entry_contract / stage_move_time_entry_contract, on the
 * set_call_billable grant model, shipped ungranted. update_ticket with
 * contract_id moves no money. Synthetic data only (G-13).
 */
class MoveTimeEntryContractToolTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contract $a;

    private Contract $b;

    private TicketNote $note;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $client = Client::create(['name' => 'Synthetic Client T']);
        $this->a = $this->contract($client, 'Synthetic Block A');
        $this->b = $this->contract($client, 'Synthetic Project B');
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $this->a->id]);
        $this->note = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 45, 'noted_at' => now(),
        ]);
    }

    private function contract(Client $client, string $name, array $o = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $client->id, 'name' => $name, 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ], $o));
    }

    private function callTool(string $token, string $name, array $args): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]);
    }

    private function toolResult(TestResponse $r): array
    {
        $r->assertOk();

        return json_decode((string) $r->json('result.content.0.text'), true) ?? ['rpc_error' => $r->json('error.message')];
    }

    private function args(array $o = []): array
    {
        return array_merge(['entry_type' => 'note', 'entry_id' => $this->note->id, 'contract_id' => $this->b->id, 'reason' => 'Synthetic move'], $o);
    }

    public function test_ungranted_including_legacy_tokens(): void
    {
        foreach ([null, ['get_ticket_detail']] as $grants) {
            $token = McpConfig::rotateStaffToken(allowedTools: $grants);
            $text = (string) ($this->callTool($token, 'move_time_entry_contract', $this->args())->json('result.content.0.text')
                ?? $this->callTool($token, 'move_time_entry_contract', $this->args())->json('error.message'));
            $this->assertStringContainsString('not allowed for this token', $text);
        }
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
        $this->assertDatabaseCount('time_entry_move_proposals', 0);
    }

    public function test_grant_model_matches_set_call_billable(): void
    {
        $tool = 'move_time_entry_contract';
        $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry($tool), 'a bare grant holds');
        $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry('stage_'.$tool));
        $this->assertSame([$tool, 'immediate'], McpToolModes::parseGrantEntry($tool.':immediate'));
        $this->assertSame('staged', McpToolModes::defaultMode($tool));
        $this->assertSame(McpToolModes::defaultMode('set_call_billable'), McpToolModes::defaultMode($tool));
    }

    public function test_bare_grant_holds_then_approval_moves_append_only(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract'], label: 'synthetic-agent');
        $held = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args() + ['staged' => false]));
        $this->assertTrue($held['staged']);
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'), 'nothing moved');
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('time-entry-moves.approve', $held['proposal_id']))->assertSessionHas('success');
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
        $this->assertSame(2, PrepayTransaction::where('contract_id', $this->a->id)->count(), 'debit kept plus the credit');
        $this->assertSame('done', TimeEntryMoveProposal::find($held['proposal_id'])->state);
        $this->actingAs($admin)->post(route('time-entry-moves.approve', $held['proposal_id']))->assertSessionHas('error');
        $this->assertSame(1, PrepayTransaction::where('contract_id', $this->b->id)->count());
    }

    public function test_held_move_goes_stale_when_the_entry_moved_meanwhile(): void
    {
        $c = $this->contract($this->ticket->client, 'Synthetic C');
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract:staged']);
        $held = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args()));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        app(\App\Services\PrepayService::class)->moveEntryContract($this->note, $c, 'Staff moved it', $admin);

        $this->actingAs($admin)->post(route('time-entry-moves.approve', $held['proposal_id']))->assertSessionHas('error');
        $this->assertSame('stale', TimeEntryMoveProposal::find($held['proposal_id'])->state);
        $this->assertSame($c->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
    }

    public function test_immediate_grant_executes_and_refuses_foreign_or_inactive(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract:immediate']);
        $other = Client::create(['name' => 'Synthetic Other']);
        $foreign = $this->contract($other, 'Synthetic Foreign');
        $expired = $this->contract($this->ticket->client, 'Synthetic Expired', ['status' => 'expired']);
        foreach ([$foreign, $expired] as $bad) {
            $r = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['contract_id' => $bad->id])));
            $this->assertSame('contract_not_allowed', $r['error_code'] ?? null);
        }
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));

        $r = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args()));
        $this->assertFalse($r['staged']);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
        $this->assertEquals(10, (float) $this->a->fresh()->prepay_balance);
    }

    /**
     * Jeeves 2026-10-02 21:38 PT: the verb states the prepay draw for an entry with no ledger
     * row moving onto an hours-prepay contract, in its description and in its staged and
     * immediate results: hours, target contract, and the already-invoiced advice.
     */
    public function test_unledgered_move_states_the_prepay_draw(): void
    {
        $advice = 'If this time was already invoiced by hand, untick billable instead of moving.';
        foreach ([false, true] as $internal) {
            $description = \App\Support\McpToolRegistry::moveTimeEntryContractTool($internal)['description'];
            $this->assertStringContainsString('NO prepay ledger row', $description);
            $this->assertStringContainsString('draws its hours from that contract right after the move', $description);
            $this->assertStringContainsString($advice, $description);
        }

        $managed = $this->contract($this->ticket->client, 'Synthetic Managed M', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $this->ticket->update(['contract_id' => $managed->id]);
        $note = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count(), 'precondition: no ledger row');
        $args = $this->args(['entry_id' => $note->id]);

        $staged = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']), 'move_time_entry_contract', $args));
        $this->assertTrue($staged['staged']);
        $this->assertSame(0.5, $staged['draw_hours_on_approval']);
        $this->assertStringContainsString('On approval: It has no prepay ledger row, so the move will draw 0.50h from Synthetic Project B. '.$advice, $staged['message']);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count(), 'staging draws nothing');

        // The ledgered entry's staged result states no draw: its move is the credit/debit pair.
        $pair = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']), 'move_time_entry_contract', $this->args()));
        $this->assertEquals(0, $pair['draw_hours_on_approval']);
        $this->assertStringNotContainsString('will draw', $pair['message']);

        TimeEntryMoveProposal::query()->update(['state' => 'denied']);
        $now = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract:immediate']), 'move_time_entry_contract', $args));
        $this->assertFalse($now['staged']);
        $this->assertFalse($now['ledger']);
        $this->assertSame(0.5, $now['drawn_hours']);
        $this->assertSame('Time entry moved. It had no prepay ledger row, so it drew 0.50h from Synthetic Project B. '.$advice, $now['message']);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->contract_id, 'the stated draw happened');
        $this->assertEquals(9.5, (float) $this->b->fresh()->prepay_balance);
    }

    /** SPEC §9 C4: update_ticket contract_id changes the ticket, moves no money, lists the entries. */
    public function test_update_ticket_contract_id_moves_no_money(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['update_ticket']);
        $ledger = PrepayTransaction::orderBy('id')->get()->toArray();
        $r = $this->toolResult($this->callTool($token, 'update_ticket', ['ticket_id' => $this->ticket->id, 'contract_id' => $this->b->id]));

        $this->assertTrue($r['success'] ?? false, json_encode($r));
        $this->assertSame($this->b->id, $this->ticket->fresh()->contract_id);
        $this->assertSame($ledger, PrepayTransaction::orderBy('id')->get()->toArray());
        $this->assertSame([['type' => 'note', 'id' => $this->note->id, 'minutes' => 45, 'contract_id' => $this->a->id]], $r['entries_on_other_contracts']);

        $other = Client::create(['name' => 'Synthetic Other']);
        $foreign = $this->contract($other, 'Synthetic Foreign');
        $r = $this->toolResult($this->callTool($token, 'update_ticket', ['ticket_id' => $this->ticket->id, 'contract_id' => $foreign->id]));
        $this->assertSame('contract_not_allowed', $r['error_code'] ?? null);
        $this->assertSame($this->b->id, $this->ticket->fresh()->contract_id);
    }
}
