<?php

namespace Tests\Feature\Mcp;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Mcp\BenjiPaysForecastTool;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * benjipays_autopay_forecast on the staff MCP surface (card revwQxh4).
 *
 * The call goes through POST /api/mcp/staff with its middleware, the REAL
 * BenjiPaysClient and the REAL redaction; only the vendor's HTTP answer is
 * faked. The item fixture carries EVERY field of the vendor's
 * AutoprocessingForecastItem schema (developer.benjipays.com/reference/
 * get_v2-autoprocessing-forecast, all fields required,
 * additionalProperties false), with the payment-bearing ones set to sentinel
 * values so the redaction test can prove none of them leaves the tool.
 * No live BenjiPays call is made (Http::preventStrayRequests).
 */
class BenjiPaysForecastToolTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-forecast-key-never-real';

    private const CUSTOMER_NAME = 'Sentinel Customer Name LLC';

    private const CUSTOMER_ID = 'CUST-SENTINEL-4471';

    private const AMOUNT = 1234.56;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
    }

    /** @return array<string, mixed> Every field of AutoprocessingForecastItem. */
    private static function item(array $overrides = []): array
    {
        return array_merge([
            'id' => '1042',
            'invoiceNumber' => '22867',
            'customerId' => self::CUSTOMER_ID,
            'customerName' => self::CUSTOMER_NAME,
            'currency' => 'USD',
            'dueDate' => '2026-09-30',
            'amountToCharge' => self::AMOUNT,
            'willBeCharged' => false,
            'dueDateMet' => true,
            'autoProcessingEnabled' => true,
            'hasEnabledProfile' => true,
            'hasInstallments' => false,
            'installmentDateMet' => null,
            'startDateMet' => true,
            'memoSkip' => false,
            'amountSkip' => false,
            'reasons' => ['Skipped: payment term "Due on receipt - manual pay" is excluded from auto-processing'],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private static function envelope(array $items): array
    {
        return ['data' => $items, 'pagination' => [
            'total' => count($items), 'limit' => 20, 'offset' => 0,
            'hasMore' => false, 'nextOffset' => null, 'prevOffset' => null,
        ]];
    }

    private function grantedToken(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: [BenjiPaysForecastTool::NAME], label: 'forecast-reader');
    }

    private function mcpCall(array $arguments, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => BenjiPaysForecastTool::NAME, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.($token ?? $this->grantedToken())]);
    }

    /** @return array<string, mixed> */
    private function answer(TestResponse $response): array
    {
        $response->assertOk();
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_skipped_by_term_reports_skipped_and_the_vendor_reason(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://api.benjipays.com/v2/autoprocessing-forecast?startDate=2026-10-01&invoiceId=1042', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', self::KEY));
            $this->assertSame('', $request->body());

            return Http::response(self::envelope([self::item()]));
        });

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']);
        $answer = $this->answer($response);

        $this->assertFalse((bool) $response->json('result.isError'));
        $this->assertSame('skipped', $answer['status']);
        $this->assertFalse($answer['will_be_charged']);
        $this->assertFalse($answer['memo_skip']);
        $this->assertSame('1042', $answer['qbo_invoice_id']);
        $this->assertSame('22867', $answer['doc_number']);
        $this->assertSame('2026-10-01', $answer['run_date']);
        $this->assertSame(['Skipped: payment term "Due on receipt - manual pay" is excluded from auto-processing'], $answer['reasons']);
        Http::assertSentCount(1);
    }

    public function test_skipped_by_memo_reports_the_memo_flag(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::envelope([
            self::item(['memoSkip' => true, 'reasons' => ['Memo skip rule matched']]),
        ]))]);

        $answer = $this->answer($this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']));

        $this->assertSame('skipped', $answer['status']);
        $this->assertTrue($answer['memo_skip']);
        $this->assertSame(['Memo skip rule matched'], $answer['reasons']);
    }

    public function test_scheduled_reports_scheduled(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::envelope([
            self::item(['willBeCharged' => true, 'reasons' => ['All conditions met']]),
        ]))]);

        $answer = $this->answer($this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']));

        $this->assertSame('scheduled', $answer['status']);
        $this->assertTrue($answer['will_be_charged']);
    }

    public function test_not_found_is_not_in_forecast_and_never_skipped(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::envelope([]))]);

        $response = $this->mcpCall(['qbo_invoice_id' => '404404', 'run_date' => '2026-10-01']);
        $answer = $this->answer($response);

        $this->assertSame('not_in_forecast', $answer['status']);
        $this->assertNull($answer['will_be_charged']);
        $this->assertSame([], $answer['reasons']);
    }

    public function test_doc_number_resolves_through_the_psa_invoice(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-1']);
        Invoice::create([
            'client_id' => $client->id, 'invoice_number' => 'INV-22867', 'qbo_invoice_id' => '1042', 'qbo_doc_number' => '22867',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'subtotal' => '1.00', 'tax' => '0.00', 'total' => '1.00',
            'total_cost' => '0.00', 'margin' => '1.00', 'status' => InvoiceStatus::Synced,
        ]);
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::envelope([self::item()]))]);

        $answer = $this->answer($this->mcpCall(['doc_number' => '22867', 'run_date' => '2026-10-01']));

        $this->assertSame('1042', $answer['qbo_invoice_id']);
        $this->assertSame('skipped', $answer['status']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'invoiceId=1042'));
    }

    public function test_an_unknown_doc_number_is_refused_without_a_vendor_call(): void
    {
        Http::fake();

        $response = $this->mcpCall(['doc_number' => 'NOPE-1']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('No PSA invoice with that DocNumber', (string) $response->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    public function test_a_403_names_the_missing_scope_plainly(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'https://api.benjipays.com/problems/forbidden', 'title' => 'Forbidden', 'status' => 403,
            'detail' => 'VENDOR-DETAIL-SENTINEL missing scope', 'instance' => '/v2/autoprocessing-forecast',
        ], 403, ['Content-Type' => 'application/problem+json'])]);

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('the BenjiPays API key lacks the organizations:autoprocessing:read scope', $text);
        $this->assertStringContainsString('Ask Charlie', $text);
        $this->assertStringNotContainsString('VENDOR-DETAIL-SENTINEL', (string) $response->getContent());
    }

    /** @return array<string, array{0: int}> */
    public static function otherStatuses(): array
    {
        return ['400' => [400], '401' => [401], '404' => [404], '429' => [429], '500' => [500]];
    }

    #[DataProvider('otherStatuses')]
    public function test_other_errors_are_status_only(int $status): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'x', 'title' => 'VENDOR-TITLE-SENTINEL', 'status' => $status, 'detail' => 'VENDOR-DETAIL-SENTINEL',
        ], $status)]);

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('(HTTP '.$status.')', (string) $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('VENDOR-', $body);
        $this->assertStringNotContainsString('autoprocessing:read', $body);
    }

    public function test_no_payment_or_customer_details_leave_the_tool(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::envelope([self::item([
            'reasons' => ['Card 4111 1111 1111 1111 declined previously', "Line\nbreak\x07"],
        ])]))]);

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']);
        $answer = $this->answer($response);
        $body = (string) $response->getContent();

        $this->assertSame([
            'qbo_invoice_id', 'doc_number', 'run_date', 'status', 'will_be_charged', 'memo_skip',
            'amount_skip', 'due_date', 'due_date_met', 'reasons', 'source', 'read_at',
        ], array_keys($answer));
        foreach ([self::CUSTOMER_NAME, self::CUSTOMER_ID, '1234.56', 'USD', 'hasEnabledProfile', 'amountToCharge', 'customer', '4111'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "forecast output leaked {$needle}");
        }
        $this->assertSame(['Card [redacted number] declined previously', 'Line break'], $answer['reasons']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unknownShapes(): array
    {
        return [
            'bare list' => [[self::item()]],
            'no pagination' => [['data' => [self::item()]]],
            'data is an object' => [['data' => self::item(), 'pagination' => []]],
            'willBeCharged missing' => [self::envelope([array_diff_key(self::item(), ['willBeCharged' => 1])])],
            'willBeCharged a string' => [self::envelope([self::item(['willBeCharged' => 'false'])])],
            'memoSkip missing' => [self::envelope([array_diff_key(self::item(), ['memoSkip' => 1])])],
            'reasons missing' => [self::envelope([array_diff_key(self::item(), ['reasons' => 1])])],
            'reasons holds a non-string' => [self::envelope([self::item(['reasons' => [['x']]])])],
            'a different invoice' => [self::envelope([self::item(['id' => '9999'])])],
            'id null' => [self::envelope([self::item(['id' => null])])],
            'two items' => [self::envelope([self::item(), self::item()])],
        ];
    }

    #[DataProvider('unknownShapes')]
    public function test_an_unknown_response_shape_fails_closed(mixed $payload): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response($payload)]);

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'run_date' => '2026-10-01']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('BenjiPays returned an unusable response', (string) $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('"status":"', (string) $response->json('result.content.0.text'));
    }

    public function test_no_stored_key_is_refused_without_a_vendor_call(): void
    {
        Setting::where('key', 'benjipays_api_key')->delete();
        Http::fake();

        $response = $this->mcpCall(['qbo_invoice_id' => '1042']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not configured', (string) $response->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badArguments(): array
    {
        return [
            'neither id' => [[]],
            'both ids' => [['qbo_invoice_id' => '1', 'doc_number' => '2']],
            'blank id' => [['qbo_invoice_id' => '  ']],
            'bad date' => [['qbo_invoice_id' => '1042', 'run_date' => '2026-02-30']],
        ];
    }

    #[DataProvider('badArguments')]
    public function test_bad_arguments_are_refused_without_a_vendor_call(array $arguments): void
    {
        Http::fake();

        $response = $this->mcpCall($arguments);

        $this->assertTrue((bool) $response->json('result.isError'));
        Http::assertNothingSent();
    }

    public function test_an_undeclared_argument_is_refused(): void
    {
        Http::fake();

        $response = $this->mcpCall(['qbo_invoice_id' => '1042', 'include_customer' => true]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Unsupported MCP argument', (string) $response->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    public function test_it_is_a_psa_read_that_needs_an_explicit_grant(): void
    {
        Http::fake();
        $this->assertContains(BenjiPaysForecastTool::NAME, array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));

        foreach ([McpConfig::rotateStaffToken(), McpConfig::rotateStaffToken(allowedTools: ['psa_version'], label: 'other')] as $token) {
            $response = $this->mcpCall(['qbo_invoice_id' => '1042'], $token);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }
        Http::assertNothingSent();
    }
}
