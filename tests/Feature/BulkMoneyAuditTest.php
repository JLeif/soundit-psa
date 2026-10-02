<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\InvoiceVoidService;
use App\Services\PrepayAlertService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BulkMoneyAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->mock(PrepayAlertService::class)->shouldReceive('checkThreshold')->andReturnNull();
    }

    private function contract(): Contract
    {
        return Contract::create([
            'client_id' => Client::factory()->create(['name' => 'Synthetic private client'])->id,
            'name' => 'Synthetic private contract', 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
    }

    private function invoice(Contract $contract): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $contract->client_id, 'contract_id' => $contract->id,
            'invoice_number' => 'AUDIT-'.$contract->id, 'invoice_date' => today(),
            'due_date' => today()->addDays(30), 'status' => InvoiceStatus::Posted,
            'subtotal' => 100, 'tax' => 0, 'total' => 100,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'description' => 'Synthetic private line',
            'quantity' => 1, 'unit_price' => 100, 'amount' => 100,
            'prepaid_time_minutes' => 120, 'sort_order' => 0,
        ]);

        return $invoice;
    }

    public function test_bulk_invoice_deposit_records_actor(): void
    {
        $actor = User::factory()->create();
        $contract = $this->contract();
        $invoice = $this->invoice($contract);
        $this->actingAs($actor)->post(route('invoices.bulk-action'), [
            'action' => 'mark_paid', 'invoice_ids' => [$invoice->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertEquals(12, $contract->fresh()->prepay_balance);
        $this->assertDatabaseHas('prepay_transactions', [
            'invoice_id' => $invoice->id, 'source' => 'invoice_deposit', 'user_id' => $actor->id, 'hours' => 2,
        ]);
    }

    public function test_bulk_invoice_reversal_records_its_own_actor(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract);
        app(InvoiceService::class)->markPaid($invoice);
        $actor = User::factory()->create();
        $this->actingAs($actor)->post(route('invoices.bulk-action'), [
            'action' => 'void', 'invoice_ids' => [$invoice->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $this->assertDatabaseHas('prepay_transactions', [
            'invoice_id' => $invoice->id, 'source' => 'invoice_reversal', 'user_id' => $actor->id, 'hours' => -2,
        ]);
    }

    public function test_unauthenticated_invoice_paths_keep_null_actor_and_idempotency(): void
    {
        $contract = $this->contract();
        $invoice = $this->invoice($contract);
        $this->assertTrue(app(InvoiceService::class)->markPaid($invoice));
        $this->assertFalse(app(InvoiceService::class)->markPaid($invoice));
        app(InvoiceVoidService::class)->void($invoice);
        app(InvoiceVoidService::class)->void($invoice);
        $rows = PrepayTransaction::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame([null, null], $rows->pluck('user_id')->all());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_bulk_call_preserves_answerer_and_logs_debit_and_delete_without_free_text(): void
    {
        $actor = User::factory()->create();
        $answerer = User::factory()->create();
        $contract = $this->contract();
        $ticket = Ticket::factory()->create(['client_id' => $contract->client_id, 'contract_id' => $contract->id, 'subject' => 'Synthetic private subject']);
        $call = PhoneCall::create([
            'call_uuid' => 'audit-call', 'from_number' => '+15555550100',
            'direction' => 'inbound', 'status' => 'completed', 'started_at' => now(),
            'ticket_id' => $ticket->id, 'is_billable' => false, 'answered_by' => $answerer->id,
        ]);
        $call->duration = 3600;
        $call->save();
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records) {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $this->actingAs($actor)->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'is_billable' => true, 'call_ids' => [$call->id],
        ])->assertOk()->assertJsonPath('summary.applied', 1);
        $txn = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertEquals($answerer->id, $txn->user_id);
        $this->assertEquals(9, $contract->fresh()->prepay_balance);
        $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'is_billable' => false, 'call_ids' => [$call->id],
        ])->assertOk()->assertJsonPath('summary.applied', 1);
        $this->assertDatabaseMissing('prepay_transactions', ['id' => $txn->id]);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $context = [
            'action' => 'debit', 'phone_call_id' => $call->id, 'contract_id' => $contract->id,
            'txn_id' => $txn->id, 'acting_user_id' => $actor->id, 'amount' => -1.0,
        ];
        $this->assertSame([
            ['info', '[Prepay] Phone call ledger event', $context],
            ['info', '[Prepay] Phone call ledger event', array_replace($context, ['action' => 'reversal', 'amount' => 1.0])],
        ], $records);
        foreach ($records as [, , $fields]) {
            $this->assertArrayNotHasKey('name', $fields);
            $this->assertArrayNotHasKey('description', $fields);
            $this->assertStringNotContainsString('Synthetic private', json_encode($fields));
        }
    }

    public function test_no_user_call_debit_and_difference_log_keep_null_actor(): void
    {
        $contract = $this->contract();
        $ticket = Ticket::factory()->create(['client_id' => $contract->client_id, 'contract_id' => $contract->id]);
        $call = PhoneCall::create([
            'call_uuid' => 'no-user-audit-call', 'from_number' => '+15555550100',
            'direction' => 'inbound', 'status' => 'completed', 'started_at' => now(),
            'ticket_id' => $ticket->id, 'is_billable' => true,
        ]);
        $call->duration = 3600;
        $call->save();
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records) {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $service = app(PrepayService::class);
        $txn = $service->debitFromPhoneCall($call);
        $this->assertNull($txn->user_id);
        $call->duration = 7200;
        $call->save();
        $this->assertSame($txn->id, $service->debitFromPhoneCall($call)->id);
        $this->assertEquals(8, $contract->fresh()->prepay_balance);
        $context = [
            'action' => 'debit', 'phone_call_id' => $call->id, 'contract_id' => $contract->id,
            'txn_id' => $txn->id, 'acting_user_id' => null, 'amount' => -1.0,
        ];
        $this->assertSame(array_fill(0, 2, ['info', '[Prepay] Phone call ledger event', $context]), $records);
        $service->reverseDebitForPhoneCall($call);
        $this->assertSame(['info', '[Prepay] Phone call ledger event', array_replace($context, ['action' => 'reversal', 'amount' => 2.0])], $records[2]);
        $service->reverseDebitForPhoneCall($call);
        $this->assertCount(3, $records);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_normal_batches_pass_and_calls_limit_is_per_user_not_ip(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->freezeTime();
        $data = ['action' => 'mark_followed_up', 'call_ids' => range(10000, 10099)];
        $this->actingAs($first);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson(route('calls.bulk-action'), $data)->assertOk()->assertJsonPath('summary.skipped', 100);
        }
        $this->postJson(route('calls.bulk-action'), $data)->assertStatus(429);
        // One user cannot reset their budget by changing address.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson(route('calls.bulk-action'), $data)->assertStatus(429);
        // A colleague at the original shared address has an independent budget.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->actingAs($second)->postJson(route('calls.bulk-action'), $data)->assertOk();
        $this->travel(61)->seconds();
        $this->actingAs($first)->postJson(route('calls.bulk-action'), $data)->assertOk();
    }

    public function test_normal_invoice_batches_pass_and_limit_returns_429(): void
    {
        $invoice = $this->invoice($this->contract());
        $this->actingAs(User::factory()->create());
        $this->freezeTime();
        $data = ['action' => 'post', 'invoice_ids' => [$invoice->id]];
        for ($i = 0; $i < 60; $i++) {
            $this->post(route('invoices.bulk-action'), $data)->assertRedirect()->assertSessionHas('success');
        }
        $this->post(route('invoices.bulk-action'), $data)->assertStatus(429);
    }
}
