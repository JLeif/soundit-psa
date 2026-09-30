<?php

namespace Tests\Feature\Cipp;

use App\Models\Setting;
use App\Models\User;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpConnector;
use App\Services\Cipp\CippSetupCheck;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6abd5c73 / jOWYaBuZ: CIPP "Check setup", backend-host normalisation,
 * plain-English AADSTS errors, and the MCP Client ID label.
 *
 * Test Connection is the REST CippClient (Guzzle, not the Http facade): its
 * token is pre-seeded in the cache and its API client is a Guzzle MockHandler,
 * through CippSetupCheck's client-factory seam.
 */
class CippCheckSetupTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-4333-8444-555555555555';

    private const CLIENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private const HOST = 'api://cipp-mcp-backend.example.test';

    /** Vendor text that must never reach a flash. */
    private const BODY_CANARY = 'VENDOR-BODY-CANARY-7c1d';

    private const CODE = 'AUTHCODE-CANARY-9e8f';

    /** @var list<PsrResponse|\Throwable> */
    private array $restQueue = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        URL::forceRootUrl('https://psa.example.test');
        URL::forceScheme('https');

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', self::TENANT);
        Setting::setValue('cipp_client_id', 'rest-client');
        Setting::setEncrypted('cipp_client_secret', 'rest-secret');
        Setting::setValue('cipp_mcp_client_id', self::CLIENT);
        Setting::setValue('cipp_mcp_backend_host', self::HOST);

        // Test Connection: cached REST token + a mocked CIPP API.
        Cache::put('cipp_oauth_token', 'REST-TOKEN', 600);
        $this->restQueue = [new PsrResponse(200, [], json_encode([['defaultDomainName' => 'a.example'], ['defaultDomainName' => 'b.example']]))];
        $this->app->bind(CippSetupCheck::class, fn () => new CippSetupCheck(function (array $config): CippClient {
            $http = new Client([
                'base_uri' => rtrim((string) $config['api_url'], '/').'/',
                'handler' => HandlerStack::create(new MockHandler($this->restQueue)),
            ]);

            return new CippClient($config, app(CacheInterface::class), $http);
        }));
    }

    // --- helpers ---------------------------------------------------------------

    /** @return array<string, array{key: string, label: string, status: string, message: string}> */
    private function runCheck(?User $as = null): array
    {
        $this->actingAs($as ?? User::factory()->admin()->create())
            ->post(route('settings.integrations.cipp.check-setup'))
            ->assertRedirect(route('settings.integrations'));

        $lines = session('cipp_setup_check');
        $this->assertIsArray($lines, 'Check setup flashed its lines');

        return collect($lines)->keyBy('key')->all();
    }

    private function callbackUrl(): string
    {
        return route('auth.cipp-mcp.callback');
    }

    /** Complete the OAuth callback with a valid state/verifier pair and the given query. */
    private function oauthCallback(array $query): \Illuminate\Testing\TestResponse
    {
        $state = str_repeat('s', 40);

        return $this->actingAs(User::factory()->admin()->create())
            ->withSession(['cipp_mcp_oauth_state' => $state, 'cipp_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.cipp-mcp.callback', ['state' => $state] + $query));
    }

    private function assertNoVendorBody(string $flash): void
    {
        $this->assertNotSame('', $flash, 'positive control: a flash was written');
        $this->assertStringNotContainsString(self::BODY_CANARY, $flash, 'the vendor body/description is never flashed');
        $this->assertStringNotContainsString(self::CODE, $flash);
    }

    // --- Check setup: gate ---------------------------------------------------------

    public function test_check_setup_is_admin_only_and_writes_nothing_for_a_non_admin(): void
    {
        $tech = User::factory()->tech()->create();

        $this->actingAs($tech)->post(route('settings.integrations.cipp.check-setup'))->assertForbidden();
        $this->assertNull(session('cipp_setup_check'));

        // Positive control on the same route: an admin gets the lines.
        $this->assertCount(8, $this->runCheck());
    }

    public function test_the_check_setup_button_renders_for_an_admin_only(): void
    {
        $admin = (string) $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringContainsString('id="cipp-check-setup-btn"', $admin);
        $this->assertStringContainsString(route('settings.integrations.cipp.check-setup'), $admin);

        $tech = (string) $this->actingAs(User::factory()->tech()->create())->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringContainsString('id="cipp-mcp-connection"', $tech, 'positive control: the tech sees the panel');
        $this->assertStringNotContainsString('id="cipp-check-setup-btn"', $tech);
    }

    // --- Check setup: every line, pass ----------------------------------------------

    public function test_a_correct_setup_passes_every_checkable_line_and_marks_the_entra_lines_cant_check(): void
    {
        $lines = $this->runCheck();

        $this->assertSame(
            ['tenant_id', 'mcp_client_id', 'backend_host', 'redirect_uri', 'client_resolves', 'redirect_registered', 'public_client_flows', 'test_connection'],
            array_keys($lines),
        );
        foreach (['tenant_id', 'mcp_client_id', 'backend_host', 'redirect_uri', 'test_connection'] as $key) {
            $this->assertSame(CippSetupCheck::PASS, $lines[$key]['status'], $key.': '.$lines[$key]['message']);
        }
        $this->assertStringContainsString($this->callbackUrl(), $lines['redirect_uri']['message'], 'the exact callback is shown for copying');
        $this->assertStringContainsString('2 tenant(s)', $lines['test_connection']['message']);

        // Never a silent pass: the app-registration lines cannot be read with existing credentials.
        foreach (['client_resolves', 'redirect_registered', 'public_client_flows'] as $key) {
            $this->assertSame(CippSetupCheck::CANT_CHECK, $lines[$key]['status'], $key);
            $this->assertStringStartsWith("Can't check automatically: open Entra → App registrations → ", $lines[$key]['message']);
            $this->assertStringContainsString(self::CLIENT, $lines[$key]['message']);
        }
        $this->assertStringContainsString('→ Authentication', $lines['redirect_registered']['message']);
        $this->assertStringContainsString($this->callbackUrl(), $lines['redirect_registered']['message']);
        $this->assertStringContainsString('Mobile and desktop applications', $lines['redirect_registered']['message']);
        $this->assertStringContainsString('Allow public client flows is Yes', $lines['public_client_flows']['message']);
    }

    public function test_the_results_render_on_the_panel_with_a_text_status_word(): void
    {
        $admin = User::factory()->admin()->create();
        $this->runCheck($admin);

        $html = (string) $this->actingAs($admin)
            ->withSession(['cipp_setup_check' => session('cipp_setup_check')])
            ->get(route('settings.integrations'))->getContent();

        $this->assertStringContainsString('id="cipp-setup-check-results"', $html);
        $this->assertStringContainsString('data-check="tenant_id" data-status="pass"', $html);
        $this->assertStringContainsString('data-check="client_resolves" data-status="cant_check"', $html);
        $this->assertStringContainsString('<strong>Pass</strong>', $html);
        $this->assertStringContainsString('<strong>Can&#039;t check</strong>', $html);
    }

    // --- Check setup: every line, fail -----------------------------------------------

    public function test_missing_and_malformed_ids_fail_with_a_fix_line(): void
    {
        Setting::setValue('cipp_tenant_id', 'contoso.onmicrosoft.com');
        Setting::setValue('cipp_mcp_client_id', null);
        $lines = $this->runCheck();
        $this->assertSame(CippSetupCheck::FAIL, $lines['tenant_id']['status']);
        $this->assertStringContainsString('Entra → Overview → Tenant ID', $lines['tenant_id']['message']);
        $this->assertSame(CippSetupCheck::FAIL, $lines['mcp_client_id']['status']);
        $this->assertStringStartsWith('Not set.', $lines['mcp_client_id']['message']);
        $this->assertStringContainsString('<your MCP client ID>', $lines['redirect_registered']['message'], 'no client id to name');

        Setting::setValue('cipp_tenant_id', null);
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        $lines = $this->runCheck();
        $this->assertSame(CippSetupCheck::FAIL, $lines['tenant_id']['status']);
        $this->assertStringStartsWith('Not set.', $lines['tenant_id']['message']);
        $this->assertSame(CippSetupCheck::FAIL, $lines['mcp_client_id']['status']);
        $this->assertStringStartsWith('Not a client ID', $lines['mcp_client_id']['message']);
    }

    public function test_a_backend_host_saved_with_a_path_fails_and_names_the_bare_value(): void
    {
        Setting::setValue('cipp_mcp_backend_host', 'https://cipp-x.azurewebsites.net/api/ExecMcp');
        $line = $this->runCheck()['backend_host'];
        $this->assertSame(CippSetupCheck::FAIL, $line['status']);
        $this->assertStringContainsString('just https://cipp-x.azurewebsites.net ', $line['message']);
        $this->assertStringContainsString('CIPP-MCP → Expose an API → Application ID URI', $line['message']);

        Setting::setValue('cipp_mcp_backend_host', null);
        $line = $this->runCheck()['backend_host'];
        $this->assertSame(CippSetupCheck::FAIL, $line['status']);
        $this->assertStringStartsWith('Not set.', $line['message']);
    }

    public function test_a_non_https_callback_fails(): void
    {
        URL::forceRootUrl('http://psa.example.test');
        URL::forceScheme('http');
        $line = $this->runCheck()['redirect_uri'];
        $this->assertSame(CippSetupCheck::FAIL, $line['status']);
        $this->assertStringContainsString('http://psa.example.test/auth/cipp-mcp/callback', $line['message']);
        $this->assertStringContainsString('APP_URL', $line['message']);
    }

    public function test_test_connection_fails_when_rest_is_not_configured_or_cipp_refuses_and_echoes_no_body(): void
    {
        $this->restQueue = [new PsrResponse(500, [], 'upstream '.self::BODY_CANARY)];
        $line = $this->runCheck()['test_connection'];
        $this->assertSame(CippSetupCheck::FAIL, $line['status']);
        $this->assertStringContainsString('(CippClientException)', $line['message']);
        $this->assertStringNotContainsString(self::BODY_CANARY, json_encode(session()->all()));

        Setting::where('key', 'cipp_client_secret')->delete();
        $line = $this->runCheck()['test_connection'];
        $this->assertSame(CippSetupCheck::FAIL, $line['status']);
        $this->assertStringContainsString('are not all saved', $line['message']);
    }

    public function test_a_stored_secret_switches_the_redirect_line_to_web_and_still_does_not_pass_it(): void
    {
        Setting::setEncrypted('cipp_mcp_client_secret', 'MCP-SECRET-CANARY-x1');
        $lines = $this->runCheck();
        $this->assertSame(CippSetupCheck::CANT_CHECK, $lines['redirect_registered']['status']);
        $this->assertStringContainsString('listed under Web', $lines['redirect_registered']['message']);
        $this->assertSame(CippSetupCheck::CANT_CHECK, $lines['public_client_flows']['status']);
        $this->assertStringContainsString('Remove stored MCP client secret', $lines['public_client_flows']['message']);
        $this->assertStringNotContainsString('MCP-SECRET-CANARY-x1', json_encode(session()->all()));
    }

    public function test_check_setup_sends_nothing_to_microsoft_and_writes_no_setting(): void
    {
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->runCheck();
        Http::assertNothingSent();
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
    }

    // --- backend host normalisation ------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> input => bare */
    public static function hostCases(): array
    {
        return [
            'connector url pasted' => ['https://cipp-x.azurewebsites.net/api/ExecMcp', 'https://cipp-x.azurewebsites.net'],
            'with query' => ['https://cipp-x.azurewebsites.net/api/ExecMCP?client=abc', 'https://cipp-x.azurewebsites.net'],
            'trailing slash' => ['api://cipp-mcp.example.test/', 'api://cipp-mcp.example.test'],
            'whitespace' => ["  api://cipp-mcp.example.test \n", 'api://cipp-mcp.example.test'],
            'bare host with path' => ['cipp-x.azurewebsites.net/api/ExecMcp/', 'cipp-x.azurewebsites.net'],
            'fragment' => ['https://cipp-x.azurewebsites.net#frag', 'https://cipp-x.azurewebsites.net'],
            'already bare api' => ['api://cipp-mcp.example.test', 'api://cipp-mcp.example.test'],
            'already bare https' => ['https://cipp-x.azurewebsites.net', 'https://cipp-x.azurewebsites.net'],
        ];
    }

    #[DataProvider('hostCases')]
    public function test_update_cipp_saves_the_bare_backend_host(string $input, string $bare): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.cipp.update'), ['mcp_backend_host' => $input])
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');

        $this->assertSame($bare, Setting::getValue('cipp_mcp_backend_host'));
    }

    #[DataProvider('hostCases')]
    public function test_scope_is_built_from_the_bare_host_even_for_a_value_stored_before_normalisation(string $input, string $bare): void
    {
        Setting::setValue('cipp_mcp_backend_host', $input);

        $expectedHost = str_contains($bare, '://') ? $bare : 'https://'.$bare;
        $this->assertSame($expectedHost.'/user_impersonation offline_access', CippMcpConnector::scope());
    }

    public function test_a_host_that_normalises_to_nothing_is_not_saved_and_scope_refuses_it(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.cipp.update'), ['mcp_backend_host' => 'https:///api/ExecMcp'])
            ->assertRedirect(route('settings.integrations'));
        $this->assertSame(self::HOST, Setting::getValue('cipp_mcp_backend_host'), 'the previous value is kept');

        Setting::setValue('cipp_mcp_backend_host', '/api/ExecMcp');
        $this->expectException(\App\Services\Cipp\CippMcpAuthException::class);
        CippMcpConnector::scope();
    }

    // --- AADSTS -> plain English ----------------------------------------------------

    /** @return array<string, array{0: int, 1: string, 2: string}> code => [number, sentence fragment, fix fragment] */
    public static function aadstsCases(): array
    {
        return [
            '500113' => [500113, 'no redirect URI registered', '→ Authentication → Add a platform → Mobile and desktop applications'],
            '50011' => [50011, 'callback URL is not registered', '→ Authentication and add'],
            '53003' => [53003, 'Conditional Access blocked the token redeem from the PSA server.', 'dedicated CIPP service account excluded from the blocking policy'],
            '65001' => [65001, 'has not been granted consent', 'Save to Azure'],
            '7000218' => [7000218, 'does not allow public client sign-in', 'Allow public client flows to Yes'],
        ];
    }

    #[DataProvider('aadstsCases')]
    public function test_an_authorization_error_is_mapped_to_plain_english_without_the_description(int $number, string $what, string $fix): void
    {
        $this->oauthCallback([
            'error' => 'invalid_request',
            'error_description' => "AADSTS{$number}: ".self::BODY_CANARY.' Trace ID: 0000',
        ])->assertRedirect(route('settings.integrations'));

        $flash = (string) session('error');
        $this->assertStringStartsWith("CIPP MCP sign-in failed (AADSTS{$number}): ", $flash);
        $this->assertStringContainsString($what, $flash);
        $this->assertStringContainsString('Fix: ', $flash);
        $this->assertStringContainsString($fix, $flash);
        $this->assertNoVendorBody($flash);
    }

    #[DataProvider('aadstsCases')]
    public function test_a_code_exchange_failure_is_mapped_to_plain_english_without_the_body(int $number, string $what, string $fix): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => "AADSTS{$number}: ".self::BODY_CANARY,
            'error_codes' => [$number],
        ], 400)]);

        $this->oauthCallback(['code' => self::CODE])->assertRedirect(route('settings.integrations'));

        $flash = (string) session('error');
        $this->assertStringStartsWith("CIPP MCP sign-in failed (AADSTS{$number}): ", $flash);
        $this->assertStringContainsString($what, $flash);
        $this->assertStringContainsString($fix, $flash);
        $this->assertNoVendorBody($flash);
        $this->assertFalse(app(CippMcpConnector::class)->isConnected());
    }

    public function test_the_fix_lines_name_the_client_id_and_the_exact_callback(): void
    {
        $this->oauthCallback(['error' => 'invalid_request', 'error_description' => 'AADSTS50011: x'])->assertRedirect();
        $flash = (string) session('error');
        $this->assertStringContainsString('App registrations → '.self::CLIENT.' → Authentication', $flash);
        $this->assertStringContainsString($this->callbackUrl(), $flash);

        $this->oauthCallback(['error' => 'access_denied', 'error_description' => 'AADSTS53003: x'])->assertRedirect();
        $this->assertStringContainsString('INSTALL.md', (string) session('error'));
    }

    public function test_an_unknown_code_shows_the_code_with_a_generic_line(): void
    {
        $this->oauthCallback(['error' => 'server_error', 'error_description' => 'AADSTS90002: '.self::BODY_CANARY])->assertRedirect();
        $flash = (string) session('error');
        $this->assertStringStartsWith('CIPP MCP sign-in failed (error code: server_error / AADSTS90002). ', $flash);
        $this->assertStringContainsString('Check setup', $flash);
        $this->assertNoVendorBody($flash);

        // No AADSTS number at all: the `error` code alone.
        $this->oauthCallback(['error' => 'access_denied'])->assertRedirect();
        $this->assertStringStartsWith('CIPP MCP sign-in failed (error code: access_denied). ', (string) session('error'));

        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'AADSTS54005: '.self::BODY_CANARY, 'error_codes' => [54005]], 400)]);
        $this->oauthCallback(['code' => self::CODE])->assertRedirect();
        $flash = (string) session('error');
        $this->assertStringStartsWith('CIPP MCP sign-in failed (error code: invalid_grant / AADSTS54005). ', $flash);
        $this->assertNoVendorBody($flash);
    }

    public function test_a_non_json_exchange_body_is_reduced_to_the_http_status(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response('<html>'.self::BODY_CANARY.'</html>', 502)]);
        $this->oauthCallback(['code' => self::CODE])->assertRedirect();
        $flash = (string) session('error');
        $this->assertStringStartsWith('CIPP MCP sign-in failed (error code: HTTP 502). ', $flash);
        $this->assertNoVendorBody($flash);
    }

    public function test_an_unreachable_token_endpoint_flashes_the_exception_class_only(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: '.self::BODY_CANARY));
        $this->oauthCallback(['code' => self::CODE])->assertRedirect();
        $flash = (string) session('error');
        $this->assertSame('Could not connect CIPP MCP: CIPP MCP code exchange could not reach Microsoft (ConnectionException)', $flash);
        $this->assertNoVendorBody($flash);
    }

    // --- label + help ---------------------------------------------------------------

    public function test_the_mcp_client_id_label_says_connect_needs_it_in_both_states(): void
    {
        foreach ([false, true] as $connected) {
            if ($connected) {
                Setting::setEncrypted(CippMcpConnector::REFRESH_TOKEN_SETTING, 'RT-CANARY-label');
            }
            $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<label for="cipp_mcp_client_id"[^>]*>MCP Client ID <small class="text-muted">\(required for Connect CIPP MCP\)</small></label>#', $html);
            $this->assertDoesNotMatchRegularExpression('#<label for="cipp_mcp_client_id"[^>]*>[^<]*<small[^>]*>[^<]*legacy#i', $html);
            $this->assertMatchesRegularExpression('#<label for="cipp_mcp_client_secret"[^>]*>MCP Client Secret <small class="text-muted">\((legacy, CIPP &lt; v11|only for a Web redirect)\)</small>#', $html, 'the secret keeps its legacy/optional label');
        }
    }

    public function test_the_panel_help_carries_the_ordered_checklist_with_the_53003_fix(): void
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('settings.integrations'))->getContent();
        $start = strpos($html, 'id="cipp-setup-check"');
        $this->assertNotFalse($start);
        $help = substr($html, $start, 2500);
        $this->assertMatchesRegularExpression('#\(1\).*Expose an API.*\(2\).*Mobile and desktop applications.*Allow public client flows.*\(3\).*AADSTS53003.*dedicated CIPP service account excluded.*\(4\).*Check setup#s', $help);
    }
}
