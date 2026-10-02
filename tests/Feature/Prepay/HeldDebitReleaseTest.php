<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContractService;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR #4868 r2 (Jeeves REVISE 2026-10-02): debits held as
 * "Needs contract" are released on EVERY transition that ends the client's
 * ambiguity, for notes and for calls, exactly once; a release that still
 * resolves AMBIGUOUS or NONE leaves the marker; held calls are visible; and
 * the r1 diff:1/4/7 behaviour is pinned. Synthetic data only (G-13).
 */
class HeldDebitReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Client $client;

    private Contract $a;

    private Contract $b;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->ticket = Ticket::factory()->create();
        $this->client = $this->ticket->client;
        $this->a = $this->contract('Synthetic A');
        $this->b = $this->contract('Synthetic B');
    }

    private function contract(string $name, array $overrides = [], ?int $clientId = null): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $clientId ?? $this->client->id, 'name' => $name,
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ], $overrides));
    }

    private function note(int $minutes = 60, array $extra = []): TicketNote
    {
        return TicketNote::forceCreate(array_merge([
            'body' => 'Synthetic note', 'ticket_id' => $this->ticket->id,
            'is_billable' => true, 'time_minutes' => $minutes, 'noted_at' => now(),
        ], $extra));
    }

    private function phoneCall(array $extra = []): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ], $extra)));
    }

    /** A held note and a held call on the ambiguous client (A and B active, no default). */
    private function heldPair(): array
    {
        $note = $this->note(60);
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id]);
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));

        $this->assertNotNull($note->fresh()->contract_held_at, 'note marked held');
        $this->assertNotNull($call->fresh()->contract_held_at, 'call marked held');
        $this->assertNull($note->fresh()->contract_id);
        $this->assertNull($call->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::count());

        return [$note, $call];
    }

    private function logs(): TestHandler
    {
        $handler = new TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);

        return $handler;
    }

    private function assertReleasedTo(Contract $to, TicketNote $note, PhoneCall $call, string $why): void
    {
        $this->assertSame($to->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'), "{$why}: note debited");
        $this->assertSame($to->id, PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id'), "{$why}: call debited");
        $this->assertSame(2, PrepayTransaction::count(), "{$why}: exactly one row each");
        $this->assertSame([$to->id, null], [$note->fresh()->contract_id, $note->fresh()->contract_held_at], "{$why}: note stamped, marker cleared");
        $this->assertSame([$to->id, null], [$call->fresh()->contract_id, $call->fresh()->contract_held_at], "{$why}: call stamped, marker cleared");
        $this->assertEquals(8, $to->fresh()->prepay_balance, "{$why}: 1h note + 1h call drawn once");
    }

    private function assertStillHeld(TicketNote $note, PhoneCall $call, string $why): void
    {
        $this->assertSame(0, PrepayTransaction::count(), "{$why}: nothing debited");
        $this->assertNotNull($note->fresh()->contract_held_at, "{$why}: note keeps its marker");
        $this->assertNotNull($call->fresh()->contract_held_at, "{$why}: call keeps its marker");
        foreach (Contract::withTrashed()->get() as $contract) {
            $this->assertEquals(10, $contract->prepay_balance, "{$why}: {$contract->name} untouched");
        }
    }

    // ── Release on every transition that ends the ambiguity (note AND call) ──

    public static function transitions(): array
    {
        return [
            'contract expired' => ['expired'],
            'contract cancelled' => ['cancelled'],
            'contract soft-deleted' => ['deleted'],
            'bulk status change (inside a transaction)' => ['bulk_expired'],
            'ticket contract set' => ['ticket_contract'],
            'default chosen' => ['default'],
        ];
    }

    #[DataProvider('transitions')]
    public function test_held_note_and_call_are_released_when_the_ambiguity_ends(string $transition): void
    {
        [$note, $call] = $this->heldPair();

        match ($transition) {
            'expired' => $this->b->update(['status' => 'expired']),
            'cancelled' => $this->b->update(['status' => 'cancelled']),
            'deleted' => $this->b->delete(),
            'bulk_expired' => app(ContractService::class)->bulkChangeStatus([$this->b->id], \App\Enums\ContractStatus::Expired, User::factory()->create()->id),
            'ticket_contract' => $this->ticket->update(['contract_id' => $this->a->id]),
            'default' => $this->actingAs(User::factory()->create())
                ->patch(route('clients.default-contract.update', $this->client), ['default_contract_id' => $this->a->id])
                ->assertSessionHasNoErrors()->assertSessionHas('success', 'Default contract updated. 2 held time entries debited.'),
        };

        $this->assertReleasedTo($this->a, $note, $call, $transition);
        $this->assertEquals(10, $this->b->fresh() ? $this->b->fresh()->prepay_balance : Contract::withTrashed()->find($this->b->id)->prepay_balance);
    }

    public function test_ticket_contract_set_releases_that_ticket_and_leaves_other_still_ambiguous_tickets_held(): void
    {
        [$note, $call] = $this->heldPair();
        $other = Ticket::factory()->create(['client_id' => $this->client->id]);
        $otherNote = $this->note(30, ['ticket_id' => $other->id]);
        $this->assertNotNull($otherNote->fresh()->contract_held_at);

        $this->ticket->update(['contract_id' => $this->a->id]);

        $this->assertSame($this->a->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
        $this->assertSame($this->a->id, PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id'));
        $this->assertNotNull($otherNote->fresh()->contract_held_at, "another ticket's held note stays held");
        $this->assertFalse(PrepayTransaction::where('ticket_note_id', $otherNote->id)->exists());
    }

    public function test_a_status_change_that_leaves_two_active_keeps_entries_held_and_the_next_releases(): void
    {
        // A third active contract: expiring one of three leaves two, still ambiguous.
        $c = $this->contract('Synthetic C');
        [$note, $call] = $this->heldPair();

        $c->update(['status' => 'expired']);
        $this->assertStillHeld($note, $call, 'two of three still active');

        $this->b->update(['status' => 'cancelled']);
        $this->assertReleasedTo($this->a, $note, $call, 'one left after the second change');
    }

    // ── Transitions outside status/default/ticket-contract that also end the ambiguity ──

    public function test_ticket_moved_to_a_client_with_one_active_contract_releases_there(): void
    {
        [$note, $call] = $this->heldPair();
        $other = Client::factory()->create();
        $c = $this->contract('Synthetic C', [], $other->id);

        app(\App\Services\TicketService::class)->moveToClient($this->ticket->fresh(), $other->id, null, User::factory()->create()->id);

        $this->assertSame([$other->id, null], [$this->ticket->fresh()->client_id, $this->ticket->fresh()->contract_id]);
        $this->assertReleasedTo($c, $note, $call, 'ticket moved');
        $this->assertEquals(10, $this->a->fresh()->prepay_balance);
        $this->assertEquals(10, $this->b->fresh()->prepay_balance);
    }

    public function test_contract_moved_to_another_client_releases_the_client_it_left(): void
    {
        [$note, $call] = $this->heldPair();

        $this->b->update(['client_id' => Client::factory()->create()->id]);

        $this->assertReleasedTo($this->a, $note, $call, 'contract moved away');
        $this->assertEquals(10, $this->b->fresh()->prepay_balance);
    }

    public function test_force_deleting_a_contract_releases_held_entries(): void
    {
        [$note, $call] = $this->heldPair();

        $this->b->forceDelete();

        $this->assertNull(Contract::withTrashed()->find($this->b->id));
        $this->assertReleasedTo($this->a, $note, $call, 'contract force-deleted');
    }

    public function test_a_renewal_created_after_a_bulk_expiry_releases_entries_held_with_no_contract(): void
    {
        [$note, $call] = $this->heldPair();
        app(ContractService::class)->bulkChangeStatus([$this->a->id, $this->b->id], \App\Enums\ContractStatus::Expired, User::factory()->create()->id);
        $this->assertStillHeld($note, $call, 'both expired together');

        $renewal = $this->contract('Synthetic renewal');

        $this->assertReleasedTo($renewal, $note, $call, 'renewal created');
    }

    public function test_a_contract_restored_active_releases_entries_held_with_no_contract(): void
    {
        [$note, $call] = $this->heldPair();
        DB::table('contracts')->update(['deleted_at' => now()]);
        $this->assertSame(0, app(PrepayService::class)->releaseHeldDebits($this->client->id));
        $this->assertStillHeld($note, $call, 'every contract deleted out of band');

        Contract::withTrashed()->find($this->a->id)->restore();

        $this->assertReleasedTo($this->a, $note, $call, 'contract restored');
        $this->assertEquals(10, Contract::withTrashed()->find($this->b->id)->prepay_balance);
    }

    public function test_merging_into_a_client_whose_default_settles_it_releases_the_moved_held_entries(): void
    {
        [$note, $call] = $this->heldPair();
        $survivor = Client::factory()->create();
        $c = $this->contract('Synthetic survivor C', [], $survivor->id);
        $survivor->forceFill(['default_contract_id' => $c->id])->save();

        app(\App\Services\ClientService::class)->mergeClients($survivor, $this->client, User::factory()->create()->id);

        $this->assertSame($survivor->id, $this->ticket->fresh()->client_id);
        $this->assertReleasedTo($c, $note, $call, 'client merged');
        $this->assertEquals(10, $this->a->fresh()->prepay_balance);
        $this->assertEquals(10, $this->b->fresh()->prepay_balance);
    }

    // ── Still ambiguous (or no contract at all) stays held; the marker stays ──

    public function test_release_that_still_resolves_ambiguous_or_none_leaves_the_marker_and_counts_it(): void
    {
        [$note, $call] = $this->heldPair();
        $logs = $this->logs();

        $this->assertSame(0, app(PrepayService::class)->releaseHeldDebits($this->client->id));
        $this->assertStillHeld($note, $call, 'ambiguous');

        // NONE: every contract gone without the model hook; the release still debits nothing.
        DB::table('contracts')->update(['status' => 'expired']);
        $this->assertSame(0, app(PrepayService::class)->releaseHeldDebits($this->client->id));
        $this->assertStillHeld($note, $call, 'none');

        $runs = array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Held debits release run'));
        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(\Monolog\Level::Info, $run->level);
            $this->assertSame(['client_id' => $this->client->id, 'held_notes' => 1, 'held_calls' => 1, 'debited' => 0, 'still_held' => 2], $run->context);
        }
        // A still-ambiguous entry is skipped, not re-run through the hold path.
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held: needs contract'))));
    }

    // ── Idempotency: a repeated release never debits twice ──

    public function test_repeated_release_does_not_debit_twice(): void
    {
        [$note, $call] = $this->heldPair();
        $this->client->forceFill(['default_contract_id' => $this->a->id])->save();
        $service = app(PrepayService::class);

        $this->assertSame(2, $service->releaseHeldDebits($this->client->id));
        $this->assertSame(0, $service->releaseHeldDebits($this->client->id));
        $this->b->update(['status' => 'expired']);
        $this->assertReleasedTo($this->a, $note, $call, 'after three releases');
    }

    public function test_release_never_touches_an_entry_that_already_has_a_ledger_row(): void
    {
        [$note, $call] = $this->heldPair();
        $this->client->forceFill(['default_contract_id' => $this->a->id])->save();
        $service = app(PrepayService::class);
        $this->assertSame(2, $service->releaseHeldDebits($this->client->id));

        // A stale marker written out of band beside a ledger row, with the time changed out of band too.
        DB::table('ticket_notes')->where('id', $note->id)->update(['contract_held_at' => now(), 'time_minutes' => 180]);
        DB::table('phone_calls')->where('id', $call->id)->update(['contract_held_at' => now(), 'duration' => 10800]);

        $this->assertSame(0, $service->releaseHeldDebits($this->client->id));
        $this->assertSame(2, PrepayTransaction::count());
        $this->assertEquals(-1, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertEquals(-1, PrepayTransaction::where('phone_call_id', $call->id)->value('hours'));
        $this->assertEquals(8, $this->a->fresh()->prepay_balance);
    }

    public function test_release_never_debits_unmarked_history(): void
    {
        [$note, $call] = $this->heldPair();
        // Billable time written before stamping existed: no stamp, no marker, no ledger row.
        $legacyNote = TicketNote::withoutEvents(fn () => $this->note(120));
        $legacyCall = $this->phoneCall(['ticket_id' => $this->ticket->id, 'duration' => 7200]);

        $this->b->update(['status' => 'expired']);

        $this->assertReleasedTo($this->a, $note, $call, 'held entries');
        $this->assertFalse(PrepayTransaction::where('ticket_note_id', $legacyNote->id)->exists(), 'legacy note untouched');
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $legacyCall->id)->exists(), 'legacy call untouched');
        $this->assertNull($legacyNote->fresh()->contract_id);
        $this->assertNull($legacyCall->fresh()->contract_id);
    }

    // ── Marker set and clear ──

    public function test_marker_is_set_by_the_hold_and_cleared_by_stamp_unlink_and_relink(): void
    {
        [$note, $call] = $this->heldPair();
        $service = app(PhoneCallService::class);

        // Unlink clears the call's marker; relinking to a resolvable ticket stamps and debits it.
        $service->unlinkCallFromTicket($call->fresh());
        $this->assertSame([null, null], [$call->fresh()->contract_id, $call->fresh()->contract_held_at]);
        $resolved = Ticket::factory()->create(['client_id' => $this->client->id, 'contract_id' => $this->b->id]);
        $call = $call->fresh();
        $call->is_billable = true;
        $service->linkCallToTicket($call, $resolved->id);
        $this->assertSame([$this->b->id, null], [$call->fresh()->contract_id, $call->fresh()->contract_held_at]);
        $this->assertSame($this->b->id, PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id'));

        // Relinking a still-held call to another ambiguous ticket keeps it held (re-marked).
        $call2 = $this->phoneCall(['ticket_id' => $this->ticket->id]);
        app(PrepayService::class)->debitFromPhoneCall($call2);
        $this->assertNotNull($call2->fresh()->contract_held_at);
        $ambiguous = Ticket::factory()->create(['client_id' => $this->client->id]);
        $service->linkCallToTicket($call2->fresh(), $ambiguous->id);
        $this->assertNull($call2->fresh()->contract_id);
        $this->assertNotNull($call2->fresh()->contract_held_at);
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $call2->id)->exists());

        // A note edit that picks a contract stamps it and clears the marker.
        $note = $note->fresh();
        $note->contract_id = $this->b->id;
        $note->save();
        $this->assertSame([$this->b->id, null], [$note->fresh()->contract_id, $note->fresh()->contract_held_at]);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
    }

    public function test_note_without_billable_time_is_never_marked_held_and_a_non_billable_call_shows_no_badge(): void
    {
        $user = User::factory()->create();
        $this->note(60, ['is_billable' => false]);
        $this->note(0);
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id, 'is_billable' => false]);
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertSame(0, TicketNote::whereNotNull('contract_held_at')->count());
        // The call path marks before it reads billability; the badge only shows for billable held time.
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $this->actingAs($user)->get(route('tickets.show', $this->ticket))->assertOk()
            ->assertDontSee("Set a default contract or this ticket's contract.", false);
    }

    // ── Visibility: held calls show "Needs contract"; held notes show it ahead of a stale stamp ──

    public function test_held_call_shows_needs_contract_on_the_ticket_and_loses_it_when_released(): void
    {
        $user = User::factory()->create();
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id]);
        app(PrepayService::class)->debitFromPhoneCall($call);
        $badge = "Set a default contract or this ticket's contract.";

        $this->actingAs($user)->get(route('tickets.show', $this->ticket))->assertOk()->assertSee($badge, false);

        $this->ticket->update(['contract_id' => $this->a->id]);
        $this->assertNull($call->fresh()->contract_held_at);
        $this->actingAs($user)->get(route('tickets.show', $this->ticket))->assertOk()->assertDontSee($badge, false);
    }

    public function test_held_note_badge_says_needs_contract_ahead_of_a_stale_stamp(): void
    {
        $user = User::factory()->create();
        DB::table('contracts')->where('id', $this->b->id)->update(['status' => 'expired']);
        $note = $this->note(60, ['note_type' => 'note', 'author_id' => $user->id, 'is_billable' => false]);
        $this->assertSame($this->a->id, $note->fresh()->contract_id);

        // A then expires out of band and two new contracts arrive: the stamp is stale and the client ambiguous.
        $this->contract('Synthetic C');
        $this->contract('Synthetic D');
        DB::table('contracts')->where('id', $this->a->id)->update(['status' => 'expired']);
        $note = $note->fresh();
        $note->is_billable = true;
        $note->save();
        $this->assertSame($this->a->id, $note->fresh()->contract_id, 'the stale stamp is still on the row');
        $this->assertNotNull($note->fresh()->contract_held_at);

        $page = $this->actingAs($user)->get(route('tickets.show', $this->ticket))->assertOk();
        $page->assertSee('Not debited: the client had several active contracts and no default. Edit the note to choose its contract.');
        $page->assertDontSee('Billed to Synthetic A');
    }

    // ── r1 diff:7: an unchanged stamp on edit keeps the stamp and does not move the balance ──

    public function test_unchanged_stamp_edit_keeps_the_stamp_and_moves_no_balance(): void
    {
        $user = User::factory()->create();
        $this->ticket->update(['contract_id' => $this->a->id]);
        $note = $this->note(60, ['author_id' => $user->id, 'note_type' => 'note']);
        $this->assertEquals(9, $this->a->fresh()->prepay_balance);

        // Body-only edit re-submitting the preselected stamp: accepted, no balance move.
        $this->actingAs($user)->put(route('tickets.notes.update', [$this->ticket, $note]), [
            'body' => 'Synthetic typo fixed', 'note_type' => 'note', 'time' => '1h', 'is_billable' => '1', 'contract_id' => $this->a->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, $note->fresh()->contract_id);
        $this->assertSame('Synthetic typo fixed', $note->fresh()->body);
        $this->assertSame(1, PrepayTransaction::count());
        $this->assertEquals(-1, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertEquals(9, $this->a->fresh()->prepay_balance);
        $this->assertEquals(10, $this->b->fresh()->prepay_balance);
    }

    public function test_unchanged_inactive_stamp_on_an_unledgered_note_is_accepted_and_re_resolved(): void
    {
        $user = User::factory()->create();
        $this->ticket->update(['contract_id' => $this->a->id]);
        $note = $this->note(60, ['author_id' => $user->id, 'note_type' => 'note', 'is_billable' => false]);
        $this->assertSame($this->a->id, $note->fresh()->contract_id);
        DB::table('contracts')->where('id', $this->a->id)->update(['status' => 'expired']);
        $this->client->forceFill(['default_contract_id' => $this->b->id])->save();

        $this->actingAs($user)->put(route('tickets.notes.update', [$this->ticket, $note]), [
            'body' => 'Synthetic now billable', 'note_type' => 'note', 'time' => '1h', 'is_billable' => '1', 'contract_id' => $this->a->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->b->id, $note->fresh()->contract_id);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
        $this->assertEquals(10, $this->a->fresh()->prepay_balance);
        $this->assertEquals(9, $this->b->fresh()->prepay_balance);
    }

    // ── r1 diff:4: a stamp is re-validated at debit (expired, soft-deleted, another client's) ──

    public static function staleStamps(): array
    {
        return ['expired' => ['expired'], 'soft-deleted' => ['deleted'], 'another client' => ['foreign']];
    }

    #[DataProvider('staleStamps')]
    public function test_a_stale_note_stamp_is_re_resolved_at_debit_never_drawn(string $how): void
    {
        $this->client->forceFill(['default_contract_id' => $this->b->id])->save();
        $stale = $this->staleContract($how);
        $note = TicketNote::withoutEvents(fn () => $this->note(60, ['contract_id' => $stale->id]));

        $txn = app(PrepayService::class)->debitFromTicketNote($note->fresh());

        $this->assertSame($this->b->id, $txn->contract_id, "{$how}: debited to the default");
        $this->assertSame($this->b->id, $note->fresh()->contract_id, "{$how}: re-stamped");
        $this->assertEquals(10, Contract::withTrashed()->find($stale->id)->prepay_balance, "{$how}: stale stamp not drawn");
        $this->assertEquals(9, $this->b->fresh()->prepay_balance);
    }

    #[DataProvider('staleStamps')]
    public function test_a_stale_call_stamp_is_re_resolved_at_debit_never_drawn(string $how): void
    {
        $this->client->forceFill(['default_contract_id' => $this->b->id])->save();
        $stale = $this->staleContract($how);
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id, 'contract_id' => $stale->id]);

        $txn = app(PrepayService::class)->debitFromPhoneCall($call->fresh());

        $this->assertSame($this->b->id, $txn->contract_id, "{$how}: debited to the default");
        $this->assertSame($this->b->id, $call->fresh()->contract_id, "{$how}: re-stamped");
        $this->assertEquals(10, Contract::withTrashed()->find($stale->id)->prepay_balance, "{$how}: stale stamp not drawn");
        $this->assertEquals(9, $this->b->fresh()->prepay_balance);
    }

    #[DataProvider('staleStamps')]
    public function test_a_stale_stamp_on_an_ambiguous_client_is_held_not_drawn(string $how): void
    {
        $stale = $this->staleContract($how);
        $note = TicketNote::withoutEvents(fn () => $this->note(60, ['contract_id' => $stale->id]));
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id, 'contract_id' => $stale->id]);

        $this->assertNull(app(PrepayService::class)->debitFromTicketNote($note->fresh()));
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call->fresh()));

        $this->assertNotNull($note->fresh()->contract_held_at, "{$how}: note held");
        $this->assertNotNull($call->fresh()->contract_held_at, "{$how}: call held");
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, Contract::withTrashed()->find($stale->id)->prepay_balance);
    }

    private function staleContract(string $how): Contract
    {
        return match ($how) {
            'expired' => $this->contract('Synthetic stale', ['status' => 'expired']),
            'deleted' => tap($this->contract('Synthetic stale', ['status' => 'expired']), function (Contract $c) {
                DB::table('contracts')->where('id', $c->id)->update(['status' => 'active', 'deleted_at' => now()]);
            }),
            'foreign' => $this->contract('Synthetic stale', [], Client::factory()->create()->id),
        };
    }
}
