<?php

namespace Tests\Feature\Assistant;

use App\Models\Asset;
use App\Models\Client;
use App\Services\Assistant\AssistantToolExecutor;
use App\Support\McpConfig;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card NSh7FP8I: find_assets / get_asset gain include_retired, so a retired
 * (soft-deleted) asset's id can be found and handed to restore_asset.
 *
 * Synthetic data only (G-13): EXAMPLE-* hostnames, factory clients.
 *
 * What is pinned here:
 *   - include_retired:true returns the trashed row with is_retired/retired_at;
 *   - the default (flag absent or false) still excludes it, and the default
 *     response is byte-identical to the explicit false — same rows, keys,
 *     order, paging and scope string;
 *   - include_retired is independent of include_inactive: a retired row comes
 *     back whatever its is_active, a live deactivated row still does not;
 *   - the client fence holds on the retired branch (find + get, executor and
 *     the MCP staff route);
 *   - a non-boolean include_retired is refused, never coerced.
 */
class FindRetiredAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function asset(Client $client, string $hostname, bool $active = true, bool $retired = false): Asset
    {
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => $hostname,
            'name' => $hostname,
            'serial_number' => 'SN-'.$hostname,
            'is_active' => $active,
        ]);
        if ($retired) {
            $asset->delete();
        }

        return $asset;
    }

    private function at(?Client $client): AssistantToolExecutor
    {
        return new AssistantToolExecutor(clientId: $client?->id);
    }

    /** @return array<int, int> */
    private function ids(array $result): array
    {
        $this->assertArrayHasKey('assets', $result, 'expected a result, got: '.json_encode($result));

        return array_column($result['assets'], 'id');
    }

    // ── Acceptance shape ──────────────────────────────────────────────────────

    public function test_include_retired_returns_the_trashed_row_with_its_id_and_retired_fields(): void
    {
        $x = Client::factory()->create();
        $retired = $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $live = $this->asset($x, 'EXAMPLE-LAPTOP');

        $result = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => true]);

        $rows = collect($result['assets'] ?? [])->keyBy('id');
        $this->assertTrue($rows->has($retired->id), 'the retired EXAMPLE-DESK row must be returned with its id');
        $this->assertTrue($rows[$retired->id]['is_retired']);
        $deletedAt = Asset::withTrashed()->find($retired->id)->deleted_at;
        $this->assertSame($deletedAt->toIso8601String(), $rows[$retired->id]['retired_at']);

        $this->assertTrue($rows->has($live->id), 'live rows still come back alongside retired ones');
        $this->assertFalse($rows[$live->id]['is_retired']);
        $this->assertNull($rows[$live->id]['retired_at']);
        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('including retired', $result['scope']);
    }

    public function test_default_excludes_the_trashed_row(): void
    {
        $x = Client::factory()->create();
        $retired = $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $live = $this->asset($x, 'EXAMPLE-LAPTOP');

        foreach ([[], ['include_retired' => false], ['include_inactive' => true]] as $flags) {
            $result = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE'] + $flags);
            $this->assertSame([$live->id], $this->ids($result), 'retired row leaked with flags '.json_encode($flags));
            $this->assertArrayNotHasKey('is_retired', $result['assets'][0], 'default rows must not grow new keys');
            $this->assertArrayNotHasKey('retired_at', $result['assets'][0]);
        }
    }

    // ── Unchanged-default contract ──────────────────────────────────────────────

    /**
     * The default contract, pinned field by field rather than compared with
     * itself: the exact row keys, the order (active first, hostname, then id),
     * total/has_more/offset and the scope string — for a list-all walked in
     * two pages, a search, and include_inactive. Then the no-flag response must
     * be byte-identical (json) to include_retired:false. Retired rows sit in
     * the population throughout, so any leak changes ids, totals or paging.
     */
    public function test_default_find_assets_contract_is_unchanged(): void
    {
        $x = Client::factory()->create();
        $b = $this->asset($x, 'EXAMPLE-B');
        $aLow = $this->asset($x, 'EXAMPLE-A');
        $aHigh = $this->asset($x, 'EXAMPLE-A'); // hostname tie: id breaks it
        $c = $this->asset($x, 'EXAMPLE-C', active: false);
        $this->asset($x, 'EXAMPLE-0RETIRED', retired: true);
        $this->asset($x, 'EXAMPLE-0RETIRED-OFF', active: false, retired: true);

        $keys = ['id', 'client_id', 'client_name', 'name', 'hostname', 'asset_type', 'serial_number', 'os', 'last_user', 'is_active'];
        $topKeys = ['count', 'total', 'offset', 'has_more', 'scope', 'assets'];

        $cases = [
            [['limit' => 2], [$aLow->id, $aHigh->id], 3, true, "client_id={$x->id}; active only; list-all (no query)"],
            [['limit' => 2, 'offset' => 2], [$b->id], 3, false, "client_id={$x->id}; active only; list-all (no query)"],
            [['query' => 'EXAMPLE'], [$aLow->id, $aHigh->id, $b->id], 3, false, "client_id={$x->id}; active only"],
            [['query' => 'EXAMPLE', 'include_inactive' => true], [$aLow->id, $aHigh->id, $b->id, $c->id], 4, false, "client_id={$x->id}; including inactive"],
        ];

        foreach ($cases as [$input, $ids, $total, $hasMore, $scope]) {
            $default = $this->at($x)->execute('find_assets', $input);
            $label = json_encode($input);

            $this->assertSame($topKeys, array_keys($default), "top-level keys changed for {$label}");
            $this->assertSame($ids, $this->ids($default), "rows/order changed for {$label}");
            $this->assertSame($total, $default['total'], "total changed for {$label}");
            $this->assertSame($hasMore, $default['has_more'], "has_more changed for {$label}");
            $this->assertSame($input['offset'] ?? 0, $default['offset']);
            $this->assertSame($scope, $default['scope'], "scope changed for {$label}");
            foreach ($default['assets'] as $row) {
                $this->assertSame($keys, array_keys($row), "row keys changed for {$label}");
            }

            $explicitFalse = $this->at($x)->execute('find_assets', $input + ['include_retired' => false]);
            $this->assertSame(json_encode($default), json_encode($explicitFalse), "include_retired:false must be byte-identical to the default for {$label}");
        }
    }

    // ── Independence from include_inactive ─────────────────────────────────────

    public function test_include_retired_returns_retired_rows_whatever_is_active_and_keeps_live_inactive_fenced(): void
    {
        $x = Client::factory()->create();
        $live = $this->asset($x, 'EXAMPLE-LIVE');
        $liveOff = $this->asset($x, 'EXAMPLE-LIVE-OFF', active: false);
        $retiredOn = $this->asset($x, 'EXAMPLE-RET-ON', retired: true);
        $retiredOff = $this->asset($x, 'EXAMPLE-RET-OFF', active: false, retired: true);

        $only = $this->ids($this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => true]));
        sort($only);
        $expected = [$live->id, $retiredOn->id, $retiredOff->id];
        sort($expected);
        $this->assertSame($expected, $only, 'retired rows come back whatever is_active; a LIVE deactivated row stays out without include_inactive');

        $both = $this->ids($this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => true, 'include_inactive' => true]));
        sort($both);
        $all = [$live->id, $liveOff->id, $retiredOn->id, $retiredOff->id];
        sort($all);
        $this->assertSame($all, $both);
    }

    // ── get_asset by hostname ─────────────────────────────────────────────────

    public function test_get_asset_by_hostname_without_and_with_the_flag(): void
    {
        $x = Client::factory()->create();
        $retired = $this->asset($x, 'EXAMPLE-DESK', active: false, retired: true);

        $without = $this->at($x)->execute('get_asset', ['hostname' => 'example-desk']);
        $this->assertArrayHasKey('error', $without, 'default get_asset must not resolve a retired device');
        $this->assertArrayNotHasKey('id', $without);

        $with = $this->at($x)->execute('get_asset', ['hostname' => 'example-desk', 'include_retired' => true]);
        $this->assertSame($retired->id, $with['id'] ?? null, 'include_retired must resolve the retired device by hostname: '.json_encode($with));
        $this->assertTrue($with['is_retired']);
        $this->assertSame(Asset::withTrashed()->find($retired->id)->deleted_at->toIso8601String(), $with['retired_at']);

        $byId = $this->at($x)->execute('get_asset', ['asset_id' => $retired->id, 'include_retired' => true]);
        $this->assertSame($retired->id, $byId['id'] ?? null);
    }

    public function test_get_asset_prefers_the_live_row_when_a_hostname_is_shared_and_keeps_default_keys(): void
    {
        $x = Client::factory()->create();
        $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $live = $this->asset($x, 'EXAMPLE-DESK');

        $with = $this->at($x)->execute('get_asset', ['hostname' => 'EXAMPLE-DESK', 'include_retired' => true]);
        $this->assertSame($live->id, $with['id'] ?? null, 'a live row outranks a retired one on a shared hostname');
        $this->assertFalse($with['is_retired']);
        $this->assertNull($with['retired_at']);

        $default = $this->at($x)->execute('get_asset', ['hostname' => 'EXAMPLE-DESK']);
        $this->assertSame($live->id, $default['id'] ?? null);
        $this->assertArrayNotHasKey('is_retired', $default, 'the default get_asset response must not grow new keys');
        $this->assertArrayNotHasKey('retired_at', $default);
    }

    // ── Cross-client fence ────────────────────────────────────────────────────

    public function test_client_fence_holds_on_the_retired_branch(): void
    {
        $x = Client::factory()->create();
        $y = Client::factory()->create();
        $mine = $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $theirs = $this->asset($y, 'EXAMPLE-DESK-Y', retired: true);
        $theirsOff = $this->asset($y, 'EXAMPLE-DESK-Y2', active: false, retired: true);

        $found = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE-DESK', 'include_retired' => true]);
        $this->assertSame([$mine->id], $this->ids($found), 'client X must never see client Y\'s retired rows');
        $this->assertSame(1, $found['total']);

        $listAll = $this->at($x)->execute('find_assets', ['include_retired' => true, 'include_inactive' => true]);
        $this->assertSame([$mine->id], $this->ids($listAll));

        foreach ([['asset_id' => $theirs->id], ['asset_id' => $theirsOff->id], ['hostname' => 'EXAMPLE-DESK-Y']] as $lookup) {
            $got = $this->at($x)->execute('get_asset', $lookup + ['include_retired' => true, 'include_inactive' => true]);
            $this->assertArrayHasKey('error', $got, 'cross-client get_asset leaked with '.json_encode($lookup));
            $this->assertArrayNotHasKey('id', $got);
        }
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_non_boolean_include_retired_is_refused(): void
    {
        $x = Client::factory()->create();
        $this->asset($x, 'EXAMPLE-DESK', retired: true);

        foreach (['true', 1, 'yes', 0, [], ['x']] as $bad) {
            foreach (['find_assets' => ['query' => 'EXAMPLE'], 'get_asset' => ['hostname' => 'EXAMPLE-DESK']] as $tool => $base) {
                $result = $this->at($x)->execute($tool, $base + ['include_retired' => $bad]);
                $label = $tool.' include_retired='.json_encode($bad);
                $this->assertArrayHasKey('error', $result, "{$label} must be refused, not coerced");
                $this->assertStringContainsString('include_retired must be a boolean', $result['error'], $label);
                $this->assertArrayNotHasKey('assets', $result, $label);
                $this->assertArrayNotHasKey('id', $result, $label);
            }
        }

        $nullIsDefault = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => null]);
        $this->assertSame([], $this->ids($nullIsDefault), 'an explicit null is the default (absent), not an error');
    }

    // ── The MCP staff route reaches the same executor ─────────────────────────

    private function staffToken(): string
    {
        $grants = [];
        foreach (McpToolSurface::liveToolNames() as $name) {
            $grants[] = $name;
        }

        return McpConfig::rotateStaffToken(allowedTools: $grants, label: 'find-retired');
    }

    /** @return array<string, mixed> */
    private function mcp(string $token, string $tool, array $arguments): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);

        return [
            'is_error' => (bool) $response->json('result.isError'),
            'text' => (string) $response->json('result.content.0.text'),
            'body' => json_decode((string) $response->json('result.content.0.text'), true) ?? [],
        ];
    }

    public function test_mcp_staff_route_publishes_and_honours_include_retired_inside_the_client_fence(): void
    {
        $x = Client::factory()->create();
        $y = Client::factory()->create();
        $mine = $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $theirs = $this->asset($y, 'EXAMPLE-DESK-Y', retired: true);
        $token = $this->staffToken();

        $listed = collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools') ?? [])->keyBy('name');
        foreach (['find_assets', 'get_asset'] as $tool) {
            $this->assertSame('boolean', $listed[$tool]['inputSchema']['properties']['include_retired']['type'] ?? null, "{$tool} must publish include_retired");
        }

        $found = $this->mcp($token, 'find_assets', ['client_id' => $x->id, 'query' => 'EXAMPLE', 'include_retired' => true]);
        $this->assertFalse($found['is_error'], $found['text']);
        $this->assertSame([$mine->id], array_column($found['body']['assets'], 'id'));
        $this->assertTrue($found['body']['assets'][0]['is_retired']);

        $default = $this->mcp($token, 'find_assets', ['client_id' => $x->id, 'query' => 'EXAMPLE']);
        $this->assertSame([], array_column($default['body']['assets'], 'id'));

        $got = $this->mcp($token, 'get_asset', ['client_id' => $x->id, 'hostname' => 'EXAMPLE-DESK', 'include_retired' => true]);
        $this->assertSame($mine->id, $got['body']['id'] ?? null, $got['text']);

        $cross = $this->mcp($token, 'get_asset', ['client_id' => $x->id, 'asset_id' => $theirs->id, 'include_retired' => true]);
        $this->assertArrayNotHasKey('id', $cross['body'], 'MCP get_asset must not cross the client fence: '.$cross['text']);

        $bad = $this->mcp($token, 'find_assets', ['client_id' => $x->id, 'include_retired' => 'true']);
        $this->assertStringContainsString('include_retired must be a boolean', $bad['text']);
    }
}
