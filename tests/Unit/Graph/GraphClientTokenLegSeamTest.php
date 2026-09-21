<?php

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The OAuth2 token leg ($authHttp) must honour the same config `handler` seam as the three
 * Graph clients, because it is the ONLY request that carries `client_secret`.
 *
 * Before this seam existed, the one credential-bearing call was the one no test could observe
 * or refuse: every wire-level control in this suite works by injecting a recording/refusing
 * handler through config['handler'], and $authHttp ignored it. A guard asserting "this command
 * sent nothing" was structurally blind to the single leg carrying a secret to Microsoft, and
 * the only thing keeping such a request on the box was the phpunit.xml proxy pin — which is
 * bypassable by an ambient environment variable (see tests/Unit/OutboundNetworkGuardTest.php).
 *
 * These controls capture the OUTGOING token request, so a regression that stops honouring the
 * seam fails HERE with a named cause rather than silently dialling login.microsoftonline.com.
 */
class GraphClientTokenLegSeamTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * A GraphClient with an EMPTY cache, so the token leg is forced to run rather than
     * being satisfied by a pre-seeded token.
     */
    private function client(Response $tokenResponse): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$tokenResponse]));
        $stack->push(Middleware::history($this->history));

        return new GraphClient([
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'test-secret-not-a-real-credential',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], new Repository(new ArrayStore));
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], (string) json_encode([
            'access_token' => 'issued-token',
            'expires_in' => 3600,
        ]));
    }

    public function test_the_token_request_routes_through_the_injected_handler(): void
    {
        $client = $this->client($this->tokenResponse());

        $method = new \ReflectionMethod($client, 'getToken');
        $method->setAccessible(true);
        $token = $method->invoke($client);

        $this->assertNotEmpty(
            $this->history,
            'The OAuth2 token request did NOT pass through the injected handler. $authHttp has '
            .'stopped honouring config[\'handler\'], so the one request carrying client_secret '
            .'is unobservable and would be dialled at login.microsoftonline.com for real.'
        );

        $request = $this->history[array_key_last($this->history)]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString(
            'tenant/oauth2/v2.0/token',
            (string) $request->getUri(),
            'The captured request is not the token request this control exists to observe.'
        );
        $this->assertSame('issued-token', $token);
    }

    /**
     * The point of the seam: a test can now REFUSE the credential-bearing request, not merely
     * watch it. Without the seam this throws a real connection error instead.
     */
    public function test_the_token_request_can_be_refused_by_the_seam(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], (string) json_encode(['error' => 'invalid_client'])),
        ]));
        $stack->push(Middleware::history($this->history));

        $client = new GraphClient([
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'test-secret-not-a-real-credential',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], new Repository(new ArrayStore));

        $method = new \ReflectionMethod($client, 'getToken');
        $method->setAccessible(true);

        try {
            $method->invoke($client);
            $this->fail('The refused token request did not surface as an exception.');
        } catch (\Throwable $exception) {
            $this->assertNotEmpty(
                $this->history,
                'The refusal came from somewhere other than the injected handler, so the seam '
                .'is not carrying the token leg.'
            );
            $this->assertStringNotContainsString(
                'cURL error',
                $exception->getMessage(),
                'The token leg attempted a REAL connection: the handler seam is not applied, so '
                .'this request left the test harness.'
            );
        }
    }
}
