<?php

namespace Tests\Feature\Settings;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysInvoiceBalance;
use App\Services\BenjiPays\InvoiceBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BenjiPaysClientTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-key-never-real';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
    }

    public function test_gateways_uses_only_fixed_https_origin_and_bounded_transport(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://api.benjipays.com/v2/gateways', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', self::KEY));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $request->header('X-Request-ID')[0]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);

            return Http::response(['data' => []]);
        });
        $this->assertSame([], app(BenjiPaysClient::class)->gateways());
        Http::assertSentCount(1);
    }

    public function test_invoice_id_is_one_encoded_path_segment_and_balance_is_integer_cents(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::invoiceFixture()])]);
        $invoice = new Invoice;
        $invoice->qbo_invoice_id = 'id /?#%+';
        $result = app(BenjiPaysInvoiceBalance::class)->read($invoice);
        $this->assertSame(1001, $result->balanceCents);
        $this->assertSame('open', $result->status);
        $this->assertSame('USD', $result->currency);
        $this->assertNull($result->reason);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.benjipays.com/v2/invoices/id%20%2F%3F%23%25%2B');
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_have_only_safe_metadata(int $status, string $reason): void
    {
        Log::spy();
        $body = ['type' => self::KEY, 'title' => self::KEY, 'status' => 200, 'detail' => "\r\n<script>".self::KEY, 'instance' => self::KEY];
        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => 'application/problem+json', 'Location' => 'https://evil.example', 'X-Request-ID' => self::KEY])]);
        try {
            app(BenjiPaysClient::class)->gateways();
            $this->fail('Expected failure');
        } catch (BenjiPaysException $e) {
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame($reason, $e->reason);
            $this->assertSame(in_array($status, [401, 403]) ? (string) $status : 'error', $e->outcome());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
            $this->assertStringNotContainsString('<script>', $e->getMessage());
            $this->assertStringNotContainsString("\r", $e->getMessage());
        }
        Http::assertSentCount(1);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public static function httpFailures(): array
    {
        return array_map(fn ($s) => [$s, match ($s) {
            401 => 'unauthorized', 403 => 'forbidden', default => 'http_error'
        }], [301, 400, 401, 403, 404, 429, 500, 503]);
    }

    public function test_transport_error_discards_secret_and_previous_chain(): void
    {
        Http::fake(fn () => throw new ConnectionException('x-api-key: '.self::KEY));
        try {
            app(BenjiPaysClient::class)->invoice('1042');
            $this->fail('Expected failure');
        } catch (BenjiPaysException $e) {
            $this->assertSame('transport', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    #[DataProvider('invalidKeys')]
    public function test_control_characters_are_refused_before_transport(string $key): void
    {
        Setting::setEncrypted('benjipays_api_key', $key);
        Http::fake(['*' => Http::response(['data' => []])]);
        try {
            app(BenjiPaysClient::class)->gateways();
            $this->fail('Expected local refusal');
        } catch (BenjiPaysException $e) {
            $this->assertSame('configuration', $e->reason);
        }
        Http::assertNothingSent();
    }

    public static function invalidKeys(): array
    {
        return array_map(fn ($c) => ['dummy'.$c.'key'], ["\r", "\n", "\0", "\t", "\x1b", "\x7f"]);
    }

    #[DataProvider('badResponses')]
    public function test_malformed_gateway_response_is_not_success(mixed $body): void
    {
        Http::fake(['*' => Http::response($body)]);
        $this->expectException(BenjiPaysException::class);
        app(BenjiPaysClient::class)->gateways();
    }

    public static function badResponses(): array
    {
        return [['not-json'], ['{"data":{}}'], ['{"data":{"0":{}}}'], [[]], [['data' => null]], [['data' => 'secret']], [['data' => ['wrong' => true]]]];
    }

    #[DataProvider('balances')]
    public function test_balance_projection_never_converts_unknown_into_zero(mixed $value, ?int $expected, ?string $reason): void
    {
        $result = InvoiceBalance::fromInvoice(array_replace(self::invoiceFixture(), ['balance' => $value]));
        $this->assertSame($expected, $result->balanceCents);
        $this->assertSame($reason, $result->reason);
    }

    public static function balances(): array
    {
        return [[null, null, 'balance_unavailable'], ['0', null, 'invalid_balance'], [false, null, 'invalid_balance'],
            [[], null, 'invalid_balance'], [0, 0, null], [0.29, 29, null], [12.3, 1230, null], [-0.01, -1, null],
            [1.001, null, 'invalid_balance'], [1e20, null, 'invalid_balance']];
    }

    public function test_balance_conversion_does_not_depend_on_the_precision_ini_setting(): void
    {
        $original = (string) ini_get('precision');
        try {
            ini_set('precision', '17');
            $result = InvoiceBalance::fromInvoice(array_replace(self::invoiceFixture(), ['balance' => 20.01]));
            $this->assertSame(2001, $result->balanceCents);
            $this->assertNull($result->reason);
            $this->assertSame(29, InvoiceBalance::fromInvoice(
                array_replace(self::invoiceFixture(), ['balance' => 0.29]))->balanceCents);
            $this->assertSame('invalid_balance', InvoiceBalance::fromInvoice(
                array_replace(self::invoiceFixture(), ['balance' => 1.001]))->reason);
        } finally {
            ini_set('precision', $original);
        }
    }

    public function test_missing_balance_and_invalid_status_are_not_accepted(): void
    {
        $data = self::invoiceFixture();
        unset($data['balance']);
        $this->assertSame('invalid_balance', InvoiceBalance::fromInvoice($data)->reason);
        $data['status'] = self::KEY;
        $result = InvoiceBalance::fromInvoice($data);
        $this->assertSame('invalid_response', $result->reason);
        $this->assertNull($result->status);
        $this->assertStringNotContainsString(self::KEY, json_encode($result));
    }

    public function test_missing_accounting_id_does_not_call_vendor(): void
    {
        Http::fake();
        $this->assertSame('missing_accounting_id', app(BenjiPaysInvoiceBalance::class)->read(new Invoice)->reason);
        Http::assertNothingSent();
    }

    public function test_balance_service_returns_safe_failure_reason(): void
    {
        Http::fake(['*' => Http::response(['detail' => self::KEY], 403)]);
        $invoice = new Invoice;
        $invoice->qbo_invoice_id = '1042';
        $result = app(BenjiPaysInvoiceBalance::class)->read($invoice);
        $this->assertNull($result->balanceCents);
        $this->assertSame('forbidden', $result->reason);
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_invoice_ids_are_local_refusals(string $id): void
    {
        Http::fake();
        try {
            app(BenjiPaysClient::class)->invoice($id);
            $this->fail('Expected refusal');
        } catch (BenjiPaysException $e) {
            $this->assertSame('invalid_id', $e->reason);
        }
        Http::assertNothingSent();
    }

    public static function invalidIds(): array
    {
        return [[''], [' '], ['.'], ['..'], ["id\n"], [str_repeat('a', 201)]];
    }

    public function test_missing_key_refuses_without_transport(): void
    {
        Setting::setEncrypted('benjipays_api_key', '');
        Http::fake();
        try {
            app(BenjiPaysClient::class)->gateways();
            $this->fail('Expected refusal');
        } catch (BenjiPaysException $e) {
            $this->assertSame('configuration', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_malformed_invoice_response_becomes_safe_unavailable_reason(): void
    {
        Http::fake(['*' => Http::response(['data' => null])]);
        $invoice = new Invoice;
        $invoice->qbo_invoice_id = '1042';
        $result = app(BenjiPaysInvoiceBalance::class)->read($invoice);
        $this->assertNull($result->balanceCents);
        $this->assertSame('invalid_response', $result->reason);
    }

    public static function invoiceFixture(): array
    {
        // Projection source: public GetInvoiceResponse/InvoiceSummary .md OpenAPI, not live data.
        return ['id' => '1042', 'invoiceNumber' => 'SYN-1', 'invoiceDate' => '2026-01-01', 'dueDate' => null,
            'createdDate' => null, 'total' => 20.01, 'balance' => 10.01, 'subtotal' => 20.01, 'taxTotal' => 0,
            'currency' => 'USD', 'status' => 'open', 'paid' => false, 'emailed' => false, 'terms' => null,
            'brandingTheme' => null, 'reference' => null, 'memo' => null, 'lastModifiedDate' => null, 'customer' => null];
    }
}
