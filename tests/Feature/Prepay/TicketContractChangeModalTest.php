<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 2, SPEC §9 M5 and U1 for the ticket contract-change modal
 * (mockup 3). Synthetic data only (G-13).
 */
class TicketContractChangeModalTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contract $a;

    private Contract $b;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic Client W']);
        $this->a = $this->contract($client, 'Synthetic Block A');
        $this->b = $this->contract($client, 'Synthetic Project B');
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $this->a->id]);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    private function contract(Client $client, string $name): Contract
    {
        return Contract::create([
            'client_id' => $client->id, 'name' => $name, 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
    }

    private function note(int $minutes): TicketNote
    {
        return TicketNote::forceCreate([
            'body' => 'Synthetic spooler reset', 'ticket_id' => $this->ticket->id, 'author_id' => $this->user->id,
            'is_billable' => true, 'time_minutes' => $minutes, 'noted_at' => now(),
        ]);
    }

    /** M5: "Change contract only" moves nothing. */
    public function test_m5_change_contract_only_moves_nothing(): void
    {
        $note = $this->note(45);
        $ledger = PrepayTransaction::orderBy('id')->get()->toArray();

        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), ['contract_id' => $this->b->id, 'change_only' => '1'])
            ->assertRedirect(route('tickets.show', $this->ticket));

        $this->assertSame($this->b->id, $this->ticket->fresh()->contract_id);
        $this->assertSame($ledger, PrepayTransaction::orderBy('id')->get()->toArray(), 'no ledger change');
        $this->assertSame($this->a->id, $note->fresh()->contract_id, 'the entry stays where it was logged');
        $this->assertEquals(9.25, (float) $this->a->fresh()->prepay_balance);
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);
        $this->assertStringContainsString('Time already logged stays',
            TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->sole()->body);
    }

    /** context:1: "Change contract only" draws no held "Needs contract" entry either. */
    public function test_change_contract_only_leaves_held_entries_undrawn(): void
    {
        $this->ticket->update(['contract_id' => null]);
        $held = $this->note(30);
        $this->assertNotNull($held->fresh()->contract_held_at, 'precondition: held (two active contracts, no default)');

        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), ['contract_id' => $this->b->id, 'change_only' => '1'])
            ->assertSessionHas('success', 'Ticket contract updated.');

        $this->assertSame($this->b->id, $this->ticket->fresh()->contract_id);
        $this->assertFalse(PrepayTransaction::where('ticket_note_id', $held->id)->exists(), 'nothing drawn');
        $this->assertNotNull($held->fresh()->contract_held_at, 'still held');
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);
    }

    /** contract-s1:10: Enter never submits through "Change contract only", which unticks every entry. */
    public function test_enter_in_the_reason_submits_the_move_not_change_only(): void
    {
        $js = file_get_contents(public_path('js/ticket-contract-change.js'));
        $this->assertStringContainsString("form.addEventListener('keydown', (e) => {", $js);
        $this->assertStringContainsString("if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;", $js);
        $this->assertStringContainsString('if (e.target === reason && !moveBtn.disabled) form.requestSubmit(moveBtn);', $js);
    }

    /** Ticked entries move through moveEntryContract; a tick without a reason is refused whole. */
    public function test_change_and_move_ticked_entries_needs_a_reason(): void
    {
        $n1 = $this->note(45);
        $n2 = $this->note(30);

        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), ['contract_id' => $this->b->id, 'move' => ['note:'.$n1->id]])
            ->assertSessionHasErrors('contract_id');
        $this->assertSame($this->a->id, $this->ticket->fresh()->contract_id, 'refused before any change');

        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), [
                'contract_id' => $this->b->id, 'move' => ['note:'.$n1->id], 'move_reason' => 'Part of the office move',
            ])->assertSessionHas('success', 'Ticket contract updated. Moved 1 time entry (0.75 h).');

        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $n1->id)->sole()->contract_id);
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $n2->id)->sole()->contract_id, 'unticked stays');
        $this->assertEquals(9.5, (float) $this->a->fresh()->prepay_balance);
        $this->assertEquals(9.25, (float) $this->b->fresh()->prepay_balance);
    }

    /**
     * diff:17: an unledgered move that draws reports the hours actually drawn (the ledger row
     * written) on the web flash, the system note and the ContractActivity rows, not 0.00 h,
     * for a note and a call; a ledgered move keeps its moved figure.
     */
    public function test_unledgered_move_reports_the_hours_drawn(): void
    {
        $managed = Contract::create([
            'client_id' => $this->ticket->client_id, 'name' => 'Synthetic Managed M', 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01',
        ]);
        $ledgered = $this->note(45);
        $this->ticket->update(['contract_id' => $managed->id]);
        $note = $this->note(30);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-draw', 'direction' => 'inbound', 'from_number' => '+15555550144',
            'status' => 'completed', 'is_billable' => true, 'duration' => 720, 'started_at' => now(), 'ticket_id' => $this->ticket->id,
        ]));
        app(\App\Services\PrepayService::class)->debitFromPhoneCall($call);
        $this->assertSame($managed->id, $call->fresh()->contract_id, 'precondition: the call is stamped on the non-prepay contract');
        $this->assertSame(0, PrepayTransaction::whereIn('ticket_note_id', [$note->id])->orWhere('phone_call_id', $call->id)->count(), 'precondition: no ledger rows');

        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), [
                'contract_id' => $this->b->id, 'move' => ['note:'.$note->id, 'call:'.$call->id], 'move_reason' => 'Synthetic reclassification',
            ])->assertSessionHas('success', 'Ticket contract updated. Moved 2 time entries (0.70 h).');

        $this->assertEquals(-0.5, (float) PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertEquals(-0.2, (float) PrepayTransaction::where('phone_call_id', $call->id)->sole()->hours);
        $this->assertEquals(9.3, (float) $this->b->fresh()->prepay_balance, 'the reported 0.70 h is what B lost');

        $system = TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->where('body', 'like', 'Moved %')->orderBy('id')->pluck('body')->all();
        $this->assertSame([
            "Moved note #{$note->id} time (0.50 h) from Synthetic Managed M to Synthetic Project B; it had no prepay ledger row, so 0.50h was drawn from Synthetic Project B: Synthetic reclassification",
            "Moved phone call #{$call->id} time (0.20 h) from Synthetic Managed M to Synthetic Project B; it had no prepay ledger row, so 0.20h was drawn from Synthetic Project B: Synthetic reclassification",
        ], $system);

        $activity = \App\Models\ContractActivity::where('action', 'like', 'entry_moved%')->orderBy('id')->get()
            ->map(fn ($a) => [$a->contract_id, $a->action, $a->changes['entry_type'], $a->changes['hours'], $a->changes['drawn_hours'] ?? null])->all();
        $this->assertEquals([
            [$managed->id, 'entry_moved_out', 'note', 0.5, 0.5], [$this->b->id, 'entry_moved_in', 'note', 0.5, 0.5],
            [$managed->id, 'entry_moved_out', 'call', 0.2, 0.2], [$this->b->id, 'entry_moved_in', 'call', 0.2, 0.2],
        ], $activity);

        // A ledgered move keeps its figure: the hours it moved, with no draw clause.
        $this->actingAs($this->user)
            ->patch(route('tickets.contract.update', $this->ticket), [
                'contract_id' => $this->b->id, 'move' => ['note:'.$ledgered->id], 'move_reason' => 'Synthetic reclassification',
            ])->assertSessionHas('success', 'Ticket contract updated. Moved 1 time entry (0.75 h).');
        $this->assertSame("Moved note #{$ledgered->id} time (0.75 h) from Synthetic Block A to Synthetic Project B: Synthetic reclassification",
            TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->where('body', 'like', 'Moved %')->orderByDesc('id')->value('body'));
    }

    /** An entry on another ticket cannot be smuggled into the move list. */
    public function test_entry_from_another_ticket_is_not_moved(): void
    {
        $other = Ticket::factory()->create(['client_id' => $this->ticket->client_id, 'contract_id' => $this->a->id]);
        $foreign = TicketNote::forceCreate(['body' => 'x', 'ticket_id' => $other->id, 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now()]);
        $this->actingAs($this->user)->patch(route('tickets.contract.update', $this->ticket), [
            'contract_id' => $this->b->id, 'move' => ['note:'.$foreign->id], 'move_reason' => 'Synthetic',
        ])->assertSessionHas('error');
        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $foreign->id)->sole()->contract_id);
    }

    /**
     * Jeeves 2026-10-02 21:38 PT: a row with NO ledger row states the prepay draw the move
     * makes. The effect text is built client-side, so the server-side facts it reads (data-ledger,
     * data-draw-hours) are asserted on the rendered row, and the statement itself in the JS.
     */
    public function test_unledgered_row_states_the_prepay_draw(): void
    {
        $managed = Contract::create([
            'client_id' => $this->ticket->client_id, 'name' => 'Synthetic Managed M', 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01',
        ]);
        $ledgered = $this->note(45);
        $this->ticket->update(['contract_id' => $managed->id]);
        $unledgered = $this->note(30);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $unledgered->id)->count(), 'precondition: no ledger row');

        $html = $this->actingAs($this->user)->get(route('tickets.show', $this->ticket))->assertOk()->getContent();
        $row = fn (TicketNote $n) => preg_match('/<tr class="js-cc-row[^"]*"([^>]*)>\s*<td>\s*<input[^>]*value="note:'.$n->id.'"/s', $html, $m) ? $m[1] : '';
        $this->assertStringContainsString('data-ledger="0"', $row($unledgered));
        $this->assertStringContainsString('data-draw-hours="0.5"', $row($unledgered));
        $this->assertStringContainsString('data-contract="'.$managed->id.'"', $row($unledgered));
        $this->assertStringContainsString('data-ledger="1"', $row($ledgered));
        $this->assertStringContainsString('data-draw-hours="0"', $row($ledgered), 'a ledgered row is the credit/debit pair, not a draw');
        $this->assertStringContainsString('"'.$this->b->id.'":{"name":"Synthetic Project B"', html_entity_decode($html), 'the target prepay contract name the JS names');

        $js = file_get_contents(public_path('js/ticket-contract-change.js'));
        $this->assertStringContainsString("'will draw ' + fmt(draw) + 'h from ' + prepay[toId].name", $js);
        $this->assertStringContainsString("'If this time was already invoiced by hand, untick billable instead of moving.'", $js);
        $this->assertStringContainsString("el('br'), el('span', 'text-muted', INVOICED_ADVICE)", $js, 'the advice is shown in the row, not only declared');
        $this->assertStringContainsString("r.dataset.ledger === '1'", $js);
        $this->assertMatchesRegularExpression('/if \(!ledgered\) \{\s*if \(prepay\[toId\] && draw > 0\) \{\s*effect\.append\(el\(\'span\', \'text-danger fw-semibold\', \'will draw \'/', $js,
            'the no-ledger-row branch is the one that states the draw');
        $this->assertStringContainsString('r.dataset.drawHours', $js);
    }

    /** U1 + M5: the modal renders the entries with their states; without entries the list is empty. */
    public function test_modal_renders_entries_and_locks(): void
    {
        $page = $this->actingAs($this->user)->get(route('tickets.show', $this->ticket))->assertOk();
        $page->assertSee('id="contractChangeModal"', false);
        $page->assertSee('data-entries="0"', false);
        $page->assertDontSee('name="move[]"', false);

        $note = $this->note(45);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-modal', 'direction' => 'inbound', 'from_number' => '+15555550143',
            'status' => 'completed', 'is_billable' => true, 'duration' => 720, 'started_at' => now(), 'ticket_id' => $this->ticket->id,
        ]));
        app(\App\Services\PrepayService::class)->debitFromPhoneCall($call);
        PhoneCallActionProposal::create(['phone_call_id' => $call->id, 'action_type' => 'set_call_billable', 'payload' => [],
            'content_hash' => str_repeat('b', 64), 'state' => 'pending', 'drafted_by' => 'synthetic']);

        $page = $this->actingAs($this->user)->get(route('tickets.show', $this->ticket))->assertOk();
        $page->assertSee('data-entries="2"', false);
        $page->assertSee('value="note:'.$note->id.'"', false);
        $page->assertSee('Locked: a staged billable action is awaiting approval');
        $page->assertSee('Change and move');
        $page->assertSee('Change contract only');
        $page->assertSee('stays on the contract it was logged against', false);
        // diff:5: the banner claims a debit only onto an hours-prepay contract.
        $page->assertDontSee('each one is credited back to', false);
        $page->assertSee('only when the new contract is hours-prepay, debited from it', false);
        $page->assertSee('id="ticketContractSelect"', false);
        $this->assertDoesNotMatchRegularExpression('/<select name="contract_id"[^>]*onchange/', $page->getContent(), 'the contract select no longer submits on change');
    }
}
