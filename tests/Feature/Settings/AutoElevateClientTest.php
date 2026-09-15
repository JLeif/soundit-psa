<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoElevateClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    public function test_exact_read_only_contract_and_transport_options(): void
    {
        $optionsSeen = null;
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen = $options;

            return Http::response('unparsed-company-data', 200);
        });
        $this->assertSame('ok', app(AutoElevateClient::class)->checkConnection());
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://partner-api.autoelevate.com/api/v1/companies?take=1'
            && $request->hasHeader('Authorization', 'Bearer synthetic-only-key')
            && $request->hasHeader('X-Acknowledgment', 'i-understand-this-is-beta-and-may-change')
            && $request->hasHeader('Accept', 'application/json')
            && $request->body() === '');
        Http::assertSentCount(1);
        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertSame(3, $optionsSeen['connect_timeout']);
        $this->assertSame(10, $optionsSeen['timeout']);
    }

    #[DataProvider('statuses')]
    public function test_status_is_authoritative_not_body_or_headers(int $status, string $expected): void
    {
        Log::shouldReceive('info', 'warning', 'error', 'debug')->never();
        Http::fake(['https://partner-api.autoelevate.com/*' => Http::response(
            ['status' => 200, 'detail' => 'synthetic-only-key', 'data' => ['private-company']],
            $status, ['Location' => 'https://untrusted.invalid', 'Retry-After' => '1']
        )]);
        $this->assertSame($expected, app(AutoElevateClient::class)->checkConnection());
        Http::assertSentCount(1);
    }

    public static function statuses(): array
    {
        return [[200, 'ok'], [201, 'error'], [204, 'error'], [302, 'error'], [307, 'error'],
            [400, '400'], [401, '401'], [403, '403'], [406, '406'], [429, '429'], [503, 'error']];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_configuration_never_sends(?string $key): void
    {
        Setting::setEncrypted('autoelevate_api_key', $key);
        Http::fake();
        $this->assertSame('configuration', app(AutoElevateClient::class)->checkConnection());
        Http::assertNothingSent();
    }

    public static function invalidKeys(): array
    {
        return [[null], [''], [' '], ["secret\r\nInjected: value"], ["secret\x00"], [str_repeat('x', 4097)]];
    }

    public function test_transport_exception_is_discarded_without_logging_or_retry(): void
    {
        Log::shouldReceive('info', 'warning', 'error', 'debug')->never();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('Authorization: Bearer synthetic-only-key');
        });
        $this->assertSame('transport', app(AutoElevateClient::class)->checkConnection());
        $this->assertSame(1, $calls);
    }
}
