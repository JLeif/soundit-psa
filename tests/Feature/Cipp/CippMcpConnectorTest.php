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
        // Entra returns an id_token because the sign-in asks for openid, and
        // preferred_username in it because it asks for profile.
        $idJwt = 'eyJhbGciOiJub25lIn0.'.rtrim(strtr(base64_encode(json_encode(['preferred_username' => 'svc-cipp@msp.example'])), '+/', '-_'), '=').'.sig';
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'id_token' => $idJwt,
            'expires_in' => 3600,
        ])]);
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

    // --- helpers -------------------------------------------------------------

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

    private function mcpClient(bool $withConnector = true, string $secret = ''): CippMcpClient
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
