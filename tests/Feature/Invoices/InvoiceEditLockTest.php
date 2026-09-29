<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PrepayTransaction;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InvoiceEditLockTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $contract = Contract::create([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Synthetic prepaid contract', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 0,
            'prepay_used' => 0, 'prepay_balance' => 0,
        ]);
        $invoice = Invoice::create([
            'client_id' => $contract->client_id, 'contract_id' => $contract->id,
            'invoice_number' => 'EDIT-1', 'invoice_date' => '2026-01-01',
            'due_date' => '2026-02-01', 'status' => InvoiceStatus::Posted,
            'subtotal' => 100, 'total' => 100, 'notes' => 'Original',
        ]);
        $line = $invoice->lines()->create([
            'description' => 'Synthetic hours', 'quantity' => 1,
            'unit_price' => 100, 'amount' => 100, 'prepaid_time_minutes' => 120,
            'quantity_source' => 'Original',
        ]);
        $data = [
            'invoice_date' => '2026-03-01', 'due_date' => '2026-04-01', 'notes' => 'Edited',
            'lines' => [['id' => $line->id, 'description' => 'Changed hours',
                'quantity' => 3, 'unit_price' => 100, 'unit_cost' => 20,
                'prepaid_time_minutes' => 360]],
        ];

        return [$invoice->fresh()->load('lines'), $contract, $user, $data];
    }

    private function snapshot(Invoice $invoice, Contract $contract): array
    {
        return [
            $invoice->fresh()->getAttributes(),
            $invoice->fresh()->lines->map->getAttributes()->all(),
            PrepayTransaction::orderBy('id')->get()->map->getAttributes()->all(),
            $contract->fresh()->getAttributes(),
        ];
    }

    public function test_a_stale_edit_is_refused_without_changing_paid_lines_totals_or_deposit(): void
    {
        [$stale, $contract, $user, $data] = $this->fixture();
        $this->assertTrue($stale->is_editable);
        $this->assertTrue(app(InvoiceService::class)->markPaid($stale->fresh()));
        $this->assertTrue($stale->is_editable);
        $this->assertEquals(2, $contract->fresh()->prepay_balance);
        $before = $this->snapshot($stale, $contract);
        // SQLite cannot prove InnoDB lock waits; this pins the locked re-check.
        $result = app(InvoiceService::class)->updateInvoice($stale, $data, $user);
        $this->assertSame($before, $this->snapshot($stale, $contract), 'Refusal must write nothing');
        $this->assertFalse($result);
    }

    public function test_b_http_stale_binding_reports_error_and_writes_nothing(): void
    {
        [$stale, $contract, $user, $data] = $this->fixture();
        // Bind the editable snapshot, then commit Paid behind that snapshot.
        Route::bind('invoice', function () use ($stale) {
            app(InvoiceService::class)->markPaid($stale->fresh());

            return $stale;
        });
        app(InvoiceService::class)->markPaid($stale->fresh());
        $before = $this->snapshot($stale, $contract);
        $this->actingAs($user)->patch(route('invoices.update', $stale), $data)
            ->assertRedirect(route('invoices.show', $stale))
            ->assertSessionHas('error', 'This invoice is no longer editable.')
            ->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot($stale, $contract));
    }

    public function test_c_edit_requests_invoice_lock_before_any_write_inside_transaction(): void
    {
        [$invoice, , $user, $data] = $this->fixture();
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $base = $connection->transactionLevel();
        // SQLite omits FOR UPDATE; inspect the actual compilation annotation, not lock waits.
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                $this->reads[] = [$query->from, $query->lock, $query->getBindings(), $query->getConnection()->transactionLevel()];

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($grammar);
        try {
            app(InvoiceService::class)->updateInvoice($invoice, $data, $user);
        } finally {
            $connection->setQueryGrammar($original);
        }
        $this->assertNotEmpty($grammar->reads);
        $this->assertSame(['invoices', true, [$invoice->id], $base + 1], $grammar->reads[0], 'First read must lock the invoice in the edit transaction');
    }

    public function test_missing_invoice_is_refused_without_writes(): void
    {
        [$stale, $contract, $user, $data] = $this->fixture();
        $stale->delete();
        $before = Invoice::withTrashed()->findOrFail($stale->id)->getAttributes();
        $lines = $stale->lines->map->getAttributes()->all();
        $this->assertFalse(app(InvoiceService::class)->updateInvoice($stale, $data, $user));
        $this->assertSame($before, Invoice::withTrashed()->findOrFail($stale->id)->getAttributes());
        $this->assertSame($lines, $stale->lines()->get()->map->getAttributes()->all());
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(0, $contract->fresh()->prepay_balance);
    }

    public function test_d_editable_invoice_preserves_edit_behavior(): void
    {
        [$invoice, , $user, $data] = $this->fixture();
        $this->actingAs($user)->patch(route('invoices.update', $invoice), $data)
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', 'Invoice updated.')
            ->assertSessionMissing('error');
        $fresh = $invoice->fresh();
        $this->assertSame('Edited', $fresh->notes);
        $this->assertEquals(300, $fresh->subtotal);
        $this->assertEquals(300, $fresh->total);
        $this->assertEquals(60, $fresh->total_cost);
        $this->assertEquals(240, $fresh->margin);
        $this->assertCount(1, $fresh->lines);
        $this->assertEquals(3, $fresh->lines[0]->quantity);
        $this->assertEquals(360, $fresh->lines[0]->prepaid_time_minutes);
        $this->assertSame('Manual edit by '.$user->name.' on '.now()->format('Y-m-d'), $fresh->lines[0]->quantity_source);
    }
}
