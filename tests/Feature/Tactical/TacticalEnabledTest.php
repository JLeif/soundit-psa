<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\ProcessTacticalWebhook;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalWebhook;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Portal\PortalInstallService;
use App\Services\Tactical\OfflineActionSweep;
use App\Services\Tactical\TacticalAlertService;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\McpToolSurface;
use App\Support\TacticalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TacticalEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-test-key');
        Setting::setEncrypted('tactical_webhook_key', 'synthetic-webhook-key');
        $this->assertSame(base_path('app/Support/TacticalConfig.php'), (new \ReflectionClass(TacticalConfig::class))->getFileName());
    }

    public function test_master_switch_defaults_on_but_accepts_only_literal_one_and_requires_credentials(): void
    {
        $this->assertTrue(TacticalConfig::isConfigured());
        $this->assertTrue(TacticalConfig::isEnabled());
        foreach (['0', '', 'true', 'yes', '01', ' 1', '1 '] as $value) {
            Setting::setValue('tactical_enabled', $value);
            $this->assertFalse(TacticalConfig::isEnabled(), 'value: '.json_encode($value));
            $this->assertTrue(TacticalConfig::isConfigured());
        }
        Setting::setValue('tactical_enabled', '1');
        $this->assertTrue(TacticalConfig::isEnabled());
        Setting::setValue('tactical_api_url', '');
        $this->assertFalse(TacticalConfig::isEnabled());
    }

    private function queued(): TechnicianRun
    {
        $ticket = Ticket::factory()->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script', 'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::QueuedOffline, 'queued_agent_id' => 'synthetic-agent',
            'queued_dedup_key' => 'synthetic-key', 'queued_at' => now()->subMinutes(5), 'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_disabled_job_skips_pending_with_exact_reason_and_never_dispatches_reconnect(): void
    {
        Queue::fake();
        $run = $this->queued();
        $row = TacticalWebhook::factory()->create(['event' => 'alert_resolved', 'agent_id' => $run->queued_agent_id, 'status' => 'pending']);
        // Permissive collaborator makes a removed job gate fail on the lifecycle assertion,
        // not on an unstubbed mock. The paired enabled test proves this path dispatches.
        $service = Mockery::mock(TacticalAlertService::class);
        $service->shouldReceive('handleAlertResolved')->andReturn(new Alert);
        Setting::setValue('tactical_enabled', '0');
        (new ProcessTacticalWebhook($row->id))->handle($service);
        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertSame('Tactical integration is disabled', $row->fresh()->error);
        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_enabled_pending_job_processes_and_dispatches_reconnect(): void
    {
        Queue::fake();
        $run = $this->queued();
        $row = TacticalWebhook::factory()->create(['event' => 'alert_resolved', 'agent_id' => $run->queued_agent_id, 'status' => 'pending']);
        $service = Mockery::mock(TacticalAlertService::class);
        $service->shouldReceive('handleAlertResolved')->once()->andReturn(new Alert);
        (new ProcessTacticalWebhook($row->id))->handle($service);
        $this->assertSame('processed', $row->fresh()->status);
        Queue::assertPushed(SweepQueuedActionsForAgent::class, 1);
    }

    public function test_disabled_receiving_controller_still_authenticates_persists_and_queues_job(): void
    {
        Queue::fake();
        Setting::setValue('tactical_enabled', '0');
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/alert_failure.json')), true);
        $this->withHeader('X-Webhook-Key', 'wrong')->postJson('/api/webhooks/tactical', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('tactical_webhooks', 0);
        $this->withHeader('X-Webhook-Key', 'synthetic-webhook-key')->postJson('/api/webhooks/tactical', $payload)->assertNoContent();
        $row = TacticalWebhook::sole();
        $this->assertSame('pending', $row->status);
        Queue::assertPushed(ProcessTacticalWebhook::class, 1);
        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));
        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertSame('Tactical integration is disabled', $row->fresh()->error);
        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }

    public function test_disabled_job_preserves_nonpending_and_missing_rows(): void
    {
        Setting::setValue('tactical_enabled', '0');
        $row = TacticalWebhook::factory()->create(['status' => 'processed', 'error' => null]);
        $service = Mockery::mock(TacticalAlertService::class);
        (new ProcessTacticalWebhook($row->id))->handle($service);
        (new ProcessTacticalWebhook($row->id + 1))->handle($service);
        $this->assertSame('processed', $row->fresh()->status);
        $this->assertNull($row->fresh()->error);
        $this->assertDatabaseCount('tactical_webhooks', 1);
    }

    public function test_publication_withdraws_all_tactical_tools_but_keeps_catalog(): void
    {
        $this->assertTrue(TriageToolDefinitions::isTacticalAvailable());
        $this->assertContains('tactical_run_script', McpToolSurface::liveToolNames());
        $this->assertNotEmpty(ChetDataSurfaceTools::clientTools());
        Setting::setValue('tactical_enabled', '0');
        $this->assertFalse(TriageToolDefinitions::isTacticalAvailable());
        foreach (McpToolSurface::liveToolNames() as $name) {
            $this->assertFalse(str_starts_with($name, 'tactical_'), $name);
        }
        foreach (array_merge(ChetDataSurfaceTools::clientTools(), ChetDataSurfaceTools::generalTools(), TriageToolDefinitions::getTools()) as $tool) {
            $this->assertFalse(str_starts_with($tool['name'], 'tactical_'), $tool['name']);
        }
        $this->assertNotEmpty(\App\Support\McpToolRegistry::tacticalActionTools());
    }

    public function test_disabled_executors_refuse_before_target_or_upstream_resolution(): void
    {
        Setting::setValue('tactical_enabled', '0');
        $client = Mockery::mock(TacticalClient::class);
        $this->app->instance(TacticalClient::class, $client);
        $scope = Client::factory()->create();
        $this->assertSame(['error' => 'Tactical RMM is disabled or not configured'], app(TacticalReadOnlyToolset::class)->execute('tactical_list_devices', [], 1));
        $this->assertSame(['error' => 'Tactical RMM is disabled or not configured'], app(StaffTacticalActionToolExecutor::class)->execute('tactical_run_script', [], $scope->id, 'test'));
        $this->assertSame(['error' => 'Tactical RMM is disabled or not configured'], app(StaffTacticalAdminToolExecutor::class)->execute('tactical_sync_devices_now', [], $scope->id, 'test'));
        $run = $this->queued();
        $this->assertSame('gate_declined', app(StaffTacticalActionToolExecutor::class)->runQueuedOnReconnect($run)->status);
        $this->assertSame('gate_declined', app(StaffTacticalActionToolExecutor::class)->approveStagedRun($run, 1)->status);
        $this->assertSame('gate_declined', app(StaffTacticalAdminToolExecutor::class)->approveStagedRun($run, 1)->status);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_disabled_sweep_does_not_call_executor_but_expiry_continues(): void
    {
        $run = $this->queued();
        TacticalAsset::create(['agent_id' => $run->queued_agent_id, 'hostname' => 'SYNTHETIC', 'status' => 'online']);
        $executor = Mockery::mock(StaffTacticalActionToolExecutor::class);
        $calls = 0;
        $executor->shouldReceive('runQueuedOnReconnect')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return new \App\Services\Technician\TechnicianApprovalResult('executed');
        });
        $sweep = new OfflineActionSweep($executor);
        Setting::setValue('tactical_enabled', '0');
        $expiry = $run->expires_at->timestamp;
        $this->assertSame(0, $sweep->sweepAgent($run->queued_agent_id));
        $this->assertSame(['ran' => 0, 'expired' => 0], $sweep->sweepDue());
        $this->assertSame(0, $calls);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
        $this->assertSame($expiry, $run->fresh()->expires_at->timestamp);
        Setting::setValue('tactical_enabled', '1');
        $this->assertSame(1, $sweep->sweepAgent($run->queued_agent_id));
        $this->assertSame(1, $calls);
        Setting::setValue('tactical_enabled', '0');
        $run->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(['ran' => 0, 'expired' => 1], $sweep->sweepDue());
        $this->assertSame(1, $calls);
        $this->assertNotSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_disabled_settings_operations_refuse_but_diagnostic_still_runs(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::setValue('tactical_enabled', '0');
        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('isHealthy')->once()->andReturn(true);
        $this->app->instance(TacticalClient::class, $client);
        foreach (['sync-devices', 'sync-scripts'] as $verb) {
            $this->post('/settings/integrations/tactical/'.$verb)->assertRedirect()->assertSessionHas('error', 'Tactical RMM is disabled or not configured.');
        }
        $this->post('/settings/integrations/tactical/provision-alerts')->assertJson(['success' => false, 'message' => 'Tactical RMM is disabled or not configured.']);
        $this->get('/settings/integrations/tactical/sites')->assertRedirect()->assertSessionHas('error', 'Tactical RMM is disabled or not configured.');
        $this->post('/settings/integrations/tactical/test')->assertJson(['success' => true]);
        $this->assertNotNull(Setting::getValue('tactical_connected_at'));
    }

    public function test_disabled_policies_and_portal_install_make_no_vendor_calls(): void
    {
        Setting::setValue('tactical_enabled', '0');
        Cache::put('tactical:policies', [['id' => 1, 'name' => 'Synthetic']], 300);
        $this->assertSame([], TacticalClient::cachedPolicies());
        $client = Client::factory()->create(['tactical_site_id' => 'Synthetic|Main']);
        $transport = Mockery::mock(TacticalClient::class);
        $transport->shouldReceive('supportsInstall')->andReturn(true);
        $transport->shouldReceive('getInstallerInfo')->andReturn(null);
        $this->app->instance(TacticalClient::class, $transport);
        $service = app(PortalInstallService::class);
        $this->assertSame([], $service->supportedPlatforms($client));
        $this->assertNull($service->buildInstaller($client, 'windows'));
        Setting::setValue('tactical_enabled', '1');
        $this->assertSame([['id' => 1, 'name' => 'Synthetic']], TacticalClient::cachedPolicies());
    }

    public function test_disabled_commands_refuse_operational_work_and_allow_expiry_only_sweep(): void
    {
        Setting::setValue('tactical_enabled', '0');
        $transport = Mockery::mock(TacticalClient::class);
        $transport->shouldReceive('getScripts')->andReturn([]);
        $this->app->instance(TacticalClient::class, $transport);
        foreach (['tactical:sync-devices', 'tactical:sync-scripts', 'tactical:reconcile-alerts', 'tactical:provision-macos-check'] as $command) {
            $this->artisan($command)->expectsOutputToContain('disabled or not configured')->assertFailed();
        }
        $run = $this->queued();
        $run->update(['expires_at' => now()->subMinute()]);
        $this->artisan('tactical:sweep-queued-actions')->expectsOutputToContain('ran 0, expired 1')->assertSuccessful();
        $this->assertNotSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_disabled_schedules_stop_before_throttle_but_preserve_expiry_schedule(): void
    {
        Client::factory()->create(['tactical_site_id' => 'Synthetic|Main']);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        Setting::setValue('tactical_enabled', '0');
        foreach (['tactical:sync-devices', 'tactical:sync-scripts', 'tactical:reconcile-alerts'] as $command) {
            $matches = $events->filter(fn ($event) => str_contains($event->command ?? '', $command));
            $this->assertCount(1, $matches);
            $this->assertFalse($matches->first()->filtersPass($this->app), $command);
        }
        $this->assertNull(Cache::get('tactical_last_device_sync'));
        $sweep = $events->first(fn ($event) => str_contains($event->command ?? '', 'tactical:sweep-queued-actions'));
        $this->assertNotNull($sweep);
        $this->assertTrue($sweep->filtersPass($this->app));
        Setting::setValue('tactical_enabled', '1');
        foreach (['tactical:sync-devices', 'tactical:sync-scripts', 'tactical:reconcile-alerts'] as $command) {
            $event = $events->first(fn ($event) => str_contains($event->command ?? '', $command));
            $this->assertTrue($event->filtersPass($this->app), $command);
        }
    }

    public function test_disabled_bus_audits_refusal_and_enabled_bus_executes(): void
    {
        $asset = Asset::factory()->create();
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'synthetic-agent', 'hostname' => 'SYNTHETIC']);
        $action = new class implements \App\Services\Tactical\Actions\TacticalAction
        {
            public int $calls = 0;

            public function key(): string
            {
                return 'tactical.synthetic';
            }

            public function summary(array $params): string
            {
                return 'Synthetic action';
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function execute(TacticalClient $client, string $agentId, array $params): \App\Services\Tactical\Actions\TacticalActionResult
            {
                $this->calls++;

                return \App\Services\Tactical\Actions\TacticalActionResult::ok('Synthetic success');
            }
        };
        $bus = new \App\Services\Tactical\TacticalActionService(Mockery::mock(TacticalClient::class));
        Setting::setValue('tactical_enabled', '0');
        $result = $bus->dispatch($action, $asset, null, [], actorLabel: 'synthetic');
        $this->assertSame('blocked', $result->status);
        $this->assertSame(0, $action->calls);
        $this->assertDatabaseHas('tactical_action_logs', ['asset_id' => $asset->id, 'result_status' => 'blocked']);
        Setting::setValue('tactical_enabled', '1');
        $this->assertSame('ok', $bus->dispatch($action, $asset, null, [], actorLabel: 'synthetic')->status);
        $this->assertSame(1, $action->calls);
    }

    public function test_disabled_asset_reads_and_triage_context_make_no_vendor_calls(): void
    {
        $this->actingAs(User::factory()->create());
        $asset = Asset::factory()->create();
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'synthetic-agent', 'hostname' => 'SYNTHETIC']);
        $this->app->instance(TacticalClient::class, Mockery::mock(TacticalClient::class));
        Setting::setValue('tactical_enabled', '0');
        foreach (['refresh', 'meshcentral'] as $verb) {
            $this->postJson('/assets/'.$asset->id.'/tactical/'.$verb)->assertStatus(422)->assertJson(['error' => 'Tactical RMM is disabled or not configured.']);
        }
        $this->getJson('/assets/'.$asset->id.'/device-data/checks?source=tactical')->assertStatus(422)->assertJson(['error' => 'Tactical RMM is disabled or not configured.']);
        $this->assertNull(app(\App\Services\Tactical\TacticalContextProvider::class)->forAsset($asset));
        $ticket = Ticket::factory()->create();
        $executor = new \App\Services\Triage\TriageToolExecutor($ticket);
        $this->assertSame(['error' => 'Tactical RMM is disabled or not configured'], $executor->execute('tactical_get_device', ['hostname' => $asset->hostname]));
    }

    public function test_servosity_disabled_switch_suppresses_tactical_field_writes(): void
    {
        $asset = Asset::factory()->create();
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'synthetic-agent', 'hostname' => 'SYNTHETIC']);
        $calls = 0;
        $transport = Mockery::mock(TacticalClient::class);
        $transport->shouldReceive('setAgentCustomField')->andReturnUsing(function () use (&$calls) {
            $calls++;
        });
        $this->app->instance(TacticalClient::class, $transport);
        Setting::setValue('tactical_enabled', '0');
        $service = app(\App\Services\Servosity\ServosityDeploymentService::class);
        $service->disableBackup($asset);
        $this->assertSame(0, $calls);
        Setting::setValue('tactical_enabled', '1');
        $service->disableBackup($asset);
        $this->assertSame(4, $calls);
    }

    public function test_disabled_client_provisioning_refuses(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        Setting::setValue('tactical_enabled', '0');
        $this->post('/clients/'.$client->id.'/tactical/provision')->assertRedirect()->assertSessionHas('error', 'Tactical RMM is disabled or not configured.');
    }

    public function test_disabled_bulk_sync_cannot_dispatch_reconnect_jobs(): void
    {
        Queue::fake();
        $run = $this->queued();
        Client::factory()->create(['tactical_site_id' => 'Synthetic|Main', 'is_active' => true]);
        $asset = Asset::factory()->create(['hostname' => 'SYNTHETIC']);
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => $run->queued_agent_id, 'hostname' => 'SYNTHETIC', 'status' => 'offline']);
        $transport = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([['agent_id' => $run->queued_agent_id, 'hostname' => 'SYNTHETIC', 'client_name' => 'Synthetic', 'site_name' => 'Main', 'status' => 'online']])),
        ]))]);
        Setting::setValue('tactical_enabled', '0');
        (new \App\Services\Tactical\TacticalDeviceSyncService(new TacticalClient($transport)))->syncDevices();
        $this->assertSame('online', TacticalAsset::where('agent_id', $run->queued_agent_id)->value('status'));
        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_dispatch_helper_obeys_switch_and_keeps_queue_intact(): void
    {
        Queue::fake();
        $run = $this->queued();
        Setting::setValue('tactical_enabled', '0');
        SweepQueuedActionsForAgent::dispatchIfQueued($run->queued_agent_id);
        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
        Setting::setValue('tactical_enabled', '1');
        SweepQueuedActionsForAgent::dispatchIfQueued($run->queued_agent_id);
        Queue::assertPushed(SweepQueuedActionsForAgent::class, 1);
    }
}
