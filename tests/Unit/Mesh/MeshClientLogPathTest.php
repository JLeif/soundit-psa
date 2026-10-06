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
 * went through the swapped-in handler and nowhere else; the handler also
 * records the URI it received, which must still carry the Mesh id (only the
 * log line is redacted). The ONE record logged is pinned by exact equality.
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
     * Endpoint as passed to get() => the path the log line must show.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        $id = self::MESH_ID;
        $q = '?filter='.self::QUERY_MARKER.'&_size=1';

        return [
            'customer read (getCustomer shape)' => ["api/customers/{$id}/", 'api/customers/<customer>'],
            'leading slash' => ["/api/customers/{$id}/", '/api/customers/<customer>'],
            'absolute URL' => ['https://'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>'],
            'scheme-relative URL' => ['//'.self::HOST."/api/customers/{$id}/", '/api/customers/<customer>'],
            'versioned prefix' => ["api/v2/customers/{$id}/", 'api/v2/customers/<customer>'],
            'nested prefix' => ["api/partners/p-1/customers/{$id}/", 'api/partners/p-1/customers/<customer>'],
            'id containing a slash' => ["api/customers/abc/{$id}/", 'api/customers/<customer>'],
            'sub-resource' => ["api/customers/{$id}/licenses/", 'api/customers/<customer>'],
            'id upper-cased' => ['api/customers/'.strtoupper($id).'/', 'api/customers/<customer>'],
            'id dashless' => ['api/customers/'.str_replace('-', '', $id).'/', 'api/customers/<customer>'],
            'id, no trailing slash' => ["api/customers/{$id}", 'api/customers/<customer>'],
            'id with a query' => ["api/customers/{$id}/{$q}", 'api/customers/<customer>'],
            'id with a fragment' => ["api/customers/{$id}/#frag", 'api/customers/<customer>'],
            'customer list with a query' => ["api/customers/{$q}", 'api/customers/'],
            'customer list' => ['api/customers/', 'api/customers/'],
            'leading ?' => [$q, ''],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_failure_line_redacts_the_customer_tail(string $endpoint, string $expectedPath): void
    {
        [$client, $mock, $seen] = $this->clientAnswering503();

        try {
            $client->get($endpoint);
            $this->fail('the 503 must throw');
        } catch (MeshClientException $e) {
            // The rethrown exception is unchanged: it still wraps Guzzle's.
            $this->assertInstanceOf(ServerException::class, $e->getPrevious());
        }

        $this->assertSame(0, $mock->count(), 'the request went through the swapped-in MockHandler');
        $this->assertCount(1, $seen->uris, 'exactly one request reached the handler');
        if (str_contains($endpoint, self::MESH_ID)) {
            $this->assertStringContainsString(self::MESH_ID, $seen->uris[0], 'positive control: the REQUEST still names the Mesh customer');
        }

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

    /** The kept shape the issue requires: GET api/customers/ survives. */
    public function test_the_customer_list_path_is_kept(): void
    {
        [$client] = $this->clientAnswering503();

        try {
            $client->get('api/customers/', ['_size' => 1, 'filter' => self::QUERY_MARKER]);
        } catch (MeshClientException) {
        }

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('GET api/customers/ failed with HTTP 503', $this->logged[0]['message']);
        $this->assertStringNotContainsString(self::QUERY_MARKER, $this->logged[0]['message']);
    }

    /** The leak assertion can see each form: a line carrying it fails. */
    public function test_the_leak_assertion_fails_on_each_form(): void
    {
        foreach ($this->leakForms() as $what => $form) {
            $line = "[MeshClient] GET api/customers/{$form} failed with HTTP 503";
            try {
                $this->assertStringNotContainsStringIgnoringCase($form, $line);
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                continue;
            }
            $this->fail("positive control: a line carrying the {$what} passed");
        }
        $this->assertCount(6, $this->leakForms());
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
