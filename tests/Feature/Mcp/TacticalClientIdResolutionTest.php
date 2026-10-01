<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Card 6abdcac2 (Huntress pattern, #4486): Tactical read tools take client_id (and
 * asset_id for devices) and resolve through the stored integration keys, which are
 * clients.tactical_site_id, the agent's synced client_name|site_name and
 * assets.tactical_asset_id. Hostname is a fallback for unlinked rows only. Unmapped,
 * cross-client, ambiguous and unknown-envelope reads fail closed.
 *
 * Every name, host and site below is synthetic.
 */
class TacticalClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_TOOLS = [
        'tactical_get_device',
        'tactical_get_device_checks',
        'tactical_get_device_network',
        'tactical_get_device_software',
        'tactical_get_device_services',
        'tactical_get_device_disks',
        'tactical_get_device_patches',
        'tactical_get_device_tasks',
        'tactical_get_endpoint_insight',
        'tactical_diagnose_device',
    ];

    /** @var list<string> agent ids the fake was asked for */
    private array $agentReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function fakeTactical(array $patches = [], array $tasks = []): void
    {
        $this->agentReads = [];
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getAgent')->andReturnUsing(function (string $id) {
            $this->agentReads[] = $id;

            return ['agent_id' => $id, 'hostname' => 'Test-MBP.lan', 'status' => 'online'];
        });
        $tactical->shouldReceive('getPatches')->andReturnUsing(function (string $id) use ($patches) {
            $this->agentReads[] = $id;

            return $patches;
        });
        $tactical->shouldReceive('getAgentTasks')->andReturnUsing(function (string $id) use ($tasks) {
            $this->agentReads[] = $id;

            return $tasks;
        });
        $tactical->shouldReceive('getAgentChecks')->andReturnUsing(function (string $id) {
            $this->agentReads[] = $id;

            return [];
        });
        $tactical->shouldReceive('getSoftware')->andReturnUsing(function (string $id) {
            $this->agentReads[] = $id;

            return [];
        });
        $tactical->shouldNotReceive('getAgents');
        $this->app->instance(TacticalClient::class, $tactical);
    }

    private function toolset(): TacticalReadOnlyToolset
    {
        return app(TacticalReadOnlyToolset::class);
    }

    private function mappedClient(string $name, string $site = 'HQ'): Client
    {
        return Client::factory()->create(['name' => $name, 'tactical_site_id' => $name.'|'.$site]);
    }

    /** A device as the sync leaves it: agent on $siteKey, linked both ways. */
    private function linkedDevice(Client $client, string $hostname, string $agentId, ?string $siteKey = null): Asset
    {
        $siteKey ??= (string) $client->tactical_site_id;
        [$clientName, $siteName] = explode('|', $siteKey, 2);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $hostname]);
        $agent = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => $hostname,
            'client_name' => $clientName,
            'site_name' => $siteName,
            'synced_at' => now(),
        ]);
        $asset->update(['tactical_asset_id' => $agent->id]);

        return $asset->fresh();
    }

    private function callMcp(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [$name], label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    // ── mapped client resolves through the key ──────────────────────────────

    public function test_mapped_client_resolves_a_device_by_asset_id_through_the_stored_link(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-alpha');
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['asset_id' => $asset->id], $alpha->id);

        $this->assertArrayNotHasKey('error', $out, json_encode($out));
        $this->assertSame(['agent-alpha'], $this->agentReads);
    }

    public function test_hostname_resolves_through_the_assets_stored_link_not_a_stale_sibling_row(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP.lan']);
        // A stale agent row still pointing at the asset, created FIRST so a bare
        // ->first() on hostname picks it (measured at base). The asset's
        // tactical_asset_id names the current agent, and that key wins.
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-stale', 'hostname' => 'Test-MBP.lan',
            'client_name' => 'Alpha', 'site_name' => 'HQ',
        ]);
        $current = TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-current', 'hostname' => 'Test-MBP.lan',
            'client_name' => 'Alpha', 'site_name' => 'HQ', 'synced_at' => now(),
        ]);
        $asset->update(['tactical_asset_id' => $current->id]);
        $this->fakeTactical();

        foreach (self::DEVICE_TOOLS as $tool) {
            $this->agentReads = [];
            $out = $this->toolset()->execute($tool, ['hostname' => 'test-mbp.lan'], $alpha->id);
            $this->assertArrayNotHasKey('error', $out, $tool.': '.json_encode($out));
            $this->assertNotContains('agent-stale', $this->agentReads, $tool);
        }
    }

    public function test_mapped_client_mapping_is_returned_by_list_clients_sites(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $this->mappedClient('Bravo');

        $one = $this->toolset()->execute('tactical_list_clients_sites', [], $alpha->id);
        $this->assertSame(1, $one['count']);
        $this->assertSame('Alpha|HQ', $one['mappings'][0]['tactical_site_id']);
        $this->assertSame('psa_client_mapping', $one['match']);

        $all = $this->toolset()->execute('tactical_list_clients_sites', [], null);
        $this->assertSame(2, $all['count']);
    }

    // ── unmapped client fails closed ─────────────────────────────────────────

    public function test_unmapped_client_fails_closed_on_every_device_read_and_the_list(): void
    {
        $unmapped = Client::factory()->create(['name' => 'Unmapped', 'tactical_site_id' => null]);
        // Leftover snapshot rows for this client must not answer for it.
        $asset = Asset::factory()->create(['client_id' => $unmapped->id, 'hostname' => 'Test-MBP.lan']);
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'agent-leftover', 'hostname' => 'Test-MBP.lan']);
        $this->fakeTactical();

        foreach ([...self::DEVICE_TOOLS, 'tactical_list_devices', 'tactical_list_clients_sites'] as $tool) {
            $out = $this->toolset()->execute($tool, ['hostname' => 'Test-MBP.lan'], $unmapped->id);
            $this->assertArrayHasKey('error', $out, $tool);
            $this->assertStringContainsString('not mapped to Tactical', $out['error'], $tool);
        }
        $this->assertSame([], $this->agentReads);
    }

    public function test_a_site_shared_by_two_clients_is_ambiguous_and_fails_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        Client::factory()->create(['name' => 'Alpha Dup', 'tactical_site_id' => 'Alpha|HQ']);
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-alpha');
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['asset_id' => $asset->id], $alpha->id);
        $this->assertStringContainsString('also mapped to another PSA client', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);
    }

    // ── another client's agent fails closed ──────────────────────────────────

    public function test_another_clients_asset_id_or_hostname_fails_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $bravo = $this->mappedClient('Bravo');
        $bravoAsset = $this->linkedDevice($bravo, 'Test-Bravo.lan', 'agent-bravo');
        $this->fakeTactical();

        foreach (self::DEVICE_TOOLS as $tool) {
            $byId = $this->toolset()->execute($tool, ['asset_id' => $bravoAsset->id], $alpha->id);
            $this->assertStringContainsString('belongs to a different client', $byId['error'] ?? '', $tool);
            $byName = $this->toolset()->execute($tool, ['hostname' => 'Test-Bravo.lan'], $alpha->id);
            $this->assertStringContainsString('belongs to a different client', $byName['error'] ?? '', $tool);
        }
        $this->assertSame([], $this->agentReads);
    }

    public function test_an_agent_on_another_clients_site_fails_closed_even_when_this_clients_asset_links_it(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $this->mappedClient('Bravo');
        // Mislink: Alpha's PSA asset points at an agent that sits on Bravo's site.
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-bravo-site', 'Bravo|HQ');
        $this->fakeTactical();

        foreach (self::DEVICE_TOOLS as $tool) {
            foreach ([['asset_id' => $asset->id], ['hostname' => 'Test-MBP.lan']] as $input) {
                $out = $this->toolset()->execute($tool, $input, $alpha->id);
                $this->assertStringContainsString('not mapped to this client', $out['error'] ?? '', $tool);
            }
        }
        $this->assertSame([], $this->agentReads);

        $list = $this->toolset()->execute('tactical_list_devices', [], $alpha->id);
        $this->assertSame(0, $list['count']);
        $this->assertSame(1, $list['withheld_off_site']);
    }

    public function test_an_agent_with_no_recorded_site_fails_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-x');
        TacticalAsset::where('agent_id', 'agent-x')->update(['client_name' => null, 'site_name' => null]);
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['asset_id' => $asset->id], $alpha->id);
        $this->assertStringContainsString('no recorded site', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);
    }

    public function test_asset_id_and_a_hostname_of_a_different_device_fail_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-a');
        $this->linkedDevice($alpha, 'Test-Other.lan', 'agent-b');
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['asset_id' => $asset->id, 'hostname' => 'Test-Other.lan'], $alpha->id);
        $this->assertStringContainsString('is not the device linked to asset', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);
    }

    // ── hostname fallback is for unlinked rows only ───────────────────────────

    public function test_hostname_fallback_serves_an_agent_row_that_no_asset_links_by_key(): void
    {
        $alpha = $this->mappedClient('Alpha');
        // Reverse pointer only: the asset carries no tactical_asset_id (pre-FK row).
        $asset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP.lan']);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-unlinked', 'hostname' => 'Test-MBP.lan',
            'client_name' => 'Alpha', 'site_name' => 'HQ',
        ]);
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-MBP.lan'], $alpha->id);
        $this->assertArrayNotHasKey('error', $out, json_encode($out));
        $this->assertSame(['agent-unlinked'], $this->agentReads);
    }

    public function test_hostname_fallback_does_not_serve_a_stale_agent_on_an_asset_keyed_to_another_agent(): void
    {
        $alpha = $this->mappedClient('Alpha');
        // The asset is mapped: its tactical_asset_id names agent-current (Test-New.lan).
        $asset = $this->linkedDevice($alpha, 'Test-New.lan', 'agent-current');
        // A stale agent row still reverse-points at that asset under its OLD name.
        // The asset is keyed to a different agent, so this row is not an unmapped one
        // and the hostname fallback must not serve it (at base, ->first() did).
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-stale', 'hostname' => 'Test-Old.lan',
            'client_name' => 'Alpha', 'site_name' => 'HQ',
        ]);
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-Old.lan'], $alpha->id);
        $this->assertStringContainsString('not found or belongs to a different client', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);

        $current = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-New.lan'], $alpha->id);
        $this->assertArrayNotHasKey('error', $current, json_encode($current));
        $this->assertSame(['agent-current'], $this->agentReads);
    }

    public function test_hostname_fallback_does_not_serve_an_agent_another_clients_asset_keys(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $bravo = $this->mappedClient('Bravo');
        // Bravo's asset keys the agent (tactical_asset_id). The agent row's reverse
        // pointer is stale and points at an UNLINKED Alpha asset, and its site even
        // reads Alpha's. The key says Bravo, so Alpha's hostname fallback must not
        // treat it as an unmapped row.
        $bravoAsset = $this->linkedDevice($bravo, 'Test-MBP.lan', 'agent-keyed-by-bravo', 'Alpha|HQ');
        $alphaAsset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-Other.lan']);
        TacticalAsset::where('agent_id', 'agent-keyed-by-bravo')->update(['asset_id' => $alphaAsset->id]);
        $this->assertSame($bravoAsset->tactical_asset_id, TacticalAsset::where('agent_id', 'agent-keyed-by-bravo')->value('id'));
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-MBP.lan'], $alpha->id);
        $this->assertStringContainsString('not found or belongs to a different client', $out['error'] ?? '', json_encode($out));
        $this->assertSame([], $this->agentReads);
    }

    public function test_a_hostname_matching_two_linked_devices_is_ambiguous_and_fails_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-1');
        $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-2');
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-MBP.lan'], $alpha->id);
        $this->assertStringContainsString('more than one linked device', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);
    }

    public function test_a_hostname_matching_two_unlinked_agents_is_ambiguous_and_fails_closed(): void
    {
        $alpha = $this->mappedClient('Alpha');
        foreach (['agent-1', 'agent-2'] as $agentId) {
            $asset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP.lan']);
            TacticalAsset::create([
                'asset_id' => $asset->id, 'agent_id' => $agentId, 'hostname' => 'Test-MBP.lan',
                'client_name' => 'Alpha', 'site_name' => 'HQ',
            ]);
        }
        $this->fakeTactical();

        $out = $this->toolset()->execute('tactical_get_device', ['hostname' => 'Test-MBP.lan'], $alpha->id);
        $this->assertStringContainsString('more than one unlinked', $out['error'] ?? '');
        $this->assertSame([], $this->agentReads);
    }

    // ── unknown upstream envelopes fail closed ────────────────────────────────

    /** @return array<string, array{0: string, 1: array<mixed>}> */
    public static function unknownEnvelopes(): array
    {
        $wrapped = ['results' => [['id' => 1, 'kb' => 'KB5000001']]];
        $object = ['detail' => 'Not found.'];
        $scalarRows = ['a', 'b'];

        return [
            'patches wrapped' => ['tactical_get_device_patches', $wrapped],
            'patches object' => ['tactical_get_device_patches', $object],
            'patches scalar rows' => ['tactical_get_device_patches', $scalarRows],
            'tasks wrapped' => ['tactical_get_device_tasks', $wrapped],
            'tasks object' => ['tactical_get_device_tasks', $object],
            'tasks scalar rows' => ['tactical_get_device_tasks', $scalarRows],
        ];
    }

    /** @dataProvider unknownEnvelopes */
    public function test_an_unknown_patch_or_task_envelope_is_an_error_not_an_empty_list(string $tool, array $payload): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-a');
        $this->fakeTactical(patches: $payload, tasks: $payload);

        $out = $this->toolset()->execute($tool, ['asset_id' => $asset->id], $alpha->id);
        $this->assertStringContainsString('unrecognised shape', $out['error'] ?? '', json_encode($out));
    }

    public function test_the_vendor_bare_list_envelope_reads_rows(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-a');
        // WinUpdateSerializer / TaskSerializer(many=True).data: a bare list of objects.
        $this->fakeTactical(
            patches: [['id' => 44, 'kb' => 'KB5000001', 'severity' => 'Critical', 'installed' => false]],
            tasks: [['id' => 21, 'name' => 'Daily cleanup', 'enabled' => true]],
        );

        $this->assertSame(1, $this->toolset()->execute('tactical_get_device_patches', ['asset_id' => $asset->id], $alpha->id)['count'] ?? null);
        $this->assertSame(1, $this->toolset()->execute('tactical_get_device_tasks', ['asset_id' => $asset->id], $alpha->id)['count'] ?? null);
    }

    // ── descriptions and the MCP boundary ────────────────────────────────────

    public function test_device_descriptions_say_client_id_is_primary_and_hostname_is_a_fallback(): void
    {
        $definitions = collect(TacticalReadOnlyToolset::definitions())->keyBy('name');

        foreach ([...self::DEVICE_TOOLS, 'tactical_list_recent_actions'] as $tool) {
            $definition = $definitions[$tool];
            $this->assertStringContainsString('client_id is the primary key', $definition['description'], $tool);
            $this->assertStringContainsString('hostname is a fallback', $definition['description'], $tool);
            $this->assertArrayHasKey('asset_id', $definition['input_schema']['properties'], $tool);
            $this->assertStringContainsString('FALLBACK', $definition['input_schema']['properties']['hostname']['description'], $tool);
            $this->assertNotContains('hostname', $definition['input_schema']['required'] ?? [], $tool);
        }

        $this->assertStringContainsString('client_id is the primary key', $definitions['tactical_list_devices']['description']);
        $this->assertArrayHasKey('client_id', $definitions['tactical_list_clients_sites']['input_schema']['properties']);
    }

    public function test_the_triage_loop_definitions_are_unchanged(): void
    {
        // The legacy six definitions are shared with triage, whose executor takes
        // hostname only: asset_id must not leak into that surface.
        foreach (\App\Services\Triage\TriageToolDefinitions::tacticalTools() as $tool) {
            $this->assertArrayNotHasKey('asset_id', $tool['input_schema']['properties'], $tool['name']);
            $this->assertStringNotContainsString('client_id is the primary key', $tool['description'], $tool['name']);
        }
    }

    public function test_mcp_boundary_passes_asset_id_and_refuses_a_malformed_client_id_on_list_clients_sites(): void
    {
        $alpha = $this->mappedClient('Alpha');
        $asset = $this->linkedDevice($alpha, 'Test-MBP.lan', 'agent-a');
        $this->fakeTactical();

        $ok = $this->callMcp('tactical_get_device', ['client_id' => $alpha->id, 'asset_id' => $asset->id]);
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $this->assertSame(['agent-a'], $this->agentReads);

        $bad = $this->callMcp('tactical_list_clients_sites', ['client_id' => 'garbage']);
        $this->assertTrue((bool) $bad->json('result.isError'));
        $this->assertStringContainsString('is not a positive integer', (string) $bad->json('result.content.0.text'));
    }
}
