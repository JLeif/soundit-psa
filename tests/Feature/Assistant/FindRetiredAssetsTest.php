<?php

namespace Tests\Feature\Assistant;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetService;
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

    // ── r2 (Jeeves REVISE at 10846c14) ────────────────────────────────────────

    /** Item 1 (context:2 / contract:1 / diff:1): a deactivated live row outranks a retired one. */
    public function test_get_asset_never_lets_a_retired_row_win_over_a_deactivated_live_row(): void
    {
        $x = Client::factory()->create();
        $retired = $this->asset($x, 'EXAMPLE-DESK', retired: true);
        $liveOff = $this->asset($x, 'EXAMPLE-DESK', active: false);

        $got = $this->at($x)->execute('get_asset', ['hostname' => 'EXAMPLE-DESK', 'include_retired' => true]);
        $this->assertArrayNotHasKey('id', $got, 'the retired row must not win over a deactivated live row: '.json_encode($got));
        $this->assertSame(
            'Asset not found at this client (a deactivated, non-retired asset carries this hostname and takes precedence over any retired row with it — set include_inactive to include it)',
            $got['error'] ?? null,
        );

        $both = $this->at($x)->execute('get_asset', ['hostname' => 'EXAMPLE-DESK', 'include_retired' => true, 'include_inactive' => true]);
        $this->assertSame($liveOff->id, $both['id'] ?? null, 'with include_inactive the deactivated live row is returned: '.json_encode($both));
        $this->assertFalse($both['is_retired']);
        $this->assertFalse($both['restorable']);

        // Among live rows an active one still beats a deactivated one, whatever the ids.
        $liveOn = $this->asset($x, 'EXAMPLE-DESK');
        $this->assertGreaterThan($liveOff->id, $liveOn->id);
        $pick = $this->at($x)->execute('get_asset', ['hostname' => 'EXAMPLE-DESK', 'include_retired' => true]);
        $this->assertSame($liveOn->id, $pick['id'] ?? null, json_encode($pick));

        // By id the retired row is still reachable directly.
        $byId = $this->at($x)->execute('get_asset', ['asset_id' => $retired->id, 'include_retired' => true]);
        $this->assertSame($retired->id, $byId['id'] ?? null);
    }

    /** Item 2 (contract:4 / diff:2): the scope string states the real fence on every flag combination. */
    public function test_find_assets_scope_string_states_the_real_scope(): void
    {
        $x = Client::factory()->create();
        $this->asset($x, 'EXAMPLE-LIVE');
        $retiredOff = $this->asset($x, 'EXAMPLE-RET-OFF', active: false, retired: true);

        $cases = [
            [[], "client_id={$x->id}; active only"],
            [['include_inactive' => true], "client_id={$x->id}; including inactive"],
            [['include_retired' => true], "client_id={$x->id}; non-retired rows active only; including retired (active or deactivated)"],
            [['include_retired' => true, 'include_inactive' => true], "client_id={$x->id}; including inactive; including retired"],
        ];
        foreach ($cases as [$flags, $scope]) {
            $result = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE'] + $flags);
            $this->assertSame($scope, $result['scope'], json_encode($flags));
        }

        // The case the finding names: a deactivated retired row is in the payload,
        // so the scope must not claim the result is active only.
        $retiredOnly = $this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => true]);
        $row = collect($retiredOnly['assets'])->firstWhere('id', $retiredOff->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_active']);
        $this->assertStringNotContainsString('; active only', $retiredOnly['scope']);
    }

    /** Item 3 (diff:3 / contract:5 / context:3): the not-found hint is true on every path. */
    public function test_get_asset_not_found_hint_is_true_on_every_flag_combination(): void
    {
        $x = Client::factory()->create();
        $retired = $this->asset($x, 'EXAMPLE-GONE', active: false, retired: true);

        // [flags, hint by hostname, hint by id]. include_retired + hostname skips the
        // active fence (a live deactivated row would hit the precedence error), so
        // a miss there excluded nothing and carries no hint; by id the fence holds.
        $cases = [
            [[], ' (deactivated assets are excluded — set include_inactive to include them; retired (soft-deleted) assets are excluded — set include_retired to include them)', null],
            [['include_inactive' => true], ' (retired (soft-deleted) assets are excluded — set include_retired to include them)', null],
            [['include_retired' => true], '', ' (deactivated non-retired assets are excluded — set include_inactive to include them)'],
            [['include_retired' => true, 'include_inactive' => true], '', null],
        ];
        foreach ($cases as [$flags, $hostnameHint, $idHint]) {
            foreach ([[['hostname' => 'EXAMPLE-NOPE'], $hostnameHint], [['asset_id' => $retired->id + 1000], $idHint ?? $hostnameHint]] as [$lookup, $hint]) {
                $got = $this->at($x)->execute('get_asset', $lookup + $flags);
                $this->assertSame('Asset not found at this client'.$hint, $got['error'] ?? null, json_encode($lookup + $flags));
            }
        }

        // The default-path lookup of a retired device points at include_retired,
        // and following that pointer reaches it.
        $miss = $this->at($x)->execute('get_asset', ['asset_id' => $retired->id, 'include_inactive' => true]);
        $this->assertStringContainsString('set include_retired', $miss['error']);
        $hit = $this->at($x)->execute('get_asset', ['asset_id' => $retired->id, 'include_retired' => true]);
        $this->assertSame($retired->id, $hit['id'] ?? null);
    }

    /** Item 4 (diff:6 / contract:2): merge tombstones are marked unrestorable, by restore_asset's own rule. */
    public function test_merge_tombstones_are_marked_not_restorable_by_the_rule_restore_asset_applies(): void
    {
        $x = Client::factory()->create();
        $user = User::factory()->create();
        $survivor = $this->asset($x, 'EXAMPLE-NEW');
        $duplicate = $this->asset($x, 'EXAMPLE-OLD');
        app(AssetService::class)->mergeAssets($survivor, $duplicate, $user->id);
        $tombstone = Asset::withTrashed()->findOrFail($duplicate->id);
        $this->assertTrue($tombstone->trashed(), 'precondition: merge soft-deletes the duplicate');
        $this->assertSame($survivor->id, (int) $tombstone->merged_into_asset_id);
        $plainRetired = $this->asset($x, 'EXAMPLE-RET', retired: true);

        $rows = collect($this->at($x)->execute('find_assets', ['query' => 'EXAMPLE', 'include_retired' => true])['assets'])->keyBy('id');
        $this->assertTrue($rows[$tombstone->id]['is_retired']);
        $this->assertFalse($rows[$tombstone->id]['restorable'], 'a merge tombstone must not be offered as restorable');
        $this->assertTrue($rows[$plainRetired->id]['is_retired']);
        $this->assertTrue($rows[$plainRetired->id]['restorable']);
        $this->assertFalse($rows[$survivor->id]['restorable'], 'a live row has nothing to restore');

        $got = $this->at($x)->execute('get_asset', ['asset_id' => $tombstone->id, 'include_retired' => true]);
        $this->assertFalse($got['restorable']);
        $this->assertSame($survivor->id, (int) $got['linked_ids']['merged_into_asset_id'], 'get_asset names the survivor');
        $this->assertTrue($this->at($x)->execute('get_asset', ['asset_id' => $plainRetired->id, 'include_retired' => true])['restorable']);

        // restorable agrees with restore_asset itself, row by row.
        Setting::setValue('triage_system_user_id', (string) $user->id);
        $token = McpConfig::rotateStaffToken(allowedTools: ['restore_asset'], label: 'find-retired-restore');
        foreach ([$tombstone->id => false, $plainRetired->id => true] as $id => $restorable) {
            $res = $this->mcp($token, 'restore_asset', ['asset_id' => $id]);
            $this->assertSame(! $restorable, $res['is_error'], "restore_asset on #{$id}: ".$res['text']);
        }
        $this->assertNotNull(Asset::withTrashed()->find($tombstone->id)->deleted_at, 'the tombstone stays retired');
        $this->assertNull(Asset::withTrashed()->find($plainRetired->id)->deleted_at);
    }

    /** The descriptions carry the r2 rules and still never mention restore_asset (r1 rework). */
    public function test_descriptions_state_the_r2_rules_without_naming_restore_asset(): void
    {
        $tools = collect(\App\Services\Assistant\AssistantToolDefinitions::getTools(true))->keyBy('name');
        foreach (['get_asset', 'find_assets'] as $name) {
            $json = json_encode($tools[$name]);
            $this->assertStringNotContainsString('restore_asset', $json, $name);
            $this->assertStringContainsString('restorable', $tools[$name]['description'], $name);
            $this->assertStringContainsString('merge tombstone', $tools[$name]['description'], $name);
        }
        $this->assertStringNotContainsString('the live one is returned', $tools['get_asset']['description']);
        $this->assertStringContainsString('a non-retired row, active or deactivated, always takes precedence', $tools['get_asset']['description']);
    }
}
