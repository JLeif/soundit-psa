<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * psa-0pb9m — checks-coverage truth at the DELIVERED MCP boundary.
 *
 * The defect: every Mac carries exactly ONE Tactical check and it fails on all
 * of them, so Macs render as covered while nothing verifies them — and if the
 * broken check is deleted, "0 failing / 0 total" reads as CLEAN. These tests
 * pin the payloads Chet actually consumes (via /api/mcp/staff), mirroring the
 * landed psa-47vxh freshness-envelope idiom: an explicit machine-readable
 * coverage signal per device, an explanatory note per payload, and per-check
 * platform-mismatch annotation that says WHY a check can never pass.
 */
class TacticalChecksCoverageMcpTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** Seed one client with the coverage shapes. @return array{client: Client} */
    private function seedCoverageFleet(): array
    {
        $client = Client::factory()->create(['name' => 'Acme']);

        $shapes = [
            // The bead's exact case: a Mac with ONE check that always fails.
            ['hostname' => 'MAC-01', 'os' => 'Darwin 23.6.0 arm64', 'plat' => 'darwin', 'checks_total' => 1, 'checks_failing' => 1, 'checks_passing' => 0, 'synced_at' => now()],
            // The delete-the-broken-check trap: zero checks must NOT read clean.
            ['hostname' => 'MAC-02', 'os' => 'Darwin 23.6.0 arm64', 'plat' => 'darwin', 'checks_total' => 0, 'checks_failing' => 0, 'checks_passing' => 0, 'synced_at' => now()],
            // Healthy Windows box: explicit passing evidence.
            ['hostname' => 'PC-01', 'os' => 'Windows 11 Pro', 'plat' => 'windows', 'checks_total' => 8, 'checks_failing' => 1, 'checks_passing' => 7, 'synced_at' => now()],
            // Never-synced counts: unknown, never clean.
            ['hostname' => 'PC-02', 'os' => 'Windows 11 Pro', 'plat' => 'windows', 'checks_total' => null, 'checks_failing' => null, 'checks_passing' => null, 'synced_at' => now()],
            // Pre-upgrade snapshot (no passing count) AND stale: coverage
            // unknown — failing < total must never read verified (revise).
            ['hostname' => 'PC-03', 'os' => 'Windows 11 Pro', 'plat' => 'windows', 'checks_total' => 8, 'checks_failing' => 1, 'checks_passing' => null, 'synced_at' => now()->subDays(3)],
        ];

        foreach ($shapes as $i => $shape) {
            $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $shape['hostname']]);
            TacticalAsset::create([
                'asset_id' => $asset->id,
                'agent_id' => 'agent-'.($i + 1),
                'hostname' => $shape['hostname'],
                'os' => $shape['os'],
                'plat' => $shape['plat'],
                'status' => 'online',
                'checks_total' => $shape['checks_total'],
                'checks_failing' => $shape['checks_failing'],
                'checks_passing' => $shape['checks_passing'],
                'last_seen_at' => now(),
                'synced_at' => $shape['synced_at'],
            ]);
        }

        return ['client' => $client];
    }

    public function test_list_devices_carries_coverage_state_note_summary_and_freshness(): void
    {
        $this->configureTactical();
        ['client' => $client] = $this->seedCoverageFleet();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('getAgents');
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_list_devices']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_list_devices', [
            'client_id' => $client->id,
        ]));

        $this->assertSame(5, $payload['count']);

        $byHost = collect($payload['devices'])->keyBy('hostname');

        // The Mac with one always-failing check: UNVERIFIED, not covered.
        $mac1 = $byHost['MAC-01'];
        $this->assertSame('darwin', $mac1['platform']);
        $this->assertSame('unverified', $mac1['checks_coverage']);
        $this->assertStringContainsStringIgnoringCase('all checks failing', $mac1['checks_summary']);

        // Zero checks: UNMONITORED, never clean.
        $mac2 = $byHost['MAC-02'];
        $this->assertSame('none', $mac2['checks_coverage']);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $mac2['checks_summary']);

        // A residual checks_passing value on the row is NOT evidence (R2: the
        // column's only historical producer was the vendor aggregate that
        // counts never-reporting checks as passing) — the snapshot can never
        // read verified; only the live per-check read can.
        $pc1 = $byHost['PC-01'];
        $this->assertSame('unknown', $pc1['checks_coverage']);
        $this->assertNull($pc1['checks_passing'], 'summary-derived residue must not be echoed as evidence');
        $this->assertStringContainsStringIgnoringCase('passing count unavailable', $pc1['checks_summary']);

        // Unknown counts stay unknown (null summary), never default-clean.
        $pc2 = $byHost['PC-02'];
        $this->assertSame('unknown', $pc2['checks_coverage']);
        $this->assertNull($pc2['checks_summary']);

        // Pre-upgrade snapshot: failing < total with NO passing evidence is
        // UNKNOWN — never verified-by-subtraction (the revise finding).
        $pc3 = $byHost['PC-03'];
        $this->assertSame('unknown', $pc3['checks_coverage']);
        $this->assertStringContainsStringIgnoringCase('passing count unavailable', $pc3['checks_summary']);

        // Per-row freshness (psa-47vxh idiom): the 3-day-old snapshot row is
        // stale; fresh rows are not.
        $this->assertTrue($pc3['stale']);
        $this->assertFalse($mac1['stale']);

        // Payload-level envelope: note + per-state tallies (fleet scan
        // support) + the snapshot freshness envelope. The stale PC-03 row is
        // QUARANTINED into the 'stale' bucket — its last-known coverage does
        // not count as current (R2: stale data must not pass as a tally
        // entry) — and the envelope fails CLOSED: data_as_of is the OLDEST
        // row stamp and data_stale propagates any-stale, so PC-03 cannot hide
        // under its siblings' fresh stamps.
        $this->assertIsString($payload['coverage_note']);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $payload['coverage_note']);
        $this->assertSame(
            ['verified' => 0, 'unverified' => 1, 'none' => 1, 'unknown' => 2, 'stale' => 1],
            $payload['coverage_summary'],
        );
        $this->assertSame($pc3['synced_at'], $payload['data_as_of'], 'data_as_of is the OLDEST row stamp, never the newest');
        $this->assertTrue($payload['data_stale'], 'one stale row fails the whole envelope closed');
        $this->assertStringContainsStringIgnoringCase('snapshot', $payload['freshness_note']);
        $this->assertStringContainsStringIgnoringCase('oldest', $payload['freshness_note']);
    }

    public function test_list_devices_envelope_is_fresh_only_when_every_row_is_fresh(): void
    {
        $this->configureTactical();
        $client = Client::factory()->create(['name' => 'Acme']);
        foreach ([['PC-10', now()->subHours(2)], ['PC-11', now()->subHours(4)]] as [$host, $syncedAt]) {
            $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $host]);
            TacticalAsset::create([
                'asset_id' => $asset->id, 'agent_id' => 'agent-'.$host, 'hostname' => $host,
                'os' => 'Windows 11 Pro', 'plat' => 'windows', 'status' => 'online',
                'checks_total' => 2, 'checks_failing' => 0, 'synced_at' => $syncedAt,
            ]);
        }

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldNotReceive('getAgents');
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_list_devices']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_list_devices', [
            'client_id' => $client->id,
        ]));

        $this->assertFalse($payload['data_stale']);
        // Oldest of the two fresh rows — the freshest the WHOLE reading can claim.
        $this->assertSame(
            collect($payload['devices'])->firstWhere('hostname', 'PC-11')['synced_at'],
            $payload['data_as_of'],
        );
        $this->assertSame(0, $payload['coverage_summary']['stale']);
    }

    public function test_get_device_checks_envelope_flags_platform_mismatch_for_wrong_platform_script(): void
    {
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac',
            'hostname' => 'MAC-01',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'checks_total' => 1,
            'checks_failing' => 1,
            'synced_at' => now(),
        ]);

        // The attached script is Windows-only per the vendor's own metadata.
        TacticalScript::create([
            'tactical_script_id' => 501,
            'name' => 'Win Disk Cleanup Check',
            'shell' => 'powershell',
            'supported_platforms' => ['windows'],
            'hidden' => false,
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgentChecks')
            ->once()
            ->with('agent-mac')
            ->andReturn([
                [
                    'id' => 9001,
                    'check_type' => 'script',
                    'script' => 501,
                    'readable_desc' => 'Script check: Win Disk Cleanup Check',
                    'check_result' => ['status' => 'failing', 'retcode' => 127, 'stdout' => 'exec format error'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device_checks']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device_checks', [
            'client_id' => $client->id,
            'hostname' => 'MAC-01',
        ]));

        // Envelope, not a bare list: coverage + explicit counts + note travel
        // with the rows.
        $this->assertSame(1, $payload['count']);
        $this->assertSame('unverified', $payload['checks_coverage']);
        $this->assertSame(0, $payload['checks_passing']);
        $this->assertSame(1, $payload['checks_failing']);
        $this->assertFalse($payload['truncated']);
        $this->assertIsString($payload['coverage_note']);

        $check = $payload['checks'][0];
        $this->assertSame('script', $check['check_type']);
        $this->assertSame('failing', $check['status']);
        $this->assertSame(127, $check['retcode']);
        $this->assertTrue($check['platform_mismatch']);
        $this->assertStringContainsString('windows', $check['platform_mismatch_reason']);
    }

    public function test_never_reporting_checks_can_never_read_verified_or_all_passing(): void
    {
        // The product reviewer's exact repro at 276c20e: one check whose
        // result is unknown/never-reported mapped to {failing: 0, total: 1}
        // and became checks_coverage=verified with a clean "0 failing / 1
        // total" summary. Never again: no explicit pass, no coverage.
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-05']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac5',
            'hostname' => 'MAC-05',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgentChecks')
            ->once()
            ->andReturn([
                [
                    'check_type' => 'script',
                    'script' => 601,
                    'readable_desc' => 'Script check: assigned but pending',
                    'check_result' => ['status' => 'pending'],
                ],
                [
                    // The vendor's never-run shape: check_result is an EMPTY
                    // object (pinned in tests/Fixtures/tactical/api_schema.json).
                    'check_type' => 'script',
                    'script' => 602,
                    'readable_desc' => 'Script check: never ran',
                    'check_result' => [],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device_checks']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device_checks', [
            'client_id' => $client->id,
            'hostname' => 'MAC-05',
        ]));

        $this->assertSame('unverified', $payload['checks_coverage']);
        $this->assertSame(0, $payload['checks_passing']);
        $this->assertSame(0, $payload['checks_failing']);
        $this->assertSame(2, $payload['checks_not_reporting']);
        $this->assertStringContainsStringIgnoringCase('no check currently passing', $payload['checks_summary']);

        // The per-check "checked-at" stamp: a never-reporting check has no
        // last_run — visibly absent, not silently omitted.
        $this->assertNull($payload['checks'][0]['last_run']);
        $this->assertNull($payload['checks'][1]['last_run']);
    }

    public function test_get_device_checks_compatible_script_is_not_flagged_and_mix_is_verified(): void
    {
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-03']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac3',
            'hostname' => 'MAC-03',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        TacticalScript::create([
            'tactical_script_id' => 601,
            'name' => 'PSA macOS Disk Capacity Check',
            'shell' => 'shell',
            'supported_platforms' => ['darwin'],
            'hidden' => false,
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgentChecks')
            ->once()
            ->andReturn([
                [
                    'check_type' => 'script',
                    'script' => 601,
                    'readable_desc' => 'Script check: PSA macOS Disk Capacity Check',
                    'check_result' => ['status' => 'passing', 'retcode' => 0, 'stdout' => 'PASS: disk capacity within thresholds', 'last_run' => '2026-07-26T00:10:00Z'],
                ],
                [
                    'check_type' => 'script',
                    'script' => 601,
                    'readable_desc' => 'Script check: PSA macOS Disk Capacity Check (staging)',
                    'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'FAIL: disk capacity - data volume 95% used', 'last_run' => '2026-07-26T00:11:00Z'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device_checks']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device_checks', [
            'client_id' => $client->id,
            'hostname' => 'MAC-03',
        ]));

        // One EXPLICITLY passing check → coverage verified even with a
        // sibling failing.
        $this->assertSame('verified', $payload['checks_coverage']);
        $this->assertSame(1, $payload['checks_passing']);

        foreach ($payload['checks'] as $check) {
            $this->assertFalse($check['platform_mismatch']);
            $this->assertNull($check['platform_mismatch_reason']);
        }

        // The per-check checked-at stamp passes through.
        $this->assertSame('2026-07-26T00:10:00Z', $payload['checks'][0]['last_run']);
    }

    public function test_endpoint_insight_carries_coverage_at_the_boundary(): void
    {
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac',
            'hostname' => 'MAC-01',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'checks_total' => 1,
            'checks_failing' => 1,
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->andReturn([
                'status' => 'online',
                'plat' => 'darwin',
                'maintenance_mode' => false,
                'logged_in_username' => 'None',
                'needs_reboot' => false,
                'disks' => [],
            ]);
        $tactical->shouldReceive('getAgentChecks')
            ->once()
            ->andReturn([
                [
                    'check_type' => 'script',
                    'script' => 777,
                    'readable_desc' => 'Script check: legacy check',
                    'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'boom'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_endpoint_insight']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_endpoint_insight', [
            'client_id' => $client->id,
            'hostname' => 'MAC-01',
        ]));

        $insight = $payload['insight'];
        $this->assertSame(1, $insight['checks_failing']);
        $this->assertSame(1, $insight['checks_total']);
        $this->assertSame(0, $insight['checks_passing']);
        $this->assertSame('unverified', $insight['checks_coverage']);
        $this->assertIsString($insight['coverage_note']);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $insight['coverage_note']);

        // The CHECKS signal carries its own freshness pair (psa-47vxh idiom):
        // this was a live read, so it is fresh — but the fields exist so a
        // mixed read can never hide a stale checks snapshot behind a fresh
        // overall stamp.
        $this->assertSame('live', $insight['checks_state']);
        $this->assertNotNull($insight['checks_as_of']);
        $this->assertFalse($insight['checks_stale']);

        // The device snapshot block carries the same truth.
        $this->assertSame('unverified', $payload['device']['checks_coverage']);
        $this->assertSame('darwin', $payload['device']['platform']);
    }

    public function test_live_get_device_carries_platform_and_coverage(): void
    {
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac',
            'hostname' => 'MAC-01',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->andReturn([
                'hostname' => 'MAC-01',
                'status' => 'online',
                'plat' => 'darwin',
                'operating_system' => 'Darwin 23.6.0 arm64',
                'checks' => ['total' => 1, 'passing' => 0, 'failing' => 1, 'warning' => 0, 'info' => 0, 'has_failing_checks' => true],
                'logged_in_username' => 'None',
                'needs_reboot' => false,
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device', [
            'client_id' => $client->id,
            'hostname' => 'MAC-01',
        ]));

        $this->assertSame('darwin', $payload['platform']);
        $this->assertSame('unverified', $payload['checks_coverage']);
        $this->assertNull($payload['checks_passing'], 'the summary dict never yields passing evidence');
        $this->assertStringContainsStringIgnoringCase('all checks failing', $payload['checks_summary']);
    }

    public function test_the_vendors_never_run_passing_claim_is_unknown_on_the_live_dict_read(): void
    {
        // The producer's documented never-run shape (calculate_agent_checks,
        // agents/utils.py @ the pinned commit: a check with NO CheckResult row
        // counts as passing): {total: 1, passing: 1, failing: 0}. The vendor
        // CLAIMS a pass the check never produced — the dict read must classify
        // UNKNOWN, never verified (psa-0pb9m R2, all four lenses).
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-08']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac8',
            'hostname' => 'MAC-08',
            'os' => 'Darwin 23.6.0 arm64',
            'plat' => 'darwin',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->once()->andReturn([
            'hostname' => 'MAC-08',
            'status' => 'online',
            'plat' => 'darwin',
            'operating_system' => 'Darwin 23.6.0 arm64',
            'checks' => ['total' => 1, 'passing' => 1, 'failing' => 0, 'warning' => 0, 'info' => 0, 'has_failing_checks' => false],
            'logged_in_username' => 'None',
            'needs_reboot' => false,
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device', [
            'client_id' => $client->id,
            'hostname' => 'MAC-08',
        ]));

        $this->assertSame('unknown', $payload['checks_coverage']);
        $this->assertNull($payload['checks_passing']);
        $this->assertStringContainsStringIgnoringCase('passing count unavailable', $payload['checks_summary']);
    }

    public function test_the_vendors_never_run_passing_claim_cannot_render_verified_anywhere_after_sync(): void
    {
        // The product reviewer's required end-to-end regression (psa-0pb9m
        // R2): start from the vendor's documented never-run summary shape,
        // carry it through the REAL device sync, and prove the snapshot
        // consumers — the MCP list, the insight snapshot fallback, the triage
        // context fallback, and the asset card — cannot render verified or
        // all-passing.
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-09']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgents')->once()->andReturn([
            [
                'agent_id' => 'agent-mac9',
                'hostname' => 'MAC-09',
                'client_name' => 'Acme',
                'site_name' => 'Main',
                'plat' => 'darwin',
                'operating_system' => 'Darwin 23.6.0 arm64',
                'monitoring_type' => 'workstation',
                'status' => 'online',
                'last_seen' => now()->toIso8601String(),
                // The vendor's never-run shape: the ONE check has no result
                // row, and calculate_agent_checks counts it as passing.
                'checks' => ['total' => 1, 'passing' => 1, 'failing' => 0, 'warning' => 0, 'info' => 0, 'has_failing_checks' => false],
            ],
        ]);
        // Every LIVE per-device read fails — the surfaces below must answer
        // from the snapshot, where no passing evidence can exist.
        $tactical->shouldReceive('getAgent')->andThrow(new \App\Services\Tactical\TacticalClientException('unreachable'));
        $tactical->shouldReceive('getAgentChecks')->andThrow(new \App\Services\Tactical\TacticalClientException('unreachable'));
        $this->app->instance(TacticalClient::class, $tactical);

        app(\App\Services\Tactical\TacticalDeviceSyncService::class)->syncDevices();

        // The sync persisted NO passing evidence from the vendor claim.
        $row = \App\Models\TacticalAsset::where('agent_id', 'agent-mac9')->firstOrFail();
        $this->assertSame(1, $row->checks_total);
        $this->assertSame(0, $row->checks_failing);
        $this->assertNull($row->checks_passing, 'the vendor aggregate must never persist as passing evidence');

        // 1. The MCP fleet list: unknown, never verified/all-passing.
        $token = $this->token(['tactical_list_devices', 'tactical_get_endpoint_insight']);
        $list = $this->decodedResult($this->callTool($token, 'tactical_list_devices', ['client_id' => $client->id]));
        $this->assertSame('unknown', $list['devices'][0]['checks_coverage']);
        $this->assertNull($list['devices'][0]['checks_passing']);
        $this->assertSame(0, $list['coverage_summary']['verified']);

        // 2. Endpoint insight, snapshot fallback (live reads unreachable).
        $insight = $this->decodedResult($this->callTool($token, 'tactical_get_endpoint_insight', [
            'client_id' => $client->id,
            'hostname' => 'MAC-09',
        ]))['insight'];
        $this->assertSame('unknown', $insight['checks_coverage']);
        $this->assertNull($insight['checks_passing']);

        // 3. The triage context fallback speaks the same truth in prose: no
        // numeric passing claim, coverage stated unknown.
        $block = app(\App\Services\Tactical\TacticalContextProvider::class)->forAsset($asset->fresh());
        $this->assertNotNull($block);
        $this->assertDoesNotMatchRegularExpression('/\d+ passing/i', $block->text, 'the vendor claim must not surface as a counted pass');
        $this->assertStringContainsStringIgnoringCase('coverage unknown', $block->text);

        // 4. The asset card renders the unknown line, never an all-clear.
        $user = \App\Models\User::factory()->create();
        $page = $this->actingAs($user)->get(route('assets.show', $asset));
        $page->assertOk();
        $page->assertSeeText('health not verified');
        $page->assertDontSeeText('all passing');
    }

    public function test_get_device_dict_counts_warning_severity_failures_and_requires_passing_evidence(): void
    {
        // The vendor summary dict severity-splits status=failing into
        // failing/warning/info (pinned in the fixture): two warning-severity
        // failures used to read {failing: 0, total: 2} → "verified". They are
        // failures, and with passing=0 the coverage is unverified.
        $this->configureTactical();

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-07']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-pc7',
            'hostname' => 'PC-07',
            'os' => 'Windows 11 Pro',
            'plat' => 'windows',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')
            ->once()
            ->andReturn([
                'hostname' => 'PC-07',
                'status' => 'online',
                'plat' => 'windows',
                'operating_system' => 'Windows 11 Pro',
                'checks' => ['total' => 2, 'passing' => 0, 'failing' => 0, 'warning' => 2, 'info' => 0, 'has_failing_checks' => true],
                'logged_in_username' => 'None',
                'needs_reboot' => false,
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device', [
            'client_id' => $client->id,
            'hostname' => 'PC-07',
        ]));

        $this->assertSame(2, $payload['checks_failing']);
        $this->assertNull($payload['checks_passing'], 'the summary dict never yields passing evidence');
        $this->assertSame('unverified', $payload['checks_coverage'], 'all-failing still proves nothing passes');
        $this->assertStringContainsStringIgnoringCase('all checks failing', $payload['checks_summary']);
    }
}
