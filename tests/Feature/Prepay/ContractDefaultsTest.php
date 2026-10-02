<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContractResolution;
use App\Services\ContractResolver;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 1: client default contract, the resolver, entry stamping
 * and the retired #4332 warning path. Synthetic data only (G-13).
 */
class ContractDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->ticket = Ticket::factory()->create();
        $this->client = $this->ticket->client;
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

    private function resolve(?int $picked = null): ContractResolution
    {
        return app(ContractResolver::class)->forEntry($this->ticket->fresh(), $picked);
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

    private function logs(): TestHandler
    {
        $handler = new TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);

        return $handler;
    }

    // ── R1: resolver order ──

    public function test_r1_resolver_order_picked_ticket_default_only_active(): void
    {
        $a = $this->contract('Synthetic A');
        $this->assertSame([ContractResolver::RULE_ONLY_ACTIVE, $a->id], [$this->resolve()->rule, $this->resolve()->contract->id]);

        $b = $this->contract('Synthetic B');
        $this->assertTrue($this->resolve()->isAmbiguous());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->resolve()->candidates->pluck('id')->all());

        $this->client->forceFill(['default_contract_id' => $b->id])->save();
        $this->assertSame([ContractResolver::RULE_CLIENT_DEFAULT, $b->id], [$this->resolve()->rule, $this->resolve()->contract->id]);

        $this->ticket->update(['contract_id' => $a->id]);
        $this->assertSame([ContractResolver::RULE_TICKET, $a->id], [$this->resolve()->rule, $this->resolve()->contract->id]);

        $c = $this->contract('Synthetic C');
        $this->assertSame([ContractResolver::RULE_PICKED, $c->id], [$this->resolve($c->id)->rule, $this->resolve($c->id)->contract->id]);
    }

    public function test_r1_none_without_contracts_and_invalid_pick_for_foreign_or_inactive(): void
    {
        $this->assertSame(ContractResolution::NONE, $this->resolve()->status);
        $own = $this->contract('Synthetic own');
        $foreign = $this->contract('Synthetic foreign', [], Client::factory()->create()->id);
        $expired = $this->contract('Synthetic expired', ['status' => 'expired']);
        $this->assertSame(ContractResolution::INVALID_PICK, $this->resolve($foreign->id)->status);
        $this->assertSame(ContractResolution::INVALID_PICK, $this->resolve($expired->id)->status);
        $this->assertSame($own->id, $this->resolve()->contract->id);
    }

    public function test_q3_expired_ticket_contract_falls_to_client_default(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $this->client->forceFill(['default_contract_id' => $b->id])->save();
        DB::table('contracts')->where('id', $a->id)->update(['status' => 'expired']);

        $r = $this->resolve();
        $this->assertSame([ContractResolver::RULE_CLIENT_DEFAULT, $b->id], [$r->rule, $r->contract->id]);

        // And it reaches money: a new note on that ticket debits the default, not the expired contract.
        $note = $this->note();
        $this->assertSame($b->id, $note->fresh()->contract_id);
        $this->assertSame($b->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
        $this->assertEquals(10, $a->fresh()->prepay_balance);
        $this->assertEquals(9, $b->fresh()->prepay_balance);
    }

    // ── R2: stale defaults are unset ──

    public function test_r2_inactive_or_foreign_default_written_by_query_builder_is_treated_as_unset(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $foreign = $this->contract('Synthetic foreign', [], Client::factory()->create()->id);

        DB::table('clients')->where('id', $this->client->id)->update(['default_contract_id' => $foreign->id]);
        $this->assertTrue($this->resolve()->isAmbiguous());

        DB::table('clients')->where('id', $this->client->id)->update(['default_contract_id' => $b->id]);
        DB::table('contracts')->where('id', $b->id)->update(['status' => 'cancelled']);
        $r = $this->resolve();
        $this->assertSame([ContractResolver::RULE_ONLY_ACTIVE, $a->id], [$r->rule, $r->contract->id]);
    }

    // ── R3: a default that stops being active is cleared and recorded ──

    public function test_r3_expiring_cancelling_or_deleting_default_clears_it_with_activity(): void
    {
        foreach (['expired' => fn (Contract $c) => $c->update(['status' => 'expired']),
            'cancelled' => fn (Contract $c) => $c->update(['status' => 'cancelled']),
            'deleted' => fn (Contract $c) => $c->delete()] as $reason => $act) {
            $default = $this->contract("Synthetic default {$reason}");
            $this->client->forceFill(['default_contract_id' => $default->id])->save();
            $act($default);
            $this->assertNull($this->client->fresh()->default_contract_id, $reason);
            $activity = ContractActivity::where('contract_id', $default->id)->where('action', 'client_default_cleared')->sole();
            $this->assertSame(['client_id' => $this->client->id, 'reason' => $reason], $activity->changes);
        }
    }

    public function test_r3_status_change_of_a_non_default_contract_leaves_default_alone(): void
    {
        $default = $this->contract('Synthetic default');
        $other = $this->contract('Synthetic other');
        $this->client->forceFill(['default_contract_id' => $default->id])->save();
        $other->update(['status' => 'expired']);
        $this->assertSame($default->id, $this->client->fresh()->default_contract_id);
        $this->assertSame(0, ContractActivity::where('action', 'client_default_cleared')->count());
    }

    // ── R4 / Q7: several contracts, no default -> unattended debits held ──

    public function test_r4_q7_webhook_call_debit_on_ambiguous_client_is_held_not_drawn(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id]);
        $logs = $this->logs();

        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));

        $this->assertSame(0, PrepayTransaction::count());
        $this->assertNull($call->fresh()->contract_id);
        $this->assertEquals(10, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Phone call debit held: needs contract'));
        $this->assertCount(1, $held);
        $this->assertSame(\Monolog\Level::Info, $held[0]->level);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $held[0]->context['candidate_contract_ids']);

        // Choosing a default releases the held time through the normal path.
        $this->client->forceFill(['default_contract_id' => $b->id])->save();
        $txn = app(PrepayService::class)->debitFromPhoneCall($call->fresh());
        $this->assertSame($b->id, $txn->contract_id);
        $this->assertSame($b->id, $call->fresh()->contract_id);
        $this->assertEquals(9, $b->fresh()->prepay_balance);
    }

    public function test_r4_q7_triage_note_debit_on_ambiguous_client_is_held_and_unstamped(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $logs = $this->logs();
        $note = $this->note();

        $this->assertNull($note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note debit held: needs contract'));
        $this->assertCount(1, $held);
        $this->assertSame(\Monolog\Level::Info, $held[0]->level);
    }

    public function test_hold_label_is_not_emitted_when_client_has_no_contract(): void
    {
        $logs = $this->logs();
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($this->phoneCall(['ticket_id' => $this->ticket->id])));
        $this->note();
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'needs contract'))));
    }

    // ── S1: notes are stamped at log time; a later ticket change does not move them ──

    public function test_s1_note_stamped_at_create_and_ticket_contract_change_does_not_move_it(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $first = $this->note(60);
        $this->assertSame($a->id, $first->fresh()->contract_id);

        $this->ticket->update(['contract_id' => $b->id]);
        $first->refresh();
        $first->time_minutes = 90;
        $first->save();
        $second = $this->note(30);

        // Earlier time stays on A, new time goes to B: one ticket, two contracts.
        $this->assertSame($a->id, $first->fresh()->contract_id);
        $this->assertSame($a->id, PrepayTransaction::where('ticket_note_id', $first->id)->value('contract_id'));
        $this->assertSame($b->id, $second->fresh()->contract_id);
        $this->assertSame($b->id, PrepayTransaction::where('ticket_note_id', $second->id)->value('contract_id'));
        $this->assertEquals(8.5, $a->fresh()->prepay_balance);
        $this->assertEquals(9.5, $b->fresh()->prepay_balance);
    }

    public function test_s1_non_billable_timed_note_is_stamped_at_log_time_and_keeps_it_when_made_billable(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $note = $this->note(60, ['is_billable' => false]);
        $this->assertSame($a->id, $note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::count());

        $this->ticket->update(['contract_id' => $b->id]);
        $note = $note->fresh();
        $note->is_billable = true;
        $note->save();

        $this->assertSame($a->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
        $this->assertEquals(9, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
    }

    public function test_s1_note_without_time_is_not_stamped(): void
    {
        $this->contract('Synthetic A');
        $this->assertNull($this->note(0)->fresh()->contract_id);
    }

    public function test_s1_legacy_unstamped_note_with_ledger_row_is_stamped_from_ledger_not_ticket(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $note = $this->note(60);
        DB::table('ticket_notes')->where('id', $note->id)->update(['contract_id' => null]);
        $this->ticket->update(['contract_id' => $b->id]);

        $note = $note->fresh();
        $note->time_minutes = 120;
        $note->save();

        $this->assertSame($a->id, $note->fresh()->contract_id);
        $this->assertEquals(8, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
    }

    // ── S2 / S3: calls are stamped at link; the later duration debit hits the stamp ──

    public function test_s2_call_stamped_at_link_and_later_debit_ignores_ticket_change(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $call = $this->phoneCall(['is_billable' => true, 'duration' => null]);

        app(PhoneCallService::class)->linkCallToTicket($call, $this->ticket->id);
        $this->assertSame($a->id, $call->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::count());

        $this->ticket->update(['contract_id' => $b->id]);
        $call = $call->fresh();
        $call->duration = 1800;
        $call->save();
        $txn = app(PrepayService::class)->debitFromPhoneCall($call);

        $this->assertSame($a->id, $txn->contract_id);
        $this->assertEquals(9.5, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
    }

    public function test_s3_unlink_clears_stamp_and_reverses_then_relink_stamps_again(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $call = $this->phoneCall();
        $service = app(PhoneCallService::class);
        $service->linkCallToTicket($call, $this->ticket->id);
        $this->assertEquals(9, $a->fresh()->prepay_balance);

        $service->unlinkCallFromTicket($call);
        $this->assertNull($call->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $a->fresh()->prepay_balance);

        $other = Ticket::factory()->create(['client_id' => $this->client->id, 'contract_id' => $b->id]);
        $call = $call->fresh();
        $call->is_billable = true;
        $service->linkCallToTicket($call, $other->id);
        $this->assertSame($b->id, $call->fresh()->contract_id);
        $this->assertSame($b->id, PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id'));
        $this->assertEquals(10, $a->fresh()->prepay_balance);
        $this->assertEquals(9, $b->fresh()->prepay_balance);
    }

    public function test_s2_resolve_for_phone_call_is_read_only(): void
    {
        $a = $this->contract('Synthetic A');
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id]);
        $this->assertSame($a->id, app(PrepayService::class)->resolveContractForPhoneCall($call)->id);
        $this->assertNull($call->fresh()->contract_id);
    }

    // ── S4: the web note form refuses ambiguous time and keeps the body ──

    public function test_s4_web_note_with_time_on_ambiguous_client_is_refused_and_body_kept(): void
    {
        $a = $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->post(route('tickets.notes.store', $this->ticket), [
            'body' => 'Synthetic ambiguous body', 'note_type' => 'note', 'time' => '1h', 'is_billable' => '1',
        ]);
        $resp->assertRedirect(route('tickets.show', $this->ticket));
        $resp->assertSessionHasErrors(['contract_id' => 'This client has several active contracts and no default. Choose the contract this time belongs to.']);
        $this->assertSame('Synthetic ambiguous body', session()->getOldInput('body'));
        $this->assertSame(0, TicketNote::where('ticket_id', $this->ticket->id)->count());

        $resp = $this->actingAs($user)->post(route('tickets.notes.store', $this->ticket), [
            'body' => 'Synthetic picked body', 'note_type' => 'note', 'time' => '1h', 'is_billable' => '1', 'contract_id' => $a->id,
        ]);
        $resp->assertSessionHasNoErrors();
        $note = TicketNote::where('ticket_id', $this->ticket->id)->sole();
        $this->assertSame($a->id, $note->contract_id);
        $this->assertEquals(9, $a->fresh()->prepay_balance);
    }

    public function test_s4_web_note_refuses_foreign_or_inactive_contract_and_note_without_time_needs_none(): void
    {
        $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $foreign = $this->contract('Synthetic foreign', [], Client::factory()->create()->id);
        $user = User::factory()->create();
        $resp = $this->actingAs($user)->post(route('tickets.notes.store', $this->ticket), [
            'body' => 'Synthetic', 'note_type' => 'note', 'time' => '1h', 'contract_id' => $foreign->id,
        ]);
        $resp->assertSessionHasErrors(['contract_id' => "Choose one of this client's active contracts."]);
        $this->assertSame(0, TicketNote::where('ticket_id', $this->ticket->id)->count());

        $this->actingAs($user)->post(route('tickets.notes.store', $this->ticket), [
            'body' => 'Synthetic no time', 'note_type' => 'note',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, TicketNote::where('ticket_id', $this->ticket->id)->count());
    }

    public function test_s4_web_note_edit_cannot_repoint_debited_time(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $user = User::factory()->create();
        $note = $this->note(60, ['author_id' => $user->id, 'note_type' => 'note']);

        $resp = $this->actingAs($user)->put(route('tickets.notes.update', [$this->ticket, $note]), [
            'body' => 'Synthetic edited', 'note_type' => 'note', 'time' => '2h', 'is_billable' => '1', 'contract_id' => $b->id,
        ]);
        $resp->assertSessionHasErrors(['contract_id' => 'This time is already debited from another contract, and editing the note cannot move it. Leave the contract unchanged.']);
        $this->assertSame($a->id, $note->fresh()->contract_id);
        $this->assertEquals(9, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);

        // Blank contract on edit keeps the ledger contract (the picker's "Automatic").
        $this->actingAs($user)->put(route('tickets.notes.update', [$this->ticket, $note]), [
            'body' => 'Synthetic edited', 'note_type' => 'note', 'time' => '2h', 'is_billable' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame($a->id, $note->fresh()->contract_id);
        $this->assertEquals(8, $a->fresh()->prepay_balance);
    }

    // ── S5: #4332 retired; an out-of-band stamp/ledger mismatch is refused ──

    public function test_s5_out_of_band_mismatch_refused_with_label_and_no_balance_change(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $note = $this->note(60);
        DB::table('ticket_notes')->where('id', $note->id)->update(['contract_id' => $b->id, 'time_minutes' => 180]);
        $logs = $this->logs();

        $this->assertNull(app(PrepayService::class)->debitFromTicketNote($note->fresh()));

        $this->assertEquals(-1, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertSame($a->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
        $this->assertEquals(9, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
        $records = array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note stamp differs from ledger'));
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Warning, $records[0]->level);
        $this->assertSame(['ticket_note_id' => $note->id, 'stamp_contract_id' => $b->id, 'ledger_contract_id' => $a->id], $records[0]->context);
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note contract mismatch')));
    }

    public function test_s5_web_edit_of_mismatched_note_keeps_stamp_and_moves_no_balance(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->ticket->update(['contract_id' => $a->id]);
        $user = User::factory()->create();
        $note = $this->note(60, ['author_id' => $user->id, 'note_type' => 'note']);
        DB::table('ticket_notes')->where('id', $note->id)->update(['contract_id' => $b->id, 'time_minutes' => 180]);
        $route = route('tickets.notes.update', [$this->ticket, $note]);
        $edit = fn (array $extra) => $this->actingAs($user)->put($route, array_merge([
            'body' => 'Synthetic typo fixed', 'note_type' => 'note', 'time' => '3h', 'is_billable' => '1',
        ], $extra));

        // The preselected stamp and "Automatic" both keep the mismatch; the debit path refuses it.
        foreach ([['contract_id' => $b->id], []] as $extra) {
            $edit($extra)->assertSessionHasNoErrors();
            $this->assertSame($b->id, $note->fresh()->contract_id);
            $this->assertEquals(-1, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
            $this->assertSame($a->id, PrepayTransaction::where('ticket_note_id', $note->id)->value('contract_id'));
            $this->assertEquals(9, $a->fresh()->prepay_balance);
            $this->assertEquals(10, $b->fresh()->prepay_balance);
        }

        // Picking the ledger contract is refused too: an edit does not re-stamp the note.
        $edit(['contract_id' => $a->id])->assertSessionHasErrors(['contract_id' => "This note's contract differs from the contract its time was debited from, and editing the note cannot change either. Leave the contract unchanged."]);
        $this->assertSame($b->id, $note->fresh()->contract_id);
        $this->assertEquals(-1, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertEquals(9, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
    }

    public function test_held_note_with_stale_stamp_shows_needs_contract_not_billed_to(): void
    {
        $user = User::factory()->create();
        $a = $this->contract('Synthetic A');
        $note = $this->note(60, ['note_type' => 'note', 'author_id' => $user->id, 'is_billable' => false]);
        $this->assertSame($a->id, $note->fresh()->contract_id);

        $this->contract('Synthetic B');
        $this->contract('Synthetic C');
        DB::table('contracts')->where('id', $a->id)->update(['status' => 'expired']);
        $note = $note->fresh();
        $note->is_billable = true;
        $note->save();

        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $this->actingAs($user)->get(route('tickets.show', $this->ticket))
            ->assertOk()
            ->assertSee('Not debited: the client had several active contracts and no default.')
            ->assertDontSee('Billed to Synthetic A');
    }

    // ── Client default setting (spec §1, ruling Q1) ──

    public function test_client_default_accepts_any_active_own_contract_and_refuses_others(): void
    {
        $user = User::factory()->create();
        $managed = $this->contract('Synthetic managed', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $prepay = $this->contract('Synthetic prepay');
        $expired = $this->contract('Synthetic expired', ['status' => 'expired']);
        $foreign = $this->contract('Synthetic foreign', [], Client::factory()->create()->id);
        $route = route('clients.default-contract.update', $this->client);

        // Q1: a non-prepay active contract may be the default.
        $this->actingAs($user)->patch($route, ['default_contract_id' => $managed->id])->assertSessionHasNoErrors();
        $this->assertSame($managed->id, $this->client->fresh()->default_contract_id);
        $this->assertSame('client_default_set', ContractActivity::where('contract_id', $managed->id)->sole()->action);

        $this->actingAs($user)->patch($route, ['default_contract_id' => $expired->id])
            ->assertSessionHasErrors(['default_contract_id' => 'Only an active contract can be the default.']);
        $this->actingAs($user)->patch($route, ['default_contract_id' => $foreign->id])
            ->assertSessionHasErrors(['default_contract_id' => 'That contract belongs to another client.']);
        $this->assertSame($managed->id, $this->client->fresh()->default_contract_id);

        $this->actingAs($user)->patch($route, ['default_contract_id' => $prepay->id])->assertSessionHasNoErrors();
        $this->assertSame($prepay->id, $this->client->fresh()->default_contract_id);
        $this->assertSame(['client_default_set', 'client_default_cleared'], ContractActivity::where('contract_id', $managed->id)->orderBy('id')->pluck('action')->all());

        $this->actingAs($user)->patch($route, ['default_contract_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->client->fresh()->default_contract_id);
    }

    public function test_client_and_ticket_pages_render_default_and_needs_contract_states(): void
    {
        $user = User::factory()->create();
        $a = $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $note = $this->note(60, ['note_type' => 'note', 'author_id' => $user->id]);
        $this->assertNull($note->fresh()->contract_id);

        $this->actingAs($user)->get(route('clients.show', $this->client))
            ->assertOk()->assertSee('No default contract, several active')->assertSee('Default contract');
        $this->actingAs($user)->get(route('tickets.show', $this->ticket))
            ->assertOk()->assertSee('Needs contract')->assertSee('Choose a contract');

        $this->client->forceFill(['default_contract_id' => $a->id])->save();
        $this->actingAs($user)->get(route('clients.show', $this->client))
            ->assertOk()->assertDontSee('No default contract, several active');
        $this->actingAs($user)->get(route('tickets.show', $this->ticket))
            ->assertOk()->assertSee('Automatic: Synthetic A');
    }

    // ── Migrations: additive, nullable, reversible ──

    public function test_migrated_columns_are_nullable_foreign_keys_to_contracts(): void
    {
        // Down/up reversibility is proven on MariaDB 10.11 (handback evidence): SQLite cannot
        // rebuild a referenced table inside the RefreshDatabase transaction.
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        foreach ([['clients', 'default_contract_id'], ['phone_calls', 'contract_id']] as [$table, $column]) {
            $col = collect($schema->getColumns($table))->firstWhere('name', $column);
            $this->assertNotNull($col, "{$table}.{$column} exists");
            $this->assertTrue($col['nullable'], "{$table}.{$column} nullable");
            $fk = collect($schema->getForeignKeys($table))->first(fn ($f) => $f['columns'] === [$column]);
            $this->assertSame('contracts', $fk['foreign_table']);
            $this->assertSame('set null', strtolower($fk['on_delete']));
        }
    }

    public function test_hard_deleting_a_default_contract_nulls_the_column(): void
    {
        $a = $this->contract('Synthetic A');
        $this->client->forceFill(['default_contract_id' => $a->id])->save();
        $call = $this->phoneCall(['ticket_id' => $this->ticket->id, 'contract_id' => $a->id]);
        DB::table('contracts')->where('id', $a->id)->delete();
        $this->assertNull($this->client->fresh()->default_contract_id);
        $this->assertNull($call->fresh()->contract_id);
    }
}
