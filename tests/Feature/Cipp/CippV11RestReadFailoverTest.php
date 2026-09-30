<?php

namespace Tests\Feature\Cipp;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpClient;
use App\Support\CippConfig;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CIPP v11 removed the resource CippMcpClient::getToken() asks for
 * (api://{mcp_client_id}/.default), so every app-only MCP sign-in now fails
 * with AADSTS500011 and, before this fix, every curated cipp_list_* read
 * answered with that error (card 6abc4adc, 2026-09-29).
 *
 * The two transports use different HTTP stacks, and each is faked at its own:
 *   - CippMcpClient uses the Http facade, so Http::fake() sees its token and
 *     ExecMCP requests.
 *   - CippClient uses raw Guzzle, which Http::fake() cannot see, so it gets an
 *     injected Guzzle MockHandler and a pre-seeded REST token.
 * Http::preventStrayRequests() makes any unfaked facade request fail the test.
 */
class CippV11RestReadFailoverTest extends TestCase
{
    use RefreshDatabase;

    private const AADSTS = 'AADSTS500011';

    /** @var array<int, array<string, mixed>> */
    private array $restHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'rest-client');
        Setting::setEncrypted('cipp_client_secret', 'rest-secret');
    }

    /**
     * No MCP credentials at all, with the relay switch ON (so only the missing
     * credentials keep the relay out). The read is served over REST and nothing
     * is sent through the Http facade: no token request, no ExecMCP call.
     */
    public function test_read_is_served_over_rest_with_no_mcp_credentials(): void
    {
        Setting::setValue('cipp_mcp_enabled', '1');
        $this->assertFalse(CippConfig::isMcpConfigured());

        Log::spy();
        Http::fake();
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $response->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('alex@acme.example', $text);
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
        Http::assertNothingSent();
        $this->assertFallbackLogged('switched on but not configured');
    }

    /**
     * The production state on 2026-09-29: MCP credentials saved, relay on, and
     * Microsoft refusing the MCP resource. The read must still answer, from REST,
     * and the sign-in error must not reach the caller.
     */
    public function test_mcp_sign_in_rejection_fails_over_to_rest_without_surfacing_the_aadsts_error(): void
    {
        Log::spy();
        $this->withMcpCredentials();
        $this->fakeMcpSignInRejected();
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $response->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('alex@acme.example', $text);
        $this->assertStringNotContainsString(self::AADSTS, $text);
        $this->assertStringNotContainsString('OAuth', $text);
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());

        // The MCP transport really was tried first, and never reached ExecMCP.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com/tenant-1/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/ExecMCP'));
        $this->assertFallbackLogged('could not sign in');
    }

    /**
     * Relay switched off (the default): REST is simply the transport, not a
     * fallback, so nothing is logged as one.
     */
    public function test_relay_off_serves_rest_without_logging_a_fallback(): void
    {
        Log::spy();
        Http::fake();
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($message): bool => str_contains((string) $message, 'serving the read over the REST API')), \Mockery::any()]);
    }

    /**
     * MCP credentials saved but unreadable (e.g. encrypted under another APP_KEY)
     * with the relay switched on: the read is served over REST and logged, not
     * thrown out of the tool call.
     */
    public function test_unreadable_mcp_settings_fail_over_to_rest(): void
    {
        Log::spy();
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setValue('cipp_mcp_client_secret', 'not-a-payload-this-key-can-open');
        Setting::setValue('cipp_mcp_enabled', '1');
        Http::fake();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $result = (new AssistantToolExecutor(null, $client->id, null))->execute('cipp_list_users', []);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
        Http::assertNothingSent();
        $this->assertFallbackLogged('settings could not be read');
    }

    private function assertFallbackLogged(string $cause): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message): bool => str_contains((string) $message, 'serving the read over the REST API')
                && str_contains((string) $message, $cause))
            ->once();
    }

    /**
     * A token endpoint that answers 200 without an access_token is also a failed
     * sign-in: nothing was sent to ExecMCP.
     */
    public function test_token_response_without_access_token_fails_over_to_rest(): void
    {
        $this->withMcpCredentials();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer'], 200)]);
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('alex@acme.example', $text);
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
    }

    /**
     * A token Microsoft issues but ExecMCP refuses (HTTP 401) is a failed sign-in
     * too, and fails over the same way.
     */
    public function test_exec_mcp_401_fails_over_to_rest(): void
    {
        $this->withMcpCredentials();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'MCP-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ExecMCP*' => Http::response('Unauthorized', 401),
        ]);
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('alex@acme.example', $text);
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
    }

    /**
     * The failover is for sign-in failures ONLY. Once ExecMCP has actually run the
     * query, its error is CIPP's answer and must reach the caller, not be papered
     * over by asking again somewhere else.
     */
    public function test_an_exec_mcp_error_after_sign_in_is_not_failed_over(): void
    {
        $this->withMcpCredentials();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'MCP-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ExecMCP*' => Http::response('upstream exploded', 500),
        ]);
        $this->bindRealMcpClient();
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $text = (string) $response->json('result.content.0.text');
        $this->assertTrue((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('HTTP 500', $text);
        $this->assertSame([], $this->restRequests());
    }

    /**
     * The REST fallback keeps the per-tool argument mapping: ListUserMailboxRules
     * reads UserID (not userId) plus userEmail, and a wrong name there turns a
     * one-mailbox read into a tenant-wide one (psa-7lgo.1). Driven on the
     * Assistant executor directly, the other consumer of the relay.
     */
    public function test_user_scoped_read_fails_over_with_the_rest_argument_names(): void
    {
        $this->withMcpCredentials();
        $this->fakeMcpSignInRejected();
        $this->bindRealMcpClient();
        $this->bindRestClient([new Response(200, ['Content-Type' => 'application/json'], json_encode([[
            'Name' => 'Forward all',
            'Enabled' => true,
        ]]))]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);
        $objectId = '22222222-2222-2222-2222-222222222222';

        $result = (new AssistantToolExecutor(null, $client->id, null))
            ->execute('cipp_list_mailbox_rules', ['user_id' => $objectId]);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertStringNotContainsString(self::AADSTS, (string) json_encode($result));
        $this->assertSame(["/api/ListUserMailboxRules?TenantFilter=acme.example&UserID={$objectId}"], $this->restRequests());
    }

    /**
     * Settings say MCP is configured, but the CippMcpClient in the container was
     * built without credentials (CippMcpClient is a singleton, so a long-lived
     * process keeps the config it was first resolved with). Its "not configured"
     * refusal is a sign-in failure too, and fails over.
     */
    public function test_mcp_client_built_without_credentials_fails_over_to_rest(): void
    {
        $this->withMcpCredentials();
        Http::fake();
        $this->app->instance(CippMcpClient::class, new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => '',
            'client_secret' => '',
        ], app(CacheInterface::class), fn (string $host): array => ['93.184.216.34']));
        $this->bindRestClient([$this->usersResponse()]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $response = $this->callStaffTool('cipp_list_users', ['client_id' => $client->id]);

        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('alex@acme.example', $text);
        $this->assertSame(['/api/ListUsers?TenantFilter=acme.example'], $this->restRequests());
        Http::assertNothingSent();
    }

    /**
     * Dynamic catalog tools exist only over ExecMCP, so there is nothing to fail
     * over to. They must still refuse without handing the AADSTS text to the caller.
     */
    public function test_catalog_tool_sign_in_failure_refuses_without_the_aadsts_error(): void
    {
        $this->withMcpCredentials();
        $this->fakeMcpSignInRejected();
        $this->bindRealMcpClient();
        \App\Models\CippMcpTool::create([
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'category' => 'CIPP',
            'description' => '[CIPP] List DB cache.',
            'input_schema' => ['type' => 'object', 'properties' => ['tenantFilter' => ['type' => 'string']], 'required' => ['tenantFilter']],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);

        $result = app(\App\Services\Cipp\CippMcpDynamicToolExecutor::class)
            ->execute('cipp_list_db_cache', ['client_id' => $client->id], $client, $client->id);

        $this->assertStringContainsString('could not sign in to CIPP MCP', (string) ($result['error'] ?? ''), json_encode($result));
        $this->assertStringNotContainsString(self::AADSTS, (string) json_encode($result));
    }

    private function withMcpCredentials(): void
    {
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');
    }

    /**
     * The real CippMcpClient, with a resolver that answers a public address so
     * the only thing that can fail is what the test fakes.
     */
    private function bindRealMcpClient(): void
    {
        $this->app->instance(CippMcpClient::class, new CippMcpClient([
            'api_url' => CippConfig::get('api_url'),
            'tenant_id' => CippConfig::get('tenant_id'),
            'client_id' => CippConfig::get('mcp_client_id'),
            'client_secret' => CippConfig::get('mcp_client_secret'),
        ], app(CacheInterface::class), fn (string $host): array => ['93.184.216.34']));
    }

    /** Microsoft's token endpoint rejecting the MCP resource, as it does since CIPP v11. */
    private function fakeMcpSignInRejected(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_resource',
                'error_description' => self::AADSTS.': The resource principal named api://mcp-client was not found in the tenant named tenant-1.',
            ], 400),
        ]);
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function bindRestClient(array $responses): void
    {
        Cache::put('cipp_oauth_token', 'rest-token', 3600);

        $this->restHistory = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->restHistory));

        $this->app->instance(CippClient::class, new CippClient(
            ['api_url' => 'https://cipp.example.test'],
            app(CacheInterface::class),
            new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://cipp.example.test/']),
        ));
    }

    private function usersResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['Results' => [[
            'id' => 'user-1',
            'displayName' => 'Alex Acme',
            'userPrincipalName' => 'alex@acme.example',
            'accountEnabled' => true,
        ]]]));
    }

    /** @return array<int, string> "<path>?<decoded query>" per REST request sent */
    private function restRequests(): array
    {
        return array_map(
            fn (array $entry): string => $entry['request']->getUri()->getPath().'?'.urldecode($entry['request']->getUri()->getQuery()),
            $this->restHistory,
        );
    }

    private function callStaffTool(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [$name], label: 'chet');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }
}
