<?php

namespace Tests\Feature\Integrations;

use App\Models\Setting;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\Litsrmm\LitsrmmClientException;
use App\Services\Litsrmm\LitsrmmDevice;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Stage 2 of the LITSRMM integration: the READ path for /v1/devices.
 *
 * Every response is served by a fake transport; nothing here can reach a real
 * host (G-5). Page bodies are the vendor's captured page (card 6ab46339,
 * 24 Sep 2026) or built from its rows; each test changes only what it names.
 * The capture's own nextCursor is synthetic and is never sent back as a
 * cursor here.
 */
class LitsrmmDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private const TOKEN = 'test-token-value';

    protected function setUp(): void
    {
        parent::setUp();

        // request() re-reads live Settings before every call, so the fake
        // integration has to be configured and switched on.
        Setting::setEncrypted('litsrmm_api_key', self::TOKEN);
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private static function capture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/litsrmm/devices-capture-2026-09-24.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function client(callable|array $handler): LitsrmmClient
    {
        $stack = HandlerStack::create(is_array($handler) ? new MockHandler($handler) : $handler);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new LitsrmmClient([
            'api_key' => self::TOKEN,
            'base_url' => 'https://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]);
    }

    private static function page(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string, string> the query of the $i-th request that left */
    private function query(int $i): array
    {
        parse_str($this->history[$i]['request']->getUri()->getQuery(), $q);

        return $q;
    }

    /** $n rows cloned from captured row 0, ids made distinct. */
    private static function rows(int $from, int $n): array
    {
        $template = self::capture()['devices'][0];

        return array_map(
            static fn (int $i) => array_merge($template, ['id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', $i)]),
            range($from, $from + $n - 1),
        );
    }

    // ---- the captured page ----

    public function test_the_captured_page_parses_with_bearer_auth_on_the_fixed_path(): void
    {
        $client = $this->client([self::page(self::capture())]);

        $page = $client->getDevicePage();

        $this->assertCount(3, $page['devices']);
        $this->assertContainsOnlyInstancesOf(LitsrmmDevice::class, $page['devices']);
        $this->assertSame('ZXhhbXBsZS1jdXJzb3ItdG9rZW4', $page['nextCursor'],
            'nextCursor is returned verbatim, never decoded');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/devices', $request->getUri()->getPath());
        $this->assertSame(['limit' => '100'], $this->query(0), 'first page: default limit, and NO cursor');
        $this->assertSame('Bearer '.self::TOKEN, $request->getHeaderLine('Authorization'));
    }

    public function test_the_normal_nulls_in_the_capture_are_not_logged(): void
    {
        Log::spy();

        $this->client([self::page(self::capture())])->getDevicePage();

        // Row 3 carries the seven agent-only nulls and row 2 a null serial. That is the
        // vendor's normal no-agent machine, not drift.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_documented_key_missing_from_every_row_is_logged_as_drift(): void
    {
        Log::spy();

        $body = self::capture();
        $body['devices'] = array_map(function ($r) {
            unset($r['osBuild']);

            return $r;
        }, $body['devices']);

        $this->client([self::page($body)])->getDevicePage();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'missing documented keys') && $ctx['missing'] === ['osBuild'])
            ->once();
    }

    // ---- cursor paging and termination ----

    public function test_the_walk_follows_cursors_verbatim_and_stops_on_a_null_cursor(): void
    {
        // The vendor's own check: limit=10 -> 10/10/10/4, 34 distinct ids.
        // Cursors carry characters a sloppy encoder would change.
        $pages = [
            [self::rows(1, 10), 'c1+/=='],
            [self::rows(11, 10), 'c2 %2F'],
            [self::rows(21, 10), 'c3&x=y'],
            [self::rows(31, 4), null],
        ];
        $calls = 0;

        $client = $this->client(function (RequestInterface $r) use (&$calls, $pages) {
            $calls++;

            // Bounded transport: an unterminated walk FAILS here rather than hangs.
            if ($calls > count($pages)) {
                throw new \RuntimeException("device walk made request {$calls} after the last page");
            }

            [$rows, $next] = $pages[$calls - 1];

            return Create::promiseFor(self::page(['devices' => $rows, 'nextCursor' => $next]));
        });

        $devices = $client->getDevices(10);

        $this->assertSame(4, $calls, 'a null nextCursor must end the walk after exactly 4 requests');
        $this->assertCount(34, $devices);
        $this->assertCount(34, array_unique(array_map(fn (LitsrmmDevice $d) => $d->id, $devices)));

        $this->assertSame(['limit' => '10'], $this->query(0));
        $this->assertSame(['limit' => '10', 'cursor' => 'c1+/=='], $this->query(1), 'cursor echoed verbatim');
        $this->assertSame(['limit' => '10', 'cursor' => 'c2 %2F'], $this->query(2), 'cursor echoed verbatim');
        $this->assertSame(['limit' => '10', 'cursor' => 'c3&x=y'], $this->query(3), 'cursor echoed verbatim');
    }

    public function test_an_endless_vendor_is_cut_off_at_the_page_bound(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls) {
            $calls++;
            if ($calls > 10) {
                throw new \RuntimeException("device walk made request {$calls}; the page bound did not hold");
            }

            return Create::promiseFor(self::page(['devices' => self::rows($calls, 1), 'nextCursor' => "fresh-{$calls}"]));
        });

        try {
            $client->getDevices(1, maxPages: 3);
            $this->fail('an endless cursor chain must throw, not return a partial list');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('exceeded 3 pages', $e->getMessage());
        }

        $this->assertSame(3, $calls);
    }

    public function test_a_repeated_cursor_is_refused_instead_of_looping(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls) {
            $calls++;
            if ($calls > 10) {
                throw new \RuntimeException("device walk made request {$calls}; the repeat guard did not hold");
            }

            return Create::promiseFor(self::page(['devices' => self::rows($calls, 1), 'nextCursor' => 'same']));
        });

        try {
            $client->getDevices(1);
            $this->fail('a cursor handed back twice must be refused');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('already followed', $e->getMessage());
        }

        $this->assertSame(2, $calls);
    }

    public function test_an_id_repeated_across_pages_is_refused(): void
    {
        $client = $this->client([
            self::page(['devices' => self::rows(1, 2), 'nextCursor' => 'n1']),
            self::page(['devices' => self::rows(2, 2), 'nextCursor' => null]),
        ]);

        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('same device id on two rows');

        $client->getDevices(2);
    }

    public function test_an_empty_string_next_cursor_is_refused_not_read_as_the_end(): void
    {
        $client = $this->client([
            self::page(['devices' => self::rows(1, 2), 'nextCursor' => '']),
            self::page(['devices' => self::rows(3, 2), 'nextCursor' => null]),
        ]);

        try {
            $client->getDevices(2);
            $this->fail('nextCursor "" is neither the end nor a cursor');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('neither null nor a non-empty string', $e->getMessage());
        }

        $this->assertCount(1, $this->history, 'no second request may carry an empty cursor');
    }

    public function test_an_empty_string_cursor_is_never_sent(): void
    {
        $client = $this->client([self::page(self::capture())]);

        try {
            $client->getDevicePage(100, '');
            $this->fail('an empty cursor must be refused locally');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('empty string', $e->getMessage());
        }

        $this->assertCount(0, $this->history, 'the refusal happens before any request');
    }

    public function test_a_missing_next_cursor_field_is_refused_not_read_as_the_end(): void
    {
        $body = self::capture();
        unset($body['nextCursor']);

        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('no nextCursor field');

        $this->client([self::page($body)])->getDevicePage();
    }

    public function test_a_body_without_a_devices_list_is_refused_not_read_as_empty(): void
    {
        foreach ([['data' => self::rows(1, 1), 'nextCursor' => null], ['devices' => ['a' => self::rows(1, 1)[0]], 'nextCursor' => null]] as $body) {
            try {
                $this->client([self::page($body)])->getDevicePage();
                $this->fail('a wrapper we do not recognise must not become an empty device list');
            } catch (LitsrmmClientException $e) {
                $this->assertStringContainsString('no devices list', $e->getMessage());
            }
        }
    }

    public function test_an_empty_last_page_is_a_legitimate_answer(): void
    {
        $page = $this->client([self::page(['devices' => [], 'nextCursor' => null])])->getDevicePage();

        $this->assertSame([], $page['devices']);
        $this->assertNull($page['nextCursor']);
    }

    // ---- limit: refused locally, never clamped ----

    public function test_an_out_of_range_limit_is_refused_before_any_request(): void
    {
        foreach ([0, 501, -1] as $limit) {
            $client = $this->client([self::page(self::capture())]);

            try {
                $client->getDevicePage($limit);
                $this->fail("limit {$limit} must be refused, not clamped");
            } catch (LitsrmmClientException $e) {
                $this->assertStringContainsString('between 1 and 500', $e->getMessage());
            }

            $this->assertCount(0, $this->history, "limit {$limit} must not reach the vendor");
        }

        foreach ([1, 500] as $limit) {
            $this->client([self::page(self::capture())])->getDevicePage($limit);
            $this->assertSame((string) $limit, $this->query(0)['limit'], "limit {$limit} is in range and is sent as-is");
        }
    }

    // ---- 400 / 401 / 403 stay three different refusals ----

    public function test_400_401_and_403_are_three_distinct_refusals(): void
    {
        $seen = [];

        foreach ([400 => 'cursor it did not issue', 401 => 'missing or invalid key', 403 => 'without the devices scope'] as $status => $meaning) {
            try {
                $this->client([new Response($status, [], '{"error":"x"}')])->getDevicePage();
                $this->fail("{$status} must throw");
            } catch (LitsrmmClientException $e) {
                $this->assertSame($status, $e->getCode(), "the refusal carries the HTTP status {$status}");
                $this->assertStringContainsString("({$status})", $e->getMessage());
                $this->assertStringContainsString($meaning, $e->getMessage());
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
                $seen[$status] = $e->getMessage();
            }
        }

        $this->assertCount(3, array_unique($seen), '401 (bad key) and 403 (missing scope) must not collapse into one refusal');
    }

    public function test_another_status_is_not_mislabelled_as_an_auth_refusal(): void
    {
        try {
            $this->client([new Response(500)])->getDevicePage();
            $this->fail('500 must throw');
        } catch (LitsrmmClientException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertStringNotContainsString('scope', $e->getMessage());
            $this->assertStringNotContainsString('key', $e->getMessage());
        }
    }

    // ---- read only ----

    public function test_the_device_read_path_only_ever_sends_get(): void
    {
        $this->client([
            self::page(['devices' => self::rows(1, 1), 'nextCursor' => 'n'.'1']),
            self::page(['devices' => self::rows(2, 1), 'nextCursor' => null]),
        ])->getDevices(1);

        $this->assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
    }

    public function test_a_disabled_integration_reads_no_devices(): void
    {
        Setting::setValue('litsrmm_enabled', '0');
        $client = $this->client([self::page(self::capture())]);

        try {
            $client->getDevices();
            $this->fail('a switched-off integration must refuse');
        } catch (LitsrmmClientException $e) {
            $this->assertStringContainsString('switched off', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }
}
