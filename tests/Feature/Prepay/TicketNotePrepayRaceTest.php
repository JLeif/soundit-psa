<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\PrepayService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketNotePrepayRaceTest extends TestCase
{
    use RefreshDatabase;

    private function recordLocks(): void
    {
        // SQLite has no row locks. Annotate compiled lock requests, not lock efficacy.
        DB::connection()->setQueryGrammar(new class(DB::connection()) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
        {
            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
            {
                return $value ? '/* requested update lock */' : '';
            }
        });
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create();
        $contract = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic prepay',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket->update(['contract_id' => $contract->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'body' => 'Synthetic note', 'ticket_id' => $ticket->id,
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]));

        return [$note, $contract];
    }

    private function assertDebit(TicketNote $note, Contract $contract, float $hours): void
    {
        $this->assertSame(1, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertEquals(-$hours, PrepayTransaction::where('ticket_note_id', $note->id)->value('hours'));
        $this->assertEquals($hours, $contract->fresh()->prepay_used);
        $this->assertEquals(10 - $hours, $contract->fresh()->prepay_balance);
    }

    public function test_lost_create_race_applies_only_difference(): void
    {
        [$note, $contract] = $this->fixture();
        $injected = false;
        // Deterministic interleaving: a winner writes its ledger and totals after
        // the loser's empty read, before the loser's INSERT reaches the database.
        PrepayTransaction::creating(function ($txn) use (&$injected, $contract) {
            if ($injected) {
                return;
            }
            $injected = true;
            $attributes = $txn->getAttributes();
            $attributes['hours'] = -0.5;
            DB::table('prepay_transactions')->insert($attributes);
            $contract->increment('prepay_used', 0.5);
            $contract->decrement('prepay_balance', 0.5);
        });
        try {
            app(PrepayService::class)->debitFromTicketNote($note);
            $this->assertTrue($injected);
            $this->assertDebit($note, $contract, 1.0);
        } finally {
            PrepayTransaction::flushEventListeners();
        }
    }

    public function test_debit_locks_parent_call_row_first_in_its_transaction(): void
    {
        [$note, $contract] = $this->fixture();
        $this->recordLocks();
        $events = [];
        DB::listen(function ($query) use (&$events) {
            $events[] = $query->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$events) {
            $events[] = 'BEGIN';
        });
        app(PrepayService::class)->debitFromTicketNote($note);
        $begin = array_search('BEGIN', $events, true);
        $this->assertNotFalse($begin);
        $first = $events[$begin + 1] ?? '';
        $this->assertStringStartsWith('select * from "ticket_notes"', $first);
        $this->assertStringContainsString('"ticket_notes"."id" = ?', $first);
        $this->assertStringContainsString('/* requested update lock */', $first);
        $this->assertDebit($note, $contract, 1.0);
    }

    public function test_single_delivery_redelivery_and_reversal(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.0);
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.0);
        $note->time_minutes = 90;
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.5);
        $service->reverseDebitForTicketNote($note);
        $service->reverseDebitForTicketNote($note);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_used);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_reversal_requests_transaction_and_locks_then_returns_hours_once(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $this->recordLocks();
        $events = [];
        Event::listen(TransactionBeginning::class, function () use (&$events) {
            $events[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$events) {
            $events[] = $query->sql;
        });
        $service->reverseDebitForTicketNote($note);
        $this->assertSame('BEGIN', $events[0]);
        foreach (['ticket_notes', 'prepay_transactions', 'contracts'] as $i => $table) {
            $this->assertStringStartsWith('select * from "'.$table.'"', $events[$i + 1]);
            $this->assertStringContainsString('/* requested update lock */', $events[$i + 1]);
        }
        $service->reverseDebitForTicketNote($note);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_used);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_both_fallbacks_explicitly_order_by_lower_id(): void
    {
        [$note, $contract] = $this->fixture();
        $higher = $contract->replicate();
        $higher->save();
        $note->ticket->update(['contract_id' => null]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'select * from "contracts"') && str_contains($query->sql, '"status" = ?')) {
                $queries[] = $query->sql;
            }
        });
        $txn = app(PrepayService::class)->debitFromTicketNote($note);
        $this->assertSame($contract->id, $txn->contract_id);
        $call = new \App\Models\PhoneCall;
        $call->setRelation('ticket', $note->ticket);
        $this->assertSame($contract->id, app(PrepayService::class)->resolveContractForPhoneCall($call)->id);
        $this->assertCount(2, $queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('order by "id" asc', $sql);
        }
    }

    public function test_changed_target_updates_original_contract_only(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $other = $contract->replicate();
        $other->prepay_used = 0;
        $other->prepay_balance = 10;
        $other->save();
        $note->contract_id = $other->id;
        $note->time_minutes = 90;
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.5);
        $this->assertEquals(0, $other->fresh()->prepay_used);
        $this->assertEquals(10, $other->fresh()->prepay_balance);
    }

    public function test_unbillable_zero_time_and_soft_delete_restore(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        foreach (['is_billable', 'time_minutes'] as $field) {
            $note->is_billable = true;
            $note->time_minutes = 60;
            $service->debitFromTicketNote($note);
            $this->assertDebit($note, $contract, 1.0);
            $note->$field = 0;
            $this->assertNull($service->debitFromTicketNote($note));
            $this->assertEquals(10, $contract->fresh()->prepay_balance);
        }
        $note->is_billable = true;
        $note->time_minutes = 60;
        $note->save();
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.0);
        $note->delete();
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $note->restore();
        $service->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 1.0);
    }

    public function test_unique_index_allows_multiple_nulls_and_down_preserves_rows(): void
    {
        [$note, $contract] = $this->fixture();
        $txn = app(PrepayService::class)->debitFromTicketNote($note);
        $attributes = $txn->getAttributes();
        unset($attributes['id']);
        $attributes['ticket_note_id'] = null;
        DB::table('prepay_transactions')->insert([$attributes, $attributes]);
        $this->assertSame(3, PrepayTransaction::count());
        $migration = require database_path('migrations/2026_09_29_010000_unique_ticket_note_prepay_transaction.php');
        $migration->down();
        $this->assertSame(3, PrepayTransaction::count());
        $attributes['ticket_note_id'] = $note->id;
        DB::table('prepay_transactions')->insert($attributes);
        $this->assertSame(4, PrepayTransaction::count());
    }
}
