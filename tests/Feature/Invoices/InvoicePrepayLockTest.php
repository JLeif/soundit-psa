<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PrepayTransaction;
use App\Services\PrepayService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePrepayLockTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Client::factory()->count(2)->create();
        $contract = Contract::create([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Synthetic prepaid contract', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 0,
            'prepay_used' => 0, 'prepay_balance' => 0,
        ]);
        $invoice = Invoice::create([
            'client_id' => $contract->client_id, 'contract_id' => $contract->id,
            'invoice_number' => 'INV-LOCK-1', 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01',
            'status' => InvoiceStatus::Posted, 'subtotal' => 100, 'total' => 100,
        ]);
        $filler = $invoice;
        $invoice = $filler->replicate();
        $invoice->invoice_number = 'INV-LOCK-2';
        $invoice->save();
        $this->assertCount(3, array_unique([$invoice->id, $contract->id, $contract->client_id]));
        $invoice->lines()->create([
            'description' => 'Synthetic hours', 'quantity' => 1,
            'unit_price' => 100, 'amount' => 100, 'prepaid_time_minutes' => 120,
        ]);

        return [$invoice, $contract];
    }

    /** SQLite omits FOR UPDATE: observe the actual builder at compilation, not source text. */
    private function observe(callable $operation, int $invoiceId): array
    {
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                $this->reads[] = [$query->from, $query->lock, $query->getBindings(), $query->getConnection()->transactionLevel(), $query->wheres];

                return parent::compileSelect($query);
            }
        };
        $base = $connection->transactionLevel();
        $queries = [];
        $active = true;
        DB::listen(function (QueryExecuted $event) use (&$queries, &$active) {
            if ($active) {
                $queries[] = [$event->sql, $event->connection->transactionLevel()];
            }
        });
        $connection->setQueryGrammar($grammar);
        try {
            $operation();
        } finally {
            $active = false;
            $connection->setQueryGrammar($original);
        }
        $this->assertNotEmpty($grammar->reads);
        $this->assertSame('invoices', $grammar->reads[0][0], 'Invoice read must precede ledger reads');
        $this->assertTrue($grammar->reads[0][1], 'Invoice read must request FOR UPDATE');
        $this->assertSame([$invoiceId], $grammar->reads[0][2]);
        $this->assertSame('invoices.id', $grammar->reads[0][4][0]['column']);
        $this->assertSame('=', $grammar->reads[0][4][0]['operator']);
        $this->assertSame($invoiceId, $grammar->reads[0][4][0]['value']);
        $this->assertNotEmpty($queries);
        foreach ($queries as [$sql, $depth]) {
            $this->assertSame($base + 1, $depth, 'Whole operation must share its transaction: '.$sql);
        }

        return $queries;
    }

    public function test_duplicate_paid_deliveries_request_the_same_invoice_lock_before_counts(): void
    {
        [$invoice, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $invoice->updateQuietly(['status' => InvoiceStatus::Paid]);
        // Sequential delivery plus lock/transaction seam, NOT a SQLite concurrency proof.
        for ($i = 0; $i < 2; $i++) {
            $this->observe(fn () => $service->depositFromInvoice($invoice, $contract), $invoice->id);
        }
        $this->assertSame(1, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(2, $contract->fresh()->prepay_balance);
    }

    public function test_reversal_requests_the_same_invoice_lock_before_ledger_reads(): void
    {
        [$invoice, $contract] = $this->fixture();
        $service = app(PrepayService::class);
        $invoice->updateQuietly(['status' => InvoiceStatus::Paid]);
        $service->depositFromInvoice($invoice, $contract);
        $invoice->updateQuietly(['status' => InvoiceStatus::Posted]);
        for ($i = 0; $i < 2; $i++) {
            $this->observe(fn () => $service->reverseDepositForInvoice($invoice, $contract), $invoice->id);
        }
        $this->assertSame(2, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
    }

    public static function notPaidStatuses(): array
    {
        return [[InvoiceStatus::Void], [InvoiceStatus::Posted]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notPaidStatuses')]
    public function test_stale_paid_model_cannot_deposit_on_void_or_posted_row(InvoiceStatus $status): void
    {
        [$invoice, $contract] = $this->fixture();
        $invoice->updateQuietly(['status' => InvoiceStatus::Paid]);
        Invoice::whereKey($invoice->id)->update(['status' => $status]);
        $this->assertNull(app(PrepayService::class)->depositFromInvoice($invoice, $contract), 'Stale Paid model must not deposit on '.$status->value);
        $this->assertSame(0, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
    }

    public function test_stale_not_paid_model_cannot_reverse_paid_row(): void
    {
        [$invoice, $contract] = $this->fixture();
        $invoice->updateQuietly(['status' => InvoiceStatus::Paid]);
        app(PrepayService::class)->depositFromInvoice($invoice, $contract);
        $invoice->status = InvoiceStatus::Posted;
        $this->assertNull(app(PrepayService::class)->reverseDepositForInvoice($invoice, $contract), 'Stale not-Paid model must not reverse Paid row');
        $this->assertSame(1, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(2, $contract->fresh()->prepay_balance);
    }

    public function test_missing_invoice_refuses_both_writes_and_logs(): void
    {
        [$invoice, $contract] = $this->fixture();
        $invoice->forceDelete();
        \Illuminate\Support\Facades\Log::spy();
        $service = app(PrepayService::class);
        $this->assertNull($service->depositFromInvoice($invoice, $contract));
        $this->assertNull($service->reverseDepositForInvoice($invoice, $contract));
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
        foreach (['deposit', 'reversal'] as $operation) {
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('[Prepay] Invoice '.$operation.' refused', ['invoice_id' => $invoice->id])->once();
        }
    }

    public function test_soft_deleted_paid_invoice_is_read_under_the_lock(): void
    {
        [$invoice, $contract] = $this->fixture();
        $invoice->updateQuietly(['status' => InvoiceStatus::Paid]);
        $invoice->delete();
        $this->assertNotNull(app(PrepayService::class)->depositFromInvoice($invoice, $contract));
        $this->assertEquals(2, $contract->fresh()->prepay_balance);
    }

    public function test_paid_open_paid_still_restores_a_second_deposit(): void
    {
        [$invoice, $contract] = $this->fixture();
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $invoice->update(['status' => InvoiceStatus::Posted]);
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
        $invoice->update(['status' => InvoiceStatus::Paid]);
        $this->assertSame(3, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(2, PrepayTransaction::where('invoice_id', $invoice->id)->sum('hours'));
        $this->assertEquals(2, $contract->fresh()->prepay_total);
        $this->assertEquals(2, $contract->fresh()->prepay_balance);
    }
}
