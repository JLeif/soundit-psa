<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\BenjiPays\AppliedPaymentLink;
use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BenjiPaysClient::createAppliedPaymentLink() (#2065). Every request is faked and
 * stray requests are refused: no live BenjiPays call from dev or CI, ever.
 */
class BenjiPaysAppliedLinkClientTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-key-never-real';

    /** Fixture copied from the vendor's published response schema (CreateAppliedPaymentLinkResponse). */
    private const LINK = ['url' => 'https://portal.benjipays.com/pay/tok_synthetic', 'expiresAt' => '2030-01-01T12:00:00.000Z'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
    }

    public function test_request_is_a_guest_checkout_post_with_a_fresh_idempotency_key(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.benjipays.com/v2/payment-links/applied/1042', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', self::KEY));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $request->header('X-Request-ID')[0]);
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{1,255}$/D', $request->header('Idempotency-Key')[0]);
            // The vendor's security note: true would expose the customer's stored cards to the link holder.
            $body = json_decode($request->body(), true);
            $this->assertSame(['allowSavedPaymentMethods' => false], $body);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);

            return Http::response(self::LINK);
        });

        $link = app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');

        $this->assertInstanceOf(AppliedPaymentLink::class, $link);
        $this->assertSame(self::LINK['url'], $link->url);
        $this->assertSame('2030-01-01T12:00:00+00:00', $link->expiresAt->toIso8601String());
        Http::assertSentCount(1);
    }

    public function test_body_flag_is_false_on_every_mint(): void
    {
        // The named red-check: flip the flag in the client and this must fail.
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::LINK)]);
        app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
        app(BenjiPaysClient::class)->createAppliedPaymentLink('77');
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => json_decode($r->body(), true)['allowSavedPaymentMethods'] === false);
        Http::assertNotSent(fn ($r) => (json_decode($r->body(), true)['allowSavedPaymentMethods'] ?? null) !== false);
    }

    public function test_idempotency_key_is_fresh_per_mint(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::LINK)]);
        app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
        app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
        $keys = [];
        Http::assertSent(function ($r) use (&$keys) {
            $keys[] = $r->header('Idempotency-Key')[0];

            return true;
        });
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_invoice_id_is_one_encoded_path_segment(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::LINK)]);
        app(BenjiPaysClient::class)->createAppliedPaymentLink('id /?#%+');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.benjipays.com/v2/payment-links/applied/id%20%2F%3F%23%25%2B');
    }

    #[DataProvider('badIds')]
    public function test_invalid_ids_are_refused_before_transport(string $id): void
    {
        Http::fake();
        try {
            app(BenjiPaysClient::class)->createAppliedPaymentLink($id);
            $this->fail('Expected local refusal');
        } catch (BenjiPaysException $e) {
            $this->assertSame('invalid_id', $e->reason);
        }
        Http::assertNothingSent();
    }

    public static function badIds(): array
    {
        return [[''], ['   '], ['.'], ['..'], ["10\n42"], [str_repeat('9', 201)]];
    }

    #[DataProvider('httpFailures')]
    public function test_http_failures_are_status_only(int $status, string $reason): void
    {
        $body = ['type' => self::KEY, 'title' => self::KEY, 'status' => 200, 'detail' => '<script>'.self::KEY, 'url' => 'https://evil.example', 'expiresAt' => '2030-01-01T00:00:00Z'];
        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => 'application/problem+json', 'Location' => 'https://evil.example'])]);
        try {
            app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
            $this->fail('Expected failure');
        } catch (BenjiPaysException $e) {
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame($reason, $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
            $this->assertStringNotContainsString('evil.example', (string) $e);
        }
        Http::assertSentCount(1);
    }

    public static function httpFailures(): array
    {
        return array_map(fn ($s) => [$s, match ($s) {
            401 => 'unauthorized', 403 => 'forbidden', default => 'http_error'
        }], [201, 301, 400, 401, 403, 404, 409, 429, 500, 503]);
    }

    public function test_transport_error_discards_secret_and_previous_chain(): void
    {
        Http::fake(fn () => throw new ConnectionException('x-api-key: '.self::KEY));
        try {
            app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
            $this->fail('Expected failure');
        } catch (BenjiPaysException $e) {
            $this->assertSame('transport', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    #[DataProvider('badResponses')]
    public function test_malformed_or_offsite_response_is_invalid_response(mixed $body): void
    {
        Http::fake(['*' => Http::response($body)]);
        try {
            app(BenjiPaysClient::class)->createAppliedPaymentLink('1042');
            $this->fail('Expected invalid_response');
        } catch (BenjiPaysException $e) {
            $this->assertSame('invalid_response', $e->reason);
        }
    }

    public static function badResponses(): array
    {
        $ok = self::LINK;

        return [
            'not json' => ['not-json'],
            'stage 1 envelope' => [['data' => $ok]],
            'list' => [[$ok]],
            'empty' => [[]],
            'missing url' => [['expiresAt' => $ok['expiresAt']]],
            'missing expiresAt' => [['url' => $ok['url']]],
            'url not string' => [['url' => 1, 'expiresAt' => $ok['expiresAt']]],
            'http url' => [['url' => 'http://portal.benjipays.com/pay/x', 'expiresAt' => $ok['expiresAt']]],
            'other host' => [['url' => 'https://benjipays.com.evil.example/pay/x', 'expiresAt' => $ok['expiresAt']]],
            'lookalike host' => [['url' => 'https://notbenjipays.com/pay/x', 'expiresAt' => $ok['expiresAt']]],
            'userinfo' => [['url' => 'https://benjipays.com@evil.example/pay/x', 'expiresAt' => $ok['expiresAt']]],
            'javascript' => [['url' => 'javascript:alert(1)', 'expiresAt' => $ok['expiresAt']]],
            'control char in url' => [['url' => "https://portal.benjipays.com/pay/x\r\nSet-Cookie: a=b", 'expiresAt' => $ok['expiresAt']]],
            'expiresAt not iso' => [['url' => $ok['url'], 'expiresAt' => 'tomorrow']],
            'expiresAt epoch' => [['url' => $ok['url'], 'expiresAt' => 1893456000]],
            'expiresAt date only' => [['url' => $ok['url'], 'expiresAt' => '2030-01-01']],
        ];
    }

    #[DataProvider('goodHosts')]
    public function test_benjipays_hosts_are_accepted(string $url): void
    {
        Http::fake(['*' => Http::response(['url' => $url, 'expiresAt' => '2030-06-01T00:00:00+00:00'])]);
        $this->assertSame($url, app(BenjiPaysClient::class)->createAppliedPaymentLink('1042')->url);
    }

    public static function goodHosts(): array
    {
        return [['https://benjipays.com/pay/x'], ['https://portal.benjipays.com/pay/x?t=abc'], ['https://APP.BenjiPays.com/p']];
    }
}
