<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Services\PrepayService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneCallPrepayContractTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create();
        $attributes = [
            'client_id' => $ticket->client_id, 'name' => 'Synthetic prepay A',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ];
        $a = Contract::create($attributes);
        $b = Contract::create(array_merge($attributes, ['name' => 'Synthetic prepay B']));
        $ticket->update(['contract_id' => $a->id]);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-contract', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'ticket_id' => $ticket->id,
            'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        $txn = app(PrepayService::class)->debitFromPhoneCall($call);
        $ticket->update(['contract_id' => $b->id]);
        $call->unsetRelation('ticket');
        $this->assertSame($b->id, app(PrepayService::class)->resolveContractForPhoneCall($call)->id);

        return [$call, $a, $b, $txn];
    }

    public static function durations(): array
    {
        return ['increase' => [5400, 1.5], 'decrease' => [1800, 0.5]];
    }

    #[DataProvider('durations')]
    public function test_redelivery_changes_only_original_contract(int $seconds, float $hours): void
    {
        [$call, $a, $b, $txn] = $this->fixture();
        $call->duration = $seconds;
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertEquals($hours, $a->fresh()->prepay_used);
        $this->assertEquals(10 - $hours, $a->fresh()->prepay_balance);
        $this->assertEquals(0, $b->fresh()->prepay_used);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
        $this->assertSame($a->id, $txn->fresh()->contract_id);
        $this->assertEquals(-$hours, $txn->fresh()->hours);
    }

    public function test_missing_original_contract_updates_ledger_without_moving_balances(): void
    {
        [$call, $a, $b, $txn] = $this->fixture();
        $a->delete();
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $call->duration = 5400;
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertEquals(0, $b->fresh()->prepay_used);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
        $original = Contract::withTrashed()->findOrFail($a->id);
        $this->assertEquals(1, $original->prepay_used);
        $this->assertEquals(9, $original->prepay_balance);
        $this->assertEquals(-1.5, $txn->fresh()->hours);
        $this->assertSame($a->id, $txn->fresh()->contract_id);
        $this->assertContains(['warning', '[Prepay] Phone call difference skipped', [
            'phone_call_id' => $call->id,
            'contract_id' => $a->id,
            'hours_difference' => 0.5,
        ]], $records);
    }

    public function test_reversal_opens_transaction(): void
    {
        [$call, $a, $b, $txn] = $this->fixture();
        $begins = 0;
        Event::listen(TransactionBeginning::class, function () use (&$begins) {
            $begins++;
        });
        app(PrepayService::class)->reverseDebitForPhoneCall($call);
        $this->assertSame(1, $begins);
        $this->assertNull($txn->fresh());
        $this->assertEquals(0, $a->fresh()->prepay_used);
        $this->assertEquals(10, $a->fresh()->prepay_balance);
        $this->assertEquals(10, $b->fresh()->prepay_balance);
    }
}
