<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalMacosCheckProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-0pb9m — provisioning the shipped macOS health check.
 *
 * The curated darwin-native script (resources/tactical/checks/) restores
 * VERIFIED coverage on Macs: a check that genuinely runs, reports real state,
 * and can pass. The provisioner is idempotent and no-clobber: dry-run by
 * default (plan only, zero writes), script upsert by name, per-agent skip when
 * our check already exists, darwin agents only — never a wrong-platform
 * attach (that is the defect class this bead removes).
 */
class TacticalMacosCheckProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    /** @return array{client: Client} */
    private function seedFleet(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);

        $mac1 = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $mac1->id, 'agent_id' => 'agent-mac-1', 'hostname' => 'MAC-01',
            'plat' => 'darwin', 'os' => 'Darwin 23.6.0 arm64', 'status' => 'online', 'synced_at' => now(),
        ]);

        // Pre-plat sync row: platform derivable only from the os string.
        $mac2 = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-02']);
        TacticalAsset::create([
            'asset_id' => $mac2->id, 'agent_id' => 'agent-mac-2', 'hostname' => 'MAC-02',
            'plat' => null, 'os' => 'macOS 14.5 (Sonoma)', 'status' => 'online', 'synced_at' => now(),
        ]);

        $pc = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01']);
        TacticalAsset::create([
            'asset_id' => $pc->id, 'agent_id' => 'agent-pc-1', 'hostname' => 'PC-01',
            'plat' => 'windows', 'os' => 'Windows 11 Pro', 'status' => 'online', 'synced_at' => now(),
        ]);

        // Unknown platform: never targeted (no guessing).
        $mystery = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'BOX-X']);
        TacticalAsset::create([
            'asset_id' => $mystery->id, 'agent_id' => 'agent-x', 'hostname' => 'BOX-X',
            'plat' => null, 'os' => null, 'status' => 'online', 'synced_at' => now(),
        ]);

        return compact('client');
    }

    private function provisioner(MockInterface $client): TacticalMacosCheckProvisioner
    {
        return new TacticalMacosCheckProvisioner($client);
    }

    public function test_dry_run_plans_darwin_agents_only_and_makes_no_writes(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldReceive('getAgentChecks')->twice()->andReturn([]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('updateScript');
        $tactical->shouldNotReceive('createCheck');

        $result = $this->provisioner($tactical)->provision(apply: false);

        $this->assertTrue($result['dry_run']);
        $this->assertSame('create', $result['script_action']);

        $byHost = collect($result['targets'])->keyBy('hostname');
        $this->assertSame('create', $byHost['MAC-01']['action']);
        $this->assertSame('create', $byHost['MAC-02']['action']); // os-sniffed darwin
        $this->assertArrayNotHasKey('PC-01', $byHost->all());
        $this->assertArrayNotHasKey('BOX-X', $byHost->all());
    }

    public function test_apply_upserts_script_and_creates_missing_checks_idempotently(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);

        // Script does not exist on the first list; after create it resolves by name.
        $tactical->shouldReceive('getScripts')->twice()->with(true, true)->andReturn(
            [],
            [[
                'id' => 900,
                'name' => TacticalMacosCheckProvisioner::SCRIPT_NAME,
                'shell' => 'shell',
                'supported_platforms' => ['darwin'],
            ]],
        );
        $tactical->shouldReceive('createScript')->once()->withArgs(function (array $body): bool {
            return $body['name'] === TacticalMacosCheckProvisioner::SCRIPT_NAME
                && $body['shell'] === 'shell'
                && $body['supported_platforms'] === ['darwin']
                && str_contains($body['script_body'], 'VERDICT: HEALTHY');
        })->andReturn('added');

        // MAC-01 already carries our check (script 900) — skipped. MAC-02 gets one.
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-1')->andReturn([
            ['check_type' => 'script', 'script' => 900, 'id' => 55],
        ]);
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-2')->andReturn([]);
        $tactical->shouldReceive('createCheck')->once()->withArgs(function (array $body): bool {
            return $body['agent'] === 'agent-mac-2'
                && $body['check_type'] === 'script'
                && $body['script'] === 900
                && $body['success_return_codes'] === [0];
        })->andReturn('added');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertFalse($result['dry_run']);
        $this->assertSame('create', $result['script_action']);
        $this->assertSame(900, $result['script_id']);

        $byHost = collect($result['targets'])->keyBy('hostname');
        $this->assertSame('skip', $byHost['MAC-01']['action']);
        $this->assertSame('create', $byHost['MAC-02']['action']);
        $this->assertSame([], $result['errors']);
    }

    public function test_apply_updates_existing_script_in_place(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([
            ['id' => 900, 'name' => TacticalMacosCheckProvisioner::SCRIPT_NAME, 'shell' => 'shell'],
        ]);
        $tactical->shouldReceive('updateScript')->once()->withArgs(function (int $id, array $body): bool {
            return $id === 900 && str_contains($body['script_body'], 'VERDICT: HEALTHY');
        })->andReturn('ok');
        $tactical->shouldNotReceive('createScript');

        // Both Macs already provisioned.
        $tactical->shouldReceive('getAgentChecks')->twice()->andReturn([
            ['check_type' => 'script', 'script' => 900],
        ]);
        $tactical->shouldNotReceive('createCheck');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertSame('update', $result['script_action']);
        $this->assertSame(['skip', 'skip'], collect($result['targets'])->pluck('action')->all());
    }

    public function test_hostname_scope_targets_one_mac_and_refuses_non_darwin(): void
    {
        $this->configureTactical();
        $fixture = $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldReceive('getAgentChecks')->never();
        $tactical->shouldNotReceive('createCheck');

        // A Windows host targeted by name is refused, not silently provisioned.
        $result = $this->provisioner($tactical)->provision(
            apply: false,
            clientId: $fixture['client']->id,
            hostname: 'PC-01',
        );

        $this->assertSame([], $result['targets']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('not a macOS agent', $result['errors'][0]);
    }

    public function test_per_agent_check_read_failure_is_an_error_row_not_an_abort(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([
            ['id' => 900, 'name' => TacticalMacosCheckProvisioner::SCRIPT_NAME, 'shell' => 'shell'],
        ]);
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-1')
            ->andThrow(new \App\Services\Tactical\TacticalClientException('boom'));
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-2')->andReturn([]);

        $result = $this->provisioner($tactical)->provision(apply: false);

        $byHost = collect($result['targets'])->keyBy('hostname');
        $this->assertSame('create', $byHost['MAC-02']['action']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('MAC-01', $result['errors'][0]);
    }
}
