<?php

namespace Tests\Feature\Qbo;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QboInvoiceDescriptionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        return Invoice::create([
            'client_id' => Client::factory()->create()->id,
            'invoice_number' => 'SYNTHETIC-1213',
            'invoice_date' => now()->subDay(),
            'due_date' => now(),
            'subtotal' => 30, 'tax' => 0, 'total' => 30,
            'status' => InvoiceStatus::Paid,
            'qbo_invoice_id' => 'synthetic-1213',
        ]);
    }

    private function mockLines(array $descriptions): void
    {
        // Synthetic value variations on the existing QBO invoice fixture contract.
        $lines = array_map(fn ($description) => array_merge([
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => 10,
            'SalesItemLineDetail' => ['Qty' => 2, 'UnitPrice' => 5],
        ], $description === null ? [] : ['Description' => $description]), $descriptions);
        $this->mock(QboClient::class)->shouldReceive('get')->once()
            ->with('invoice/synthetic-1213')->andReturn(['Invoice' => [
                'TotalAmt' => 30, 'Balance' => 0, 'Line' => $lines,
            ]]);
    }

    private function captureLog(): array
    {
        $stream = fopen('php://memory', 'w+');
        Log::swap(new Logger('synthetic-qbo', [new StreamHandler($stream)]));

        return [$stream];
    }

    public static function descriptions(): array
    {
        return [
            'short positive control' => ['Synthetic service', 'Synthetic service'],
            'exact boundary' => [str_repeat('x', 255), str_repeat('x', 255)],
            'ASCII overflow' => [str_repeat('x', 300), str_repeat('x', 255)],
            'multibyte overflow' => [str_repeat('界🙂', 160), mb_substr(str_repeat('界🙂', 160), 0, 255, 'UTF-8')],
            'empty preserved' => ['', ''],
            'missing preserved' => [null, 'original'],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_bounded_write_finishes_every_line_without_logging_values(?string $description, string $expected): void
    {
        $this->assertSame(realpath(base_path('app/Services/Qbo/QboSyncService.php')), (new \ReflectionClass(QboSyncService::class))->getFileName());
        $invoice = $this->invoice();
        foreach (range(0, 2) as $i) {
            $invoice->lines()->create(['description' => 'original', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1, 'sort_order' => $i]);
        }
        // SQLite does not enforce VARCHAR lengths. Enforce the column's character
        // contract at the actual SQL UPDATE, not in a mock of the service.
        DB::unprepared("CREATE TRIGGER qbo_description_length BEFORE UPDATE ON invoice_lines WHEN length(NEW.description) > 255 BEGIN SELECT RAISE(ABORT, 'synthetic description overflow'); END");
        $this->mockLines(['first synthetic', $description, 'last synthetic']);
        [$stream] = $this->captureLog();

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo(1);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['errors']);
        $lines = $invoice->lines()->orderBy('sort_order')->get();
        $this->assertSame(['first synthetic', $expected, 'last synthetic'], $lines->pluck('description')->all());
        foreach ($lines as $line) {
            $this->assertSame('10.00', $line->amount);
            $this->assertSame('2.00', $line->quantity);
            $this->assertTrue(mb_check_encoding($line->description, 'UTF-8'));
            $this->assertLessThanOrEqual(255, mb_strlen($line->description, 'UTF-8'));
        }
        rewind($stream);
        $log = stream_get_contents($stream);
        $this->assertStringContainsString('Paid-invoice re-check pass complete', $log);
        $this->assertStringNotContainsString('first synthetic', $log);
        $this->assertStringNotContainsString('last synthetic', $log);
        fclose($stream);
    }

    public function test_non_line_failure_logs_only_structural_metadata(): void
    {
        $invoice = $this->invoice();
        $this->mock(QboClient::class)->shouldReceive('get')->once()
            ->with('invoice/synthetic-1213')->andThrow(new \RuntimeException('SYNTHETIC-PRIVATE-RESPONSE'));
        [$stream] = $this->captureLog();

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo(1);

        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['checked']);
        rewind($stream);
        $log = stream_get_contents($stream);
        $this->assertStringContainsString('"invoice_id":'.$invoice->id, $log);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-RESPONSE', $log);
        $this->assertStringNotContainsString('SYNTHETIC-1213', $log);
        $this->assertStringContainsString('"line_index":null', $log);
        $this->assertStringContainsString('"exception_class":"RuntimeException"', $log);
        fclose($stream);
    }

    public function test_direct_caller_cannot_report_sql_or_previous_exception(): void
    {
        $invoice = $this->invoice();
        $invoice->lines()->create(['description' => 'original', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1]);
        $this->mockLines(['SYNTHETIC-PRIVATE-DESCRIPTION']);
        DB::unprepared("CREATE TRIGGER qbo_forced_failure BEFORE UPDATE ON invoice_lines BEGIN SELECT RAISE(ABORT, 'SYNTHETIC-SQL-FAILURE'); END");

        try {
            app(QboSyncService::class)->syncInvoiceStatusFromQbo($invoice);
            $this->fail('Expected the real line UPDATE to fail');
        } catch (\App\Services\Qbo\QboLineSyncException $e) {
            $this->assertSame('QBO invoice line sync failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertSame(0, $e->lineIndex);
            $this->assertSame(\Illuminate\Database\QueryException::class, $e->exceptionClass);
        }
    }

    public function test_database_failure_logs_only_structural_metadata(): void
    {
        $invoice = $this->invoice();
        $line = $invoice->lines()->create(['description' => 'original', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1]);
        $sentinel = 'SYNTHETIC-PRIVATE-DESCRIPTION';
        $this->mockLines([$sentinel]);
        [$stream] = $this->captureLog();
        DB::unprepared("CREATE TRIGGER qbo_forced_failure BEFORE UPDATE ON invoice_lines BEGIN SELECT RAISE(ABORT, 'SYNTHETIC-SQL-FAILURE'); END");

        $result = app(QboSyncService::class)->syncPaidInvoicesFromQbo(1);

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('original', InvoiceLine::findOrFail($line->id)->description);
        rewind($stream);
        $log = stream_get_contents($stream);
        $this->assertStringContainsString('Failed to re-check paid invoice', $log);
        $this->assertStringContainsString('"invoice_id":'.$invoice->id, $log);
        foreach ([$sentinel, 'SYNTHETIC-SQL-FAILURE', 'SQLSTATE', 'update "invoice_lines"', 'SYNTHETIC-1213'] as $private) {
            $this->assertStringNotContainsString($private, $log);
        }
        $this->assertStringContainsString('"line_index":0', $log);
        $this->assertStringContainsString('QueryException', $log);
        fclose($stream);
    }
}
