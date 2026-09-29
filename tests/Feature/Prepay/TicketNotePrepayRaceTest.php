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

    public function test_unmatched_unique_exception_is_rethrown(): void
    {
        [$note] = $this->fixture();
        PrepayTransaction::creating(function () {
            throw new \Illuminate\Database\UniqueConstraintViolationException('sqlite', 'synthetic insert', [], new \PDOException('synthetic unique violation'));
        });
        try {
            try {
                app(PrepayService::class)->debitFromTicketNote($note);
                $this->fail('Unmatched unique exception must propagate');
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $this->assertStringContainsString('synthetic unique violation', $e->getMessage());
            }
        } finally {
            PrepayTransaction::flushEventListeners();
        }
    }

    public function test_locked_hours_override_stale_caller(): void
    {
        [$note, $contract] = $this->fixture();
        DB::table('ticket_notes')->where('id', $note->id)->update(['time_minutes' => 120]);
        app(PrepayService::class)->debitFromTicketNote($note);
        $this->assertDebit($note, $contract, 2.0);
    }

    public function test_trashed_at_lock_never_creates(): void
    {
        [$note, $contract] = $this->fixture();
        DB::table('ticket_notes')->where('id', $note->id)->update(['deleted_at' => now()]);
        $this->assertNull(app(PrepayService::class)->debitFromTicketNote($note));
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_missing_row_read_has_no_lock_annotation(): void
    {
        [$note, $contract] = $this->fixture();
        $this->recordLocks();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'select * from "prepay_transactions"')) {
                $queries[] = $query->sql;
            }
        });
        app(PrepayService::class)->debitFromTicketNote($note);
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('/* requested update lock */', $queries[0]);
        $this->assertDebit($note, $contract, 1.0);
    }

    public function test_soft_deleted_ledger_contract_gets_difference_and_reversal(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $other = $contract->replicate();
        $other->save();
        $note->forceFill(['contract_id' => $other->id, 'time_minutes' => 120])->saveQuietly();
        $contract->delete();
        $service->debitFromTicketNote($note);
        $this->assertEquals(8, Contract::withTrashed()->find($contract->id)->prepay_balance);
        $service->reverseDebitForTicketNote($note);
        $this->assertEquals(10, Contract::withTrashed()->find($contract->id)->prepay_balance);
        $this->assertSame(0, PrepayTransaction::count());
    }

    public function test_unbillable_after_move_to_client_without_prepay_reverses(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $otherTicket = Ticket::factory()->create();
        $note->ticket->update(['client_id' => $otherTicket->client_id, 'contract_id' => null]);
        $note->is_billable = false;
        $note->saveQuietly();
        $service->debitFromTicketNote($note->fresh());
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_existing_debit_requests_primary_key_and_contract_locks(): void
    {
        [$note] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromTicketNote($note);
        $this->recordLocks();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $service->debitFromTicketNote($note);
        $ledgerLocks = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "prepay_transactions"') && str_contains($sql, '/* requested update lock */')));
        $this->assertCount(1, $ledgerLocks);
        $this->assertStringContainsString('"prepay_transactions"."id" = ?', $ledgerLocks[0]);
        $contractLocks = array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "contracts"') && str_contains($sql, '/* requested update lock */'));
        $this->assertCount(1, $contractLocks);
    }

    public function test_hard_missing_contract_preserves_row_and_logs_both_refusals(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $txn = $service->debitFromTicketNote($note);
        // Synthetic orphan without violating the fixture's other foreign keys.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('prepay_transactions')->where('id', $txn->id)->update(['contract_id' => 999999]);
        // RefreshDatabase rolls this synthetic orphan back; it is never committed.
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);
        $note->time_minutes = 120;
        $note->saveQuietly();
        $service->debitFromTicketNote($note);
        $this->assertEquals(-1, $txn->fresh()->hours);
        $service->reverseDebitForTicketNote($note);
        $this->assertEquals(-1, $txn->fresh()->hours);
        foreach (['difference', 'reversal'] as $operation) {
            $records = array_values(array_filter($handler->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note '.$operation.' refused'));
            $this->assertCount(1, $records);
            $this->assertSame(\Monolog\Level::Warning, $records[0]->level);
            $this->assertSame(999999, $records[0]->context['contract_id']);
        }
    }

    public function test_migration_duplicate_guard_and_clean_up(): void
    {
        [$note, $contract] = $this->fixture();
        $txn = app(PrepayService::class)->debitFromTicketNote($note);
        $migration = require database_path('migrations/2026_09_29_010000_unique_ticket_note_prepay_transaction.php');
        $migration->down();
        $attributes = $txn->getAttributes();
        unset($attributes['id']);
        $duplicate = DB::table('prepay_transactions')->insertGetId($attributes);
        try {
            $migration->up();
            $this->fail('Duplicate ticket note must require money reconciliation');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ticket-note prepay duplicates require money reconciliation; ticket_note_ids: '.$note->id, $e->getMessage());
        }
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasIndex('prepay_transactions', 'prepay_transactions_ticket_note_id_unique'));
        DB::table('prepay_transactions')->where('id', $duplicate)->delete();
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('prepay_transactions', 'prepay_transactions_ticket_note_id_unique'));
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
            try {
                app(PrepayService::class)->debitFromTicketNote($note);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $this->fail('A winning row must be re-read after the unique collision');
            }
            $this->assertTrue($injected);
            $this->assertDebit($note, $contract, 1.0);
        } finally {
            PrepayTransaction::flushEventListeners();
        }
    }

    public function test_debit_locks_parent_note_row_first_in_its_transaction(): void
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
        $note->saveQuietly();
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
        $this->assertStringNotContainsString('/* requested update lock */', $events[2]);
        unset($events[2]);
        $events = array_values($events);
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
        $description = PrepayTransaction::where('ticket_note_id', $note->id)->value('description');
        $note->ticket->update(['subject' => 'Other client private subject']);
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);
        $note->contract_id = $other->id;
        $note->time_minutes = 90;
        $note->saveQuietly();
        $service->debitFromTicketNote($note);
        $this->assertSame($description, PrepayTransaction::where('ticket_note_id', $note->id)->value('description'));
        $records = array_values(array_filter($handler->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note contract mismatch'));
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Warning, $records[0]->level);
        $this->assertSame($other->id, $records[0]->context['resolved_contract_id']);
        $this->assertSame($contract->id, $records[0]->context['ledger_contract_id']);
        $this->assertDebit($note, $contract, 1.5);
        $this->assertEquals(0, $other->fresh()->prepay_used);
        $this->assertEquals(10, $other->fresh()->prepay_balance);
    }

    public function test_direct_debit_call_unbillable_zero_time_and_soft_delete_restore(): void
    {
        [$note, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        foreach (['is_billable', 'time_minutes'] as $field) {
            $note->is_billable = true;
            $note->time_minutes = 60;
            $note->saveQuietly();
            $service->debitFromTicketNote($note);
            $this->assertDebit($note, $contract, 1.0);
            $note->$field = 0;
            $note->saveQuietly();
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

    public function test_unique_index_allows_multiple_nulls_and_down_removes_index(): void
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
