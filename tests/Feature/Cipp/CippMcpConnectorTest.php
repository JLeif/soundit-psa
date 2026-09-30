<?php

namespace Tests\Feature\Cipp;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cipp\CippMcpAuthException;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippMcpConnector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * CIPP MCP delegated-auth connector (card 6abc4adc leg 2) and the #4393
 * negative token cache.
 *
 * Token and ExecMCP traffic goes through the Http facade, so it is faked with
 * Http::fake() and counted with a Guzzle history middleware pushed onto the
 * facade's global stack (the history seam); Http::preventStrayRequests() fails
 * the test on anything unfaked. Canary token strings are used so a leak can be
 * searched for byte-for-byte in logs, the session, alerts and the database.
 */
class CippMcpConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const REFRESH = 'RT-CANARY-9f3a1c-refresh-token';

    private const ROTATED = 'RT-CANARY-rotated-77e0b2';

    private const ACCESS = 'AT-CANARY-5d8e44-access-token';

    private const CODE = 'AUTHCODE-CANARY-1b2c3d';

    private const HOST = 'api://cipp-mcp-backend.example.test';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        $this->history = [];
        Http::globalMiddleware(\GuzzleHttp\Middleware::history($this->history));

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'rest-client');
        Setting::setEncrypted('cipp_client_secret', 'rest-secret');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setValue('cipp_mcp_backend_host', self::HOST);
    }

    // --- connect: redirect + callback ------------------------------------------

    public function test_connect_redirects_with_pkce_s256_state_and_the_configured_delegated_scope(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('auth.cipp-mcp'));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/tenant-1/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('mcp-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile '.self::HOST.'/user_impersonation offline_access', $query['scope'], 'openid profile, or Entra returns no id_token to take the UPN from');
        $this->assertSame(route('auth.cipp-mcp.callback'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('cipp_mcp_oauth_state'), $query['state']);
        $this->assertSame(40, strlen($query['state']));

        $verifier = (string) session('cipp_mcp_oauth_verifier');
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertStringNotContainsString($verifier, $location, 'the verifier itself must never leave in the redirect');
        Http::assertNothingSent();
    }

    public function test_a_bare_backend_host_is_given_the_https_scheme(): void
    {
        Setting::setValue('cipp_mcp_backend_host', 'cipp-backend.example.test/');

        $this->assertSame('https://cipp-backend.example.test/user_impersonation offline_access', CippMcpConnector::scope());
    }

    public function test_connect_refuses_without_the_backend_host(): void
    {
        Setting::setValue('cipp_mcp_backend_host', null);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('auth.cipp-mcp'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error');
        $this->assertNull(session('cipp_mcp_oauth_state'));
    }

    public function test_connect_and_callback_are_admin_only(): void
    {
        $tech = User::factory()->tech()->create();

        $this->actingAs($tech)->get(route('auth.cipp-mcp'))->assertForbidden();
        $this->actingAs($tech)->get(route('auth.cipp-mcp.callback', ['state' => 'x', 'code' => 'y']))->assertForbidden();
    }

    public function test_callback_refuses_a_state_mismatch_and_exchanges_nothing(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['cipp_mcp_oauth_state' => str_repeat('a', 40), 'cipp_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.cipp-mcp.callback', ['state' => str_repeat('b', 40), 'code' => self::CODE]))
            ->assertStatus(400);

        Http::assertNothingSent();
        $this->assertFalse(app(CippMcpConnector::class)->isConnected());
        // The state is single-use: a replay after the refusal also fails.
        $this->assertNull(session('cipp_mcp_oauth_state'));
    }

    public function test_callback_refuses_when_no_state_was_issued(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('auth.cipp-mcp.callback', ['state' => '', 'code' => self::CODE]))
            ->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_callback_refuses_a_missing_verifier_and_exchanges_nothing(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $state = str_repeat('s', 40);

        $this->actingAs($admin)
            ->withSession(['cipp_mcp_oauth_state' => $state])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state, 'code' => self::CODE]))
            ->assertStatus(400);

        Http::assertNothingSent();
        $this->assertFalse(app(CippMcpConnector::class)->isConnected());
    }

    public function test_code_exchange_stores_the_refresh_token_encrypted_and_leaks_no_token(): void
    {
        $this->captureLogs();
        // Entra returns an id_token only because the sign-in asks for openid, and
        // preferred_username in it only because it asks for profile: the fake
        // endpoint applies that rule to the POSTed scope (entraTokenEndpoint).
        $idJwt = self::idToken(true);
        Http::fake(['login.microsoftonline.com/*' => $this->entraTokenEndpoint()]);
        $admin = User::factory()->admin()->create();
        $state = str_repeat('s', 40);
        $verifier = str_repeat('v', 64);

        $response = $this->actingAs($admin)
            ->withSession(['cipp_mcp_oauth_state' => $state, 'cipp_mcp_oauth_verifier' => $verifier])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state, 'code' => self::CODE]));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('success');

        // The exchange sent the code, the verifier and the delegated scope.
        $posts = $this->tokenPosts();
        $this->assertCount(1, $posts);
        $this->assertSame('authorization_code', $posts[0]['grant_type']);
        $this->assertSame(self::CODE, $posts[0]['code']);
        $this->assertSame($verifier, $posts[0]['code_verifier']);
        $this->assertSame(route('auth.cipp-mcp.callback'), $posts[0]['redirect_uri']);
        $this->assertSame('openid profile '.self::HOST.'/user_impersonation offline_access', $posts[0]['scope']);
        $this->assertArrayNotHasKey('client_secret', $posts[0], 'a public client sends no secret');

        // Stored ENCRYPTED: the raw column is not the token, and decrypts to it.
        $raw = (string) DB::table('settings')->where('key', CippMcpConnector::REFRESH_TOKEN_SETTING)->value('value');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::REFRESH, $raw);
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING));

        $status = app(CippMcpConnector::class)->status();
        $this->assertSame('connected', $status['state']);
        $this->assertSame('svc-cipp@msp.example', $status['upn']);
        $this->assertNotNull($status['connected_at']);
        $this->assertNotNull($status['access_expires_at']);

        // Nothing leaks: logs, flash/session, alerts, other settings. The verifier
        // and state are consumed from the session.
        $this->assertNull(session('cipp_mcp_oauth_verifier'));
        $this->assertNull(session('cipp_mcp_oauth_state'));
        // Positive controls: the haystacks searched below are not empty.
        $this->assertStringContainsString('[CIPP MCP OAuth] Connected', json_encode($this->logs));
        $this->assertStringContainsString('CIPP MCP connected as svc-cipp@msp.example', (string) session('success'));
        $this->assertNoTokenLeaked([self::REFRESH, self::ACCESS, self::CODE, $idJwt, $verifier]);
    }

    public function test_a_refused_code_exchange_flashes_the_error_code_only(): void
    {
        $this->captureLogs();
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'AADSTS54005: OAuth2 Authorization code was already redeemed '.self::CODE,
            'error_codes' => [54005],
        ], 400)]);
        $admin = User::factory()->admin()->create();
        $state = str_repeat('s', 40);

        $response = $this->actingAs($admin)
            ->withSession(['cipp_mcp_oauth_state' => $state, 'cipp_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state, 'code' => self::CODE]));

        $response->assertRedirect(route('settings.integrations'));
        $flash = (string) session('error');
        $this->assertStringContainsString('invalid_grant / AADSTS54005', $flash);
        $this->assertStringNotContainsString('already redeemed', $flash, 'the vendor body is never echoed');
        $this->assertFalse(app(CippMcpConnector::class)->isConnected());
        $this->assertNoTokenLeaked([self::CODE]);
    }

    // --- getToken ---------------------------------------------------------------

    public function test_get_token_uses_the_refresh_token_grant_with_the_delegated_scope_when_connected(): void
    {
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->refreshOk()]);

        $client = $this->mcpClient();
        $this->assertSame(self::ACCESS, $this->token($client));
        $this->assertSame(self::ACCESS, $this->token($client), 'second call is served from the access-token cache');

        $posts = $this->tokenPosts();
        $this->assertCount(1, $posts);
        $this->assertSame('refresh_token', $posts[0]['grant_type']);
        $this->assertSame(self::REFRESH, $posts[0]['refresh_token']);
        $this->assertSame('mcp-client', $posts[0]['client_id']);
        $this->assertSame(self::HOST.'/user_impersonation offline_access', $posts[0]['scope']);
        $this->assertArrayNotHasKey('client_secret', $posts[0]);
        $this->assertNotNull(Setting::getValue(CippMcpConnector::ACCESS_EXPIRES_AT));
    }

    public function test_a_confidential_client_sends_its_secret_on_refresh(): void
    {
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->refreshOk()]);

        // The delegated refresh reads the stored secret live (so an admin removal
        // reaches a long-lived client), not the value captured at construction.
        Setting::setEncrypted('cipp_mcp_client_secret', 'web-secret');

        $this->token($this->mcpClient(true, 'web-secret'));

        $this->assertSame('web-secret', $this->tokenPosts()[0]['client_secret'] ?? null);
    }

    public function test_get_token_without_a_connector_keeps_the_client_credentials_grant(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'APP-TOKEN', 'expires_in' => 3600])]);

        // Connector service injected but not connected, and not injected at all.
        $this->assertSame('APP-TOKEN', $this->token($this->mcpClient(true, 'mcp-secret')));
        Cache::flush();
        $this->assertSame('APP-TOKEN', $this->token($this->mcpClient(false, 'mcp-secret')));

        foreach ($this->tokenPosts() as $post) {
            $this->assertSame('client_credentials', $post['grant_type']);
            $this->assertSame('api://mcp-client/.default', $post['scope']);
            $this->assertArrayNotHasKey('refresh_token', $post);
        }
        $this->assertCount(2, $this->tokenPosts());
    }

    public function test_without_a_connector_missing_credentials_still_throw_the_auth_exception(): void
    {
        Http::fake();

        $this->expectException(CippMcpAuthException::class);
        $this->expectExceptionMessage('client credentials are not configured');
        try {
            $this->token($this->mcpClient(true, ''));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_rotated_refresh_token_is_persisted_encrypted(): void
    {
        $this->captureLogs();
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->refreshOk(['refresh_token' => self::ROTATED])]);

        $this->token($this->mcpClient());

        $this->assertSame(self::ROTATED, Setting::getEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING));
        $raw = (string) DB::table('settings')->where('key', CippMcpConnector::REFRESH_TOKEN_SETTING)->value('value');
        $this->assertStringNotContainsString(self::ROTATED, $raw);
        $this->assertNoTokenLeaked([self::REFRESH, self::ROTATED, self::ACCESS]);
    }

    // --- refresh failure: one alert per episode ----------------------------------

    public function test_a_refresh_failure_alerts_once_is_not_realerted_and_clears_on_success(): void
    {
        $this->captureLogs();
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->pushResponse($this->refreshRejected())
            ->pushResponse($this->refreshRejected())
            ->pushResponse($this->refreshOk())]);
        $client = $this->mcpClient();

        // First failure: a CippMcpAuthException (so leg 1 fails over) and ONE alert.
        try {
            $this->token($client);
            $this->fail('expected CippMcpAuthException');
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('invalid_grant / AADSTS70043', $e->getMessage());
            $this->assertStringNotContainsString('expired due to inactivity', $e->getMessage(), 'code only, never the body');
        }
        $alerts = $this->openCippAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('reconnect', strtolower($alerts[0]->title));
        $this->assertStringContainsString(CippMcpConnector::RECONNECT_ACTION, (string) $alerts[0]->message);
        $this->assertStringContainsString('may be fine', (string) $alerts[0]->message, 'a dead token must not read as CIPP down');
        $status = app(CippMcpConnector::class)->status();
        $this->assertSame('refresh_failed', $status['state']);
        $this->assertSame('invalid_grant / AADSTS70043', $status['error_code']);
        $failedAt = $status['failed_at'];

        // Second failure (after the negative cache lapses): same episode, no new alert.
        $this->travel(CippMcpClient::SIGN_IN_FAILURE_TTL + 1)->seconds();
        try {
            $this->token($client);
            $this->fail('expected CippMcpAuthException');
        } catch (CippMcpAuthException) {
        }
        $this->assertCount(1, Alert::where('source', AlertSource::Cipp)->get(), 'not re-alerted within one episode');
        $this->assertSame(0, (int) Alert::where('source', AlertSource::Cipp)->value('refired_count'));
        $this->assertSame($failedAt, app(CippMcpConnector::class)->status()['failed_at'], 'the episode keeps its start time');

        // Success: the alert resolves and the status clears.
        $this->travel(CippMcpClient::SIGN_IN_FAILURE_TTL + 1)->seconds();
        $this->assertSame(self::ACCESS, $this->token($client));
        $this->assertCount(0, $this->openCippAlerts());
        $this->assertSame(AlertStatus::Resolved, Alert::where('source', AlertSource::Cipp)->first()->status);
        $this->assertSame('connected', app(CippMcpConnector::class)->status()['state']);
        $this->assertNull(Setting::getValue(CippMcpConnector::ALERT_ID));

        $this->assertCount(3, $this->tokenPosts());
        $this->assertStringContainsString('connector refresh failed', json_encode($this->logs), 'positive control: the failure was logged');
        $this->assertNoTokenLeaked([self::REFRESH, self::ACCESS]);
    }

    public function test_a_new_failure_after_recovery_opens_a_new_episode_with_one_new_alert(): void
    {
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->pushResponse($this->refreshRejected())
            ->pushResponse($this->refreshOk(['expires_in' => 60]))
            ->pushResponse($this->refreshRejected())]);
        $client = $this->mcpClient();

        try {
            $this->token($client);
        } catch (CippMcpAuthException) {
        }
        $this->travel(CippMcpClient::SIGN_IN_FAILURE_TTL + 1)->seconds();
        $this->token($client);
        Cache::flush();
        try {
            $this->token($client);
        } catch (CippMcpAuthException) {
        }

        $this->assertCount(2, Alert::where('source', AlertSource::Cipp)->get());
        $this->assertCount(1, $this->openCippAlerts());
    }

    // --- #4393 negative token cache ----------------------------------------------

    public function test_negative_cache_suppresses_repeat_token_posts_within_the_ttl_and_allows_one_after(): void
    {
        // App-only path (no connector) in the prod state of 2026-09-29: Entra
        // rejects the resource with AADSTS500011.
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_resource', 'error_codes' => [500011]], 400)]);
        $client = $this->mcpClient(true, 'mcp-secret');

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->token($client);
                $this->fail('expected CippMcpAuthException');
            } catch (CippMcpAuthException) {
            }
        }
        $this->assertCount(1, $this->tokenPosts(), 'one doomed POST, then the cached failure answers');

        // Literal wall-clock bounds, not the constant: the cache must be SHORT
        // (a fixed credential is retried within about a minute), and a test that
        // read the constant would move with any change to it.
        $this->travel(59)->seconds();
        try {
            $this->token($client);
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('not retrying yet', $e->getMessage());
        }
        $this->assertCount(1, $this->tokenPosts(), 'still inside the TTL at 59s');

        $this->travel(2)->seconds();
        try {
            $this->token($client);
        } catch (CippMcpAuthException) {
        }
        $this->assertCount(2, $this->tokenPosts(), 'exactly one retry at 61s, once the 60s TTL has passed');
    }

    public function test_negative_cache_also_bounds_the_delegated_refresh(): void
    {
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->refreshRejected()]);
        $client = $this->mcpClient();

        for ($i = 0; $i < 4; $i++) {
            try {
                $this->token($client);
            } catch (CippMcpAuthException) {
            }
        }

        $this->assertCount(1, $this->tokenPosts());
    }

    public function test_a_reconnect_is_not_blocked_by_the_previous_connections_cached_failure(): void
    {
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->pushResponse($this->refreshRejected())
            ->pushResponse($this->refreshOk())]);
        $client = $this->mcpClient();
        try {
            $this->token($client);
        } catch (CippMcpAuthException) {
        }

        // Operator reconnects (a new connected_at generation) within the TTL.
        $this->travel(5)->seconds();
        Setting::setEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING, self::ROTATED);
        Setting::setValue(CippMcpConnector::CONNECTED_AT, now()->toIso8601String());

        $this->assertSame(self::ACCESS, $this->token($client));
        $this->assertSame(self::ROTATED, $this->tokenPosts()[1]['refresh_token']);
    }

    // --- status panel --------------------------------------------------------

    private function panel(): string
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'id="cipp-mcp-connection"');
        $this->assertNotFalse($start, 'the CIPP MCP connection panel renders');

        return substr($html, $start, 3000);
    }

    private function credentialsHelp(): string
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->getContent();
        $start = strpos($html, 'id="cipp-mcp-credentials-help"');
        $this->assertNotFalse($start);

        return substr($html, $start, 1200);
    }

    public function test_panel_renders_not_connected_with_the_connect_button_and_legacy_help(): void
    {
        $panel = $this->panel();

        $this->assertStringContainsString('data-state="not_connected"', $panel);
        $this->assertStringContainsString('Not connected', $panel);
        $this->assertStringContainsString('Connect CIPP MCP', $panel);
        $this->assertStringContainsString(route('auth.cipp-mcp'), $panel);
        $this->assertStringContainsString('Legacy (CIPP &lt; v11)', $this->credentialsHelp());
        $this->assertStringContainsString('as a <strong>Mobile/desktop</strong> (public) redirect', $panel, 'no secret stored: a public redirect works');
    }

    public function test_panel_renders_connected_with_upn_since_and_expiry_and_real_help_text(): void
    {
        $this->connect();
        Setting::setValue(CippMcpConnector::ACCESS_EXPIRES_AT, '2026-09-30T02:00:00+00:00');

        $panel = $this->panel();

        $this->assertStringContainsString('data-state="connected"', $panel);
        $this->assertStringContainsString('Connected', $panel);
        $this->assertStringContainsString('svc-cipp@msp.example', $panel);
        $this->assertStringContainsString('since', $panel);
        $expiry = \Illuminate\Support\Carbon::parse('2026-09-30T02:00:00+00:00')->setTimezone(\App\Support\AppTimezone::get())->format('Y-m-d H:i T');
        $this->assertStringContainsString('Access token expires '.$expiry, $panel, 'expiry shown in the app timezone');
        $this->assertStringContainsString('Reconnect CIPP MCP', $panel);
        $this->assertStringNotContainsString('Refresh failed', $panel);

        $help = $this->credentialsHelp();
        $this->assertStringNotContainsString('Legacy', $help);
        $this->assertStringContainsString('connection below signs in through', $help);
        $this->assertStringContainsString('No secret is stored, so none is sent', $help);
        $this->assertStringNotContainsString(self::REFRESH, $panel.$help);
    }

    public function test_panel_renders_refresh_failed_with_when_and_the_code_only(): void
    {
        $this->connect();
        app(CippMcpConnector::class)->recordRefreshFailure('invalid_grant / AADSTS70043');

        $panel = $this->panel();

        $this->assertStringContainsString('data-state="refresh_failed"', $panel);
        $this->assertStringContainsString('Refresh failed at', $panel);
        $this->assertStringContainsString('<code>invalid_grant / AADSTS70043</code>', $panel);
        $this->assertStringContainsString('not a CIPP outage', $panel);
        $this->assertStringContainsString('Reconnect CIPP MCP', $panel);
    }

    public function test_the_connector_alone_counts_as_mcp_configured_but_an_empty_one_does_not(): void
    {
        $this->assertFalse(\App\Support\CippConfig::isMcpConfigured());
        $this->connect();
        $this->assertTrue(\App\Support\CippConfig::isMcpConfigured());
    }

    public function test_a_stored_mcp_secret_is_sent_on_exchange_and_refresh_and_the_panel_asks_for_a_web_redirect(): void
    {
        // The prod state: the pre-v11 app-only secret is still saved for the MCP client app.
        Setting::setEncrypted('cipp_mcp_client_secret', 'legacy-mcp-secret');
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'expires_in' => 3600,
        ])]);
        $admin = User::factory()->admin()->create();
        $state = str_repeat('s', 40);

        $this->actingAs($admin)
            ->withSession(['cipp_mcp_oauth_state' => $state, 'cipp_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state, 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'));
        $this->token(app(CippMcpClient::class));

        $posts = $this->tokenPosts();
        $this->assertCount(2, $posts);
        $this->assertSame('authorization_code', $posts[0]['grant_type']);
        $this->assertSame('legacy-mcp-secret', $posts[0]['client_secret'] ?? null, 'sent with the sign-in');
        $this->assertSame('refresh_token', $posts[1]['grant_type']);
        $this->assertSame('legacy-mcp-secret', $posts[1]['client_secret'] ?? null, 'sent with the refresh');

        $this->assertStringContainsString('as a <strong>Web</strong> redirect, because an MCP Client Secret is stored', $this->panel());
        $help = $this->credentialsHelp();
        $this->assertStringContainsString('A secret is stored and is sent with the sign-in and every refresh', $help);
        $this->assertStringNotContainsString('No secret is stored', $help);
    }

    // --- r2 A: the UPN comes from a real Entra response shape ----------------

    public function test_r2a_authorize_url_asks_for_openid_profile_and_the_refresh_does_not(): void
    {
        $location = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('auth.cipp-mcp'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $scopes = explode(' ', (string) ($query['scope'] ?? ''));
        $this->assertContains('openid', $scopes, 'without openid Entra returns no id_token');
        $this->assertContains('profile', $scopes, 'without profile the id_token has no preferred_username');
        $this->assertContains(self::HOST.'/user_impersonation', $scopes);
        $this->assertContains('offline_access', $scopes);

        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->entraTokenEndpoint()]);
        $this->token($this->mcpClient());
        $this->assertSame(self::HOST.'/user_impersonation offline_access', $this->tokenPosts()[0]['scope'], 'the refresh keeps the ruled scope alone');
    }

    public function test_r2a_a_real_exchange_stores_the_upn_from_the_id_token_that_openid_earns(): void
    {
        Http::fake(['login.microsoftonline.com/*' => $this->entraTokenEndpoint()]);

        $this->completeConnect();

        $this->assertSame('svc-cipp@msp.example', app(CippMcpConnector::class)->status()['upn']);
    }

    public function test_r2a_an_exchange_with_no_id_token_stores_no_fabricated_upn(): void
    {
        // Entra's token response when openid was not granted: no id_token at all.
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'scope' => self::HOST.'/user_impersonation',
            'expires_in' => 4467,
            'ext_expires_in' => 4467,
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
        ])]);

        $this->completeConnect()->assertSessionHas('success');

        $connector = app(CippMcpConnector::class);
        $this->assertTrue($connector->isConnected());
        $this->assertNull(Setting::getValue(CippMcpConnector::UPN), 'no id_token, no UPN: nothing invented');
        $this->assertNull($connector->status()['upn']);
        $this->assertSame('CIPP MCP connected.', (string) session('success'));
        $this->assertStringContainsString('Signed in as <strong>unknown account</strong>', $this->panel());
    }

    // --- r2 B: a stored legacy secret, and the admin control that removes it ---

    private const LEGACY_SECRET = 'MCPSECRET-CANARY-legacy-4c1e';

    public function test_r2b_removing_the_stored_secret_sends_none_on_the_exchange_or_the_refresh(): void
    {
        // Prod state: the pre-v11 app-only secret is still stored.
        Setting::setEncrypted('cipp_mcp_client_secret', self::LEGACY_SECRET);
        // The container's long-lived client is built BEFORE the removal, as a
        // worker's singleton would be.
        $client = app(CippMcpClient::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.integrations.cipp.update'), ['remove_mcp_client_secret' => '1'])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');
        $this->assertNull(Setting::getValue('cipp_mcp_client_secret'), 'the stored secret row is gone');
        $this->assertSame('mcp-client', Setting::getValue('cipp_mcp_client_id'), 'nothing else is touched');
        $this->assertNotNull(Setting::getValue('cipp_client_secret'), 'the REST secret is a different setting and stays');

        Http::fake(['login.microsoftonline.com/*' => $this->entraTokenEndpoint()]);
        $this->completeConnect($admin)->assertSessionHas('success');
        $this->assertSame(self::ACCESS, $this->token($client));

        $posts = $this->tokenPosts();
        $this->assertCount(2, $posts);
        $this->assertSame('authorization_code', $posts[0]['grant_type']);
        $this->assertArrayNotHasKey('client_secret', $posts[0], 'no secret on the exchange');
        $this->assertSame('refresh_token', $posts[1]['grant_type']);
        $this->assertArrayNotHasKey('client_secret', $posts[1], 'no secret on the refresh');
        $this->assertSame('mcp-client', $posts[1]['client_id']);
    }

    public function test_r2b_a_stored_secret_without_the_control_is_sent_on_the_exchange_and_the_refresh(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', self::LEGACY_SECRET);
        $admin = User::factory()->admin()->create();
        // A save WITHOUT the control keeps the secret (the positive control for the test above).
        $this->actingAs($admin)->post(route('settings.integrations.cipp.update'), ['mcp_client_id' => 'mcp-client'])
            ->assertSessionHas('success');
        $this->assertSame(self::LEGACY_SECRET, Setting::getEncrypted('cipp_mcp_client_secret'));

        Http::fake(['login.microsoftonline.com/*' => $this->entraTokenEndpoint()]);
        $this->completeConnect($admin);
        $this->token(app(CippMcpClient::class));

        $posts = $this->tokenPosts();
        $this->assertCount(2, $posts);
        $this->assertSame(self::LEGACY_SECRET, $posts[0]['client_secret'] ?? null, 'Web redirect: sent on the exchange');
        $this->assertSame(self::LEGACY_SECRET, $posts[1]['client_secret'] ?? null, 'Web redirect: sent on the refresh');
    }

    public function test_r2b_a_non_admin_cannot_remove_the_stored_secret(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', self::LEGACY_SECRET);
        $tech = User::factory()->tech()->create();

        $this->actingAs($tech)->post(route('settings.integrations.cipp.update'), [
            'remove_mcp_client_secret' => '1',
            'mcp_backend_host' => 'api://changed.example.test',
        ])->assertForbidden();

        $this->assertSame(self::LEGACY_SECRET, Setting::getEncrypted('cipp_mcp_client_secret'), 'the secret survives');
        $this->assertSame(self::HOST, Setting::getValue('cipp_mcp_backend_host'), 'nothing in the refused submit was saved');

        // Same gate as Connect: the tech is refused there too.
        $this->actingAs($tech)->get(route('auth.cipp-mcp'))->assertForbidden();
        // And the control is not offered to a tech, while an admin sees it.
        $html = (string) $this->actingAs($tech)->get(route('settings.integrations'))->getContent();
        $this->assertStringNotContainsString('name="remove_mcp_client_secret"', $html);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->getContent();
        $this->assertStringContainsString('name="remove_mcp_client_secret"', $html);
        $this->assertStringContainsString('Remove stored MCP client secret', $html);
    }

    public function test_r2b_removing_while_also_saving_a_new_secret_is_refused_and_changes_nothing(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', self::LEGACY_SECRET);

        $this->actingAs(User::factory()->admin()->create())->post(route('settings.integrations.cipp.update'), [
            'remove_mcp_client_secret' => '1',
            'mcp_client_secret' => 'a-new-one',
        ])->assertSessionHas('error');

        $this->assertSame(self::LEGACY_SECRET, Setting::getEncrypted('cipp_mcp_client_secret'));
    }

    public function test_r2b_help_text_says_public_redirect_remove_first_and_web_redirect_keep_it(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', self::LEGACY_SECRET);
        $this->connect();

        $help = $this->credentialsHelp();
        $this->assertStringContainsString('Web redirect: keep it', $help);
        $this->assertStringContainsString('Public (Mobile/desktop) redirect: tick <strong>Remove stored MCP client secret</strong> and save first', $help);
        $this->assertStringNotContainsString('cannot be cleared', $help, 'd5572dc5 wording retired');
        $this->assertStringContainsString('tick <strong>Remove stored MCP client secret</strong> above and save first', $this->panel());

        $install = (string) file_get_contents(base_path('docs/INSTALL.md'));
        $this->assertStringContainsString('ticks **Remove stored MCP client secret**', $install);
        $this->assertStringContainsString('**Web redirect:** keep the secret', $install);
        $this->assertStringNotContainsString('the panel cannot clear it', $install);
    }

    // --- r2 C: bookkeeping never escapes getToken() ---------------------------

    /** When true, every INSERT/UPDATE/DELETE on `settings` throws a real QueryException. */
    private bool $failSettingsWrites = false;

    /** @var array<int, mixed> bindings of the refused writes (they hold the ciphertext) */
    private array $refusedBindings = [];

    /** Was the minted token already cached when bookkeeping first touched the DB? */
    private ?bool $cachedAtFirstBookkeeping = null;

    private function failSettingsWrites(): void
    {
        $this->failSettingsWrites = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings, $connection): void {
            if (! $this->failSettingsWrites || ! preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+"settings"/i', $query)) {
                return;
            }
            $this->cachedAtFirstBookkeeping ??= Cache::get($this->delegatedCacheKey()) === self::ACCESS;
            array_push($this->refusedBindings, ...$bindings);
            // What a real DB outage raises: the message embeds the SQL bindings.
            throw new \Illuminate\Database\QueryException($connection->getName(), $query, $bindings, new \PDOException('SQLSTATE[HY000]: forced write failure'));
        });
    }

    private function delegatedCacheKey(): string
    {
        return 'cipp_mcp_oauth_token:delegated:'.sha1('tenant-1|mcp-client|'.app(CippMcpConnector::class)->generation());
    }

    public function test_r2c_success_arm_a_settings_write_that_throws_still_returns_and_caches_the_token(): void
    {
        $this->captureLogs();
        $this->connect();
        Setting::setValue(CippMcpConnector::FAILED_AT, now()->toIso8601String());
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->pushResponse($this->refreshOk(['refresh_token' => self::ROTATED]))
            ->pushResponse($this->refreshRejected())]);
        $client = $this->mcpClient();
        $this->failSettingsWrites();

        $this->assertSame(self::ACCESS, $this->token($client), 'the read gets its token');
        $this->failSettingsWrites = false;

        $this->assertTrue($this->cachedAtFirstBookkeeping, 'the token was cached BEFORE any bookkeeping write');
        $this->assertSame(self::ACCESS, Cache::get($this->delegatedCacheKey()), 'the cache holds the token');
        $this->assertSame(self::ACCESS, $this->token($client), 'the next read is served from the cache');
        $this->assertCount(1, $this->tokenPosts(), 'no second refresh');

        // The rotated-token write really was refused, with the ciphertext in its bindings...
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING));
        $cipher = array_values(array_filter($this->refusedBindings, fn ($b) => is_string($b) && rescue(fn () => \Illuminate\Support\Facades\Crypt::decryptString($b), null, false) === self::ROTATED));
        $this->assertNotEmpty($cipher, 'positive control: a refused write carried the rotated token ciphertext');
        // ...and the log names the step and class only: no token, no ciphertext, no SQL.
        $logs = json_encode($this->logs);
        $this->assertStringContainsString('storeRotatedRefreshToken', $logs);
        $this->assertStringContainsString('recordAccessExpiry', $logs);
        $this->assertStringContainsString('QueryException', $logs);
        $this->assertStringNotContainsString('forced write failure', $logs, 'no exception message');
        $this->assertStringNotContainsString('settings', $logs, 'no SQL');
        $this->assertNoTokenLeaked([self::REFRESH, self::ROTATED, self::ACCESS, ...$cipher]);
    }

    public function test_r2c_success_arm_a_resolve_whose_ticket_note_throws_still_returns_and_caches_the_token(): void
    {
        $this->captureLogs();
        $this->connect();
        User::factory()->admin()->create();
        $ticket = \App\Models\Ticket::factory()->create(['status' => \App\Enums\TicketStatus::New->value, 'closed_at' => null]);
        $alert = Alert::create([
            'source' => AlertSource::Cipp, 'source_alert_id' => 'cipp-mcp-connector-refresh:x',
            'severity' => \App\Enums\AlertSeverity::Error, 'status' => AlertStatus::Active,
            'title' => 'CIPP MCP sign-in expired: reconnect required', 'ticket_id' => $ticket->id, 'fired_at' => now(),
        ]);
        Setting::setValue(CippMcpConnector::FAILED_AT, now()->toIso8601String());
        Setting::setValue(CippMcpConnector::ALERT_ID, (string) $alert->id);
        $this->mock(\App\Services\TicketService::class, function ($mock): void {
            $mock->shouldReceive('addNote')->once()->andThrow(new \RuntimeException('note failed near '.self::ACCESS));
        });
        Http::fake(['login.microsoftonline.com/*' => $this->refreshOk()]);
        $client = $this->mcpClient();

        $this->assertSame(self::ACCESS, $this->token($client));

        $this->assertSame(self::ACCESS, Cache::get($this->delegatedCacheKey()), 'the cache holds the token');
        $this->assertSame(self::ACCESS, $this->token($client));
        $this->assertCount(1, $this->tokenPosts());
        $this->assertSame('connected', app(CippMcpConnector::class)->status()['state'], 'the other steps still ran');
        $logs = json_encode($this->logs);
        $this->assertStringContainsString('resolveAlert', $logs);
        $this->assertStringContainsString('RuntimeException', $logs);
        $this->assertStringNotContainsString('note failed', $logs, 'no exception message');
        $this->assertNoTokenLeaked([self::REFRESH, self::ACCESS]);
    }

    public function test_r2c_failure_arm_settings_writes_that_throw_still_raise_the_auth_exception(): void
    {
        $this->captureLogs();
        $this->connect();
        Http::fake(['login.microsoftonline.com/*' => $this->refreshRejected()]);
        $client = $this->mcpClient();
        $this->failSettingsWrites();

        try {
            $this->token($client);
            $this->fail('expected CippMcpAuthException');
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('invalid_grant / AADSTS70043', $e->getMessage());
        } finally {
            $this->failSettingsWrites = false;
        }

        $this->assertNotEmpty($this->refusedBindings, 'positive control: openEpisode/setValue were attempted and refused');
        $logs = json_encode($this->logs);
        $this->assertStringContainsString('openEpisode', $logs);
        $this->assertStringContainsString('recordFailureCode', $logs);
        $this->assertStringNotContainsString('forced write failure', $logs);
        $this->assertNoTokenLeaked([self::REFRESH, self::ACCESS]);
    }

    /**
     * The client's invariant must not depend on the connector guarding itself: a
     * connector whose bookkeeping methods throw outright (whatever the cause) still
     * yields the token (success arm) or CippMcpAuthException (failure arm).
     */
    public function test_r2c_client_invariant_holds_even_when_the_connector_bookkeeping_itself_throws(): void
    {
        $this->captureLogs();
        $this->connect();
        $throwing = new class extends CippMcpConnector
        {
            public function storeRotatedRefreshToken(mixed $refreshToken): void
            {
                throw new \LogicException('rotate boom');
            }

            public function recordAccessExpiry(int $expiresIn): void
            {
                throw new \LogicException('expiry boom');
            }

            public function recordRefreshSuccess(): void
            {
                throw new \LogicException('success boom');
            }

            public function recordRefreshFailure(string $errorCode): void
            {
                throw new \LogicException('failure boom');
            }
        };
        $client = new CippMcpClient(['api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1', 'client_id' => 'mcp-client', 'client_secret' => null],
            app(CacheInterface::class), fn (string $host): array => ['93.184.216.34'], $throwing);
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->pushResponse($this->refreshOk())
            ->pushResponse($this->refreshRejected())]);

        $this->assertSame(self::ACCESS, $this->token($client), 'success arm: the token');
        $this->assertSame(self::ACCESS, Cache::get($this->delegatedCacheKey()), 'and it is cached');

        Cache::flush();
        try {
            $this->token($client);
            $this->fail('expected CippMcpAuthException');
        } catch (CippMcpAuthException $e) {
            $this->assertStringContainsString('invalid_grant / AADSTS70043', $e->getMessage(), 'failure arm: the auth exception, not the LogicException');
        }

        $logs = json_encode($this->logs);
        foreach (['storeRotatedRefreshToken', 'recordAccessExpiry', 'recordRefreshSuccess', 'recordRefreshFailure'] as $step) {
            $this->assertStringContainsString($step, $logs);
        }
        $this->assertStringNotContainsString('boom', $logs, 'class only, never the message');
    }

    public function test_r2c_failure_arm_an_alert_service_that_throws_still_raises_the_auth_exception(): void
    {
        $this->captureLogs();
        $this->connect();
        $this->mock(\App\Services\AlertService::class, function ($mock): void {
            $mock->shouldReceive('upsert')->andThrow(new \RuntimeException('alert failed near '.self::REFRESH));
        });
        Http::fake(['login.microsoftonline.com/*' => $this->refreshRejected()]);

        $this->expectException(CippMcpAuthException::class);
        try {
            $this->token($this->mcpClient());
        } finally {
            $this->assertStringNotContainsString('alert failed', json_encode($this->logs));
            $this->assertNoTokenLeaked([self::REFRESH, self::ACCESS]);
        }
    }

    // --- helpers -------------------------------------------------------------

    /**
     * A token endpoint that follows Entra's documented rule for id_token ("Only
     * provided if openid scope was requested", v2 auth-code flow, successful
     * response): it inspects the POSTed scope and adds an id_token, carrying
     * preferred_username only when profile was asked for too.
     */
    private function entraTokenEndpoint(): \Closure
    {
        return function (\Illuminate\Http\Client\Request $request) {
            $scopes = explode(' ', (string) ($request->data()['scope'] ?? ''));
            $body = [
                'token_type' => 'Bearer',
                'scope' => implode(' ', array_diff($scopes, ['openid', 'profile', 'offline_access'])),
                'expires_in' => 3600,
                'access_token' => self::ACCESS,
                'refresh_token' => self::REFRESH,
            ];
            if (in_array('openid', $scopes, true)) {
                $body['id_token'] = self::idToken(in_array('profile', $scopes, true));
            }

            return Http::response($body);
        };
    }

    /** An unsigned test id_token; preferred_username only with the profile scope. */
    private static function idToken(bool $profile): string
    {
        $claims = ['aud' => 'mcp-client', 'tid' => 'tenant-1', 'sub' => 'x'];
        if ($profile) {
            $claims['preferred_username'] = 'svc-cipp@msp.example';
        }

        return 'eyJhbGciOiJub25lIn0.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.sig';
    }

    /** Complete Connect as an admin with a valid state/verifier pair. */
    private function completeConnect(?User $as = null): \Illuminate\Testing\TestResponse
    {
        $state = str_repeat('s', 40);

        return $this->actingAs($as ?? User::factory()->admin()->create())
            ->withSession(['cipp_mcp_oauth_state' => $state, 'cipp_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state, 'code' => self::CODE]));
    }

    private function captureLogs(): void
    {
        $this->logs = [];
        Log::listen(function ($event): void {
            $this->logs[] = ['level' => $event->level, 'message' => (string) $event->message, 'context' => $event->context];
        });
    }

    private function connect(string $refresh = self::REFRESH): void
    {
        Setting::setEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING, $refresh);
        Setting::setValue(CippMcpConnector::CONNECTED_AT, now()->subDay()->toIso8601String());
        Setting::setValue(CippMcpConnector::UPN, 'svc-cipp@msp.example');
    }

    private function mcpClient(bool $withConnector = true, ?string $secret = null): CippMcpClient
    {
        return new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client',
            'client_secret' => $secret,
        ], app(CacheInterface::class), fn (string $host): array => ['93.184.216.34'],
            $withConnector ? app(CippMcpConnector::class) : null);
    }

    /** Calls the private getToken() the way callTool()/listTools() do. */
    private function token(CippMcpClient $client): string
    {
        return (fn () => $this->getToken())->call($client);
    }

    /** @return array<int, array<string, string>> form bodies of token POSTs seen by the Guzzle history seam */
    private function tokenPosts(): array
    {
        $posts = [];
        foreach ($this->history as $entry) {
            $request = $entry['request'];
            if (str_contains((string) $request->getUri(), '/oauth2/v2.0/token')) {
                parse_str((string) $request->getBody(), $form);
                $posts[] = $form;
            }
        }

        return $posts;
    }

    private function refreshOk(array $extra = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['token_type' => 'Bearer', 'access_token' => self::ACCESS, 'expires_in' => 3600] + $extra);
    }

    private function refreshRejected(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'AADSTS70043: The refresh token has expired due to inactivity. Trace ID: x '.self::REFRESH,
            'error_codes' => [70043],
        ], 400);
    }

    private function openCippAlerts(): \Illuminate\Support\Collection
    {
        return Alert::where('source', AlertSource::Cipp)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();
    }

    /** Every place a token could leak to: logs, session, alerts, all settings but the encrypted one. */
    private function assertNoTokenLeaked(array $secrets, string $extraHaystack = ''): void
    {
        $haystacks = [
            'logs' => json_encode($this->logs),
            // Laravel's StartSession records every GET's full URL as _previous.url, so
            // the callback URL (with its single-use, already-redeemed code) lands
            // there as it does for the QBO/AppRiver callbacks. It is useless without
            // the PKCE verifier, which the callback has already pulled. Everything
            // else in the session, the flash message included, is checked.
            'session' => json_encode(\Illuminate\Support\Arr::except(session()->all(), ['_previous'])),
            'alerts' => json_encode(Alert::all()->toArray()),
            'settings' => json_encode(Setting::where('key', '!=', CippMcpConnector::REFRESH_TOKEN_SETTING)->pluck('value', 'key')),
            'extra' => $extraHaystack,
        ];
        foreach ($haystacks as $where => $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, (string) $text, "token leaked into {$where}");
            }
        }
    }
}
