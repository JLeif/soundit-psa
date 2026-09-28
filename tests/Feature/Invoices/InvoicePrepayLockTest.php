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
                $this->reads[] = [$query->from, $query->lock, $query->getBindings(), $query->getConnection()->transactionLevel()];

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
        $this->assertContains($invoiceId, $grammar->reads[0][2]);
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
        $service->depositFromInvoice($invoice, $contract);
        for ($i = 0; $i < 2; $i++) {
            $this->observe(fn () => $service->reverseDepositForInvoice($invoice, $contract), $invoice->id);
        }
        $this->assertSame(2, PrepayTransaction::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
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
