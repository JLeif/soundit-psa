<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC3dTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 200)]);
        Http::preventStrayRequests();
    }

    public function test_trashed_phone_reversal_restores_columns_once(): void
    {
        $client = Client::create(['name' => 'Synthetic C3d']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic hours', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        PrepayTransaction::create(['contract_id' => $contract->id, 'source' => 'manual_credit',
            'date' => now()->subDay(), 'hours' => 10]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c3d-reversal', 'direction' => 'inbound', 'status' => 'completed',
            'from_number' => '+15555550142', 'ticket_id' => $ticket->id, 'contract_id' => $contract->id,
            'duration' => 1800, 'is_billable' => true, 'started_at' => now(),
        ]));
        $service = app(PrepayService::class);
        $this->assertNotNull($service->debitFromPhoneCall($call));
        $this->assertEquals(0.5, $contract->fresh()->prepay_used);
        $this->assertEquals(9.5, $contract->fresh()->prepay_balance);
        $contract->delete();

        $service->reverseDebitForPhoneCall($call);
        $service->reverseDebitForPhoneCall($call);

        $restored = Contract::withTrashed()->findOrFail($contract->id);
        $this->assertTrue($restored->trashed());
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertEquals(0, $restored->prepay_used);
        $this->assertEquals(10, $restored->prepay_balance);
        $this->assertEquals(10, $restored->prepay_total);
        $this->assertEquals(10, PrepayTransaction::where('contract_id', $contract->id)->sum('hours'));
    }
}
