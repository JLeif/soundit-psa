<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\ContractResolver;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Tests\TestCase;

class ContractC14Test extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_contracts_take_precedence_over_unverified_intake(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $contract = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C15 '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
            $ids[] = $contract->id;
            $this->assertTrue(app(\App\Services\PrepayService::class)->isHoursPrepay($contract));
        }
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c15-ambiguous', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic ambiguous time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: needs contract'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key => $id, 'ticket_id' => $ticket->id, 'candidate_contract_ids' => $ids], $records[0]->context);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'awaiting verification'))));
    }

    public function test_no_contract_intake_logs_verification_hold_for_note_and_call(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $this->assertSame(0, Contract::where('client_id', $ticket->client_id)->active()->count());
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $resolution = app(ContractResolver::class)->forEntry($ticket);
        $this->assertFalse($resolution->isResolved());
        $this->assertFalse($resolution->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c15-none', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic no-contract time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: awaiting verification'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key => $id, 'ticket_id' => $ticket->id, 'candidate_contract_ids' => []], $records[0]->context);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held: needs contract'))));
    }

    public function test_unverified_intake_logs_verification_hold_for_note_and_call(): void
    {
        // Synthetic intake fixture follows ContractC10Test::linkedCall.
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $contract = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic C14',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $resolver = app(ContractResolver::class);
        $this->assertSame($contract->id, $resolver->forClient($ticket->client_id)->contract?->id);
        $resolution = $resolver->forEntry($ticket);
        $this->assertFalse($resolution->isResolved());
        $this->assertFalse($resolution->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c14', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: awaiting verification'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key => $id, 'ticket_id' => $ticket->id, 'candidate_contract_ids' => []], $records[0]->context);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held: needs contract'))));

        $ticket->forceFill(['contact_intake_verified_at' => now()])->saveQuietly();
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertSame($contract->id, $resolver->forEntry($ticket->fresh())->contract?->id);
        $service = app(\App\Services\PrepayService::class);
        $this->assertSame($contract->id, $service->contractForPhoneCall($call->fresh())?->id);
        $this->assertTrue($service->isHoursPrepay($contract->fresh()));
    }
}
