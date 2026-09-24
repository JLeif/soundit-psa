<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateClient;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card fh7wGneF: the vendor rate-limits list reads (measured 2026-09-24 on production: 57
 * back-to-back computersForCompany() reads → 24 ok / 33 http_429; 4 s or 8 s apart → 8/8 ok).
 * getPage() therefore retries a 429 a bounded number of times before failing as `http_429`.
 *
 * Hermetic: Http::fake + preventStrayRequests (no live vendor call) and Sleep::fake (no real
 * waiting). Every sleep the client asks for is asserted, so a wrong schedule is caught too.
 */
class AutoElevateRateLimitRetryTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    private function service(): AutoElevateReadService
    {
        return app(AutoElevateReadService::class);
    }

    public function test_a_429_then_429_then_200_succeeds_after_three_requests(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::sequence()
            ->push('', 429)
            ->push('', 429)
            ->push(self::envelope([self::computer()]), 200)]);

        $computers = $this->service()->computersForCompany(self::COMPANY_A);

        $this->assertCount(1, $computers, 'the retried read must return the vendor rows, not a failure');
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
    }

    public function test_a_persistent_429_fails_as_http_429_after_exactly_max_attempts(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response('', 429)]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('a persistent 429 must still fail the read (C-56), not return empty');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('http_429', $e->reason);
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame(4, AutoElevateClient::MAX_ATTEMPTS, 'the brief bounds the retry at 4 attempts');
        Http::assertSentCount(AutoElevateClient::MAX_ATTEMPTS);
        // One wait between each pair of attempts, none after the last.
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds(), Sleep::for(20)->seconds()]);
    }

    /** @return array<string, array{int}> */
    public static function nonRetriedStatuses(): array
    {
        return ['401' => [401], '403' => [403], '400' => [400], '500' => [500], '503' => [503]];
    }

    #[DataProvider('nonRetriedStatuses')]
    public function test_other_failures_are_not_retried(int $status): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response('', $status)]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail("HTTP {$status} must fail the read");
        } catch (AutoElevateReadException $e) {
            $this->assertSame('http_'.$status, $e->reason);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_small_integer_retry_after_is_honoured(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push(self::envelope([self::computer()]), 200)]);

        $this->assertCount(1, $this->service()->computersForCompany(self::COMPANY_A));
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_retry_after_parsing_is_bounded_to_a_plain_integer(): void
    {
        $this->assertSame(0, AutoElevateClient::retryDelaySeconds('0', 1));
        $this->assertSame(60, AutoElevateClient::retryDelaySeconds('60', 1), 'the ceiling itself is honoured');
        $this->assertSame(5, AutoElevateClient::retryDelaySeconds('61', 1), 'over the ceiling → schedule');
        $this->assertSame(10, AutoElevateClient::retryDelaySeconds('3600', 2));
        $this->assertSame(20, AutoElevateClient::retryDelaySeconds('', 3), 'no header → schedule');
        $this->assertSame(5, AutoElevateClient::retryDelaySeconds('Wed, 21 Oct 2026 07:28:00 GMT', 1), 'HTTP date → schedule');
        $this->assertSame(5, AutoElevateClient::retryDelaySeconds('-1', 1));
        $this->assertSame(5, AutoElevateClient::retryDelaySeconds('1.5', 1));
        $this->assertSame(20, AutoElevateClient::retryDelaySeconds('', 9), 'past the schedule → its last step');
    }

    public function test_a_retried_read_still_fails_on_transport_without_chaining(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                return Http::response('', 429);
            }
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 Bearer synthetic-only-key');
        });

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('transport failure must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('transport', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-only-key', $e->getMessage());
        }
        $this->assertSame(2, $calls);
    }

    private function asWebRequest(): void
    {
        // Application memoises runningInConsole(); mark this app as serving HTTP, not artisan.
        (function () {
            $this->isRunningInConsole = false;
        })->call($this->app);
        $this->assertFalse($this->app->runningInConsole());
    }

    public function test_a_web_request_is_not_retried_and_fails_as_http_429_at_once(): void
    {
        $this->asWebRequest();
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response('', 429, ['Retry-After' => '60'])]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('a 429 in a web request must still fail the read (C-56), not return empty');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('http_429', $e->reason);
            $this->assertNull($e->getPrevious());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }
}
