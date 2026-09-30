<?php

namespace Tests\Feature\Qbo;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\RecurringInvoiceProfile;
use App\Models\Setting;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Autopay skip via a QBO payment term (card revwQxh4).
 *
 * A NON-recurring invoice (profile_id null, the #736 discriminator) is pushed
 * with SalesTermRef = `qbo_nonrecurring_sales_term_id`; the operator switches
 * that term off in the payment processor's Invoice Skip Settings. Recurring
 * invoices send no SalesTermRef. An empty setting is a no-op. The PSA's own
 * DueDate is still sent beside the term, so the due date does not move.
 *
 * The skip memo (#736) is untouched and stays the interim guard; it is
 * configured here too so each test also proves the term does not disturb it.
 */
class QboInvoiceSalesTermTest extends TestCase
{
    use RefreshDatabase;

    private const TERM_ID = '7';

    private const SKIP_MEMO = 'Not auto-charged - pay by check or portal';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.qbo_nonrecurring_skip_memo' => self::SKIP_MEMO]);
    }

    private function setTerm(?string $value): void
    {
        if ($value !== null) {
            Setting::setValue(QboSyncService::NONRECURRING_SALES_TERM_SETTING, $value);
        }
    }

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-1'])->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-TERM-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Laptop',
            'quantity' => 1,
            'unit_price' => '500.00',
            'unit_cost' => '200.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            'is_taxable' => false,
            'sort_order' => 0,
        ]);

        return $invoice->fresh();
    }

    private function makeRecurringInvoice(array $attrs = []): Invoice
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-2']);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => '2026-01-01',
        ]);
        $profile = RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-10-01',
        ]);

        return $this->makeInvoice(array_merge(['client_id' => $client->id, 'profile_id' => $profile->id], $attrs));
    }

    /** Mock the QBO client, capturing every posted invoice payload into $posts. */
    private function mockQboClient(array &$posts, array $currentInvoice = []): void
    {
        $this->mock(QboClient::class, function (MockInterface $m) use (&$posts, $currentInvoice): void {
            if ($currentInvoice !== []) {
                $m->shouldReceive('get')->andReturn(['Invoice' => $currentInvoice]);
            }
            $m->shouldReceive('post')->andReturnUsing(function ($path, $payload) use (&$posts) {
                $posts[] = $payload;

                return [
                    'Invoice' => [
                        'Id' => $payload['Id'] ?? '9001',
                        'DocNumber' => 'DOC-9001',
                        'TotalAmt' => 500.0,
                        'TxnTaxDetail' => ['TotalTax' => 0],
                    ],
                ];
            });
        });
    }

    /** @return list<array<string, mixed>> */
    private function push(Invoice $invoice, array $currentInvoice = []): array
    {
        $posts = [];
        $this->mockQboClient($posts, $currentInvoice);
        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        return $posts;
    }

    // ── non-recurring gets the term ──

    public function test_create_sends_the_term_on_a_non_recurring_invoice(): void
    {
        $this->setTerm(self::TERM_ID);
        $posts = $this->push($this->makeInvoice());

        $this->assertCount(1, $posts);
        $this->assertSame(['value' => self::TERM_ID], $posts[0]['SalesTermRef'] ?? null);
        // DueDate wins: the PSA's own due date is still sent beside the term.
        $this->assertSame('2026-09-15', $posts[0]['DueDate']);
        // The interim memo guard is untouched.
        $this->assertSame(['value' => self::SKIP_MEMO], $posts[0]['CustomerMemo'] ?? null);
    }

    public function test_update_sends_the_term_on_a_non_recurring_invoice(): void
    {
        $this->setTerm(self::TERM_ID);
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7780', 'status' => InvoiceStatus::Synced]);
        $posts = $this->push($invoice, ['Id' => '7780', 'SyncToken' => '4']);

        $this->assertCount(1, $posts);
        $this->assertSame('7780', $posts[0]['Id']);
        $this->assertSame(['value' => self::TERM_ID], $posts[0]['SalesTermRef'] ?? null);
        $this->assertSame('2026-09-15', $posts[0]['DueDate']);
    }

    public function test_update_replaces_a_different_term_already_on_the_qbo_invoice(): void
    {
        // QBO shows the customer's default (e.g. Net 30, id 3); the push must
        // send the configured term, not echo the one it read back.
        $this->setTerm(self::TERM_ID);
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7781', 'status' => InvoiceStatus::Synced]);
        $posts = $this->push($invoice, ['Id' => '7781', 'SyncToken' => '1', 'SalesTermRef' => ['value' => '3', 'name' => 'Net 30']]);

        $this->assertSame(['value' => self::TERM_ID], $posts[0]['SalesTermRef'] ?? null);
    }

    public function test_the_setting_value_is_trimmed(): void
    {
        $this->setTerm('  '.self::TERM_ID."\n");
        $posts = $this->push($this->makeInvoice());

        $this->assertSame(['value' => self::TERM_ID], $posts[0]['SalesTermRef'] ?? null);
    }

    // ── recurring gets no term ──

    public function test_create_sends_no_term_on_a_recurring_invoice(): void
    {
        $this->setTerm(self::TERM_ID);
        $posts = $this->push($this->makeRecurringInvoice());

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('SalesTermRef', $posts[0]);
        $this->assertArrayNotHasKey('CustomerMemo', $posts[0]);
    }

    public function test_update_sends_no_term_on_a_recurring_invoice(): void
    {
        $this->setTerm(self::TERM_ID);
        $invoice = $this->makeRecurringInvoice(['qbo_invoice_id' => '7782', 'status' => InvoiceStatus::Synced]);
        $posts = $this->push($invoice, ['Id' => '7782', 'SyncToken' => '0', 'SalesTermRef' => ['value' => '3']]);

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('SalesTermRef', $posts[0]);
    }

    // ── empty setting = exactly today's payload ──

    /**
     * The payload the builder produced at base 77b4b01c for this fixture, key
     * order included, written out by hand from that builder: CustomerRef,
     * DocNumber, TxnDate, DueDate, Line, then CustomerMemo when stamped.
     *
     * @return array<string, mixed>
     */
    private function todaysCreatePayload(Invoice $invoice): array
    {
        return [
            'CustomerRef' => ['value' => 'QBO-CUST-1'],
            'DocNumber' => $invoice->invoice_number,
            'TxnDate' => '2026-09-01',
            'DueDate' => '2026-09-15',
            'Line' => [[
                'Amount' => 500.0,
                'DetailType' => 'SalesItemLineDetail',
                'Description' => 'Laptop',
                'SalesItemLineDetail' => [
                    'Qty' => 1.0,
                    'UnitPrice' => 500.0,
                    'TaxCodeRef' => ['value' => 'NON'],
                ],
            ]],
            'CustomerMemo' => ['value' => self::SKIP_MEMO],
        ];
    }

    /** @return array<string, array{0: ?string}> */
    public static function emptySettings(): array
    {
        return [
            'no row' => [null],
            'empty string' => [''],
            'whitespace only' => ["  \t\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptySettings')]
    public function test_an_empty_setting_sends_a_byte_identical_create_payload(?string $value): void
    {
        $this->setTerm($value);
        $invoice = $this->makeInvoice();
        $posts = $this->push($invoice);

        $this->assertCount(1, $posts);
        $this->assertSame(
            json_encode($this->todaysCreatePayload($invoice), JSON_THROW_ON_ERROR),
            json_encode($posts[0], JSON_THROW_ON_ERROR),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptySettings')]
    public function test_an_empty_setting_sends_a_byte_identical_update_payload(?string $value): void
    {
        $this->setTerm($value);
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7783', 'status' => InvoiceStatus::Synced]);
        $posts = $this->push($invoice, ['Id' => '7783', 'SyncToken' => '5']);

        $expected = $this->todaysCreatePayload($invoice);
        unset($expected['CustomerMemo']);
        // Update path at base: Id, SyncToken appended, then the memo decision
        // unsets and re-adds CustomerMemo, so it lands last.
        $expected += ['Id' => '7783', 'SyncToken' => '5', 'CustomerMemo' => ['value' => self::SKIP_MEMO]];

        $this->assertCount(1, $posts);
        $this->assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($posts[0], JSON_THROW_ON_ERROR));
    }

    // ── 409 retry keeps the term ──

    public function test_409_retry_keeps_the_term(): void
    {
        $this->setTerm(self::TERM_ID);
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7784', 'status' => InvoiceStatus::Synced]);
        $posts = [];

        $this->mock(QboClient::class, function (MockInterface $m) use (&$posts): void {
            $m->shouldReceive('get')->once()->ordered()
                ->andReturn(['Invoice' => ['Id' => '7784', 'SyncToken' => '2']]);
            $m->shouldReceive('post')->once()->ordered()
                ->andReturnUsing(function ($path, $payload) use (&$posts) {
                    $posts[] = $payload;
                    throw new QboClientException('conflict', 409);
                });
            // The refetch shows someone set a different term in QBO meanwhile.
            $m->shouldReceive('get')->once()->ordered()->andReturn([
                'Invoice' => ['Id' => '7784', 'SyncToken' => '3', 'SalesTermRef' => ['value' => '3']],
            ]);
            $m->shouldReceive('post')->once()->ordered()
                ->andReturnUsing(function ($path, $payload) use (&$posts) {
                    $posts[] = $payload;

                    return ['Invoice' => ['Id' => '7784', 'TotalAmt' => 500.0, 'TxnTaxDetail' => ['TotalTax' => 0]]];
                });
        });

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(2, $posts);
        $this->assertSame('2', $posts[0]['SyncToken']);
        $this->assertSame('3', $posts[1]['SyncToken']);
        $this->assertSame(['value' => self::TERM_ID], $posts[0]['SalesTermRef'] ?? null);
        $this->assertSame(['value' => self::TERM_ID], $posts[1]['SalesTermRef'] ?? null);
        $this->assertSame('2026-09-15', $posts[1]['DueDate']);
    }
}
