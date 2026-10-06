<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshLicenseSyncService;
use App\Services\Mesh\MeshReadTools;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * C-56 (card qyR9zm3y, #5282): the Mesh read sites outside #5272's list report
 * a failed call by its HTTP status (statusPhrase()) or, for a Throwable that
 * is not a MeshClientException, by its class in the log, never by message.
 *
 *   - MeshReadTools: the email-log search and the events read (tool result
 *     and log), and the queue-id cache write (log);
 *   - MeshLicenseSyncService: the per-client failure log, by client id;
 *   - MeshCustomerController::index and IntegrationsController::syncMesh:
 *     the staff flash;
 *   - #5282: MeshClientException::report(), so the exception handler's
 *     formatted record carries neither the message nor the chained Guzzle
 *     exception (request URI, host, vendor body, API-KEY header).
 *
 * The vendor error is real Guzzle output. The read tools and the license sync
 * get a MeshClient whose Guzzle is scripted. The two controllers build their
 * own MeshClient with `new`, so their call goes through Guzzle's default
 * handler to a loopback `php -S` acting as the HTTP proxy (Guzzle honours
 * HTTP_PROXY in CLI); nothing leaves 127.0.0.1. The connect-failure arm uses
 * phpunit.xml's own proxy, the discard port, which refuses.
 *
 * Synthetic data only: example.test, RFC 5737, made-up uuids.
 */
class MeshC56ReadSitesTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'mesh.example.test';

    /** The class as a record's JSON-encoded context carries it. */
    private const CLASS_IN_CONTEXT = 'App\\\\Services\\\\Mesh\\\\MeshClientException';

    private const MESH_ID = '3f2a9c1e-5b7d-4e60-9a1b-2c3d4e5f6a7b';

    /** Body marker; a fresh suffix per process. */
    private static string $marker = '';

    /** Query-string marker (sent as a filter value). */
    private static string $queryMarker = '';

    private static string $apiKey = '';

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $router = '';

    /** '503' or 'connect' */
    private string $mode = '503';

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $rand = bin2hex(random_bytes(4));
        self::$marker = 'SYNTHETIC-VENDOR-BODY-'.$rand;
        self::$queryMarker = 'SYNTHETIC-QUERY-'.$rand;
        self::$apiKey = sprintf('SYNTHETIC-KEY-%s', $rand);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$router !== '' && is_file(self::$router)) {
            unlink(self::$router);
        }
        self::$server = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message.' '.json_encode($e->context)];
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_PROXY']);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['HTTP 503' => ['503'], 'connect failure, no status' => ['connect']];
    }

    // ---- MeshReadTools ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_log_search_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient(fn (RequestInterface $r) => $this->failure($r)));

        $out = (new MeshReadTools)->search($client, $client->id, ['subject' => self::$queryMarker], '[Test]');

        $this->assertArrayHasKey('error', $out);
        $this->assertStringStartsWith('Mesh query failed: ', $out['error'], 'the PSA prose is kept');
        $this->assertStatusOnly($out['error'], 'search tool result');
        $this->assertStringContainsString('email-log search', $out['error']);

        $log = $this->logsContaining('Mesh log search failed');
        $this->assertStatusOnly($log, 'search log');
        $this->assertStringContainsString(self::CLASS_IN_CONTEXT, $log, 'search log: the exception class is named');
        $this->assertNoVendorText($this->allLogs(), 'every record', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_events_read_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();
        $queueId = 'SYNTHQ'.self::$queryMarker;
        $this->app->instance(MeshClient::class, $this->scriptedClient(function (RequestInterface $r) use ($queueId) {
            if (str_ends_with($r->getUri()->getPath(), 'emaillogs/')) {
                return Create::promiseFor(new Response(200, [], json_encode(['list' => [
                    ['Customer-ID' => self::MESH_ID, 'queue_id' => $queueId],
                ]])));
            }

            return $this->failure($r);
        }));
        $tools = new MeshReadTools;
        $this->assertSame(1, $tools->search($client, $client->id, [], '[Test]')['total'] ?? null, 'precondition: this client\'s search served the queue id');

        $out = $tools->events($client, $client->id, ['queue_id' => $queueId], '[Test]');

        $this->assertArrayHasKey('error', $out);
        $this->assertStringStartsWith('Mesh query failed: ', $out['error']);
        $this->assertStatusOnly(str_replace($queueId, '', $out['error']), 'events tool result');
        $this->assertStringContainsString('email-events read', $out['error']);

        $log = $this->logsContaining('Mesh events query failed');
        $this->assertStringContainsString($queueId, $log, 'the queue id stays in the log context');
        $this->assertStatusOnly(str_replace($queueId, '', $log), 'events log');
        $this->assertStringContainsString(self::CLASS_IN_CONTEXT, $log);
    }

    /** A Throwable that is not a MeshClientException: no message out, its class in the log. */
    public function test_a_foreign_failure_in_either_read_tool_reports_no_message(): void
    {
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient($this->foreignFailure()));
        $tools = new MeshReadTools;

        $search = $tools->search($client, $client->id, [], '[Test]');
        Cache::put('mesh-read-scope:queue:'.$client->id.':'.sha1('q-1'), MeshReadTools::normaliseId(self::MESH_ID), 60);
        $events = $tools->events($client, $client->id, ['queue_id' => 'q-1'], '[Test]');

        foreach (['search' => $search, 'events' => $events] as $where => $out) {
            $this->assertArrayHasKey('error', $out, $where);
            $this->assertNoVendorText($out['error'], "{$where} tool result");
            $this->assertStringContainsString('failed with an unexpected error', $out['error'], $where);
        }
        $log = $this->logsContaining('[Test] Mesh');
        $this->assertNoVendorText($log, 'read tool logs');
        $this->assertStringContainsString('RuntimeException', $this->logsContaining('Mesh log search failed'), 'search: the class is logged');
        $this->assertStringContainsString('RuntimeException', $this->logsContaining('Mesh events query failed'), 'events: the class is logged');
    }

    public function test_a_queue_id_cache_failure_logs_the_class_only(): void
    {
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient(fn () => Create::promiseFor(new Response(200, [], json_encode(['list' => [
            ['Customer-ID' => self::MESH_ID, 'queue_id' => 'q-2'],
        ]])))));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis at '.self::HOST.' said '.self::$marker));

        $out = (new MeshReadTools)->search($client, $client->id, [], '[Test]');

        $this->assertSame(1, $out['total'] ?? null, 'the rows are still served');
        $log = $this->logsContaining('Mesh queue id could not be recorded');
        $this->assertNoVendorText($log, 'queue-id log');
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- MeshLicenseSyncService -------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_license_sync_logs_the_client_id_and_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();

        $result = (new MeshLicenseSyncService($this->scriptedClient(fn (RequestInterface $r) => $this->failure($r))))->syncLicenses();

        $this->assertSame(1, $result->errors);
        $log = $this->logsContaining('[MeshSync] Failed for client');
        $this->assertStringContainsString('[MeshSync] Failed for client '.$client->id.':', $log, 'the client is named by id');
        $this->assertStringNotContainsString($client->name, $this->allLogs(), 'the client name is not logged');
        $this->assertStatusOnly($log, 'license sync log');
        $this->assertStringContainsString('('.MeshClientException::class.')', $log, 'the class is named');
        $this->assertNoVendorText($this->allLogs(), 'every record', false);
    }

    public function test_a_foreign_failure_in_the_license_sync_logs_the_class_only(): void
    {
        $client = $this->mappedClient();

        (new MeshLicenseSyncService($this->scriptedClient($this->foreignFailure())))->syncLicenses();

        $log = $this->logsContaining('[MeshSync] Failed for client '.$client->id.':');
        $this->assertStringNotContainsString($client->name, $log);
        $this->assertNoVendorText($log, 'license sync log');
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- staff flashes ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_customer_mapping_page_flashes_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->loopbackMesh();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.mesh-customers.index'));

        $response->assertRedirect(route('settings.integrations'));
        $flash = (string) session('error');
        $this->assertStringStartsWith('Could not load Mesh customers: ', $flash, 'the PSA prose is kept');
        $this->assertStringNotContainsString('connect', $flash, 'not "could not connect" when Mesh may have answered');
        $this->assertStatusOnly($flash, 'mapping page flash');
        $this->assertNoVendorText($this->allLogs(), 'every record', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_mesh_sync_button_keeps_vendor_text_out_end_to_end(string $mode): void
    {
        $this->mode = $mode;
        $this->loopbackMesh();
        $client = $this->mappedClient();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'));

        // syncLicenses() absorbs a per-client failure, so the flash is the
        // summary and the failure is the client-id log line.
        $response->assertRedirect(route('settings.integrations'));
        $this->assertNoVendorText((string) session('success').(string) session('error'), 'sync flash');
        $log = $this->logsContaining('[MeshSync] Failed for client '.$client->id.':');
        $this->assertStatusOnly($log, 'sync log');
        $this->assertStringNotContainsString($client->name, $this->allLogs());
        $this->assertNoVendorText($this->allLogs(), 'every record', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_mesh_failure_reaching_the_sync_button_flashes_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        $thrown = $this->realFailure();
        $this->bindSync()->shouldReceive('syncLicenses')->andThrow($thrown);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertRedirect(route('settings.integrations'));

        $flash = (string) session('error');
        $this->assertStringStartsWith('Mesh sync failed: ', $flash);
        $this->assertStatusOnly($flash, 'sync flash');
    }

    public function test_a_foreign_failure_reaching_the_sync_button_flashes_no_message(): void
    {
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        $this->bindSync()->shouldReceive('syncLicenses')
            ->andThrow(new \RuntimeException('at '.self::HOST.' '.self::$marker));

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'));

        $flash = (string) session('error');
        $this->assertStringStartsWith('Mesh sync failed', $flash);
        $this->assertNoVendorText($flash, 'sync flash');
        $log = $this->logsContaining('[MeshSync] Sync failed');
        $this->assertNoVendorText($log, 'sync log');
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- #5282: the exception handler and the previous chain ---------------------

    /**
     * report() on a real, chained MeshClientException, through the default
     * handler into a real Monolog channel with Laravel's own LineFormatter
     * (stack traces and the [previous exception] walk on). The formatted file
     * is what is checked, so exception normalisation is included.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_reported_mesh_exception_logs_no_vendor_text_down_the_previous_chain(string $mode): void
    {
        $this->mode = $mode;
        $thrown = $this->realFailure();
        $this->assertInstanceOf(\GuzzleHttp\Exception\GuzzleException::class, $thrown->getPrevious(), 'precondition: the Guzzle exception is chained');
        $this->assertSame([self::$apiKey], $thrown->getPrevious()->getRequest()->getHeader('API-KEY'), 'positive control: the chained request carries the key');
        $this->assertStringContainsString(self::HOST, $thrown->getMessage(), 'positive control: the message carries the host');
        $this->assertStringContainsString(self::$queryMarker, $thrown->getMessage(), 'positive control: the message carries the query');

        // Configured only now, so the client's own failure line is not in it.
        $path = tempnam(sys_get_temp_dir(), 'c56log');
        config(['logging.channels.c56probe' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'logging.default' => 'c56probe']);
        Log::forgetChannel('c56probe');

        $this->app->make(ExceptionHandler::class)->report($thrown);

        $written = (string) file_get_contents($path);
        unlink($path);
        $this->assertNotSame('', trim($written), 'positive control: the handler wrote to this channel');
        $this->assertNoVendorText($written, 'reported record');
        $this->assertStringContainsString(MeshClientException::class.' reported', $written, 'the record is the status-only report() line');
        $this->assertStatusOnly($written, 'reported record');
        $this->assertStringNotContainsString('[previous exception]', $written, 'the chain is not walked');
    }

    /**
     * The controller resolves the service with its client as a parameter,
     * and make() with parameters skips instance(), so bind a closure.
     */
    private function bindSync(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(MeshLicenseSyncService::class);
        $this->app->bind(MeshLicenseSyncService::class, fn () => $mock);

        return $mock;
    }

    /** A MeshClientException thrown by the real client over the scripted Guzzle. */
    private function realFailure(): MeshClientException
    {
        try {
            $this->scriptedClient(fn (RequestInterface $r) => $this->failure($r))
                ->get('api/customers/', ['_size' => 1, 'filter' => self::$queryMarker]);
        } catch (MeshClientException $e) {
            return $e;
        }
        $this->fail('the scripted read was expected to fail');
    }

    // ---- the scripted vendor ----------------------------------------------------

    /**
     * A real MeshClient whose Guzzle is scripted: $answer decides per request.
     * The API-KEY header the client sets is the synthetic key.
     *
     * @param  \Closure(RequestInterface): mixed  $answer
     */
    private function scriptedClient(\Closure $answer): MeshClient
    {
        $client = new MeshClient(['api_key' => self::$apiKey, 'base_url' => 'https://'.self::HOST]);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => HandlerStack::create(fn (RequestInterface $r) => $answer($r)),
            'http_errors' => true,
        ]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        return $client;
    }

    /** The failure this test's mode calls for, as Guzzle itself words it. */
    private function failure(RequestInterface $request): mixed
    {
        if ($this->mode === 'connect') {
            return Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to '.self::HOST.' '.self::$marker.' for '.$request->getUri(),
                $request,
                null,
                ['errno' => 7],
            ));
        }

        return Create::promiseFor(new Response((int) $this->mode, ['Content-Type' => 'application/json'], json_encode([
            'detail' => self::$marker,
        ])));
    }

    /** A Throwable that is not a MeshClientException, raised inside the transport. */
    private function foreignFailure(): \Closure
    {
        return function (): never {
            throw new \RuntimeException('cache backend down at '.self::HOST.' '.self::$marker);
        };
    }

    /**
     * Point Guzzle's default handler at a loopback proxy (Guzzle honours
     * HTTP_PROXY under CLI and reads $_SERVER first). '503': a php -S router
     * answering every request 503 with the body marker. 'connect': the discard
     * port phpunit.xml already uses, which refuses (errno 7).
     */
    private function loopbackMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        Setting::setValue('mesh_base_url', 'http://'.self::HOST);

        if ($this->mode === 'connect') {
            $_SERVER['HTTP_PROXY'] = 'http://127.0.0.1:9';

            return;
        }

        if (! is_resource(self::$server)) {
            $probe = stream_socket_server('tcp://127.0.0.1:0');
            self::$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
            fclose($probe);

            self::$router = tempnam(sys_get_temp_dir(), 'c56mesh').'.php';
            file_put_contents(self::$router, '<?php http_response_code(503); header("Content-Type: application/json"); echo json_encode(["detail" => '.var_export(self::$marker, true).']);');
            self::$server = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            for ($i = 0; $i < 100; $i++) {
                if (($s = @fsockopen('127.0.0.1', self::$port, $en, $es, 0.1)) !== false) {
                    fclose($s);
                    break;
                }
                usleep(50_000);
            }
        }

        $_SERVER['HTTP_PROXY'] = 'http://127.0.0.1:'.self::$port;
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create([
            'name' => 'Synthetic Client Name 7c1d',
            'mesh_customer_id' => self::MESH_ID,
            'is_active' => true,
        ]);
    }

    // ---- assertions -------------------------------------------------------------

    /** No vendor text, host, query, key or Mesh id; the status, or that there was none. */
    private function assertStatusOnly(string $text, string $where): void
    {
        $this->assertNoVendorText($text, $where);
        $this->assertStringNotContainsString('Mesh API', $text, "{$where}: the exception message was used");

        if (is_numeric($this->mode)) {
            $this->assertStringContainsString('with HTTP '.$this->mode, $text, "{$where}: the status is the report");
        } else {
            $this->assertStringContainsString('failed without an HTTP status from Mesh', $text, "{$where}: a status-less failure says so");
            $this->assertStringNotContainsString('HTTP 0', $text, $where);
        }
    }

    /**
     * $pathIdToo is false only for ALL records together: MeshClient's own
     * failure line logs the endpoint path, which can carry the Mesh customer
     * id (a separate issue, outside this card).
     */
    private function assertNoVendorText(string $text, string $where, bool $pathIdToo = true): void
    {
        $this->assertStringNotContainsString(self::$marker, $text, "{$where}: vendor body leaked");
        $this->assertStringNotContainsString(self::$queryMarker, $text, "{$where}: request query leaked");
        $this->assertStringNotContainsString(self::HOST, $text, "{$where}: request host leaked");
        $this->assertStringNotContainsString(self::$apiKey, $text, "{$where}: API key leaked");
        if ($pathIdToo) {
            $this->assertStringNotContainsString(self::MESH_ID, $text, "{$where}: request path (Mesh id) leaked");
        }
        $this->assertStringNotContainsString('_size', $text, "{$where}: request query leaked");
    }

    /** Every record logged, at any level, joined. */
    private function allLogs(): string
    {
        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $this->logged));
    }

    /** The records whose text contains $needle, at any level; at least one must exist. */
    private function logsContaining(string $needle): string
    {
        $lines = array_filter($this->logged, fn (array $r) => str_contains($r['message'], $needle));
        $this->assertNotSame([], $lines, "positive control: a record containing '{$needle}' was logged");

        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $lines));
    }
}
