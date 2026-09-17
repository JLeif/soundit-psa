<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * assets.last_boot_at for Tactical-synced devices.
 *
 * The defect: no Tactical path ever wrote last_boot_at, so the column kept
 * whatever the original import left and read as a months-old uptime for the
 * whole Tactical-only fleet — while AssetHealthService::patchFactor() scored it
 * as "up {N}d (patches may be pending)". The agent DETAIL payload carries
 * boot_time (epoch seconds) and is the only Tactical payload that does.
 *
 * These cases pin the write AND its limits: it fills in and moves forward, it
 * never drags the column backwards over another integration (Ninja and Level
 * write the same column), and no observation is never a blanking.
 */
class TacticalBootTimeRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function syncService(array $queue): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function linkedAsset(array $assetOverrides = []): Asset
    {
        $asset = Asset::factory()->create(array_merge(['hostname' => 'BOX-1'], $assetOverrides));

        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'offline',
            'synced_at' => now()->subDay(),
        ]);

        return $asset->refresh();
    }

    /** @param array<string, mixed> $overrides */
    private function agentDetail(array $overrides = []): string
    {
        return json_encode(array_merge([
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro',
            'last_seen' => '2026-09-17 01:00:00',
        ], $overrides));
    }

    /**
     * The headline case: a stale June value on a device that rebooted hours ago.
     * This is the exact production fingerprint — 175 Tactical-only assets sitting
     * on a 2026-06-24 ceiling while the agent reports current uptime.
     */
    public function test_detail_sync_moves_a_stale_boot_time_forward(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => Carbon::parse('2026-06-24 19:47:00')]);
        $bootedAt = Carbon::parse('2026-09-17 04:00:00');

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootedAt->timestamp])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertSame(
            $bootedAt->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'the observed boot time should replace the stale import value',
        );
    }

    /** An empty column is filled in — the 6 Tactical-only assets that never got a value. */
    public function test_detail_sync_fills_an_empty_boot_time(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);
        $bootedAt = Carbon::parse('2026-09-16 08:30:00');

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootedAt->timestamp])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $bootedAt->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * The adoption guard. linkOrCreateAsset ADOPTS assets that Ninja or Level may
     * also maintain, and both write last_boot_at. An older Tactical observation
     * must not drag a newer one backwards and resurrect the stale-uptime bug in
     * the other direction.
     */
    public function test_an_older_observation_never_drags_the_column_backwards(): void
    {
        $newer = Carbon::parse('2026-09-17 06:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $newer]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => Carbon::parse('2026-09-10 06:00:00')->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $newer->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'a stale Tactical read must not overwrite a newer value from another integration',
        );
    }

    /** No observation is not a blanking: a payload without boot_time leaves the column alone. */
    public function test_a_payload_without_boot_time_leaves_the_column_untouched(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail()),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /** A zero/unparseable boot_time is the same non-observation, not epoch 1970. */
    public function test_a_zero_boot_time_is_not_an_observation(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => 0])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'a zero boot_time must not write 1970 and read as a 56-year uptime',
        );
    }

    /**
     * The same non-observation, but on an EMPTY column, where the
     * never-drag-backwards guard cannot mask it. This is the case that matters in
     * production: the Tactical-only assets whose last_boot_at is NULL. Treating an
     * absent boot_time as epoch 0 would stamp 1970 and read as a 56-year uptime —
     * a far worse number than the empty cell it replaced.
     */
    public function test_a_zero_boot_time_on_an_empty_column_writes_nothing(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => 0])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertNull(
            $asset->refresh()->last_boot_at,
            'a zero boot_time must leave the column empty, not stamp 1970',
        );
    }

    /** Likewise for a payload with no boot_time key at all and an empty column. */
    public function test_an_absent_boot_time_on_an_empty_column_writes_nothing(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail()),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertNull($asset->refresh()->last_boot_at);
    }

    /** A clock-skewed agent must not park the column ahead of every real observation. */
    public function test_a_future_boot_time_is_refused(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => now()->addDays(3)->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * The point of the fix, stated as the operator sees it: the health factor stops
     * claiming patches may be pending once the real reboot time lands. This asserts
     * the downstream CONSEQUENCE, not the column.
     */
    public function test_a_refreshed_boot_time_clears_the_long_uptime_health_penalty(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => Carbon::parse('2026-06-24 19:47:00')]);

        $stale = (new \App\Services\AssetHealthService)->compute($asset->refresh())->factors;
        $stalePatch = collect($stale)->firstWhere('key', 'patch');
        $this->assertNotNull($stalePatch);
        $this->assertSame('warn', $stalePatch['status']);
        $this->assertStringContainsString('patches may be pending', $stalePatch['detail']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => now()->subHours(3)->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $fresh = (new \App\Services\AssetHealthService)->compute($asset->refresh())->factors;
        $freshPatch = collect($fresh)->firstWhere('key', 'patch');
        $this->assertNotNull($freshPatch);
        $this->assertSame('ok', $freshPatch['status']);
        $this->assertSame(0, $freshPatch['points']);
    }

    /** An unlinked snapshot has no asset to write; the sync must not fail over it. */
    public function test_an_unlinked_agent_snapshot_is_handled_without_error(): void
    {
        $ta = TacticalAsset::create([
            'asset_id' => null,
            'agent_id' => 'AGENT-2',
            'hostname' => 'BOX-2',
            'status' => 'offline',
            'synced_at' => now()->subDay(),
        ]);

        $asset = Asset::factory()->create(['hostname' => 'BOX-3']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-3',
            'hostname' => 'BOX-3',
            'status' => 'offline',
            'synced_at' => now()->subDay(),
        ]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => now()->subHour()->timestamp])),
        ]);

        $result = $service->syncDeviceDetail($asset->refresh());

        $this->assertTrue($result->ok);
        $this->assertNull($ta->refresh()->asset_id);
    }
}
