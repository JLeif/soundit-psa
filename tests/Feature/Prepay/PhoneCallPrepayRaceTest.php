<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Services\PrepayService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneCallPrepayRaceTest extends TestCase
{
    use RefreshDatabase;

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
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-race', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'ticket_id' => $ticket->id,
            'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));

        return [$call, $contract];
    }

    private function assertDebit(PhoneCall $call, Contract $contract, float $hours): void
    {
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertEquals(-$hours, PrepayTransaction::where('phone_call_id', $call->id)->value('hours'));
        $this->assertEquals($hours, $contract->fresh()->prepay_used);
        $this->assertEquals(10 - $hours, $contract->fresh()->prepay_balance);
    }

    public function test_lost_create_race_applies_only_difference(): void
    {
        [$call, $contract] = $this->fixture();
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
            app(PrepayService::class)->debitFromPhoneCall($call);
            $this->assertTrue($injected);
            $this->assertDebit($call, $contract, 1.0);
        } finally {
            PrepayTransaction::flushEventListeners();
        }
    }

    public function test_debit_locks_parent_call_row_first_in_its_transaction(): void
    {
        [$call, $contract] = $this->fixture();
        $events = [];
        DB::listen(function ($query) use (&$events) {
            $events[] = $query->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$events) {
            $events[] = 'BEGIN';
        });
        app(PrepayService::class)->debitFromPhoneCall($call);
        $begin = array_search('BEGIN', $events, true);
        $this->assertNotFalse($begin);
        $first = $events[$begin + 1] ?? '';
        $this->assertStringStartsWith('select * from "phone_calls"', $first);
        $this->assertStringContainsString('"phone_calls"."id" = ?', $first);
        $this->assertDebit($call, $contract, 1.0);
    }

    public function test_single_delivery_redelivery_and_reversal(): void
    {
        [$call, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $service->debitFromPhoneCall($call);
        $this->assertDebit($call, $contract, 1.0);
        $service->debitFromPhoneCall($call);
        $this->assertDebit($call, $contract, 1.0);
        $call->duration = 5400;
        $service->debitFromPhoneCall($call);
        $this->assertDebit($call, $contract, 1.5);
        $service->reverseDebitForPhoneCall($call);
        $service->reverseDebitForPhoneCall($call);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_used);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_unique_index_allows_multiple_nulls_and_down_preserves_rows(): void
    {
        [$call, $contract] = $this->fixture();
        $txn = app(PrepayService::class)->debitFromPhoneCall($call);
        $attributes = $txn->getAttributes();
        unset($attributes['id']);
        $attributes['phone_call_id'] = null;
        DB::table('prepay_transactions')->insert([$attributes, $attributes]);
        $this->assertSame(3, PrepayTransaction::count());
        $migration = require database_path('migrations/2026_09_28_180000_unique_phone_call_prepay_transaction.php');
        $migration->down();
        $this->assertSame(3, PrepayTransaction::count());
        $attributes['phone_call_id'] = $call->id;
        DB::table('prepay_transactions')->insert($attributes);
        $this->assertSame(4, PrepayTransaction::count());
    }
}
