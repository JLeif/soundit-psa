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

    /** Seed one client with the four coverage shapes. @return array{client: Client} */
    private function seedCoverageFleet(): array
    {
        $client = Client::factory()->create(['name' => 'Acme']);

        $shapes = [
            // The bead's exact case: a Mac with ONE check that always fails.
            ['hostname' => 'MAC-01', 'os' => 'Darwin 23.6.0 arm64', 'plat' => 'darwin', 'checks_total' => 1, 'checks_failing' => 1],
            // The delete-the-broken-check trap: zero checks must NOT read clean.
            ['hostname' => 'MAC-02', 'os' => 'Darwin 23.6.0 arm64', 'plat' => 'darwin', 'checks_total' => 0, 'checks_failing' => 0],
            // Healthy Windows box: at least one check passing.
            ['hostname' => 'PC-01', 'os' => 'Windows 11 Pro', 'plat' => 'windows', 'checks_total' => 8, 'checks_failing' => 1],
            // Never-synced counts: unknown, never clean.
            ['hostname' => 'PC-02', 'os' => 'Windows 11 Pro', 'plat' => 'windows', 'checks_total' => null, 'checks_failing' => null],
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
                'last_seen_at' => now(),
                'synced_at' => now(),
            ]);
        }

        return ['client' => $client];
    }

    public function test_list_devices_carries_coverage_state_note_and_summary(): void
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

        $this->assertSame(4, $payload['count']);

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

        // Healthy device keeps the legacy summary wording.
        $pc1 = $byHost['PC-01'];
        $this->assertSame('verified', $pc1['checks_coverage']);
        $this->assertSame('1 failing / 8 total', $pc1['checks_summary']);

        // Unknown counts stay unknown (null summary), never default-clean.
        $pc2 = $byHost['PC-02'];
        $this->assertSame('unknown', $pc2['checks_coverage']);
        $this->assertNull($pc2['checks_summary']);

        // Payload-level envelope: note + per-state tallies (fleet scan support).
        $this->assertIsString($payload['coverage_note']);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $payload['coverage_note']);
        $this->assertSame(
            ['verified' => 1, 'unverified' => 1, 'none' => 1, 'unknown' => 1],
            $payload['coverage_summary'],
        );
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

        // Envelope, not a bare list: coverage + note travel with the rows.
        $this->assertSame(1, $payload['count']);
        $this->assertSame('unverified', $payload['checks_coverage']);
        $this->assertIsString($payload['coverage_note']);

        $check = $payload['checks'][0];
        $this->assertSame('script', $check['check_type']);
        $this->assertSame('failing', $check['status']);
        $this->assertSame(127, $check['retcode']);
        $this->assertTrue($check['platform_mismatch']);
        $this->assertStringContainsString('windows', $check['platform_mismatch_reason']);
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
            'name' => 'PSA macOS Health Check',
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
                    'readable_desc' => 'Script check: PSA macOS Health Check',
                    'check_result' => ['status' => 'passing', 'retcode' => 0, 'stdout' => 'HEALTHY: disk 42% used'],
                ],
                [
                    'check_type' => 'script',
                    'script' => 601,
                    'readable_desc' => 'Script check: PSA macOS Health Check (staging)',
                    'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'disk 95% used'],
                ],
            ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $token = $this->token(['tactical_get_device_checks']);
        $payload = $this->decodedResult($this->callTool($token, 'tactical_get_device_checks', [
            'client_id' => $client->id,
            'hostname' => 'MAC-03',
        ]));

        // One passing check → coverage verified even with a sibling failing.
        $this->assertSame('verified', $payload['checks_coverage']);

        foreach ($payload['checks'] as $check) {
            $this->assertFalse($check['platform_mismatch']);
            $this->assertNull($check['platform_mismatch_reason']);
        }
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
        $this->assertSame('unverified', $insight['checks_coverage']);
        $this->assertIsString($insight['coverage_note']);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $insight['coverage_note']);

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
        $this->assertStringContainsStringIgnoringCase('all checks failing', $payload['checks_summary']);
    }
}
