<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5323 / #5329 (card 6ac53e69): MeshClient's failure line redacts the whole
 * path after a customers/ segment, wherever that segment sits, with the
 * literal token <customer>, which is not PSR-3 {placeholder} syntax.
 *
 * Each shape is driven through the public get() -> request() over a real
 * MeshClient whose Guzzle is a MockHandler answering 503. The handler queue
 * holds exactly one response and must be empty afterwards, so the request
 * went through the swapped-in handler and nowhere else. For EVERY shape the
 * handler also records the URI it received, which must still carry the form
 * the endpoint was built with (the id as given, upper-cased or dashless
 * included): only the log line is redacted, never the request (#5334). The
 * rethrown exception is checked to be a MeshClientException with Guzzle's
 * code (503) whose message is 'Mesh API error: ' plus Guzzle's own message,
 * wrapping that ServerException; this test does not make its content safe
 * (see MeshClient::request()). The ONE record logged is pinned by exact
 * equality.
 *
 * Synthetic data only (G-13): a made-up Mesh uuid, host and query marker.
 */
class MeshClientLogPathTest extends TestCase
{
    private const HOST = 'mesh-logpath.example.test';

    private const MESH_ID = '7b2e9d41-5c3a-4f86-a0d2-e91c4b7f3a65';

    private const QUERY_MARKER = 'LOGPATH-QUERY-5c19e2';

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    /**
     * Endpoint as passed to get() => the path the log line must show => a
     * substring the URI the handler received must still contain.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function shapes(): array
    {
        $id = self::MESH_ID;
        $up = strtoupper($id);
        $bare = str_replace('-', '', $id);
        // An id with a newline in it (mesh_customer_id is stored raw): '.+'
        // reaches past the newline only under the s flag (#5337).
        $nl = substr($id, 0, 8)."\n".substr($id, 9);
        $q = '?filter='.self::QUERY_MARKER.'&_size=1';

        return [
            'customer read (getCustomer shape)' => ["api/customers/{$id}/", 'api/customers/<customer>', "/api/customers/{$id}/"],
            'leading slash' => ["/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            'absolute URL' => ['https://'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            'scheme-relative URL' => ['//'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            'versioned prefix' => ["api/v2/customers/{$id}/", 'api/v2/customers/<customer>', "/api/v2/customers/{$id}/"],
            'nested prefix' => ["api/partners/p-1/customers/{$id}/", 'api/partners/p-1/customers/<customer>', "/api/partners/p-1/customers/{$id}/"],
            'id containing a slash' => ["api/customers/abc/{$id}/", 'api/customers/<customer>', "/api/customers/abc/{$id}/"],
            'sub-resource' => ["api/customers/{$id}/licenses/", 'api/customers/<customer>', "/api/customers/{$id}/licenses/"],
            'id upper-cased' => ["api/customers/{$up}/", 'api/customers/<customer>', "/api/customers/{$up}/"],
            'id dashless' => ["api/customers/{$bare}/", 'api/customers/<customer>', "/api/customers/{$bare}/"],
            'id, no trailing slash' => ["api/customers/{$id}", 'api/customers/<customer>', "/api/customers/{$id}"],
            // get() always passes Guzzle a 'query' option (empty here), which
            // replaces a query written into the endpoint: the request carries
            // the path only, so that is what these rows expect it to carry.
            'id with a query' => ["api/customers/{$id}/{$q}", 'api/customers/<customer>', "/api/customers/{$id}/"],
            'id with a fragment' => ["api/customers/{$id}/#frag", 'api/customers/<customer>', "/api/customers/{$id}/"],
            // #5337: the regex flags and the segment boundary.
            'id with a newline (s flag)' => ["api/customers/{$nl}/", 'api/customers/<customer>', '/api/customers/'.str_replace("\n", '%0A', $nl).'/'],
            'upper-case scheme (i flag, host strip)' => ['HTTPS://'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            'upper-case Customers/ (i flag, redaction)' => ["api/Customers/{$id}/", 'api/Customers/<customer>', "/api/Customers/{$id}/"],
            'bare customers/ at the start (^ branch)' => ["customers/{$id}/", 'customers/<customer>', "/customers/{$id}/"],
            // Not a customers/ segment, so nothing is redacted. The tail is
            // deliberately not an id, so the leak checks still hold.
            'xcustomers/ is not a segment (boundary)' => ['api/xcustomers/p-7/', 'api/xcustomers/p-7/', '/api/xcustomers/p-7/'],
            'customer list with a query' => ["api/customers/{$q}", 'api/customers/', '/api/customers/'],
            'customer list' => ['api/customers/', 'api/customers/', '/api/customers/'],
            'leading ?' => [$q, '', 'https://'.self::HOST.'/'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_failure_line_redacts_the_customer_tail(string $endpoint, string $expectedPath, string $requestCarries): void
    {
        [$client, $mock, $seen] = $this->clientAnswering503();

        try {
            $client->get($endpoint);
            $this->fail('the 503 must throw');
        } catch (MeshClientException $e) {
            // What the rethrow is (not that its content is safe: it is not).
            $this->assertSame(MeshClientException::class, $e::class);
            $previous = $e->getPrevious();
            $this->assertInstanceOf(ServerException::class, $previous);
            $this->assertSame(ServerException::class, $previous::class);
            $this->assertSame(503, $e->getCode(), "the rethrow keeps Guzzle's code");
            $this->assertSame('Mesh API error: '.$previous->getMessage(), $e->getMessage());
        }

        $this->assertSame(0, $mock->count(), 'the request went through the swapped-in MockHandler');
        $this->assertCount(1, $seen->uris, 'exactly one request reached the handler');
        // Positive control, every shape: the REQUEST is not redacted.
        $this->assertStringContainsString($requestCarries, $seen->uris[0], 'positive control: the request still carries the endpoint as built');

        $this->assertCount(1, $this->logged, 'records: '.json_encode($this->logged));
        $message = $this->logged[0]['message'];

        // Leaks first, so a failure names its cause before the exact pin does.
        foreach ($this->leakForms() as $what => $form) {
            $this->assertStringNotContainsStringIgnoringCase($form, $message, "{$what} reached the log");
        }
        $this->assertStringNotContainsString('{', $message, 'no PSR-3 placeholder syntax in the logged path');
        $this->assertStringNotContainsString('}', $message, 'no PSR-3 placeholder syntax in the logged path');

        // The complete record: one error line, exact text, no context.
        $this->assertSame('error', $this->logged[0]['level']);
        $this->assertSame([], $this->logged[0]['context']);
        $this->assertSame(
            "[MeshClient] GET {$expectedPath} failed with HTTP 503 (".ServerException::class.')',
            $message,
        );
    }

    /**
     * The kept shape the issue requires: GET api/customers/ survives. The
     * query travels as get()'s second argument, i.e. as Guzzle's 'query'
     * option, never inside $endpoint, so logPath()'s '?'/'#' cut is not what
     * keeps it out of this line (the 'customer list with a query' shape pins
     * that cut). Here the request is checked to have carried the query and
     * the line to have dropped it.
     */
    public function test_the_customer_list_path_is_kept(): void
    {
        [$client, $mock, $seen] = $this->clientAnswering503();

        try {
            $client->get('api/customers/', ['_size' => 1, 'filter' => self::QUERY_MARKER]);
            $this->fail('the 503 must throw');
        } catch (MeshClientException) {
        }

        $this->assertSame(0, $mock->count(), 'the request went through the swapped-in MockHandler');
        $this->assertCount(1, $seen->uris, 'exactly one request reached the handler');
        $this->assertStringContainsString('filter='.self::QUERY_MARKER, $seen->uris[0], 'positive control: the request carried the query');

        $this->assertCount(1, $this->logged);
        $this->assertSame(
            '[MeshClient] GET api/customers/ failed with HTTP 503 ('.ServerException::class.')',
            $this->logged[0]['message'],
        );
    }

    /**
     * logPath() itself, reached by reflection, on the shapes whose line must
     * differ from the endpoint: each comes back changed and free of every
     * leak form, and a kept shape comes back as given. An identity logPath()
     * (returning its input) fails here (#5335; this replaces a check that
     * only exercised PHPUnit).
     */
    public function test_log_path_changes_every_redacted_shape(): void
    {
        $logPath = new \ReflectionMethod(MeshClient::class, 'logPath');
        $redacted = 0;

        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            $out = $logPath->invoke(null, $endpoint);
            $this->assertSame($expectedPath, $out, "{$name}: logPath()");
            if ($expectedPath === $endpoint) {
                continue;
            }
            $redacted++;
            $this->assertNotSame($endpoint, $out, "{$name}: logPath() returned its input");
            foreach ($this->leakForms() as $what => $form) {
                if (stripos($endpoint, $form) !== false) {
                    $this->assertStringNotContainsStringIgnoringCase($form, $out, "{$name}: {$what} survived logPath()");
                }
            }
        }

        $this->assertGreaterThanOrEqual(15, $redacted, 'positive control: the shapes include the redacted ones');
    }

    /** @return array<string, string> */
    private function leakForms(): array
    {
        return [
            'Mesh id' => self::MESH_ID,
            'Mesh id, dashless' => str_replace('-', '', self::MESH_ID),
            'Mesh id, upper-cased' => strtoupper(self::MESH_ID),
            'query marker' => self::QUERY_MARKER,
            'host' => self::HOST,
            'id prefix segment' => 'abc/',
        ];
    }

    /**
     * A real MeshClient whose Guzzle is a one-response MockHandler (503).
     *
     * @return array{0: MeshClient, 1: MockHandler, 2: object{uris: list<string>}}
     */
    private function clientAnswering503(): array
    {
        $mock = new MockHandler([new Response(503, [], 'vendor body')]);
        $seen = new class
        {
            /** @var list<string> */
            public array $uris = [];
        };
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $next) use ($seen) {
            return function (RequestInterface $r, array $o) use ($next, $seen) {
                $seen->uris[] = (string) $r->getUri();

                return $next($r, $o);
            };
        });

        $client = new MeshClient(['api_key' => 'synthetic-key', 'base_url' => 'https://'.self::HOST]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => $stack,
        ]));

        return [$client, $mock, $seen];
    }
}
