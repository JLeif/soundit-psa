<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Litsrmm\LitsrmmClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1b: the settings surface for LITSRMM.
 *
 * Stage 1 made the integration exist; without this, the only way to configure
 * it was env or config, so an operator could not use it at all. Jeeves's stage-1
 * ruling put the panel and Test connection here.
 *
 * WHAT THESE CONTROLS PIN
 * -----------------------
 * That an operator can set the host, key and toggle; that the key is never
 * rendered back to the page; that Test connection reaches the REAL client
 * rather than a second hand-built request; and that each not-configured case
 * names the field the operator has to fix.
 *
 * WHAT THEY DO NOT
 * ----------------
 * No live call is made. isHealthy() is exercised through the Guzzle handler
 * seam, so these tests say nothing about whether the vendor's real /v1/health
 * behaves as its proposal describes.
 */
class LitsrmmSettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * Bind a LitsrmmClient whose transport is observable, so a test can prove
     * what left the process instead of trusting a return value.
     */
    private function bindClient(array $responses): void
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $this->app->bind(LitsrmmClient::class, fn () => new LitsrmmClient([
            'api_key' => 'panel-token-value',
            'base_url' => 'http://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]));
    }

    private function configure(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', 'http://litsrmm.test');
    }

    // ---- the panel renders and can be configured at all ----

    public function test_the_integrations_page_renders_a_litsrmm_card(): void
    {
        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        $response->assertSee('Leif IT Solutions RMM', false);
    }

    public function test_an_operator_can_save_a_base_url_and_api_key(): void
    {
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.example.com',
            'api_key' => 'operator-entered-key',
        ]);

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('success');

        $this->assertSame('https://rmm.example.com', Setting::getValue('litsrmm_base_url'));
        $this->assertSame('operator-entered-key', Setting::getEncrypted('litsrmm_api_key'),
            'the key must round-trip, or an operator cannot configure the integration at all');
    }

    public function test_a_trailing_slash_on_the_base_url_is_normalised_away(): void
    {
        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://rmm.example.com/',
        ]);

        // The client joins paths onto this value; a stored trailing slash makes
        // every request carry a double slash, which some hosts route differently.
        $this->assertSame('https://rmm.example.com', Setting::getValue('litsrmm_base_url'));
    }

    public function test_saving_one_field_does_not_clear_the_other(): void
    {
        $this->configure();

        $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'https://moved.example.com',
        ]);

        $this->assertSame('https://moved.example.com', Setting::getValue('litsrmm_base_url'));
        $this->assertSame('panel-token-value', Setting::getEncrypted('litsrmm_api_key'),
            'submitting only the host must not wipe the key: the form says "leave blank to keep current"');
    }

    public function test_a_malformed_base_url_is_refused_and_nothing_is_stored(): void
    {
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm', [
            'base_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('base_url');
        $this->assertNull(Setting::getValue('litsrmm_base_url'),
            'a refused submission must not half-apply');
    }

    // ---- the key is never echoed back ----

    public function test_the_stored_api_key_is_never_rendered_into_the_page(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        // Asserting on the whole body, not on a field: the hazard is the value
        // appearing ANYWHERE - a value attribute, a data- attribute, a JS blob.
        $this->assertStringNotContainsString('panel-token-value', $response->getContent(),
            'the API key must not be recoverable from the settings page or its HTML source');
    }

    public function test_the_base_url_i_s_shown_so_an_operator_can_confirm_the_host(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        // The counterpart to the control above: hiding the host too would leave
        // an operator unable to tell which instance is configured. This control
        // exists so "hide everything" cannot satisfy the test above.
        $response->assertSee('http://litsrmm.test', false);
    }

    // ---- Test connection reaches the real client ----

    public function test_test_connection_reports_success_and_stamps_the_time(): void
    {
        $this->configure();
        $this->bindClient([new Response(200, [], json_encode(['ok' => true]))]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertCount(1, $this->history,
            'the button must reach the real client, not report success from configuration alone');
        $this->assertStringEndsWith('/v1/health', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer panel-token-value', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertNotNull(Setting::getValue('litsrmm_connected_at'));
    }

    public function test_test_connection_reports_failure_without_stamping_the_time(): void
    {
        $this->configure();
        $this->bindClient([new Response(500, [], 'upstream exploded')]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $this->assertNull(Setting::getValue('litsrmm_connected_at'),
            'a failed test must not leave a "last successful test" timestamp behind');
    }

    public function test_a_missing_api_key_and_a_missing_host_are_reported_separately(): void
    {
        // Two different operator actions, so one shared "not configured"
        // message would send them looking at the wrong field.
        Setting::setValue('litsrmm_base_url', 'http://litsrmm.test');
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('API key', $response->json('message'));

        Setting::setEncrypted('litsrmm_api_key', 'panel-token-value');
        Setting::setValue('litsrmm_base_url', '');
        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('Base URL', $response->json('message'));
    }

    public function test_a_switched_off_integration_says_so_rather_than_blaming_credentials(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');
        $this->bindClient([new Response(200, [], json_encode(['ok' => true]))]);

        $response = $this->actingAs($this->admin())->post('/settings/integrations/litsrmm/test');

        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('switched off', $response->json('message'),
            'isHealthy() is gated on isAvailable(), so a disabled vendor fails the test - say why');
        $this->assertCount(0, $this->history,
            'and it must not reach the network while disabled');
    }

    // ---- the toggle is reachable from the panel ----

    public function test_the_toggle_route_accepts_litsrmm(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->post('/settings/integrations/toggle', [
            'integration' => 'litsrmm',
        ]);

        $response->assertRedirect(route('settings.integrations'));
        // No 'enabled' key submitted = the unchecked checkbox = switched off.
        $this->assertSame('0', Setting::getValue('litsrmm_enabled'));

        $this->actingAs($this->admin())->post('/settings/integrations/toggle', [
            'integration' => 'litsrmm',
            'enabled' => '1',
        ]);
        $this->assertSame('1', Setting::getValue('litsrmm_enabled'));
    }

    public function test_the_test_button_is_wired_to_a_route_the_javascript_knows(): void
    {
        $this->configure();

        $response = $this->actingAs($this->admin())->get('/settings/integrations');
        $body = $response->getContent();

        // The page's testConnection() helper looks the URL up in an explicit
        // route map keyed by service name. A button whose key is missing from
        // that map fetches undefined and fails silently, which looks exactly
        // like a broken integration. Both halves are asserted because either
        // one alone passes while the button does nothing.
        $this->assertStringContainsString("testConnection('litsrmm')", $body,
            'the button must call the shared helper');
        $this->assertStringContainsString("litsrmm: '".route('settings.integrations.litsrmm.test')."'", $body,
            'and the helper must be able to resolve a URL for it');
    }

    // ---- the three badge states are distinguishable ----

    public function test_a_configured_but_disabled_integration_does_not_read_as_unconfigured(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');

        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        // Collapsing these two states is how an operator concludes their
        // credentials were lost and re-enters them.
        $response->assertSee('Configured (disabled)', false);
    }

    public function test_an_unconfigured_integration_hides_the_enable_toggle(): void
    {
        $response = $this->actingAs($this->admin())->get('/settings/integrations');

        $response->assertOk();
        $this->assertStringNotContainsString('id="litsrmm_enabled"', $response->getContent(),
            'offering a switch for a vendor with no credentials invites turning on something inert');
    }
}
