<?php

namespace Tests\Feature\Prepay;

use App\Enums\PersonType;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayExpirationService;
use App\Services\PrepayService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 2: PrepayService::moveEntryContract, SPEC §9 M1-M4 and the
 * ledger-shape properties (a)-(f) of LEDGER-SHAPE.md. The ledger is
 * append-only (ruling Q4); time never crosses clients (ruling Q5, #4924).
 * Synthetic data only (G-13).
 */
class MoveEntryContractTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Client $client;

    private Contract $a;

    private Contract $b;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 200)]);
        Http::preventStrayRequests();
        $this->client = Client::create(['name' => 'Synthetic Client M']);
        $this->a = $this->contract('Synthetic Block A');
        $this->b = $this->contract('Synthetic Block B');
        $this->ticket = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $this->a->id, 'subject' => 'Synthetic printer work',
        ]);
        $this->user = User::factory()->create();
    }

    private function contract(string $name, array $overrides = [], ?int $clientId = null): Contract
    {
        $contract = Contract::create(array_merge([
            'client_id' => $clientId ?? $this->client->id, 'name' => $name,
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ], $overrides));
        if ($contract->prepay_total !== null) {
            // The ledger backs the columns, so recalculateBalance is a real check.
            PrepayTransaction::create([
                'contract_id' => $contract->id, 'source' => PrepayTransactionSource::ManualCredit,
                'date' => now()->subDay(), 'hours' => 10, 'description' => 'Synthetic opening hours',
            ]);
        }

        return $contract;
    }

    private function note(int $minutes = 45, array $extra = []): TicketNote
    {
        return TicketNote::forceCreate(array_merge([
            'body' => 'Synthetic note', 'ticket_id' => $this->ticket->id, 'author_id' => $this->user->id,
            'is_billable' => true, 'time_minutes' => $minutes, 'noted_at' => now(),
        ], $extra))->fresh();
    }

    private function phoneCall(int $seconds = 720): PhoneCall
    {
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => $seconds, 'started_at' => now(),
            'ticket_id' => $this->ticket->id,
        ]));
        app(PrepayService::class)->debitFromPhoneCall($call);

        return $call->fresh();
    }

    private function move(TicketNote|PhoneCall $entry, Contract $to, string $reason = 'Synthetic reclassification'): array
    {
        return app(PrepayService::class)->moveEntryContract($entry, $to, $reason, $this->user);
    }

    /** @return array<int, array<string, mixed>> every ledger row, by id, as stored */
    private function ledger(): array
    {
        return DB::table('prepay_transactions')->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->keyBy('id')->all();
    }

    private function columns(Contract $c): array
    {
        $f = $c->fresh();

        return array_map(fn ($v) => round((float) $v, 4), [
            $f->prepay_total, $f->prepay_used, $f->prepay_expired, $f->prepay_balance,
        ]);
    }

    private function assertRecalcMatches(Contract ...$contracts): void
    {
        foreach ($contracts as $c) {
            $before = $this->columns($c);
            app(PrepayService::class)->recalculateBalance($c->fresh());
            $this->assertSame($before, $this->columns($c), "recalculateBalance on {$c->name} equals the denormalised columns");
        }
    }

    /** M1 + (b): one note moved: visible credit on A, debit on B, audits on both, a system note. */
    public function test_m1_b_moving_a_note_writes_a_visible_credit_and_debit_pair(): void
    {
        $note = $this->note(45);
        $orig = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($this->a->id, $orig->contract_id);
        $this->assertEquals(9.25, $this->a->fresh()->prepay_balance);

        $result = $this->move($note, $this->b, 'Part of the office move');

        $this->assertSame(['note', $note->id, $this->a->id, $this->b->id, 0.75, true], [
            $result['entry_type'], $result['entry_id'], $result['from_contract_id'], $result['to_contract_id'], $result['hours'], $result['ledger'],
        ]);
        $onA = PrepayTransaction::where('contract_id', $this->a->id)->where('source', '!=', PrepayTransactionSource::ManualCredit)->orderBy('id')->get();
        $this->assertCount(2, $onA, 'A keeps its debit and gains the credit');
        $this->assertEquals(-0.75, (float) $onA[0]->hours);
        $this->assertSame($orig->id, $onA[0]->id);
        $this->assertSame(PrepayTransactionSource::EntryMovedOut, $onA[1]->source);
        $this->assertEquals(0.75, (float) $onA[1]->hours);
        $this->assertSame($note->id, $onA[1]->moved_ticket_note_id);
        $this->assertStringStartsWith('Moved to Synthetic Block B: Ticket #'.$this->ticket->id, $onA[1]->description);
        $this->assertSame('Part of the office move', $onA[1]->note);

        $onB = PrepayTransaction::where('contract_id', $this->b->id)->where('source', '!=', PrepayTransactionSource::ManualCredit)->sole();
        $this->assertEquals(-0.75, (float) $onB->hours);
        $this->assertSame($note->id, $onB->ticket_note_id, 'the new debit is the entry\'s linked row');
        $this->assertSame(PrepayTransactionSource::TicketTime, $onB->source);

        $this->assertEquals(10, $this->a->fresh()->prepay_balance);
        $this->assertEquals(0, $this->a->fresh()->prepay_used);
        $this->assertEquals(9.25, $this->b->fresh()->prepay_balance);
        $this->assertEquals(0.75, $this->b->fresh()->prepay_used);
        $this->assertSame($this->b->id, $note->fresh()->contract_id, 'stamp moved');

        $this->assertSame(['entry_moved_out'], ContractActivity::where('contract_id', $this->a->id)->where('action', 'like', 'entry_moved%')->pluck('action')->all());
        $this->assertSame(['entry_moved_in'], ContractActivity::where('contract_id', $this->b->id)->where('action', 'like', 'entry_moved%')->pluck('action')->all());
        $act = ContractActivity::where('action', 'entry_moved_in')->sole();
        $this->assertSame(['note', $note->id, $this->ticket->id, 'Part of the office move', $this->a->id, $this->b->id],
            [$act->changes['entry_type'], $act->changes['entry_id'], $act->changes['ticket_id'], $act->changes['reason'], $act->changes['from_contract_id'], $act->changes['to_contract_id']]);
        $system = TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->sole();
        $this->assertSame("Moved note #{$note->id} time (0.75 h) from Synthetic Block A to Synthetic Block B; credited 0.75h back to Synthetic Block A and debited 0.75h from Synthetic Block B: Part of the office move", $system->body);
        $this->assertStringContainsString('0.75 h', $system->body);
        $this->assertTrue((bool) $system->is_private);
        $this->assertRecalcMatches($this->a, $this->b);
    }

    /** (a): no ledger row is deleted or re-created; the original row is byte-identical except its link. */
    public function test_a_no_ledger_row_is_deleted_or_recreated(): void
    {
        $note = $this->note(45);
        $call = $this->phoneCall(720);
        $noteRowId = PrepayTransaction::where('ticket_note_id', $note->id)->value('id');
        $before = $this->ledger();
        $deleted = 0;
        PrepayTransaction::deleting(function () use (&$deleted) {
            $deleted++;
        });

        $this->move($note, $this->b);
        $this->move($call, $this->b);
        $this->move($note->fresh(), $this->a, 'Moved back');

        $after = $this->ledger();
        $this->assertSame(0, $deleted, 'no Eloquent delete');
        $this->assertSame([], array_diff(array_keys($before), array_keys($after)), 'every earlier row id still exists');
        $this->assertCount(count($before) + 2 + 2 + 2, $after, 'each ledgered move appends exactly a credit and a debit');
        foreach ($before as $id => $row) {
            foreach (['contract_id', 'hours', 'date', 'description', 'source', 'user_id', 'created_at'] as $col) {
                $this->assertSame($row[$col], $after[$id][$col], "row {$id} {$col} unchanged");
            }
        }
        $this->assertSame($note->id, $after[$noteRowId]['moved_ticket_note_id']);
        $this->assertNull($after[$noteRowId]['ticket_note_id']);
        $this->assertRecalcMatches($this->a, $this->b);
        PrepayTransaction::flushEventListeners();
    }

    /** (c): recalculateBalance equals the columns after moves of notes and calls, there and back. */
    public function test_c_recalculate_matches_denormalised_columns(): void
    {
        $n1 = $this->note(45);
        $n2 = $this->note(90);
        $call = $this->phoneCall(720);
        $this->move($n1, $this->b);
        $this->assertRecalcMatches($this->a, $this->b);
        $this->move($call, $this->b);
        $this->assertRecalcMatches($this->a, $this->b);
        $this->move($n1->fresh(), $this->a, 'Back again');
        $this->assertRecalcMatches($this->a, $this->b);
        // A: n1 0.75 (moved back) + n2 1.5 = 2.25 used; B: the call's 0.2.
        $this->assertSame([10.0, 2.25, 0.0, 7.75], $this->columns($this->a));
        $this->assertSame([10.0, 0.2, 0.0, 9.8], $this->columns($this->b));
        $this->assertSame($this->a->id, $n2->fresh()->contract_id, 'an unticked entry stays');
    }

    /** Rule (b): consumption of a lot expired at the move stays consumed. */
    public function test_c_expiry_replay_preserves_expired_consumption(): void
    {
        $note = $this->note(60);
        $this->move($note, $this->b);
        $lot = PrepayTransaction::create([
            'contract_id' => $this->a->id, 'source' => PrepayTransactionSource::ManualCredit,
            'date' => now()->subMonths(13), 'hours' => 2, 'expiry_date' => now()->subMonth(),
        ]);
        // The work was logged a year ago (inside the lot's life) and moved today, as in real use.
        PrepayTransaction::whereNotNull('moved_ticket_note_id')->where('hours', '<', 0)->update(['date' => now()->subMonths(12)]);
        $expirations = app(PrepayExpirationService::class)->computeExpirations($this->a->fresh(), now());
        $this->assertSame([[$lot->id, 1.0]], array_map(fn ($e) => [$e['lot_id'], $e['hours']], $expirations));
    }

    private function expiredMoveFixture(bool $mixed = false): array
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-06-15 12:00:00'));
        $this->a->prepayTransactions()->delete();
        $expired = PrepayTransaction::create([
            'contract_id' => $this->a->id, 'source' => PrepayTransactionSource::ManualCredit,
            'date' => '2026-01-01', 'hours' => 2, 'expiry_date' => '2026-05-01',
        ]);
        $live = $mixed ? PrepayTransaction::create([
            'contract_id' => $this->a->id, 'source' => PrepayTransactionSource::ManualCredit,
            'date' => '2026-02-01', 'hours' => 3, 'expiry_date' => '2026-07-01',
        ]) : null;
        $note = $this->note($mixed ? 180 : 60, ['noted_at' => '2026-03-01']);
        $expiry = app(PrepayExpirationService::class);
        $before = $expiry->expireContract($this->a->fresh());
        $this->assertSame($mixed ? 0.0 : 1.0, $before['forfeited_hours']);
        $this->move($note, $this->b);

        return [$expired, $live];
    }

    public function test_no_second_forfeiture_after_move_off_expired_lot(): void
    {
        $this->expiredMoveFixture();
        $result = app(PrepayExpirationService::class)->expireContract($this->a->fresh());
        $this->assertSame(1.0, $result['forfeited_hours']);
        $this->assertSame([0, 0, 0], [$result['created'], $result['updated'], $result['deleted']]);
        $this->assertSame([2.0, 0.0, 1.0, 1.0], $this->columns($this->a));
    }

    public function test_move_spanning_expired_and_live_lots_frees_only_live_consumption(): void
    {
        [$expired, $live] = $this->expiredMoveFixture(true);
        $expiry = app(PrepayExpirationService::class);
        $this->assertSame([], $expiry->computeExpirations($this->a->fresh(), now()));
        $later = $expiry->computeExpirations($this->a->fresh(), now()->addMonth());
        $this->assertSame([[$live->id, 3.0]], array_map(fn ($e) => [$e['lot_id'], $e['hours']], $later));
    }

    public function test_expired_move_reconciliation_is_idempotent(): void
    {
        $this->expiredMoveFixture();
        $expiry = app(PrepayExpirationService::class);
        $first = $expiry->expireContract($this->a->fresh());
        $ledger = $this->ledger();
        $columns = $this->columns($this->a);
        $second = $expiry->expireContract($this->a->fresh());
        $this->assertSame(1.0, $second['forfeited_hours']);
        $this->assertSame($first, $second);
        $this->assertSame($ledger, $this->ledger());
        $this->assertSame($columns, $this->columns($this->a));
    }

    public function test_round_trip_move_does_not_reduce_the_forfeiture(): void
    {
        $this->expiredMoveFixture();
        $note = TicketNote::findOrFail(PrepayTransaction::whereNotNull('moved_ticket_note_id')->value('moved_ticket_note_id'));
        $expiry = app(PrepayExpirationService::class);

        $this->travelTo(\Carbon\Carbon::parse('2026-06-20 12:00:00'));
        $this->move($note->fresh(), $this->a, 'Moved back');
        $back = $expiry->expireContract($this->a->fresh());
        $this->assertSame(1.0, $back['forfeited_hours']);
        $this->assertSame([0, 0, 0], [$back['created'], $back['updated'], $back['deleted']]);
        $this->assertSame([2.0, 1.0, 1.0, 0.0], $this->columns($this->a));

        $this->travelTo(\Carbon\Carbon::parse('2026-06-25 12:00:00'));
        $this->move($note->fresh(), $this->b, 'Moved out again');
        $again = $expiry->expireContract($this->a->fresh());
        $this->assertSame(1.0, $again['forfeited_hours']);
        $this->assertSame([0, 0, 0], [$again['created'], $again['updated'], $again['deleted']]);
        $this->assertSame([2.0, 0.0, 1.0, 1.0], $this->columns($this->a));
    }

    /** (d): later edit, unbill, rebill, re-debit and delete act on the new contract only. */
    public function test_d_later_changes_act_on_the_new_contract_only(): void
    {
        $note = $this->note(45);
        $call = $this->phoneCall(720);
        $this->move($note, $this->b);
        $this->move($call, $this->b);
        $oldPair = fn () => collect($this->ledger())->filter(fn ($r) => (int) $r['contract_id'] === $this->a->id)->all();
        $frozen = $oldPair();
        $this->assertCount(5, $frozen, 'opening credit + two debit/credit pairs');
        $aCols = $this->columns($this->a);

        $note->fresh()->update(['time_minutes' => 90]);
        $this->assertEquals(-1.5, (float) PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertEquals(10 - 1.5 - 0.2, (float) $this->b->fresh()->prepay_balance);

        $note->fresh()->update(['is_billable' => false]);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count(), 'unbill reverses only B\'s row');
        $this->assertEquals(9.8, (float) $this->b->fresh()->prepay_balance);
        $note->fresh()->update(['is_billable' => true]);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->contract_id, 're-debit hits the stamp, B');

        app(PrepayService::class)->debitFromPhoneCall($call->fresh());
        app(PrepayService::class)->debitFromPhoneCall($call->fresh());
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count(), 'redelivery never double-counts');

        $note->fresh()->delete();
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertEquals(9.8, (float) $this->b->fresh()->prepay_balance);

        $this->assertSame($frozen, $oldPair(), 'the old contract\'s pair never changes');
        $this->assertSame($aCols, $this->columns($this->a));
        $this->assertRecalcMatches($this->a, $this->b);
    }

    /** (e): the unique index still holds, and the migration is additive and reversible. */
    public function test_e_unique_index_holds_and_migration_reverses(): void
    {
        $note = $this->note(45);
        $this->move($note, $this->b);
        try {
            PrepayTransaction::create(['contract_id' => $this->a->id, 'source' => PrepayTransactionSource::TicketTime, 'ticket_note_id' => $note->id, 'hours' => -1]);
            $this->fail('a second linked row for the entry must violate the unique index');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        $migration = require database_path('migrations/2026_10_04_000001_add_moved_entry_ids_to_prepay_transactions.php');
        DB::table('prepay_transactions')->update(['moved_ticket_note_id' => null]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('prepay_transactions', 'moved_ticket_note_id'));
        $this->assertFalse(Schema::hasColumn('prepay_transactions', 'moved_phone_call_id'));
        $this->assertTrue(Schema::hasColumn('prepay_transactions', 'ticket_note_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('prepay_transactions', 'moved_ticket_note_id'));
    }

    /** (f): the old contract's portal ledger shows the pair in that client's own words. */
    public function test_f_portal_ledger_stays_coherent_and_single_client(): void
    {
        Setting::setValue('portal_enabled', '1');
        $note = $this->note(45);
        $this->move($note, $this->b, 'Internal reason text');
        $person = Person::create([
            'client_id' => $this->client->id, 'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User', 'email' => 'portal-m@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true,
        ]);
        $page = $this->actingAs($person, 'portal')->get(route('portal.contracts.show', $this->a));
        $page->assertOk();
        $page->assertSee('Ticket #'.$this->ticket->id.': Synthetic printer work', false);
        $page->assertSee('Moved to Synthetic Block B: Ticket #'.$this->ticket->id, false);
        $page->assertSee('-0.75');
        $page->assertSee('+0.75');
        $page->assertDontSee('Internal reason text');
    }

    /** M2 + Q5: inactive, another client's, same contract, pending staged action: refused, nothing written. */
    public function test_m2_refusals_write_nothing(): void
    {
        $note = $this->note(45);
        $call = $this->phoneCall(720);
        $otherClient = Client::create(['name' => 'Synthetic Client N']);
        $foreign = $this->contract('Synthetic Foreign', [], $otherClient->id);
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        $cancelled = $this->contract('Synthetic Cancelled', ['status' => 'cancelled']);
        $deleted = $this->contract('Synthetic Deleted');
        $deleted->delete();
        PhoneCallActionProposal::create(['phone_call_id' => $call->id, 'action_type' => 'set_call_billable', 'payload' => [], 'content_hash' => str_repeat('a', 64), 'state' => 'pending', 'drafted_by' => 'synthetic']);

        $cases = [
            [$note, $foreign, "not an active contract of this ticket's client"],
            [$note, $expired, 'not an active contract'],
            [$note, $cancelled, 'not an active contract'],
            [$note, $deleted, 'not an active contract'],
            [$note, $this->a, 'already on Synthetic Block A'],
            [$call, $this->b, 'awaiting approval'],
        ];
        $ledger = $this->ledger();
        $cols = [$this->columns($this->a), $this->columns($this->b)];
        foreach ($cases as [$entry, $to, $message]) {
            try {
                $this->move($entry, $to);
                $this->fail("move to {$to->name} must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        try {
            $this->move($note, $this->b, '   ');
            $this->fail('blank reason must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }
        $this->assertSame($ledger, $this->ledger());
        $this->assertSame($cols, [$this->columns($this->a), $this->columns($this->b)]);
        $this->assertSame(0, ContractActivity::where('action', 'like', 'entry_moved%')->count());
        $this->assertSame($this->a->id, $note->fresh()->contract_id);
        $this->assertSame(0, TicketNote::where('note_type', 'system')->count());
    }

    /** Q5: a ticket moved to another client keeps its time there; moving it across clients is refused. */
    public function test_q5_entry_on_another_clients_contract_cannot_be_moved_across(): void
    {
        $note = $this->note(45);
        $otherClient = Client::create(['name' => 'Synthetic Client N']);
        $foreign = $this->contract('Synthetic Foreign', [], $otherClient->id);
        $this->ticket->update(['client_id' => $otherClient->id, 'contract_id' => null]);
        $ledger = $this->ledger();
        try {
            $this->move($note->fresh(), $foreign);
            $this->fail('cross-client move must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("That note's ledger source contract is missing or belongs to another client; nothing moved.", $e->getMessage());
        }
        $this->assertSame($ledger, $this->ledger());
    }

    /** M3: an entry with no ledger row moves its stamp only (non-prepay origin and non-billable). */
    public function test_m3_unledgered_entry_moves_stamp_only(): void
    {
        $managed = $this->contract('Synthetic Managed', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $this->ticket->update(['contract_id' => $managed->id]);
        $unbilled = $this->note(30, ['is_billable' => false]);
        $this->assertSame($managed->id, $unbilled->contract_id);
        $ledger = $this->ledger();

        $result = $this->move($unbilled, $this->b);
        $this->assertFalse($result['ledger']);
        $this->assertSame(0.0, $result['hours']);
        $this->assertSame($this->b->id, $unbilled->fresh()->contract_id);
        $this->assertSame($ledger, $this->ledger(), 'no ledger write');
        $this->assertEquals(10, (float) $this->b->fresh()->prepay_balance);
        $this->assertSame(1, ContractActivity::where('contract_id', $managed->id)->where('action', 'entry_moved_out')->count());
        $this->assertSame(1, ContractActivity::where('contract_id', $this->b->id)->where('action', 'entry_moved_in')->count());

        // A billable entry stamped on a non-prepay contract: the move writes no pair; the
        // ordinary debit path then debits B (the entry's new stamp) once.
        $billable = $this->note(30);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $billable->id)->count());
        $this->move($billable, $this->b);
        $this->assertSame($this->b->id, PrepayTransaction::where('ticket_note_id', $billable->id)->sole()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('source', PrepayTransactionSource::EntryMovedOut)->count());
        $this->assertEquals(9.5, (float) $this->b->fresh()->prepay_balance);
        $this->assertRecalcMatches($this->b);
    }

    /** diff:3: an unledgered call moved with no draw reports its duration in the system note, as a note reports its minutes. */
    public function test_unledgered_call_move_without_a_draw_reports_its_time(): void
    {
        $m = $this->contract('Synthetic Managed M', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $n = $this->contract('Synthetic Managed N', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $this->ticket->update(['contract_id' => $m->id]);
        $call = $this->phoneCall(720);
        $this->assertSame($m->id, $call->contract_id, 'precondition: stamped on the non-prepay contract');
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count(), 'precondition: no ledger row');

        $result = $this->move($call, $n);

        $this->assertFalse($result['ledger']);
        $this->assertSame(0.0, $result['drawn_hours']);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count(), 'nothing drawn');
        $this->assertSame("Moved phone call #{$call->id} time (0.20 h) from Synthetic Managed M to Synthetic Managed N: Synthetic reclassification",
            TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->where('body', 'like', 'Moved %')->sole()->body);
    }

    /** Moving a ledgered entry to a non-prepay contract credits A and debits nothing. */
    public function test_move_to_non_prepay_contract_credits_old_and_writes_no_debit(): void
    {
        $managed = $this->contract('Synthetic Managed', ['prepay_total' => null, 'prepay_used' => null, 'prepay_balance' => null]);
        $note = $this->note(45);
        $this->move($note, $managed);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertSame(3, PrepayTransaction::where('contract_id', $this->a->id)->count(), 'opening credit, debit, moved-out credit');
        $this->assertEquals(10, (float) $this->a->fresh()->prepay_balance);
        $this->assertSame($managed->id, $note->fresh()->contract_id);
        $note->fresh()->update(['time_minutes' => 60]);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count(), 'later edits do not drift back to A');
        $this->assertRecalcMatches($this->a);
    }

    /** M4: entry -> ledger row -> contracts ascending by id, in both move directions. */
    public function test_m4_lock_order_entry_then_row_then_contracts_ascending(): void
    {
        $note = $this->note(45);
        $this->ticket->update(['contract_id' => $this->b->id]);
        $oppositeNote = $this->note(30);
        $driver = DB::getDriverName();
        $this->assertContains($driver, ['sqlite', 'mariadb']);
        if (getenv('DB_CONNECTION') === 'mariadb') {
            $this->assertSame('mariadb', $driver);
            $this->assertStringContainsString('10.11.', DB::selectOne('select version() as version')->version);
        }
        if ($driver === 'sqlite') {
            DB::connection()->setQueryGrammar(new class(DB::connection()) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
            {
                protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
                {
                    return $value ? '/* requested update lock */' : '';
                }
            });
        }
        $locks = [];
        DB::listen(function ($q) use (&$locks) {
            if (str_contains($q->sql, '/* requested update lock */') || str_ends_with($q->sql, 'for update')) {
                $locks[] = [$q->sql, $q->bindings];
            }
        });
        foreach ([[$note, $this->b], [$oppositeNote, $this->a]] as [$entry, $target]) {
            $locks = [];
            $this->move($entry->fresh(), $target);
            $this->assertGreaterThanOrEqual(3, count($locks));
            $sql = array_map(fn ($lock) => str_replace('`', '"', $lock[0]), $locks);
            $this->assertStringStartsWith('select * from "ticket_notes"', $sql[0]);
            $this->assertStringStartsWith('select * from "prepay_transactions"', $sql[1]);
            $this->assertStringStartsWith('select * from "contracts"', $sql[2]);
            $this->assertStringContainsString('order by "id" asc', $sql[2]);
            $this->assertSame([$this->a->id, $this->b->id], array_map('intval', $locks[2][1]), 'both contracts in one ascending locking read');
            $this->assertSame($target->id, $entry->fresh()->contract_id);
        }
        $this->assertRecalcMatches($this->a, $this->b);
    }
}
