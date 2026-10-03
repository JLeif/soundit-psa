<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card BMdt5WQZ: when Tactical sync refuses to create an asset because the
 * hostname belongs to a RETIRED (soft-deleted) asset, the result names that
 * asset — id, both hostnames, and whether restore_asset would bring it back —
 * on every consumer: SyncResult details, the Integrations banner, the
 * tactical:sync-devices command and the tactical_sync_devices_now MCP return.
 *
 * Synthetic data only (G-13): *.example.test hostnames, placeholder client.
 */
class TacticalSyncRetiredSkipNamesTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $agents */
    private function tacticalClient(array $agents): TacticalClient
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($agents))])),
            'timeout' => 30,
        ]);

        return new TacticalClient($http);
    }

    /** @param  array<int, array<string, mixed>>  $agents */
    private function bindAgents(array $agents): void
    {
        $this->app->instance(TacticalClient::class, $this->tacticalClient($agents));
    }

    /** @param  array<int, array<string, mixed>>  $agents */
    private function sync(array $agents): \App\Services\SyncResult
    {
        return (new TacticalDeviceSyncService($this->tacticalClient($agents)))->syncDevices();
    }

    private function enableTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create(['name' => 'Example Co', 'tactical_site_id' => 'Example|Main', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function agent(string $agentId, string $hostname): array
    {
        return [
            'agent_id' => $agentId,
            'hostname' => $hostname,
            'client_name' => 'Example',
            'site_name' => 'Main',
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'serial_number' => 'SN-'.$agentId,
            'last_seen' => '2026-07-29 12:00:00',
        ];
    }

    private function retiredAsset(Client $client, string $hostname, array $attrs = []): Asset
    {
        $asset = Asset::factory()->create(array_merge(['client_id' => $client->id, 'hostname' => $hostname, 'name' => $hostname], $attrs));
        $asset->delete();

        return $asset->fresh() ?? Asset::withTrashed()->findOrFail($asset->id);
    }

    /** A retired row that AssetService::mergeAssets tombstoned. */
    private function mergeTombstone(Client $client, string $hostname): array
    {
        $survivor = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'survivor.example.test', 'name' => 'survivor.example.test']);
        $tomb = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $hostname, 'name' => $hostname]);
        $tomb->merged_into_asset_id = $survivor->id;
        $tomb->save();
        $tomb->delete();

        // Keep the survivor out of the agent's way: link it to some other agent.
        $other = TacticalAsset::create(['agent_id' => 'AGENT-SURV', 'hostname' => 'survivor.example.test', 'asset_id' => $survivor->id, 'status' => 'online', 'synced_at' => now()]);
        $survivor->update(['tactical_asset_id' => $other->id]);

        return [$tomb, $survivor];
    }

    // ── Leg 1: collect ──

    public function test_a_retired_conflict_is_named_with_both_hostnames_and_restorability(): void
    {
        $client = $this->mappedClient();
        // Matched on NAME, so the asset's own hostname differs from the agent's.
        $retired = $this->retiredAsset($client, 'old-pc-07.example.test', ['name' => 'pc-07.example.test']);

        $result = $this->sync([$this->agent('AGENT-7', 'PC-07.example.test')]);

        $this->assertSame(1, $result->details['assets_skipped'] ?? 0);
        $this->assertSame(1, $result->details['assets_skipped_reasons']['soft_deleted_conflict'] ?? 0);
        $this->assertSame([[
            'asset_id' => $retired->id,
            'agent_hostname' => 'PC-07.example.test',
            'asset_hostname' => 'old-pc-07.example.test',
            'restorable' => true,
            'merged_into_asset_id' => null,
        ]], $result->details['assets_skipped_retired'] ?? null);
    }

    public function test_a_merge_tombstone_is_named_as_not_restorable_with_its_survivor(): void
    {
        $client = $this->mappedClient();
        [$tomb, $survivor] = $this->mergeTombstone($client, 'pc-08.example.test');

        $result = $this->sync([$this->agent('AGENT-8', 'pc-08.example.test')]);

        $rows = $result->details['assets_skipped_retired'] ?? [];
        $this->assertCount(1, $rows);
        $this->assertSame($tomb->id, $rows[0]['asset_id']);
        $this->assertFalse($rows[0]['restorable']);
        $this->assertSame($survivor->id, $rows[0]['merged_into_asset_id']);
    }

    public function test_the_retired_list_is_capped_while_the_counter_stays_the_total(): void
    {
        $client = $this->mappedClient();
        $n = TacticalDeviceSyncService::RETIRED_SKIP_LIST_LIMIT + 5;
        $agents = [];
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $host = sprintf('ws-%02d.example.test', $i);
            $ids[] = $this->retiredAsset($client, $host)->id;
            $agents[] = $this->agent(sprintf('AGENT-%02d', $i), $host);
        }

        $result = $this->sync($agents);

        $this->assertSame(20, TacticalDeviceSyncService::RETIRED_SKIP_LIST_LIMIT);
        $this->assertSame($n, $result->details['assets_skipped_reasons']['soft_deleted_conflict'] ?? 0);
        $this->assertSame($n, $result->details['assets_skipped'] ?? 0);
        $rows = $result->details['assets_skipped_retired'] ?? [];
        $this->assertCount(20, $rows);
        $this->assertSame(array_slice($ids, 0, 20), array_column($rows, 'asset_id'), 'first 20 skips, in skip order');
    }

    public function test_negative_control_no_retired_conflict_adds_no_list(): void
    {
        $client = $this->mappedClient();
        // A LIVE hostname conflict (reinstall case) and a clean create.
        $live = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'pc-09.example.test', 'name' => 'pc-09.example.test']);
        $old = TacticalAsset::create(['agent_id' => 'AGENT-OLD', 'hostname' => 'pc-09.example.test', 'asset_id' => $live->id, 'status' => 'offline', 'synced_at' => now()->subDay()]);
        $live->update(['tactical_asset_id' => $old->id]);

        $result = $this->sync([$this->agent('AGENT-9', 'pc-09.example.test'), $this->agent('AGENT-10', 'pc-10.example.test')]);

        $this->assertSame(1, $result->details['assets_skipped_reasons']['hostname_conflict'] ?? 0);
        $this->assertSame(1, $result->details['assets_created'] ?? 0);
        $this->assertArrayNotHasKey('assets_skipped_retired', $result->details);
    }

    // ── Leg 2a: Integrations banner ──

    public function test_the_sync_banner_names_each_retired_asset_and_its_restorability(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $restorable = $this->retiredAsset($client, 'old-pc-07.example.test', ['name' => 'pc-07.example.test']);
        [$tomb, $survivor] = $this->mergeTombstone($client, 'pc-08.example.test');
        $this->bindAgents([$this->agent('AGENT-7', 'pc-07.example.test'), $this->agent('AGENT-8', 'pc-08.example.test')]);

        $this->actingAs(User::factory()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.tactical.sync-devices'))
            ->assertRedirect(route('settings.integrations'));

        $warning = (string) session('warning');
        $this->assertStringContainsString(
            '2 of them matched a retired (soft-deleted) asset: '
            ."asset #{$restorable->id} (pc-07.example.test, asset hostname old-pc-07.example.test) — restorable from its asset page; "
            ."asset #{$tomb->id} (pc-08.example.test) — merged into asset #{$survivor->id}, cannot be restored.",
            $warning,
        );
        $this->assertStringNotContainsString('more not listed', $warning);
    }

    public function test_the_sync_banner_reports_retired_rows_beyond_the_cap_as_not_listed(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $agents = [];
        for ($i = 1; $i <= 22; $i++) {
            $host = sprintf('ws-%02d.example.test', $i);
            $this->retiredAsset($client, $host);
            $agents[] = $this->agent(sprintf('AGENT-%02d', $i), $host);
        }
        $this->bindAgents($agents);

        $this->actingAs(User::factory()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.tactical.sync-devices'));

        $warning = (string) session('warning');
        $this->assertStringContainsString('22 of them matched a retired (soft-deleted) asset: ', $warning);
        $this->assertSame(20, substr_count($warning, 'restorable from its asset page'));
        $this->assertStringContainsString('; +2 more not listed.', $warning);
    }

    public function test_negative_control_banner_has_no_retired_note_without_a_retired_conflict(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $live = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'pc-09.example.test', 'name' => 'pc-09.example.test']);
        $old = TacticalAsset::create(['agent_id' => 'AGENT-OLD', 'hostname' => 'pc-09.example.test', 'asset_id' => $live->id, 'status' => 'offline', 'synced_at' => now()->subDay()]);
        $live->update(['tactical_asset_id' => $old->id]);
        $this->bindAgents([$this->agent('AGENT-9', 'pc-09.example.test')]);

        $this->actingAs(User::factory()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.tactical.sync-devices'));

        $warning = (string) session('warning');
        $this->assertStringContainsString('1 device could not be linked to an asset', $warning, 'positive control: the skip banner fired');
        $this->assertStringNotContainsString('retired', $warning);
    }

    // ── Leg 2b: artisan command ──

    public function test_the_command_prints_each_retired_asset(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $restorable = $this->retiredAsset($client, 'old-pc-07.example.test', ['name' => 'pc-07.example.test']);
        [$tomb, $survivor] = $this->mergeTombstone($client, 'pc-08.example.test');
        $this->bindAgents([$this->agent('AGENT-7', 'pc-07.example.test'), $this->agent('AGENT-8', 'pc-08.example.test')]);

        $this->artisan('tactical:sync-devices')
            ->expectsOutput('Assets skipped: 2')
            ->expectsOutput('  - soft_deleted_conflict: 2')
            ->expectsOutput('Retired (soft-deleted) assets blocking a create:')
            ->expectsOutput("  - asset #{$restorable->id} (pc-07.example.test, asset hostname old-pc-07.example.test) — restorable from its asset page")
            ->expectsOutput("  - asset #{$tomb->id} (pc-08.example.test) — merged into asset #{$survivor->id}, cannot be restored")
            ->doesntExpectOutputToContain('more not listed')
            ->assertExitCode(0);
    }

    public function test_the_command_reports_retired_rows_beyond_the_cap(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $agents = [];
        for ($i = 1; $i <= 21; $i++) {
            $host = sprintf('ws-%02d.example.test', $i);
            $this->retiredAsset($client, $host);
            $agents[] = $this->agent(sprintf('AGENT-%02d', $i), $host);
        }
        $this->bindAgents($agents);

        $this->artisan('tactical:sync-devices')
            ->expectsOutput('  - soft_deleted_conflict: 21')
            ->expectsOutput('  - +1 more not listed')
            ->assertExitCode(0);
    }

    public function test_negative_control_command_prints_no_retired_block_without_a_retired_conflict(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $live = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'pc-09.example.test', 'name' => 'pc-09.example.test']);
        $old = TacticalAsset::create(['agent_id' => 'AGENT-OLD', 'hostname' => 'pc-09.example.test', 'asset_id' => $live->id, 'status' => 'offline', 'synced_at' => now()->subDay()]);
        $live->update(['tactical_asset_id' => $old->id]);
        $this->bindAgents([$this->agent('AGENT-9', 'pc-09.example.test')]);

        $this->artisan('tactical:sync-devices')
            ->expectsOutput('  - hostname_conflict: 1')
            ->doesntExpectOutputToContain('Retired')
            ->assertExitCode(0);
    }

    // ── Leg 3: MCP tactical_sync_devices_now ──

    /** @return array<string, mixed> */
    private function callSyncTool(Client $client): array
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_sync_devices_now'], label: 'opsbot');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'tactical_sync_devices_now', 'arguments' => [
                    'client_id' => $client->id,
                    'reason' => 'Refresh local Tactical device inventory for this PSA client.',
                ]],
            ]);

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    public function test_the_mcp_sync_return_carries_skip_counts_reasons_and_the_retired_list(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $retired = $this->retiredAsset($client, 'pc-07.example.test');
        $this->bindAgents([$this->agent('AGENT-7', 'pc-07.example.test')]);

        $out = $this->callSyncTool($client);

        $this->assertTrue($out['success'] ?? false, json_encode($out));
        $this->assertSame('1 created', $out['summary'], 'existing keys kept');
        $this->assertSame(1, $out['created']);
        $this->assertSame(1, $out['assets_skipped']);
        $this->assertSame(['soft_deleted_conflict' => 1], $out['assets_skipped_reasons']);
        $this->assertSame([[
            'asset_id' => $retired->id,
            'agent_hostname' => 'pc-07.example.test',
            'asset_hostname' => 'pc-07.example.test',
            'restorable' => true,
            'merged_into_asset_id' => null,
        ]], $out['assets_skipped_retired']);
    }

    public function test_negative_control_mcp_return_has_empty_skip_fields_on_a_clean_sync(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();
        $this->bindAgents([$this->agent('AGENT-10', 'pc-10.example.test')]);

        $out = $this->callSyncTool($client);

        $this->assertTrue($out['success'] ?? false, json_encode($out));
        $this->assertSame(1, Asset::where('hostname', 'pc-10.example.test')->count(), 'positive control: the sync really ran');
        $this->assertSame(0, $out['assets_skipped']);
        $this->assertSame([], $out['assets_skipped_reasons']);
        $this->assertSame([], $out['assets_skipped_retired']);
    }

    public function test_the_mcp_error_return_also_carries_the_skip_fields(): void
    {
        $this->enableTactical();
        $client = $this->mappedClient();

        $syncResult = new \App\Services\SyncResult;
        $syncResult->recordError('Upsert failed for agent AGENT-X');
        $syncResult->details = [
            'assets_skipped' => 1,
            'assets_skipped_reasons' => ['soft_deleted_conflict' => 1],
            'assets_skipped_retired' => [[
                'asset_id' => 41, 'agent_hostname' => 'pc-41.example.test', 'asset_hostname' => 'pc-41.example.test',
                'restorable' => true, 'merged_into_asset_id' => null,
            ]],
        ];
        $deviceSync = \Mockery::mock(TacticalDeviceSyncService::class);
        $deviceSync->shouldReceive('syncDevices')->once()->with($client->id)->andReturn($syncResult);
        $this->app->instance(TacticalDeviceSyncService::class, $deviceSync);

        $out = $this->callSyncTool($client);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame(['Upsert failed for agent AGENT-X'], $out['errors'], 'existing keys kept');
        $this->assertSame(1, $out['assets_skipped']);
        $this->assertSame(['soft_deleted_conflict' => 1], $out['assets_skipped_reasons']);
        $this->assertSame(41, $out['assets_skipped_retired'][0]['asset_id'] ?? null);
    }
}
