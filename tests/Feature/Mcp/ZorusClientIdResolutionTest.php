<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Triage\TriageToolExecutor;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusReadOnlyToolset;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card 6abdcac2, fifth integration: Zorus reads take client_id and resolve strictly
 * within that client. PINNING ONLY: the audit at d14ba139 measured every surface as
 * already client-strict, but found the triage tool untested and the cross-client
 * endpoint uuid unpinned, so a dropped fence there would have shipped green.
 *
 * Surfaces: the staff MCP (zorus_get_filtering_status, zorus_list_endpoints via
 * ChetDataSurfaceToolExecutor) and triage (zorus_get_endpoints, bound to the ticket's
 * client). The Assistant publishes no zorus tool. Every read answers from synced
 * assets columns; none constructs a Zorus vendor client (pinned by the trap below).
 *
 * Synthetic data only: "Alpha Synthetic"/"Bravo Synthetic", hosts such as
 * Test-MBP.lan and Bravo-WS, all-hex placeholder endpoint uuids.
 */
class ZorusClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const BRAVO_ENDPOINT = 'bbbbbbbb-0000-0000-0000-000000000002';

    private const BRAVO_GROUP = 'BRAVO-ONLY-POLICY';

    private const UNMAPPED_HOST = 'UNMAPPED-LEFTOVER-HOST';

    private const READS = ['zorus_list_endpoints', 'zorus_get_filtering_status'];

    private Client $alpha;

    private Client $bravo;

    /** @var array<int, int> Alpha's linked asset ids */
    private array $alphaIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('zorus_api_key', 'k');
        Setting::setValue('zorus_enabled', '1');

        // Trap: any read that resolves a Zorus vendor client fails the test.
        $this->app->bind(ZorusClient::class, function (): never {
            throw new \LogicException('ZORUS VENDOR CLIENT RESOLVED ON A READ PATH');
        });

        $this->alpha = Client::factory()->create(['name' => 'Alpha Synthetic', 'zorus_customer_id' => 'aaaaaaaa-0000-0000-0000-00000000000a']);
        $this->bravo = Client::factory()->create(['name' => 'Bravo Synthetic', 'zorus_customer_id' => 'bbbbbbbb-0000-0000-0000-00000000000b']);

        $this->alphaIds[] = $this->linked($this->alpha, 'Test-MBP.lan', 'aaaaaaaa-0000-0000-0000-000000000001')->id;
        $this->alphaIds[] = $this->linked($this->alpha, 'Alpha-WS1', 'aaaaaaaa-0000-0000-0000-000000000003')->id;

        $this->linked($this->bravo, 'Bravo-WS', self::BRAVO_ENDPOINT, ['name' => 'Bravo-Asset-Name', 'zorus_group_name' => self::BRAVO_GROUP]);
        Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => 'Bravo-UNLINKED', 'name' => 'Bravo-UNLINKED']);
    }

    private function linked(Client $client, string $host, string $endpoint, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => $host,
            'name' => $host,
            'is_active' => true,
            'zorus_endpoint_id' => $endpoint,
            'zorus_group_name' => 'Alpha Policy',
            'zorus_filtering_enabled' => true,
            'zorus_cybersight_enabled' => false,
            'zorus_agent_version' => '4.1.0',
            'zorus_agent_state' => 'Connected',
            'zorus_last_seen_at' => now()->subHour(),
            'zorus_synced_at' => now()->subHours(2),
        ], $overrides));
    }

    private function mcp(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: self::READS, label: 'zorus-clientid');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function text(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    /** @return array<string, mixed> */
    private function ok(TestResponse $response): array
    {
        $raw = $this->text($response);
        $this->assertFalse((bool) $response->json('result.isError'), "expected a served read, got: {$raw}");

        return json_decode($raw, true);
    }

    private function assertNoBravo(string $raw, string $context): void
    {
        foreach (['Bravo', 'bravo', self::BRAVO_ENDPOINT, self::BRAVO_GROUP, $this->bravo->zorus_customer_id] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "{$context}: another client's data crossed ({$needle})");
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function ids(array $rows): array
    {
        $ids = array_map(fn (array $row): int => $row['asset_id'], $rows);
        sort($ids);

        return $ids;
    }

    private function triage(Client $client): TriageToolExecutor
    {
        return new TriageToolExecutor(Ticket::factory()->create(['client_id' => $client->id]));
    }

    private function unmappedWithLeftover(): Client
    {
        $client = Client::factory()->create(['name' => 'Unmapped Synthetic', 'zorus_customer_id' => null]);
        $this->linked($client, self::UNMAPPED_HOST, 'cccccccc-0000-0000-0000-000000000004');

        return $client;
    }

    // ── staff MCP: client_id resolves strictly ─────────────────────────────────

    public function test_mcp_reads_resolve_strictly_within_client_id(): void
    {
        $list = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id]));
        $this->assertSame($this->alpha->id, $list['psa_client_id']);
        $this->assertSame($this->alpha->zorus_customer_id, $list['zorus_customer_id']);
        $this->assertSame($this->alphaIds, $this->ids($list['endpoints']));
        $this->assertNoBravo(json_encode($list), 'list_endpoints');

        $status = $this->ok($this->mcp('zorus_get_filtering_status', ['client_id' => $this->alpha->id]));
        $this->assertSame($this->alpha->id, $status['psa_client_id']);
        $this->assertSame(2, $status['endpoint_count']);
        $this->assertSame(2, $status['fleet_coverage']['active_total']);
        $this->assertNoBravo(json_encode($status), 'get_filtering_status');

        // Symmetry: the same call for Bravo serves Bravo's row, so the fence is a
        // fence and not an empty table.
        $bravo = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->bravo->id]));
        $this->assertSame(['Bravo-WS'], array_column($bravo['endpoints'], 'hostname'));
    }

    public function test_another_clients_hostname_endpoint_uuid_or_asset_name_is_not_found_over_mcp(): void
    {
        foreach (['Bravo-WS', 'bravo-ws', self::BRAVO_ENDPOINT, 'Bravo-UNLINKED', 'Bravo-Asset-Name'] as $key) {
            $raw = $this->text($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'hostname' => $key]));
            $result = json_decode($raw, true);

            $this->assertSame(0, $result['count'], "{$key}: another client's device was served for Alpha");
            $this->assertSame([], $result['endpoints'], $key);
            $this->assertSame($this->alpha->id, $result['psa_client_id'], $key);
            $this->assertStringContainsString('No PSA asset for this client matches', $result['no_match_note'], $key);
            // The note echoes the caller's own query; nothing else of Bravo's may appear.
            $this->assertNoBravo(str_replace($key, '<query>', $raw), $key);
        }
    }

    public function test_the_hostname_is_a_filter_inside_the_client_never_a_resolver(): void
    {
        $dupA = $this->linked($this->alpha, 'Alpha-DUP', 'aaaaaaaa-0000-0000-0000-000000000005')->id;
        $dupB = $this->linked($this->alpha, 'Alpha-DUP', 'aaaaaaaa-0000-0000-0000-000000000006')->id;
        $this->linked($this->bravo, 'Alpha-DUP', 'bbbbbbbb-0000-0000-0000-000000000007', ['zorus_group_name' => self::BRAVO_GROUP]);

        // Same hostname in both clients and twice in Alpha: Alpha's two rows are
        // listed by asset_id, nothing is picked, and Bravo's twin never appears.
        $dup = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'hostname' => 'Alpha-DUP']));
        $this->assertSame([$dupA, $dupB], $this->ids($dup['endpoints']));
        $this->assertNoBravo(json_encode($dup), 'duplicate hostname');

        // A LIKE wildcard cannot widen the read past the client.
        $wild = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'hostname' => '%']));
        $this->assertSame([...$this->alphaIds, $dupA, $dupB], $this->ids($wild['endpoints']));
        $this->assertNoBravo(json_encode($wild), 'wildcard hostname');
    }

    public function test_the_filtering_enabled_filter_and_its_miss_note_stay_inside_the_client(): void
    {
        $alphaOff = $this->linked($this->alpha, 'Alpha-OFF', 'aaaaaaaa-0000-0000-0000-000000000008', ['zorus_filtering_enabled' => false])->id;
        $this->linked($this->bravo, 'Bravo-OFF', 'bbbbbbbb-0000-0000-0000-000000000009', ['zorus_filtering_enabled' => false, 'zorus_group_name' => self::BRAVO_GROUP]);

        // Bravo has a linked row on each side of the filter, so a filter that escaped
        // the client scope would add one of Bravo's rows to either answer.
        $on = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'filtering_enabled' => true]));
        $this->assertSame($this->alphaIds, $this->ids($on['endpoints']));
        $this->assertNoBravo(json_encode($on), 'filtering_enabled true');

        $off = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'filtering_enabled' => false]));
        $this->assertSame([$alphaOff], $this->ids($off['endpoints']));
        $this->assertNoBravo(json_encode($off), 'filtering_enabled false');

        // Positive control: the miss note's filter-interaction count is reached, and
        // reports a hostname match inside the client that the filter excluded.
        $own = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'hostname' => 'Alpha-OFF', 'filtering_enabled' => true]));
        $this->assertSame([], $own['endpoints']);
        $this->assertStringContainsString("1 Zorus-linked endpoint(s) matched hostname 'Alpha-OFF'", $own['no_match_note']);

        // That count must never see another client's endpoint: a Bravo hostname under
        // either filter value is a plain miss, not a filtered-out match.
        foreach (['Bravo-WS', 'Bravo-OFF'] as $host) {
            foreach ([true, false] as $filter) {
                $label = "{$host} filtering_enabled=".json_encode($filter);
                $result = $this->ok($this->mcp('zorus_list_endpoints', ['client_id' => $this->alpha->id, 'hostname' => $host, 'filtering_enabled' => $filter]));

                $this->assertSame(0, $result['count'], $label);
                $this->assertSame($this->alpha->id, $result['psa_client_id'], $label);
                $this->assertStringNotContainsString('Zorus-linked endpoint(s) matched', $result['no_match_note'], $label);
                $this->assertStringContainsString('No PSA asset for this client matches', $result['no_match_note'], $label);
                $this->assertNoBravo(str_replace($host, '<query>', json_encode($result, JSON_UNESCAPED_SLASHES)), $label);
            }
        }
    }

    public function test_fleet_coverage_lifecycle_exclusions_count_only_the_clients_own_assets(): void
    {
        // Distinct per-client counts, so an exclusion count that dropped its client
        // scope would report the cross-client sum. Retired = soft-deleted with
        // is_active left true, the shape AssetService::deleteAsset leaves.
        Asset::factory()->create(['client_id' => $this->alpha->id, 'hostname' => 'Alpha-INACTIVE', 'name' => 'Alpha-INACTIVE', 'is_active' => false]);
        Asset::factory()->create(['client_id' => $this->alpha->id, 'hostname' => 'Alpha-RETIRED', 'name' => 'Alpha-RETIRED'])->delete();
        foreach (['Bravo-INACTIVE-1', 'Bravo-INACTIVE-2'] as $host) {
            Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => $host, 'name' => $host, 'is_active' => false]);
        }
        foreach (['Bravo-RETIRED-1', 'Bravo-RETIRED-2', 'Bravo-RETIRED-3'] as $host) {
            Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => $host, 'name' => $host])->delete();
        }

        $alpha = $this->ok($this->mcp('zorus_get_filtering_status', ['client_id' => $this->alpha->id]));
        $this->assertSame(2, $alpha['fleet_coverage']['active_total']);
        $this->assertSame(1, $alpha['fleet_coverage']['inactive_assets_excluded']);
        $this->assertSame(1, $alpha['fleet_coverage']['retired_assets_excluded']);
        $this->assertNoBravo(json_encode($alpha), 'alpha lifecycle exclusions');

        $bravo = $this->ok($this->mcp('zorus_get_filtering_status', ['client_id' => $this->bravo->id]));
        $this->assertSame(2, $bravo['fleet_coverage']['inactive_assets_excluded']);
        $this->assertSame(3, $bravo['fleet_coverage']['retired_assets_excluded']);
    }

    public function test_undeclared_device_or_customer_keys_are_refused_not_honoured(): void
    {
        $cases = [
            ['zorus_list_endpoints', 'endpoint_id', self::BRAVO_ENDPOINT],
            ['zorus_list_endpoints', 'asset_id', 1],
            ['zorus_list_endpoints', 'zorus_customer_id', 'bbbbbbbb-0000-0000-0000-00000000000b'],
            ['zorus_list_endpoints', 'customer_id', 'bbbbbbbb-0000-0000-0000-00000000000b'],
            ['zorus_get_filtering_status', 'hostname', 'Bravo-WS'],
            ['zorus_get_filtering_status', 'zorus_customer_id', 'bbbbbbbb-0000-0000-0000-00000000000b'],
        ];

        foreach ($cases as [$tool, $key, $value]) {
            $response = $this->mcp($tool, ['client_id' => $this->alpha->id, $key => $value]);
            $raw = $this->text($response);

            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} {$key}");
            $this->assertStringContainsString("Unsupported MCP argument(s): {$key}.", $raw, "{$tool} {$key}");
            $this->assertStringNotContainsString('Bravo-WS"', $raw);
        }
    }

    // ── missing / malformed / unknown / unmapped client_id ─────────────────────

    public function test_a_missing_or_malformed_client_id_is_refused_at_the_boundary_with_no_read(): void
    {
        foreach (self::READS as $tool) {
            foreach ([null, 'abc', '0', 0, -1, '07x', 1.5] as $clientId) {
                $arguments = $clientId === null ? [] : ['client_id' => $clientId];
                $response = $this->mcp($tool, $arguments);
                $raw = $this->text($response);

                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} ".json_encode($clientId));
                $this->assertSame("client_id is required for {$tool}.", $raw, "{$tool} ".json_encode($clientId));
            }
        }
    }

    public function test_a_null_client_is_refused_by_the_executor_and_by_the_toolset(): void
    {
        foreach (self::READS as $tool) {
            $this->assertSame(
                ['error' => "client_id is required for {$tool}."],
                app(ChetDataSurfaceToolExecutor::class)->execute($tool, [], null),
            );
            $this->assertSame(
                ['error' => 'client_id is required'],
                app(ZorusReadOnlyToolset::class)->execute($tool, [], null),
            );
            // An input client_id is never a substitute for the resolved one. The
            // toolset alone would fall back to input['client_id'] (positiveInt), so
            // this executor refusal is what keeps that fallback unreachable; the MCP
            // boundary also strips client_id from the arguments before dispatch.
            $this->assertSame(
                ['error' => "client_id is required for {$tool}."],
                app(ChetDataSurfaceToolExecutor::class)->execute($tool, ['client_id' => $this->bravo->id], null),
            );
        }
    }

    public function test_unknown_and_unmapped_client_ids_fail_closed(): void
    {
        $unmapped = $this->unmappedWithLeftover();

        foreach (self::READS as $tool) {
            $unknown = $this->mcp($tool, ['client_id' => 999999]);
            $this->assertTrue((bool) $unknown->json('result.isError'));
            $this->assertStringContainsString('PSA client 999999 was not found.', $this->text($unknown));

            $response = $this->mcp($tool, ['client_id' => $unmapped->id]);
            $raw = $this->text($response);
            $this->assertTrue((bool) $response->json('result.isError'), $tool);
            $this->assertStringContainsString('is not mapped to a Zorus customer', $raw);
            $this->assertStringNotContainsString(self::UNMAPPED_HOST, $raw);
            $this->assertNoBravo($raw, "{$tool} unmapped");
        }
    }

    // ── triage: zorus_get_endpoints is bound to the ticket's client ────────────

    public function test_triage_zorus_get_endpoints_serves_only_the_tickets_client(): void
    {
        $alpha = $this->triage($this->alpha)->execute('zorus_get_endpoints', []);
        $this->assertSame(['Alpha-WS1', 'Test-MBP.lan'], array_column($alpha, 'name'));
        $this->assertNoBravo(json_encode($alpha), 'triage alpha');

        $bravo = $this->triage($this->bravo)->execute('zorus_get_endpoints', []);
        $this->assertSame(['Bravo-WS'], array_column($bravo, 'name'));
    }

    public function test_triage_ignores_an_agent_typed_client_id_hostname_or_endpoint(): void
    {
        $result = $this->triage($this->alpha)->execute('zorus_get_endpoints', [
            'client_id' => $this->bravo->id,
            'hostname' => 'Bravo-WS',
            'endpoint_id' => self::BRAVO_ENDPOINT,
            'zorus_customer_id' => $this->bravo->zorus_customer_id,
        ]);

        $this->assertSame(['Alpha-WS1', 'Test-MBP.lan'], array_column($result, 'name'));
        $this->assertNoBravo(json_encode($result), 'triage typed keys');
    }

    public function test_triage_refuses_an_unmapped_client_even_with_leftover_rows(): void
    {
        $result = $this->triage($this->unmappedWithLeftover())->execute('zorus_get_endpoints', []);

        $this->assertSame(['error' => 'Client has no Zorus customer mapping'], $result);
    }

    // ── no vendor call on any read path ────────────────────────────────────────

    public function test_the_vendor_client_trap_fires_when_resolved(): void
    {
        // Positive control for the setUp() trap: resolving the vendor client the way
        // the live paths do (DeviceAbsenceVerifier: app(ZorusClient::class)) throws.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ZORUS VENDOR CLIENT RESOLVED ON A READ PATH');

        app(ZorusClient::class);
    }

    public function test_no_read_constructs_a_zorus_vendor_client(): void
    {
        // The container trap only catches app() resolution; a `new ZorusClient` would
        // bypass it, so the read sources are checked for the vendor types as well.
        $toolset = file_get_contents(app_path('Services/Zorus/ZorusReadOnlyToolset.php'));
        $method = new \ReflectionMethod(TriageToolExecutor::class, 'zorusGetEndpoints');
        $lines = file($method->getFileName());
        $triage = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        foreach (['toolset' => $toolset, 'triage' => $triage] as $where => $source) {
            $this->assertStringContainsString('zorus_endpoint_id', $source, "{$where}: wrong source read");
            foreach (['ZorusClient', 'GuzzleHttp', 'Http::'] as $vendor) {
                $this->assertStringNotContainsString($vendor, $source, "{$where} references {$vendor}");
            }
        }
    }
}
