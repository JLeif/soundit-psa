<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\PrepayService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #5067 r3: ticket_notes.time_adjustment_minutes is billable time on top of time_minutes,
 * priced wherever a note's time is priced for prepay. Synthetic data only (G-13).
 */
class HaloTimeAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->ticket = Ticket::factory()->create();
        $this->contract = Contract::create([
            'client_id' => $this->ticket->client_id, 'name' => 'Synthetic prepay',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
    }

    /** A note written without observers, then given its adjustment through the query builder. */
    private function note(int $minutes, ?int $adjustment, array $extra = []): TicketNote
    {
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate($extra + [
            'body' => 'Synthetic note', 'ticket_id' => $this->ticket->id, 'is_billable' => true,
            'time_minutes' => $minutes, 'noted_at' => now(), 'contract_id' => $this->contract->id,
        ]));
        DB::table('ticket_notes')->where('id', $note->id)->update(['time_adjustment_minutes' => $adjustment]);

        return $note->fresh();
    }

    public function test_priced_minutes_is_time_plus_adjustment_with_null_as_zero(): void
    {
        $this->assertSame(43, $this->note(30, 13)->pricedMinutes());
        $this->assertSame(30, $this->note(30, null)->pricedMinutes());
        $this->assertSame(15, $this->note(0, 15)->pricedMinutes());
    }

    public function test_debit_prices_time_plus_adjustment(): void
    {
        $note = $this->note(30, 13);

        $txn = app(PrepayService::class)->debitFromTicketNote($note);

        $this->assertNotNull($txn);
        $this->assertSame(-0.7167, (float) $txn->hours);
        $this->assertEqualsWithDelta(10 - 0.7167, (float) $this->contract->fresh()->prepay_balance, 0.006); // the balance column holds 2 places
    }

    public function test_a_negative_adjustment_prices_the_prepay_part_only(): void
    {
        // Group A: 60 min of note time, of which 15 min was drawn from prepay.
        $note = $this->note(60, -45);

        $txn = app(PrepayService::class)->debitFromTicketNote($note);

        $this->assertSame(-0.25, (float) $txn->hours);
        $this->assertEqualsWithDelta(9.75, (float) $this->contract->fresh()->prepay_balance, 0.006);
    }

    public function test_a_native_note_without_an_adjustment_prices_its_time_alone(): void
    {
        $txn = app(PrepayService::class)->debitFromTicketNote($this->note(30, null));

        $this->assertSame(-0.5, (float) $txn->hours);
    }

    public function test_a_zero_minute_note_with_an_adjustment_is_debited_not_reversed(): void
    {
        $note = $this->note(0, 15);

        $txn = app(PrepayService::class)->debitFromTicketNote($note);

        $this->assertNotNull($txn);
        $this->assertSame(-0.25, (float) $txn->hours);
        $this->assertSame(1, PrepayTransaction::where('ticket_note_id', $note->id)->count());
    }

    public function test_editing_time_through_the_model_keeps_the_adjustment_and_reprices_the_sum(): void
    {
        $note = $this->note(30, 13);
        app(PrepayService::class)->debitFromTicketNote($note);

        // The adjustment is not mass assignable: update() cannot clear it.
        $note->update(['time_minutes' => 60, 'time_adjustment_minutes' => null]);

        $this->assertSame(13, (int) $note->fresh()->time_adjustment_minutes);
        $this->assertSame(-1.2167, (float) PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertEqualsWithDelta(10 - 1.2167, (float) $this->contract->fresh()->prepay_balance, 0.006); // the balance column holds 2 places
    }

    public function test_contract_move_entries_list_and_draw_the_priced_hours(): void
    {
        $zero = $this->note(0, 15);
        $timed = $this->note(30, 13);
        $service = app(TimeEntryContractMoveService::class);

        $rows = $service->entries($this->ticket)->keyBy('id');

        $this->assertTrue($rows->has($zero->id), 'a zero-minute note with an adjustment has time to move');
        $this->assertSame(0.25, $rows[$zero->id]['hours']);
        $this->assertSame(0.72, $rows[$timed->id]['hours']);
        $this->assertSame(0.7167, $service->drawHoursIfMoved($timed, null));
    }
}
