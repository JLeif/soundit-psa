<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Services\Tactical\TacticalMacosCheckProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-0pb9m — provisioning the shipped macOS disk-capacity check.
 *
 * The curated darwin-native script (resources/tactical/checks/) restores
 * VERIFIED coverage on Macs: a check that genuinely runs, reports real state,
 * and can pass. Revise contract: PLAN-FIRST (ambiguity aborts before any
 * write), NO-CLOBBER (an existing same-name script is reused only on an exact
 * match; drift ALWAYS refuses — no overwrite switch, psa-0pb9m R2; duplicates
 * always refuse), SCOPE-SAFE (an ambiguous hostname refuses and lists
 * candidates), per-agent
 * idempotent, darwin-only, and the local script catalog is upserted on apply
 * so the client-boundary platform guard sees the script immediately.
 */
class TacticalMacosCheckProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function shippedBody(): string
    {
        return file_get_contents(resource_path('tactical/checks/psa-macos-disk-capacity-check.sh'));
    }

    /** The getScripts row shape for an exact, shipped-definition match. */
    private function matchingScriptRow(int $id = 900): array
    {
        return [
            'id' => $id,
            'name' => TacticalMacosCheckProvisioner::SCRIPT_NAME,
            'shell' => 'shell',
            'supported_platforms' => ['darwin'],
        ];
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
        $this->assertFalse($result['aborted']);
        $this->assertSame('create', $result['script_action']);

        $byHost = collect($result['targets'])->keyBy('hostname');
        $this->assertSame('create', $byHost['MAC-01']['action']);
        $this->assertSame('create', $byHost['MAC-02']['action']); // os-sniffed darwin
        $this->assertArrayNotHasKey('PC-01', $byHost->all());
        $this->assertArrayNotHasKey('BOX-X', $byHost->all());
    }

    public function test_apply_creates_script_and_missing_checks_and_upserts_the_local_catalog(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);

        // Script does not exist on the plan read; after create it resolves by name.
        $tactical->shouldReceive('getScripts')->twice()->with(true, true)->andReturn(
            [],
            [$this->matchingScriptRow(900)],
        );
        $tactical->shouldReceive('createScript')->once()->withArgs(function (array $body): bool {
            // The shipped script says what it gates on — disk capacity — and
            // never overclaims whole-device health (revise: no "HEALTHY").
            return $body['name'] === TacticalMacosCheckProvisioner::SCRIPT_NAME
                && $body['shell'] === 'shell'
                && $body['supported_platforms'] === ['darwin']
                && str_contains($body['script_body'], 'PASS: disk capacity within thresholds')
                && str_contains($body['script_body'], 'NOT-DARWIN')
                && str_contains($body['script_body'], 'PATH=/usr/bin:/bin:/usr/sbin:/sbin')
                && ! str_contains($body['script_body'], 'VERDICT: HEALTHY');
        })->andReturn('added');

        // MAC-01 already carries our check (script 900) — skipped. MAC-02 gets one.
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-1')->andReturn([
            ['check_type' => 'script', 'script' => 900, 'id' => 55],
        ]);
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-2')->andReturn([]);
        $tactical->shouldReceive('createCheck')->once()->withArgs(function (array $body, ?array $scriptMeta = null): bool {
            // The guarded client call carries the vendor-meta claim so the
            // platform gate assesses the just-created script correctly.
            return $body['agent'] === 'agent-mac-2'
                && $body['check_type'] === 'script'
                && $body['script'] === 900
                && $body['success_return_codes'] === [0]
                && $scriptMeta === ['shell' => 'shell', 'supported_platforms' => ['darwin']];
        })->andReturn('added');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertFalse($result['dry_run']);
        $this->assertFalse($result['aborted']);
        $this->assertSame('create', $result['script_action']);
        $this->assertSame(900, $result['script_id']);

        $byHost = collect($result['targets'])->keyBy('hostname');
        $this->assertSame('skip', $byHost['MAC-01']['action']);
        $this->assertSame('create', $byHost['MAC-02']['action']);
        $this->assertSame([], $result['errors']);

        // The local catalog mirrors the managed script immediately.
        $local = TacticalScript::where('tactical_script_id', 900)->first();
        $this->assertNotNull($local);
        $this->assertSame(TacticalMacosCheckProvisioner::SCRIPT_NAME, $local->name);
        $this->assertSame(['darwin'], $local->supported_platforms);
    }

    public function test_matching_existing_script_is_reused_unchanged_with_no_write(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([$this->matchingScriptRow(900)]);
        $tactical->shouldReceive('downloadScript')->once()->with(900, false)->andReturn([
            'filename' => 'PSA macOS Disk Capacity Check.sh',
            'code' => $this->shippedBody(),
        ]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('updateScript');

        // Both Macs already provisioned.
        $tactical->shouldReceive('getAgentChecks')->twice()->andReturn([
            ['check_type' => 'script', 'script' => 900],
        ]);
        $tactical->shouldNotReceive('createCheck');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertFalse($result['aborted']);
        $this->assertSame('unchanged', $result['script_action']);
        $this->assertSame(['skip', 'skip'], collect($result['targets'])->pluck('action')->all());
    }

    public function test_drifted_same_name_script_always_refuses_and_aborts(): void
    {
        // psa-0pb9m R2: there is NO overwrite switch. The script object is
        // global (an overwrite would rewrite every referencing check
        // fleet-wide) and a drifted body may be operator-owned — the refusal
        // names the out-of-band remedies (rename or edit/delete in Tactical).
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([$this->matchingScriptRow(900)]);
        // Operator-edited body: NOT the shipped definition.
        $tactical->shouldReceive('downloadScript')->once()->with(900, false)->andReturn([
            'filename' => 'x.sh',
            'code' => "#!/bin/bash\necho operator-owned\n",
        ]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('updateScript');
        $tactical->shouldNotReceive('createCheck');
        $tactical->shouldNotReceive('getAgentChecks');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertTrue($result['aborted']);
        $this->assertSame('drift-refused', $result['script_action']);
        $this->assertSame([], $result['targets']);
        $this->assertStringContainsString('never overwrites', $result['errors'][0]);
        $this->assertStringContainsString('RENAME', $result['errors'][0]);
        $this->assertStringNotContainsString('--update-script', $result['errors'][0], 'the overwrite switch is gone');
    }

    public function test_multiple_same_name_scripts_always_refuse_as_an_ownership_collision(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([
            $this->matchingScriptRow(900),
            $this->matchingScriptRow(901),
        ]);
        $tactical->shouldNotReceive('downloadScript');
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('updateScript');
        $tactical->shouldNotReceive('createCheck');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertTrue($result['aborted']);
        $this->assertSame('ambiguous', $result['script_action']);
        $this->assertStringContainsString('900, 901', $result['errors'][0]);
    }

    public function test_ambiguous_hostname_across_clients_refuses_and_lists_candidates(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        // A second client with the SAME hostname — the documented fan-out trap.
        $other = Client::factory()->create(['name' => 'Bravo', 'tactical_site_id' => 'Bravo|Main']);
        $twin = Asset::factory()->create(['client_id' => $other->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $twin->id, 'agent_id' => 'agent-mac-9', 'hostname' => 'MAC-01',
            'plat' => 'darwin', 'os' => 'Darwin 23.6.0 arm64', 'status' => 'online', 'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('createCheck');
        $tactical->shouldNotReceive('getAgentChecks');

        $result = $this->provisioner($tactical)->provision(apply: true, hostname: 'MAC-01');

        $this->assertTrue($result['aborted']);
        $this->assertSame([], $result['targets']);
        $this->assertStringContainsString('matches 2 Tactical agents', $result['errors'][0]);
        $this->assertStringContainsString('--agent-id', $result['errors'][0]);
    }

    public function test_agent_id_targets_exactly_one_agent(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-2')->andReturn([]);

        $result = $this->provisioner($tactical)->provision(apply: false, agentId: 'agent-mac-2');

        $this->assertFalse($result['aborted']);
        $this->assertSame(['MAC-02'], collect($result['targets'])->pluck('hostname')->all());
    }

    public function test_hostname_scope_refuses_non_darwin_before_any_write(): void
    {
        $this->configureTactical();
        $fixture = $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldReceive('getAgentChecks')->never();
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('createCheck');

        // A Windows host targeted by name is refused, not silently provisioned —
        // and the abort happens before the script would have been created.
        $result = $this->provisioner($tactical)->provision(
            apply: true,
            clientId: $fixture['client']->id,
            hostname: 'PC-01',
        );

        $this->assertTrue($result['aborted']);
        $this->assertSame([], $result['targets']);
        $this->assertStringContainsString('not a macOS agent', $result['errors'][0]);
    }

    public function test_empty_scope_writes_nothing_including_the_script(): void
    {
        $this->configureTactical();
        // No fleet at all: an apply run with no darwin targets must not
        // create the global script either (plan-first, zero fan-out).
        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('createCheck');

        $result = $this->provisioner($tactical)->provision(apply: true);

        $this->assertFalse($result['aborted']);
        $this->assertSame([], $result['targets']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['script_id']);
    }

    public function test_per_agent_check_read_failure_is_an_error_row_not_an_abort(): void
    {
        $this->configureTactical();
        $this->seedFleet();

        $tactical = Mockery::mock(\App\Services\Tactical\TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([$this->matchingScriptRow(900)]);
        $tactical->shouldReceive('downloadScript')->once()->with(900, false)->andReturn([
            'filename' => 'x.sh',
            'code' => $this->shippedBody(),
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
