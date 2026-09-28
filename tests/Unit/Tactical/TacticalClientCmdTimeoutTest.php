<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #3971: cmd() was cut by the client's 30s HTTP timeout whatever command
 * timeout the caller asked for, and every cut came back "offline".
 *
 * Budget assertions read the OPTIONS the history middleware records — what the
 * handler was handed, not what the call site intended.
 *
 * Classification assertions drive REAL cURL against loopback sockets, so the
 * handler context under test is the one cURL produces, not one written here:
 *   - a listener that never answers: the request is written, then the read
 *     times out (errno 28, request_size > 0) — outcome unknown;
 *   - HTTPS against that same plain-TCP listener: the TLS handshake never
 *     completes, so the timeout fires before any request (errno 28,
 *     request_size 0) — the same errno, and it must stay offline;
 *   - a closed port: connection refused (errno 7) — offline.
 */
class TacticalClientCmdTimeoutTest extends TestCase
{
    /** @var array<int, array{request: mixed, options: array<string, mixed>}> */
    private array $history = [];

    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];

        parent::tearDown();
    }

    /** @param array<int, Response|\Throwable> $queue */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
        ]));
    }

    /** @return array<string, mixed> */
    private function lastOptions(): array
    {
        return $this->history[array_key_last($this->history)]['options'];
    }

    // ── timeout budget ──────────────────────────────────────────────────

    public function test_a_command_timeout_above_30s_is_not_cut_at_30s(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode('done'))]);

        $client->cmd('AGENT-1', 'long-job', 'powershell', 25);

        $this->assertSame(40.0, $this->lastOptions()['timeout'], 'budget = command timeout + 15s margin');
        $this->assertSame(10.0, $this->lastOptions()['connect_timeout'], 'connect budget stays short');
    }

    public function test_the_minimum_command_timeout_still_gets_the_margin(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode('done'))]);

        $client->cmd('AGENT-1', 'hostname', 'cmd', 10);

        $this->assertSame(25.0, $this->lastOptions()['timeout']);
    }

    public function test_the_budget_is_capped(): void
    {
        $client = $this->clientReturning([
            new Response(200, [], json_encode('done')),
            new Response(200, [], json_encode('done')),
            new Response(200, [], json_encode('done')),
        ]);

        $client->cmd('AGENT-1', 'long-job', 'shell', 30);
        $this->assertSame(45.0, $this->lastOptions()['timeout'], 'timeout + margin reaches the cap exactly');

        // Under nginx's 60s fastcgi_read_timeout default, which the documented
        // install does not raise: a longer command is cut by this client, where
        // the cut is classified and audited, not by nginx with a 504.
        $client->cmd('AGENT-1', 'long-job', 'shell', 600);
        $this->assertSame(45.0, $this->lastOptions()['timeout'], 'the validated maximum is held to the cap');

        // cmd() is public; a caller past RunCommandAction's 10..600 validation
        // still cannot hold a worker longer than the cap.
        $client->cmd('AGENT-1', 'long-job', 'shell', 5000);
        $this->assertSame(45.0, $this->lastOptions()['timeout']);
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveCommandTimeouts(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[DataProvider('nonPositiveCommandTimeouts')]
    public function test_a_non_positive_command_timeout_is_refused_and_nothing_is_sent(int $timeout): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode('done'))]);

        try {
            $client->cmd('AGENT-1', 'hostname', 'cmd', $timeout);
            $this->fail('A non-positive command timeout should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        $this->assertSame([], $this->history, 'nothing may reach the transport');
    }

    /** @return array<string, array{float}> */
    public static function unusablePostTimeouts(): array
    {
        return ['zero' => [0.0], 'negative' => [-1.0], 'NAN' => [NAN], 'INF' => [INF]];
    }

    #[DataProvider('unusablePostTimeouts')]
    public function test_post_refuses_an_unusable_per_request_timeout_like_patch(float $timeout): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        try {
            $client->post('agents/AGENT-1/cmd/', [], $timeout);
            $this->fail('An unusable per-request timeout should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('finite number greater than zero', $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function test_post_without_an_override_keeps_the_clients_own_timeout(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode([]))]);

        $client->post('agents/AGENT-1/reboot/', []);

        $this->assertSame(30, $this->lastOptions()['timeout']);
        $this->assertArrayNotHasKey('connect_timeout', $this->lastOptions());
    }

    // ── classification over real cURL ─────────────────────────────────────────────

    /** A loopback listener that accepts into the kernel backlog and never answers. */
    private function silentListener(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a loopback listener: {$errstr}");
        $this->sockets[] = $server;

        return (string) stream_socket_get_name($server, false);
    }

    /** A loopback address with nothing listening on it. */
    private function closedPort(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return $address;
    }

    /**
     * A client on Guzzle's default (cURL) handler; timeouts are kept short.
     * phpunit.xml points HTTP(S)_PROXY at the discard port so no test reaches
     * the network; these loopback-only clients bypass it explicitly, or every
     * case would measure the proxy's refusal instead of the socket under test.
     */
    private function realClient(string $baseUri, array $options = []): TacticalClient
    {
        $this->assertSame('127.0.0.1', parse_url($baseUri, PHP_URL_HOST), 'loopback only');

        return new TacticalClient(new GuzzleClient($options + [
            'base_uri' => $baseUri,
            'timeout' => 1,
            'connect_timeout' => 1,
            'proxy' => '',
        ]));
    }

    private function failureOf(callable $call): TacticalClientException
    {
        try {
            $call();
        } catch (TacticalClientException $e) {
            return $e;
        }

        $this->fail('The call was expected to fail at the transport.');
    }

    public function test_a_read_timeout_after_the_request_was_sent_is_outcome_unknown(): void
    {
        $address = $this->silentListener();
        $client = $this->realClient("http://{$address}/");

        $e = $this->failureOf(fn () => $client->post('agents/AGENT-1/cmd/', ['cmd' => 'hostname'], 1.0));

        $this->assertTrue($e->isTransportFailure());
        $this->assertNull($e->statusCode());
        $this->assertSame(28, $e->getPrevious()->getHandlerContext()['errno'], 'precondition: cURL timed out');
        $this->assertTrue($e->timedOutAfterSend(), 'the request was written; the far end may have acted on it');
    }

    public function test_a_timeout_before_the_connection_completed_is_not_outcome_unknown(): void
    {
        // TCP connects, but the listener never speaks TLS, so the handshake
        // is what times out: the same cURL errno 28 as the read timeout above.
        $address = $this->silentListener();
        $client = $this->realClient("https://{$address}/", ['verify' => false]);

        $e = $this->failureOf(fn () => $client->post('agents/AGENT-1/cmd/', ['cmd' => 'hostname'], 1.0));

        $this->assertTrue($e->isTransportFailure());
        $this->assertSame(28, $e->getPrevious()->getHandlerContext()['errno'], 'precondition: same errno as the read timeout');
        $this->assertFalse($e->timedOutAfterSend(), 'nothing was sent; this is offline, not unknown');
    }

    public function test_a_refused_connection_is_not_outcome_unknown(): void
    {
        $client = $this->realClient('http://'.$this->closedPort().'/');

        $e = $this->failureOf(fn () => $client->post('agents/AGENT-1/cmd/', ['cmd' => 'hostname'], 1.0));

        $this->assertTrue($e->isTransportFailure());
        $this->assertSame(7, $e->getPrevious()->getHandlerContext()['errno'], 'precondition: the refusal came from the closed port');
        $this->assertFalse($e->timedOutAfterSend());
    }

    public function test_a_failure_that_carried_an_http_response_is_never_outcome_unknown(): void
    {
        // Even when a caller builds the exception by hand with the flag set, an
        // HTTP answer means Tactical replied, so the outcome is not unknown.
        $e = new TacticalClientException('Tactical API error (HTTP 504)', statusCode: 504, transportFailure: false, timedOutAfterSend: true);

        $this->assertFalse($e->timedOutAfterSend());
    }
}
