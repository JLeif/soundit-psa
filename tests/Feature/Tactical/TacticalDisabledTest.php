<?php

namespace Tests\Feature\Tactical;

use App\Jobs\ProcessTacticalWebhook;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalWebhook;
use App\Models\User;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Tactical\Actions\RecoverAction;
use App\Services\Tactical\TacticalActionService;
use App\Services\Tactical\TacticalAlertService;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Tactical\TacticalInsightService;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\McpToolSurface;
use App\Support\TacticalConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * OFF=OFF for Tactical RMM. Settings has always rendered an "Integration enabled"
 * switch that writes `tactical_enabled`, but TacticalConfig::isEnabled() was defined
 * AS isConfigured() and nothing read the switch. Turning Tactical off changed
 * nothing: every client page without a Tactical site still made a live, uncached
 * policy fetch against a dead Tactical API and stalled on its timeout (30s, then
 * ~3s), and the AI tools, schedules, panels, actions and webhooks all stayed live.
 *
 * With the switch off Tactical must be ignored everywhere while its synced data
 * stays in the DB. Each surface is asserted in both directions where the "on"
 * half is cheap, so a fix that over-blocks is caught as well as one that leaks.
 */
class TacticalDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function disable(): void
    {
        Setting::setValue('tactical_enabled', '0');
    }

    private function linkedAsset(): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
            'synced_at' => now()->subMinutes(5),
        ]);

        return $asset->refresh();
    }

    /** Bind a client whose transport fails the test if any request reaches it. */
    private function bindUnreachableClient(): MockHandler
    {
        $mock = new MockHandler([]);
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => HandlerStack::create($mock)]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));

        return $mock;
    }

    // ── Config ───────────────────────────────────────────────────────────────

    public function test_switch_defaults_on_and_availability_needs_switch_and_credentials(): void
    {
        $this->assertTrue(TacticalConfig::isEnabled(), 'an install that never touched the switch stays on');
        $this->assertTrue(TacticalConfig::isAvailable());

        $this->disable();

        $this->assertFalse(TacticalConfig::isEnabled());
        $this->assertTrue(TacticalConfig::isConfigured(), 'credentials are kept — only the switch is off');
        $this->assertFalse(TacticalConfig::isAvailable());
    }

    public function test_settings_toggle_turns_the_switch_off(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('settings.integrations.toggle'), ['integration' => 'tactical'])
            ->assertRedirect(route('settings.integrations'));

        $this->assertFalse(TacticalConfig::isEnabled());
    }

    // ── Transport backstop ───────────────────────────────────────────────────

    public function test_gate_middleware_refuses_before_transport_when_disabled_and_passes_when_enabled(): void
    {
        $reached = 0;
        $inner = function (RequestInterface $request, array $options) use (&$reached) {
            $reached++;

            return Create::promiseFor(new Response(200, [], '{"ok":true}'));
        };
        $stack = HandlerStack::create($inner);
        $stack->push(TacticalClient::enabledGateMiddleware());
        $client = new TacticalClient(new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://tactical.example.com/']));

        $this->assertSame(['ok' => true], $client->get('agents/'));
        $this->assertSame(1, $reached);

        $this->disable();

        try {
            $client->get('agents/');
            $this->fail('expected a refusal with the integration switched off');
        } catch (TacticalClientException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
            $this->assertFalse($e->isTransportFailure(), 'disabled must never classify as an offline agent');
        }

        $this->assertSame(1, $reached, 'no request may reach the transport while disabled');
    }

    public function test_config_driven_client_wires_the_gate_into_its_handler_stack(): void
    {
        $client = new TacticalClient;
        $http = (new \ReflectionProperty(TacticalClient::class, 'http'))->getValue($client);

        $this->assertStringContainsString('tactical_enabled_gate', (string) $http->getConfig('handler'));
    }

    // ── Client page (the reported 30s → 3s stall) ───────────────────────────

    public function test_client_page_omits_tactical_card_and_policy_fetch_when_disabled(): void
    {
        Bus::fake();
        // Any configured registry vendor makes the Integrations tab render.
        Setting::setEncrypted('mesh_api_key', 'mesh-key');
        $user = User::factory()->create();
        $client = Client::factory()->create(['is_active' => true, 'tactical_site_id' => null]);

        // Enabled: the card renders with policies (pre-cached so no network is attempted).
        Cache::put('tactical:policies', [['id' => 7, 'name' => 'Standard Workstations']], 300);
        $this->actingAs($user)->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Standard Workstations');

        $this->disable();
        Cache::forget('tactical:policies');

        $this->actingAs($user)->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee('Workstation policy')
            ->assertDontSee(route('clients.tactical.provision', $client));

        $this->assertFalse(Cache::has('tactical:policies'), 'no policy fetch may be attempted while disabled');
        $this->assertSame([], TacticalClient::cachedPolicies());
    }

    // ── AI / MCP tool surfaces ───────────────────────────────────────────────

    public function test_ai_and_mcp_tool_surfaces_withdraw_tactical_tools_when_disabled(): void
    {
        $tacticalNames = fn (array $defs) => array_values(array_filter(
            array_column($defs, 'name'),
            fn (string $n) => str_starts_with($n, 'tactical_'),
        ));

        $this->assertNotEmpty($tacticalNames(TriageToolDefinitions::getTools()), 'enabled: triage publishes tactical tools');
        $this->assertNotEmpty($tacticalNames(ChetDataSurfaceTools::clientTools()), 'enabled: MCP read tools published');
        $this->assertNotEmpty($tacticalNames(McpToolSurface::liveClientScopedToolDefinitions()), 'enabled: MCP action tools published');

        $this->disable();

        $this->assertSame([], $tacticalNames(TriageToolDefinitions::getTools()));
        $this->assertSame([], $tacticalNames(ChetDataSurfaceTools::clientTools()));
        $this->assertSame([], $tacticalNames(ChetDataSurfaceTools::generalTools()));
        $this->assertSame([], $tacticalNames(McpToolSurface::liveClientScopedToolDefinitions()));
        $this->assertSame([], $tacticalNames(McpToolSurface::liveGeneralToolDefinitions()));
    }

    // ── Actions, panels, insight ─────────────────────────────────────────────

    public function test_action_bus_refuses_without_calling_tactical_when_disabled(): void
    {
        $mock = $this->bindUnreachableClient();
        $asset = $this->linkedAsset();
        $this->disable();

        $result = app(TacticalActionService::class)->dispatch(
            new RecoverAction, $asset, User::factory()->create(), ['mode' => 'mesh'],
        );

        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('disabled', (string) $result->message);
        $this->assertNull($mock->getLastRequest(), 'no Tactical request may be made');
    }

    public function test_asset_action_endpoint_refuses_when_disabled(): void
    {
        $mock = $this->bindUnreachableClient();
        $asset = $this->linkedAsset();
        $this->disable();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assets.recover-tactical', $asset), ['mode' => 'mesh'])
            ->assertStatus(422)
            ->assertJson(['error' => 'Tactical RMM integration is disabled.']);

        $this->assertNull($mock->getLastRequest());
    }

    public function test_insight_reads_as_not_linked_but_synced_data_is_kept_when_disabled(): void
    {
        $asset = $this->linkedAsset();

        $this->assertTrue(app(TacticalInsightService::class)->forAsset($asset)->linked);

        $this->disable();

        $this->assertFalse(app(TacticalInsightService::class)->forAsset($asset->refresh())->linked);
        $this->assertDatabaseHas('tactical_assets', ['asset_id' => $asset->id, 'agent_id' => 'AGENT-1']);
    }

    public function test_portal_install_rmm_list_excludes_tactical_when_disabled(): void
    {
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $this->assertContains('tactical', $client->availableRmms());

        $this->disable();

        $this->assertNotContains('tactical', $client->availableRmms());
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    public function test_webhook_is_acked_but_not_stored_or_queued_when_disabled(): void
    {
        Queue::fake();
        Setting::setEncrypted('tactical_webhook_key', 'test-tactical-webhook-key-1234567890');
        $this->disable();

        $this->withHeaders(['X-Webhook-Key' => 'test-tactical-webhook-key-1234567890'])
            ->postJson('/api/webhooks/tactical', ['event' => 'alert_failure', 'agent_id' => 'AGENT-1'])
            ->assertNoContent();

        $this->assertDatabaseCount('tactical_webhooks', 0);
        Queue::assertNotPushed(ProcessTacticalWebhook::class);
    }

    public function test_already_queued_webhook_is_skipped_when_disabled(): void
    {
        // A real alert payload — one that WOULD become an alert with the switch on.
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/alert_failure.json')), true);
        $webhook = TacticalWebhook::create([
            'event' => 'alert_failure',
            'agent_id' => $payload['agent_id'] ?? null,
            'payload' => $payload,
            'status' => 'pending',
            'dedup_key' => 'test-dedup-1',
        ]);
        $this->disable();

        (new ProcessTacticalWebhook($webhook->id))->handle(app(TacticalAlertService::class));

        $webhook->refresh();
        $this->assertSame('skipped', $webhook->status);
        $this->assertSame('Tactical RMM integration is disabled', $webhook->error);
        $this->assertDatabaseCount('alerts', 0);
    }
}
