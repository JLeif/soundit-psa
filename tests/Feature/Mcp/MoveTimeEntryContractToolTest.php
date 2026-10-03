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

    /** context:1: update_ticket's contract change draws no held "Needs contract" entry, as its message says. */
    public function test_update_ticket_contract_id_leaves_held_entries_undrawn(): void
    {
        $this->ticket->update(['contract_id' => null]);
        $held = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);
        $this->assertNotNull($held->fresh()->contract_held_at, 'precondition: held (two active contracts, no default)');

        $r = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['update_ticket']), 'update_ticket',
            ['ticket_id' => $this->ticket->id, 'contract_id' => $this->b->id]));

        $this->assertTrue($r['success'] ?? false, json_encode($r));
        $this->assertStringContainsString('No time moved', $r['message']);
        $this->assertFalse(PrepayTransaction::where('ticket_note_id', $held->id)->exists(), 'nothing drawn');
        $this->assertNotNull($held->fresh()->contract_held_at, 'still held');
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);
    }

    /** diff:14: the cockpit card states what approving each held move does. */
    public function test_cockpit_card_states_each_moves_effect(): void
    {
        $advice = 'If this time was already invoiced by hand, untick billable instead of moving.';
        $managed = $this->contract($this->ticket->client, 'Synthetic Managed M', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $this->ticket->update(['contract_id' => $managed->id]);
        $unledgered = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']);
        $draw = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['entry_id' => $unledgered->id])));
        $pair = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args()));
        $noDebit = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['contract_id' => $managed->id])));

        $moves = app(\App\Services\TimeEntryContractMoveService::class);
        $this->assertSame('It has no prepay ledger row, so the move will draw 0.50h from Synthetic Project B. '.$advice.' Nothing has moved yet.',
            $moves->approvalEffect(TimeEntryMoveProposal::find($draw['proposal_id'])));
        $this->assertSame('Approving credits 0.75h back to Synthetic Block A and debits 0.75h from Synthetic Project B. Nothing has moved yet.',
            $moves->approvalEffect(TimeEntryMoveProposal::find($pair['proposal_id'])));
        $this->assertSame('Approving credits 0.75h back to Synthetic Block A; Synthetic Managed M is not an hours-prepay contract, so nothing is debited. Nothing has moved yet.',
            $moves->approvalEffect(TimeEntryMoveProposal::find($noDebit['proposal_id'])));

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $html = view('cockpit.partials.time-entry-moves')->render();
        $this->assertStringContainsString('will draw 0.50h from Synthetic Project B. '.$advice, $html);
        $this->assertStringContainsString('Synthetic Managed M is not an hours-prepay contract, so nothing is debited.', $html);
        $this->assertStringNotContainsString('debits them from the new one', $html);
    }

    /** contract-s3:12: a new contract gone since staging leaves the proposal stale, not pending behind a 500. */
    public function test_approval_with_a_deleted_target_goes_stale(): void
    {
        $held = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']), 'move_time_entry_contract', $this->args()));
        \Illuminate\Support\Facades\DB::table('contracts')->where('id', $this->b->id)->delete();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('time-entry-moves.approve', $held['proposal_id']))
            ->assertRedirect()->assertSessionHas('error', 'The entry or the new contract no longer exists; nothing moved.');
        $this->assertSame('stale', TimeEntryMoveProposal::find($held['proposal_id'])->state);
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
    }

    /**
     * diff:16: the immediate result and the tool description claim a debit on the new contract
     * only when one is written there, on approvalEffect()'s branches: ledgered to prepay,
     * ledgered to non-prepay, unledgered to non-prepay (nothing drawn).
     */
    public function test_immediate_result_and_description_claim_a_debit_only_onto_hours_prepay(): void
    {
        foreach ([false, true] as $internal) {
            $description = \App\Support\McpToolRegistry::moveTimeEntryContractTool($internal)['description'];
            $this->assertStringNotContainsString('and debited from the new contract.', $description);
            $this->assertStringContainsString('and, only when the new contract is hours-prepay, debited from it; a new contract that is not hours-prepay is debited nothing', $description);
        }

        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract:immediate']);
        $managed = $this->contract($this->ticket->client, 'Synthetic Managed M', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $second = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);

        $toPrepay = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args()));
        $this->assertTrue($toPrepay['ledger']);
        $this->assertSame('Time entry moved. It credited 0.75h back to Synthetic Block A and debited 0.75h from Synthetic Project B.', $toPrepay['message']);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->sole()->contract_id, 'the stated debit exists');

        $toManaged = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['entry_id' => $second->id, 'contract_id' => $managed->id])));
        $this->assertTrue($toManaged['ledger']);
        $this->assertSame('Time entry moved. It credited 0.50h back to Synthetic Block A; Synthetic Managed M is not an hours-prepay contract, so nothing was debited.', $toManaged['message']);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $second->id)->count(), 'no debit was written');

        $this->ticket->update(['contract_id' => $managed->id]);
        $unledgered = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 15, 'noted_at' => now(),
        ]);
        $c = $this->contract($this->ticket->client, 'Synthetic Managed N', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $plain = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['entry_id' => $unledgered->id, 'contract_id' => $c->id])));
        $this->assertFalse($plain['ledger']);
        $this->assertSame('Time entry moved. It had no prepay ledger row and no prepay hours were drawn.', $plain['message']);
        $this->assertStringNotContainsString('debited', $plain['message']);
    }

    /** diff:15: held moves are approved or denied in the technician cockpit, not on the ticket page. */
    public function test_staged_move_names_the_technician_cockpit(): void
    {
        foreach ([false, true] as $internal) {
            $description = \App\Support\McpToolRegistry::moveTimeEntryContractTool($internal)['description'];
            $this->assertStringContainsString('Bare or :staged grants hold for staff approval in the technician cockpit;', $description);
            $this->assertStringNotContainsString('ticket page', $description);
        }
        $held = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']), 'move_time_entry_contract', $this->args()));
        $this->assertTrue($held['staged']);
        $this->assertStringContainsString('Move held for staff approval in the technician cockpit; no prepay hours moved.', $held['message']);
        $this->assertStringNotContainsString('ticket page', $held['message']);

        // The cockpit is where the approve control renders.
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->assertStringContainsString(route('time-entry-moves.approve', $held['proposal_id']), view('cockpit.partials.time-entry-moves')->render());

        foreach (['app/Models/TimeEntryMoveProposal.php', 'app/Support/McpToolRegistry.php', 'app/Services/TimeEntryContractMoveService.php',
            'database/migrations/2026_10_04_000002_create_time_entry_move_proposals_table.php'] as $file) {
            $this->assertStringNotContainsString('on the ticket page', file_get_contents(base_path($file)), $file.' names the cockpit');
        }
    }

    /**
     * c1:v2:1: the cockpit preview applies approval's ticket-client check. After the ticket moves
     * to another client, the card says approval refuses the move as stale, and approval does.
     */
    public function test_cockpit_preview_refuses_a_target_off_the_tickets_current_client(): void
    {
        $managed = $this->contract($this->ticket->client, 'Synthetic Managed M', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']);
        $pair = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args()));
        $this->ticket->update(['contract_id' => $managed->id]);
        $unledgered = TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);
        $draw = $this->toolResult($this->callTool($token, 'move_time_entry_contract', $this->args(['entry_id' => $unledgered->id])));
        $moves = app(\App\Services\TimeEntryContractMoveService::class);
        $this->assertStringStartsWith('Approving credits 0.75h back', $moves->approvalEffect(TimeEntryMoveProposal::find($pair['proposal_id'])), 'precondition');

        $other = Client::create(['name' => 'Synthetic Other']);
        \Illuminate\Support\Facades\DB::table('tickets')->where('id', $this->ticket->id)->update(['client_id' => $other->id, 'contract_id' => null]);

        $refusal = "Synthetic Project B is not a contract of the ticket's current client, so approving refuses the move as stale. Nothing has moved yet.";
        $this->assertSame($refusal, $moves->approvalEffect(TimeEntryMoveProposal::find($pair['proposal_id'])));
        $this->assertSame($refusal, $moves->approvalEffect(TimeEntryMoveProposal::find($draw['proposal_id'])));

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('time-entry-moves.approve', $pair['proposal_id']))->assertSessionHas('error');
        $this->assertSame('stale', TimeEntryMoveProposal::find($pair['proposal_id'])->state, 'approval refuses it as stale, as the card said');
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
    }

    /** contract-s1:8: moved A -> C -> A since staging is still a change, so approval refuses it as stale. */
    public function test_held_move_goes_stale_after_a_round_trip(): void
    {
        $c = $this->contract($this->ticket->client, 'Synthetic C');
        $held = $this->toolResult($this->callTool(McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract']), 'move_time_entry_contract', $this->args()));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $prepay = app(\App\Services\PrepayService::class);
        $prepay->moveEntryContract($this->note, $c, 'Staff moved it', $admin);
        $prepay->moveEntryContract($this->note->fresh(), $this->a, 'Staff moved it back', $admin);
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'), 'back on the staged contract');

        $this->actingAs($admin)->post(route('time-entry-moves.approve', $held['proposal_id']))
            ->assertSessionHas('error', 'The entry changed since this move was staged; nothing moved. Re-stage it if it is still wanted.');
        $this->assertSame('stale', TimeEntryMoveProposal::find($held['proposal_id'])->state);
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $this->note->id)->value('contract_id'));
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);
    }
}
