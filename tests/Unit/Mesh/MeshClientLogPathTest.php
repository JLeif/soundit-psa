<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
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
 * handler also records the URI it received, and the row pins that URI's
 * scheme and host, its exact path, its query and its fragment: the path keeps
 * the id in the form it was built with (upper-cased or dashless included, a
 * newline percent-encoded), so only the log line is redacted, never the
 * request (#5334). The endpoint's own query is NOT carried: get() always
 * passes Guzzle a 'query' option, which replaces it (empty here), and the
 * query rows pin that empty query. A fragment is carried on the request URI
 * and dropped only from the log line (#5378, #5379, #5380, #5385, #5387). The
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
     * Endpoint as passed to get() => the path the log line must show => the
     * exact path of the URI the handler received => its exact query (default
     * '') => its exact fragment (default '').
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3?: string, 4?: string}>
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
            // Query rows. get() always passes Guzzle a 'query' option, and
            // that option REPLACES a query written into the endpoint: with
            // get()'s default [] the request carries the endpoint's path and
            // an empty query (measured; pinned by the '' query below).
            'id with a query' => ["api/customers/{$id}/{$q}", 'api/customers/<customer>', "/api/customers/{$id}/", ''],
            'customer list with a query' => ["api/customers/{$q}", 'api/customers/', '/api/customers/', ''],
            // A bare query resolves against base_uri: path '/', query
            // replaced by the empty option (measured).
            'leading ?' => [$q, '', '/', ''],
            // Fragment row. The query option does not touch a fragment: the
            // request URI KEEPS '#frag' (Guzzle's resolver carries the
            // relative fragment), and only the log line drops it.
            'id with a fragment' => ["api/customers/{$id}/#frag", 'api/customers/<customer>', "/api/customers/{$id}/", '', 'frag'],
            // #5337: the regex flags and the segment boundary. The request
            // path carries the newline percent-encoded.
            'id with a newline (s flag)' => ["api/customers/{$nl}/", 'api/customers/<customer>', '/api/customers/'.str_replace("\n", '%0A', $nl).'/'],
            'upper-case scheme (i flag, host strip)' => ['HTTPS://'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            'upper-case Customers/ (i flag, redaction)' => ["api/Customers/{$id}/", 'api/Customers/<customer>', "/api/Customers/{$id}/"],
            'bare customers/ at the start (^ branch)' => ["customers/{$id}/", 'customers/<customer>', "/customers/{$id}/"],
            // Not a customers/ segment, so nothing is redacted. The tail is
            // deliberately not an id, so the leak checks still hold.
            'xcustomers/ is not a segment (boundary)' => ['api/xcustomers/p-7/', 'api/xcustomers/p-7/', '/api/xcustomers/p-7/'],
            'customer list' => ['api/customers/', 'api/customers/', '/api/customers/'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_failure_line_redacts_the_customer_tail(
        string $endpoint,
        string $expectedPath,
        string $requestPath,
        string $requestQuery = '',
        string $requestFragment = '',
    ): void {
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
        // Positive control, every shape: the REQUEST is not redacted. Each
        // URI component is pinned exactly, so a request sent to any other
        // path, or carrying the endpoint's own query, fails here.
        $uri = new Uri($seen->uris[0]);
        $this->assertSame('https', $uri->getScheme(), 'positive control: request scheme');
        $this->assertSame(self::HOST, $uri->getHost(), 'positive control: request host');
        $this->assertSame($requestPath, $uri->getPath(), 'positive control: the request path keeps the id as built');
        $this->assertSame($requestQuery, $uri->getQuery(), "positive control: the request query is get()'s query option, not the endpoint's");
        $this->assertSame($requestFragment, $uri->getFragment(), 'positive control: the request fragment');

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
        $uri = new Uri($seen->uris[0]);
        $this->assertSame('/api/customers/', $uri->getPath(), 'positive control: the request path');
        $this->assertSame('_size=1&filter='.self::QUERY_MARKER, $uri->getQuery(), 'positive control: the request carried the query option');

        $this->assertCount(1, $this->logged);
        $this->assertSame(
            '[MeshClient] GET api/customers/ failed with HTTP 503 ('.ServerException::class.')',
            $this->logged[0]['message'],
        );
    }

    /**
     * logPath() itself, reached by reflection, on every shape: each comes
     * back exactly as the row expects (so an identity logPath() fails on
     * every changed row, #5335), and a changed row is free of every leak form
     * the endpoint held. The rows are counted by kind with exact numbers: 17
     * redact a customer tail to <customer>, 2 are changed only by the query
     * cut ('customer list with a query', 'leading ?'), 2 come back as given
     * (#5383). Dropping or unredacting a row changes a count.
     */
    public function test_log_path_changes_every_redacted_shape(): void
    {
        $logPath = new \ReflectionMethod(MeshClient::class, 'logPath');
        $redacted = 0;
        $queryCutOnly = 0;
        $kept = 0;

        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            $out = $logPath->invoke(null, $endpoint);
            $this->assertSame($expectedPath, $out, "{$name}: logPath()");
            if ($out === $endpoint) {
                $kept++;

                continue;
            }
            if (str_contains($out, '<customer>')) {
                $redacted++;
            } else {
                $queryCutOnly++;
            }
            foreach ($this->leakForms() as $what => $form) {
                if (stripos($endpoint, $form) !== false) {
                    $this->assertStringNotContainsStringIgnoringCase($form, $out, "{$name}: {$what} survived logPath()");
                }
            }
        }

        $this->assertSame(17, $redacted, 'rows whose customer tail logPath() redacts');
        $this->assertSame(2, $queryCutOnly, 'rows changed only by the query cut');
        $this->assertSame(2, $kept, 'rows logPath() keeps as given');
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
