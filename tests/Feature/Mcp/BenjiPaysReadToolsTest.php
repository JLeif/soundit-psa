<?php

namespace Tests\Feature\Mcp;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Mcp\BenjiPaysInvoiceTool;
use App\Services\Mcp\BenjiPaysPaymentMethodsTool;
use App\Services\Mcp\BenjiPaysTransactionsTool;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only BenjiPays MCP tools of card 6abec4f9 stage 1:
 * benjipays_list_transactions, benjipays_get_invoice and
 * benjipays_get_customer_payment_methods.
 *
 * Every call goes through POST /api/mcp/staff with its middleware, the REAL
 * BenjiPaysClient, fence and projection; only the vendor's HTTP answer is
 * faked (Http::preventStrayRequests, no live call). Fixtures carry EVERY
 * property of the documented item schema (developer.benjipays.com/reference:
 * get_v2-transactions TransactionListItem, get_v2-payment-methods
 * PaymentMethodListItem, get_v2-invoices-invoiceid InvoiceSummary; read
 * 2026-10-01), with the sensitive ones set to SENTINEL values so each
 * redaction test proves none of them leaves the tool. Names are synthetic.
 */
class BenjiPaysReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-read-key-never-real';

    private const CUST = 'QBO-CUST-77';

    private const SENTINELS = [
        'SENTINEL-CUSTOMER-NAME', 'SENTINEL-GATEWAY-MSG', 'SENTINEL-RECEIPT', 'SENTINEL-PAYREF',
        'SENTINEL-RAW-RESULT', 'SENTINEL-GWCUST', 'SENTINEL-NOTE', 'SENTINEL-MEMO', 'SENTINEL-REFERENCE',
        'SENTINEL-PARENT', 'SENTINEL-ACCTPAY', 'SENTINEL-BATCH', 'SENTINEL-GATEWAY-ID', 'SENTINEL-PM-ID',
        '203.0.113.77', '411111', '****1111', 'XXXX6789', '021000021',
    ];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->client = Client::factory()->create(['qbo_customer_id' => self::CUST]);
        $this->invoice($this->client, '1042', '22867');
    }

    private function invoice(Client $client, string $qboId, string $doc): Invoice
    {
        return Invoice::create([
            'client_id' => $client->id, 'invoice_number' => 'INV-'.$doc, 'qbo_invoice_id' => $qboId, 'qbo_doc_number' => $doc,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'subtotal' => '1.00', 'tax' => '0.00', 'total' => '1.00',
            'total_cost' => '0.00', 'margin' => '1.00', 'status' => InvoiceStatus::Synced,
        ]);
    }

    /** @return array<string, mixed> Every property of TransactionListItem. */
    private static function txn(array $overrides = []): array
    {
        return array_merge([
            'id' => 'SENTINEL-TXN-ID', 'type' => 'payment', 'amount' => 312.5, 'surchargeRate' => 3, 'surchargeAmount' => 9.1,
            'currency' => 'USD', 'approved' => true, 'status' => 'approved', 'paymentType' => 'cc',
            'details' => [
                'message' => 'SENTINEL-GATEWAY-MSG', 'maskedPan' => '411111******1111', 'cardType' => 'Visa',
                'receiptNumber' => 'SENTINEL-RECEIPT', 'paymentRef' => 'SENTINEL-PAYREF', 'account' => null,
            ],
            'paymentLocation' => 'customer portal', 'accountingPaymentId' => 'SENTINEL-ACCTPAY',
            'surchargeJournalEntryId' => 'SENTINEL-JE', 'surchargeJournalEntryNumber' => 'SENTINEL-JEN',
            'transactionDate' => '2026-09-29T17:04:11.000Z',
            'integrationData' => ['dataId' => 'SENTINEL-INTEG', 'integrationType' => 'quoter'],
            'paymentsToMake' => [[
                'invoiceId' => '1042', 'amount' => 312.5, 'invoiceNumber' => '22867', 'currency' => 'USD',
                'installmentDateId' => null, 'installmentDate' => null, 'installmentAmount' => null, 'date' => '2026-09-01',
            ]],
            'customerId' => self::CUST, 'voided' => false, 'voidTransactions' => null, 'refundTransactions' => null,
            'dueDate' => '2026-09-30', 'estimatedSettlementDate' => '2026-10-01', 'invoiceNumber' => '22867', 'invoiceId' => '1042',
            'customerName' => 'SENTINEL-CUSTOMER-NAME', 'result' => 'SENTINEL-RAW-RESULT', 'paymentSubType' => 'credit',
            'surchargeReportingAmount' => 9.1, 'commissions' => 1.25, 'expectedSettlementAmount' => 300.0,
            'gatewayId' => 'SENTINEL-GATEWAY-ID', 'gatewayProcessor' => 'benjipayments', 'settlementEligible' => true,
            'settlementStatus' => 'complete', 'settlementDate' => '2026-10-01T00:00:00.000Z', 'paymentApplied' => true,
            'batchRefNumber' => 'SENTINEL-BATCH',
        ], $overrides);
    }

    /** @return array<string, mixed> Every property of PaymentMethodListItem. */
    private static function pm(array $overrides = []): array
    {
        return array_merge([
            'id' => 'SENTINEL-PM-ID', 'accountingCompanyId' => 'SENTINEL-COMPANY', 'customerId' => self::CUST,
            'accountingType' => 'quickbooks', 'gatewayProcessor' => 'benjipayments', 'gatewayId' => 'SENTINEL-GATEWAY-ID',
            'gatewayCustomerId' => 'SENTINEL-GWCUST', 'customerName' => 'SENTINEL-CUSTOMER-NAME', 'maskedPan' => '****1111',
            'expiryMonth' => '08', 'expiryYear' => '2028', 'cardBrand' => 'Visa', 'paymentType' => 'cc', 'isDebitCard' => false,
            'autoPayEnabled' => true, 'autoPayPriority' => 1, 'benjiSurchargeEnabled' => true, 'note' => 'SENTINEL-NOTE',
            'declineCount' => 0, 'lastDeclineDate' => null, 'creatorIpAddress' => '203.0.113.77',
            'createdDate' => '2026-01-02T03:04:05.000Z', 'lastModifiedDate' => '2026-01-02T03:04:05.000Z',
        ], $overrides);
    }

    /** @return array<string, mixed> Every property of InvoiceSummary (no `accounting`: never requested). */
    private static function inv(array $overrides = []): array
    {
        $drop = $overrides['__drop'] ?? null;
        unset($overrides['__drop']);

        return array_diff_key(array_merge([
            'id' => '1042', 'invoiceNumber' => '22867', 'invoiceDate' => '2026-09-01', 'dueDate' => '2026-09-30',
            'createdDate' => '2026-09-01T10:00:00.000Z', 'total' => 500, 'balance' => 187.5, 'subtotal' => 480, 'taxTotal' => 20,
            'currency' => 'USD', 'status' => 'overdue', 'paid' => false, 'emailed' => true,
            'terms' => ['id' => '3', 'name' => 'Net 30'], 'brandingTheme' => null,
            'reference' => 'SENTINEL-REFERENCE', 'memo' => 'SENTINEL-MEMO', 'lastModifiedDate' => '2026-09-29T10:00:00.000Z',
            'customer' => ['id' => self::CUST, 'name' => 'SENTINEL-CUSTOMER-NAME', 'active' => true, 'isProject' => false,
                'parents' => [['id' => 'P1', 'name' => 'SENTINEL-PARENT']]],
        ], $overrides), $drop === null ? [] : [$drop => 1]);
    }

    /** @return array<string, mixed> */
    private static function page(array $items, bool $hasMore = false): array
    {
        return ['data' => $items, 'pagination' => [
            'total' => count($items) + ($hasMore ? 10 : 0), 'limit' => 25, 'offset' => 0,
            'hasMore' => $hasMore, 'nextOffset' => $hasMore ? 25 : null, 'prevOffset' => null,
        ]];
    }

    private function mcp(string $tool, array $arguments, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.($token ?? McpConfig::rotateStaffToken(allowedTools: [$tool], label: 'bp-reader'))]);
    }

    /** @return array<string, mixed> */
    private function answer(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->getContent());
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), (string) $response->getContent());

        return (string) $response->json('result.content.0.text');
    }

    private function assertOnlyGets(int $count): void
    {
        Http::assertSentCount($count);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->body() === '' && $r->hasHeader('x-api-key', self::KEY));
    }

    // ── benjipays_list_transactions ─────────────────────────────────────────

    public function test_transactions_happy_path_by_client_is_one_get_filtered_to_the_mapped_customer(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.benjipays.com/v2/transactions?customerId=QBO-CUST-77&sort=transactionDate&order=desc&limit=25&offset=0', $request->url());

            return Http::response(self::page([
                self::txn(),
                self::txn(['status' => 'declined', 'approved' => false, 'paymentType' => 'bank', 'surchargeAmount' => null, 'surchargeRate' => null,
                    'details' => ['message' => null, 'maskedPan' => null, 'cardType' => null, 'receiptNumber' => null, 'paymentRef' => null, 'account' => 'XXXX6789']]),
            ], true));
        });

        $answer = $this->answer($this->mcp(BenjiPaysTransactionsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertSame($this->client->id, $answer['client_id']);
        $this->assertSame(2, $answer['count']);
        $this->assertTrue($answer['has_more']);
        $this->assertSame([
            'date' => '2026-09-29T17:04:11.000Z', 'type' => 'payment', 'status' => 'approved', 'approved' => true,
            'amount' => 312.5, 'currency' => 'USD', 'surcharge_amount' => 9.1, 'surcharge_rate' => 3,
            'method_type' => 'card', 'last4' => '1111', 'invoice_id' => '1042', 'invoice_number' => '22867',
            'applied_to' => [['invoice_id' => '1042', 'invoice_number' => '22867', 'amount' => 312.5]],
        ], $answer['transactions'][0]);
        $this->assertSame(['declined', false, 'bank', '6789', null], [
            $answer['transactions'][1]['status'], $answer['transactions'][1]['approved'], $answer['transactions'][1]['method_type'],
            $answer['transactions'][1]['last4'], $answer['transactions'][1]['surcharge_amount'],
        ]);
        $this->assertOnlyGets(1);
    }

    public function test_transactions_by_doc_number_filter_to_that_invoice_of_its_own_client(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::txn()]))]);

        $answer = $this->answer($this->mcp(BenjiPaysTransactionsTool::NAME, ['doc_number' => '22867']));

        $this->assertSame('1042', $answer['qbo_invoice_id']);
        $this->assertSame($this->client->id, $answer['client_id']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/v2/transactions?customerId=QBO-CUST-77&invoiceId=1042&'));
        $this->assertOnlyGets(1);
    }

    public function test_transactions_redaction_strips_every_sensitive_field(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::txn()]))]);

        $response = $this->mcp(BenjiPaysTransactionsTool::NAME, ['client_id' => $this->client->id]);
        $body = (string) $response->getContent();
        $answer = $this->answer($response);

        foreach ([...self::SENTINELS, 'SENTINEL-TXN-ID', 'SENTINEL-JE', 'SENTINEL-INTEG', 'customerName', 'maskedPan', 'paymentRef', 'Visa'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "transactions output leaked {$needle}");
        }
        $this->assertSame(['date', 'type', 'status', 'approved', 'amount', 'currency', 'surcharge_amount', 'surcharge_rate',
            'method_type', 'last4', 'invoice_id', 'invoice_number', 'applied_to'], array_keys($answer['transactions'][0]));
    }

    public function test_transactions_limit_is_capped_at_the_max(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([]))]);

        $this->answer($this->mcp(BenjiPaysTransactionsTool::NAME, ['client_id' => $this->client->id, 'limit' => 1000]));

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '&limit=50&'));
        $this->assertOnlyGets(1);
    }

    public function test_transactions_a_row_for_another_customer_fails_the_whole_read(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::txn(), self::txn(['customerId' => 'QBO-OTHER'])]))]);

        $text = $this->refusal($this->mcp(BenjiPaysTransactionsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
        $this->assertStringNotContainsString('312.5', $text);
    }

    // ── benjipays_get_invoice ───────────────────────────────────────────────

    public function test_invoice_happy_path_reports_balance_status_and_partial_payment(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.benjipays.com/v2/invoices/1042', $request->url());

            return Http::response(['data' => self::inv()]);
        });

        $answer = $this->answer($this->mcp(BenjiPaysInvoiceTool::NAME, ['doc_number' => '22867']));

        $this->assertSame([
            'client_id' => $this->client->id, 'qbo_invoice_id' => '1042', 'invoice_number' => '22867',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'status' => 'overdue', 'paid' => false,
            'total' => 500, 'balance' => 187.5, 'subtotal' => 480, 'tax_total' => 20, 'currency' => 'USD', 'terms' => 'Net 30',
        ], array_diff_key($answer, ['source' => 1, 'read_at' => 1]));
        $this->assertOnlyGets(1);
    }

    public function test_invoice_redaction_strips_every_sensitive_field(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::inv()])]);

        $response = $this->mcp(BenjiPaysInvoiceTool::NAME, ['qbo_invoice_id' => '1042']);
        $this->answer($response);
        $body = (string) $response->getContent();

        foreach ([...self::SENTINELS, 'customer', 'memo', 'reference', 'parents'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "invoice output leaked {$needle}");
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function foreignInvoices(): array
    {
        return [
            'another customer' => [['customer' => ['id' => 'QBO-OTHER', 'name' => 'x', 'active' => true]]],
            'no customer' => [['customer' => null]],
            'another invoice' => [['id' => '9999']],
            'undocumented status' => [['status' => 'partial']],
            'balance as a string' => [['balance' => '187.50']],
            'balance absent' => [['__drop' => 'balance']],
        ];
    }

    #[DataProvider('foreignInvoices')]
    public function test_invoice_an_answer_not_provably_this_clients_invoice_fails_closed(array $overrides): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::inv($overrides)])]);

        $text = $this->refusal($this->mcp(BenjiPaysInvoiceTool::NAME, ['qbo_invoice_id' => '1042']));

        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
    }

    public function test_invoice_of_another_client_is_refused_before_any_vendor_call(): void
    {
        $other = Client::factory()->create(['qbo_customer_id' => 'QBO-OTHER']);
        $this->invoice($other, '2001', '30001');
        Http::fake();

        $text = $this->refusal($this->mcp(BenjiPaysInvoiceTool::NAME, ['client_id' => $this->client->id, 'qbo_invoice_id' => '2001']));

        $this->assertStringContainsString('does not belong to client_id', $text);
        Http::assertNothingSent();
    }

    // ── benjipays_get_customer_payment_methods ──────────────────────────────

    public function test_payment_methods_happy_path_is_one_get_for_the_mapped_customer(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.benjipays.com/v2/payment-methods?customerId=QBO-CUST-77&limit=25&offset=0', $request->url());

            return Http::response(self::page([
                self::pm(),
                self::pm(['paymentType' => 'bank', 'maskedPan' => 'XXXX6789', 'cardBrand' => null, 'expiryMonth' => null, 'expiryYear' => null,
                    'autoPayEnabled' => false, 'autoPayPriority' => 2]),
            ]));
        });

        $answer = $this->answer($this->mcp(BenjiPaysPaymentMethodsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertSame([
            ['method_type' => 'card', 'brand' => 'Visa', 'last4' => '1111', 'expiry_month' => '08', 'expiry_year' => '2028', 'autopay_enabled' => true, 'autopay_priority' => 1],
            ['method_type' => 'bank', 'brand' => null, 'last4' => '6789', 'expiry_month' => null, 'expiry_year' => null, 'autopay_enabled' => false, 'autopay_priority' => 2],
        ], $answer['payment_methods']);
        $this->assertFalse($answer['has_more']);
        $this->assertOnlyGets(1);
    }

    public function test_payment_methods_redaction_strips_every_sensitive_field(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::pm()]))]);

        $response = $this->mcp(BenjiPaysPaymentMethodsTool::NAME, ['client_id' => $this->client->id]);
        $this->answer($response);
        $body = (string) $response->getContent();

        foreach ([...self::SENTINELS, 'SENTINEL-COMPANY', 'creatorIpAddress', 'gatewayCustomerId', 'note'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "payment-method output leaked {$needle}");
        }
    }

    public function test_payment_methods_limit_is_capped_and_a_foreign_row_fails_closed(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::sequence()
            ->push(self::page([]))
            ->push(self::page([self::pm(['customerId' => 'QBO-OTHER'])]))]);

        $this->answer($this->mcp(BenjiPaysPaymentMethodsTool::NAME, ['client_id' => $this->client->id, 'limit' => 999]));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '&limit=25&'));

        $text = $this->refusal($this->mcp(BenjiPaysPaymentMethodsTool::NAME, ['client_id' => $this->client->id]));
        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
        $this->assertOnlyGets(2);
    }

    public function test_payment_methods_require_a_client_id(): void
    {
        Http::fake();

        $this->refusal($this->mcp(BenjiPaysPaymentMethodsTool::NAME, []));

        Http::assertNothingSent();
    }

    // ── every tool ──────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> tool, scope, a mapped-client call */
    public static function tools(): array
    {
        return [
            'transactions' => [BenjiPaysTransactionsTool::NAME, 'organizations:transactions:read', ['client_id' => 0]],
            'invoice' => [BenjiPaysInvoiceTool::NAME, 'organizations:invoices:read', ['qbo_invoice_id' => '1042']],
            'payment methods' => [BenjiPaysPaymentMethodsTool::NAME, 'organizations:payment-methods:read', ['client_id' => 0]],
        ];
    }

    /** client_id 0 in the provider is a placeholder for the fixture client, which only exists after setUp. */
    private function args(array $arguments): array
    {
        return ($arguments['client_id'] ?? null) === 0 ? ['client_id' => $this->client->id] + $arguments : $arguments;
    }

    #[DataProvider('tools')]
    public function test_an_unmapped_client_is_refused_without_a_vendor_call(string $tool, string $scope, array $arguments): void
    {
        $this->client->update(['qbo_customer_id' => null]);
        Http::fake();

        $text = $this->refusal($this->mcp($tool, $this->args($arguments)));

        $this->assertStringContainsString('has no QuickBooks customer mapping', $text);
        Http::assertNothingSent();
    }

    #[DataProvider('tools')]
    public function test_an_unknown_client_or_invoice_is_refused_without_a_vendor_call(string $tool, string $scope, array $arguments): void
    {
        Http::fake();

        $arguments = isset($arguments['client_id']) ? ['client_id' => 999999] : ['qbo_invoice_id' => 'NOT-A-PSA-INVOICE'];
        $text = $this->refusal($this->mcp($tool, $arguments));

        $this->assertStringContainsString(isset($arguments['client_id']) ? 'No PSA client with id 999999.' : 'No PSA invoice carries that QuickBooks id.', $text);
        Http::assertNothingSent();
    }

    #[DataProvider('tools')]
    public function test_a_403_names_the_missing_scope(string $tool, string $scope, array $arguments): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'https://api.benjipays.com/problems/forbidden', 'title' => 'Forbidden', 'status' => 403,
            'detail' => 'VENDOR-DETAIL-SENTINEL', 'instance' => '/v2/x',
        ], 403, ['Content-Type' => 'application/problem+json'])]);

        $response = $this->mcp($tool, $this->args($arguments));
        $text = $this->refusal($response);

        $this->assertStringContainsString('the BenjiPays API key lacks the '.$scope.' scope', $text);
        $this->assertStringContainsString('Ask Charlie', $text);
        $this->assertStringNotContainsString('VENDOR-', (string) $response->getContent());
        $this->assertOnlyGets(1);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}> */
    public static function toolsAndFailures(): array
    {
        $out = [];
        foreach (self::tools() as $label => $row) {
            foreach ([500, 502, 429, 404, 401] as $status) {
                $out[$label.' '.$status] = [...$row, $status];
            }
        }

        return $out;
    }

    #[DataProvider('toolsAndFailures')]
    public function test_a_vendor_failure_is_status_only(string $tool, string $scope, array $arguments, int $status): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'x', 'title' => 'VENDOR-TITLE-SENTINEL', 'status' => $status, 'detail' => 'VENDOR-DETAIL-SENTINEL 4111111111111111',
        ], $status)]);

        $response = $this->mcp($tool, $this->args($arguments));
        $text = $this->refusal($response);

        $this->assertStringContainsString('(HTTP '.$status.')', $text);
        foreach (['VENDOR-', '4111111111111111', $scope] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent());
        }
        $this->assertOnlyGets(1);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badShapes(): array
    {
        return [
            'transactions bare list' => [BenjiPaysTransactionsTool::NAME, [self::txn()]],
            'transactions no pagination' => [BenjiPaysTransactionsTool::NAME, ['data' => [self::txn()]]],
            'transactions unknown paymentType' => [BenjiPaysTransactionsTool::NAME, self::page([self::txn(['paymentType' => 'crypto'])])],
            'transactions amount as string' => [BenjiPaysTransactionsTool::NAME, self::page([self::txn(['amount' => '312.50'])])],
            'payment methods data object' => [BenjiPaysPaymentMethodsTool::NAME, ['data' => self::pm(), 'pagination' => []]],
            'payment methods no autopay flag' => [BenjiPaysPaymentMethodsTool::NAME, self::page([array_diff_key(self::pm(), ['autoPayEnabled' => 1])])],
            'payment methods no customerId' => [BenjiPaysPaymentMethodsTool::NAME, self::page([array_diff_key(self::pm(), ['customerId' => 1])])],
            'invoice bare object' => [BenjiPaysInvoiceTool::NAME, self::inv()],
        ];
    }

    #[DataProvider('badShapes')]
    public function test_an_undocumented_shape_fails_closed(string $tool, mixed $payload): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response($payload)]);

        $arguments = $tool === BenjiPaysInvoiceTool::NAME ? ['qbo_invoice_id' => '1042'] : ['client_id' => $this->client->id];
        $text = $this->refusal($this->mcp($tool, $arguments));

        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function badArguments(): array
    {
        return [
            'transactions nothing' => [BenjiPaysTransactionsTool::NAME, []],
            'transactions malformed client_id' => [BenjiPaysTransactionsTool::NAME, ['client_id' => 'abc']],
            'transactions both invoice ids' => [BenjiPaysTransactionsTool::NAME, ['qbo_invoice_id' => '1042', 'doc_number' => '22867']],
            'transactions limit zero' => [BenjiPaysTransactionsTool::NAME, ['qbo_invoice_id' => '1042', 'limit' => 0]],
            'invoice nothing' => [BenjiPaysInvoiceTool::NAME, []],
            'invoice blank id' => [BenjiPaysInvoiceTool::NAME, ['qbo_invoice_id' => ' ']],
            'payment methods malformed client_id' => [BenjiPaysPaymentMethodsTool::NAME, ['client_id' => -3]],
            'raw vendor customer id' => [BenjiPaysPaymentMethodsTool::NAME, ['customer_id' => self::CUST]],
            'raw vendor customerId' => [BenjiPaysTransactionsTool::NAME, ['customerId' => self::CUST]],
        ];
    }

    #[DataProvider('badArguments')]
    public function test_bad_or_raw_vendor_arguments_are_refused_without_a_vendor_call(string $tool, array $arguments): void
    {
        Http::fake();

        $this->refusal($this->mcp($tool, $arguments));

        Http::assertNothingSent();
    }

    public function test_no_stored_key_is_refused_without_a_vendor_call(): void
    {
        Setting::where('key', 'benjipays_api_key')->delete();
        Http::fake();

        $this->assertStringContainsString('not configured', $this->refusal($this->mcp(BenjiPaysTransactionsTool::NAME, ['client_id' => $this->client->id])));
        Http::assertNothingSent();
    }

    #[DataProvider('tools')]
    public function test_each_tool_is_a_psa_read_that_needs_an_explicit_grant(string $tool, string $scope, array $arguments): void
    {
        Http::fake();
        $this->assertContains($tool, array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));

        $ungranted = McpConfig::rotateStaffToken(allowedTools: ['psa_version'], label: 'other');
        foreach ([McpConfig::rotateStaffToken(), $ungranted] as $token) {
            $this->assertStringContainsString('not allowed for this token', $this->refusal($this->mcp($tool, $this->args($arguments), $token)));
        }
        Http::assertNothingSent();

        $matches = json_decode((string) $this->mcp('search_tools', ['query' => $tool], $ungranted)->json('result.content.0.text'), true)['matches'] ?? [];
        $this->assertSame('available_ungranted', collect($matches)->firstWhere('name', $tool)['grant_state'] ?? null, json_encode($matches));
    }
}
