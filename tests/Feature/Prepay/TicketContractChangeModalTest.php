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
        $page->assertSee('id="ticketContractSelect"', false);
        $this->assertDoesNotMatchRegularExpression('/<select name="contract_id"[^>]*onchange/', $page->getContent(), 'the contract select no longer submits on change');
    }
}
