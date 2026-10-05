<?php

namespace Tests\Feature\Assets;

use App\Models\AssetWatch;
use App\Models\McpAuditLog;
use App\Models\TechnicianActionLog;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The staff MCP surface of asset watches (card K3VEcxtw): grant gating,
 * client fence, Tactical-only scope, dedup, lifetime bounds, owner isolation
 * and audit. Every call goes through the real /api/mcp/staff route.
 */
class AssetWatchToolTest extends TestCase
{
    use AssetWatchFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-03-02T10:00:00Z'));
        $this->enableTactical();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function create(string $owner, int $clientId, int $assetId, array $extra = []): array
    {
        return $this->callAs($owner, 'create_asset_watch', array_merge([
            'client_id' => $clientId, 'asset_id' => $assetId, 'state' => 'online', 'reason' => 'synthetic reason',
        ], $extra));
    }

    // ------------------------------------------------------------ grant gate

    public function test_search_tools_finds_the_watch_tools_once_granted_and_not_callable_ungranted(): void
    {
        $granted = $this->callAs(self::OWNER_A, 'search_tools', ['query' => 'watch']);
        $states = array_column($granted['matches'], 'grant_state', 'name');
        foreach (['create_asset_watch', 'list_asset_watches', 'remove_asset_watch'] as $tool) {
            $this->assertSame('granted', $states[$tool] ?? null, $tool);
        }

        $this->tokens['ungranted'] = McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'ungranted');
        $ungranted = $this->callAs('ungranted', 'search_tools', ['query' => 'watch']);
        $states = array_column($ungranted['matches'], 'grant_state', 'name');
        foreach (['create_asset_watch', 'list_asset_watches', 'remove_asset_watch'] as $tool) {
            $this->assertSame('available_ungranted', $states[$tool] ?? null, $tool);
        }

        $listed = array_column($this->mcpAs('ungranted', 'tools/list', [])->json('result.tools'), 'name');
        $this->assertNotContains('create_asset_watch', $listed);
        $r = $this->mcpAs('ungranted', 'tools/call', ['name' => 'list_asset_watches', 'arguments' => []]);
        $this->assertTrue($r->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', (string) $r->json('result.content.0.text'));
    }

    public function test_the_legacy_full_surface_token_does_not_inherit_the_watch_tools(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: null);
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_asset_watches', 'arguments' => []],
        ]);
        $this->assertTrue($r->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', (string) $r->json('result.content.0.text'));
    }

    public function test_the_tools_are_unavailable_while_tactical_is_off(): void
    {
        \App\Models\Setting::setValue('tactical_enabled', '0');
        $states = array_column($this->callAs(self::OWNER_A, 'search_tools', ['query' => 'watch'])['matches'], 'grant_state', 'name');
        $this->assertSame('unavailable_config', $states['create_asset_watch'] ?? null);
    }

    // ------------------------------------------------------------- create

    public function test_create_returns_a_watch_id_with_a_seven_day_default_and_is_audited(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $out = $this->create(self::OWNER_A, $client->id, $asset->id);

        $this->assertFalse($out['existing']);
        $row = $this->watchRow($out['watch_id']);
        $this->assertSame(self::OWNER_A, $row->owner);
        $this->assertSame($client->id, $row->client_id);
        $this->assertTrue($row->expires_at->equalTo(Carbon::now()->addDays(7)));
        $this->assertSame('active', $out['status']);

        $log = TechnicianActionLog::query()->where('action_type', 'create_asset_watch')->sole();
        $this->assertSame($client->id, $log->client_id);
        $this->assertSame('mcp-staff:'.self::OWNER_A, $log->actor_label);
        $audit = McpAuditLog::query()->where('tool_name', 'create_asset_watch')->where('status', 'success')->sole();
        $this->assertSame(mb_strlen('synthetic reason'), $audit->arguments['reason_length']);
        $this->assertArrayNotHasKey('reason', $audit->arguments);
    }

    public function test_the_client_fence_refuses_another_clients_asset_like_a_missing_one(): void
    {
        $client = $this->mappedClient();
        $other = $this->mappedClient('Other Synthetic|HQ');
        $foreign = $this->tacticalAsset($other, 'AGENT-SYN-9');

        $fenced = $this->create(self::OWNER_A, $client->id, $foreign->id);
        $missing = $this->create(self::OWNER_A, $client->id, 999999);
        $this->assertSame('Asset not found for this client.', $fenced['error'] ?? null);
        $this->assertSame($missing, $fenced);
        $this->assertSame(0, AssetWatch::query()->count());

        $noClient = $this->callAs(self::OWNER_A, 'create_asset_watch', ['asset_id' => $foreign->id, 'state' => 'online', 'reason' => 'x']);
        $this->assertStringContainsString('client_id is required', (string) ($noClient['error'] ?? ''));
    }

    public function test_a_duplicate_returns_the_existing_watch_unchanged(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $first = $this->create(self::OWNER_A, $client->id, $asset->id);
        $second = $this->create(self::OWNER_A, $client->id, $asset->id, ['reason' => 'different words', 'repeat' => true]);

        $this->assertSame($first['watch_id'], $second['watch_id']);
        $this->assertTrue($second['existing']);
        $this->assertSame(1, AssetWatch::query()->count());
        $this->assertFalse($this->watchRow($first['watch_id'])->repeat, 'a duplicate changes nothing on the existing watch');

        // Other state and other owner are separate watches.
        $this->assertFalse($this->create(self::OWNER_A, $client->id, $asset->id, ['state' => 'offline'])['existing']);
        $this->assertFalse($this->create(self::OWNER_B, $client->id, $asset->id)['existing']);
        $this->assertSame(3, AssetWatch::query()->count());
    }

    public function test_after_removal_or_firing_the_same_watch_can_be_created_again(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $first = $this->create(self::OWNER_A, $client->id, $asset->id);
        $this->callAs(self::OWNER_A, 'remove_asset_watch', ['watch_id' => $first['watch_id'], 'reason' => 'done']);
        $again = $this->create(self::OWNER_A, $client->id, $asset->id);
        $this->assertNotSame($first['watch_id'], $again['watch_id']);
        $this->assertFalse($again['existing']);
    }

    public function test_non_tactical_assets_are_refused_plainly(): void
    {
        $client = $this->mappedClient();
        $plain = \App\Models\Asset::factory()->create(['client_id' => $client->id]);
        $ninja = $this->tacticalAsset($client, 'AGENT-SYN-2', null, ['ninja_id' => 4242]);
        $level = $this->tacticalAsset($client, 'AGENT-SYN-3', null, ['level_id' => 'lvl-synthetic']);

        foreach ([$plain, $ninja, $level] as $asset) {
            $out = $this->create(self::OWNER_A, $client->id, $asset->id);
            $this->assertStringStartsWith('Asset watches cover Tactical RMM assets only', (string) ($out['error'] ?? ''), (string) $asset->id);
        }
        $this->assertSame(0, AssetWatch::query()->count());
    }

    public function test_expires_at_bounds_are_enforced(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);

        $cases = [
            'past' => [Carbon::now()->subMinute()->format('Y-m-d\TH:i:s\Z'), 'in the past'],
            'beyond 30d' => [Carbon::now()->addDays(30)->addMinute()->format('Y-m-d\TH:i:s\Z'), 'maximum watch lifetime'],
            'garbage' => ['next tuesday', 'ISO-8601'],
            'no timezone' => ['2026-03-05T10:00:00', 'ISO-8601'],
            'overflow' => ['2026-02-31T10:00:00Z', 'ISO-8601'],
            'not a string' => [12345, 'ISO-8601'],
        ];
        foreach ($cases as $label => [$value, $needle]) {
            $out = $this->create(self::OWNER_A, $client->id, $asset->id, ['expires_at' => $value]);
            $this->assertStringContainsString($needle, (string) ($out['error'] ?? ''), $label);
        }
        $this->assertSame(0, AssetWatch::query()->count());

        $ok = $this->create(self::OWNER_A, $client->id, $asset->id, ['expires_at' => Carbon::now()->addDays(30)->format('Y-m-d\TH:i:sP')]);
        $this->assertArrayHasKey('watch_id', $ok);
    }

    public function test_bad_state_and_repeat_and_reason_are_refused(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $this->assertStringContainsString('state must be', (string) ($this->create(self::OWNER_A, $client->id, $asset->id, ['state' => 'overdue'])['error'] ?? ''));
        $this->assertStringContainsString('repeat must be', (string) ($this->create(self::OWNER_A, $client->id, $asset->id, ['repeat' => 'yes'])['error'] ?? ''));
        $this->assertStringContainsString('reason is required', (string) ($this->create(self::OWNER_A, $client->id, $asset->id, ['reason' => '  '])['error'] ?? ''));
    }

    // ------------------------------------------------------- owner isolation

    public function test_list_shows_own_watches_only_with_status(): void
    {
        $client = $this->mappedClient();
        $other = $this->mappedClient('Other Synthetic|HQ');
        $a1 = $this->tacticalAsset($client, 'AGENT-SYN-1');
        $a2 = $this->tacticalAsset($other, 'AGENT-SYN-2');
        $mine = $this->create(self::OWNER_A, $client->id, $a1->id)['watch_id'];
        $mine2 = $this->create(self::OWNER_A, $other->id, $a2->id)['watch_id'];
        $theirs = $this->create(self::OWNER_B, $client->id, $a1->id)['watch_id'];
        $this->callAs(self::OWNER_A, 'remove_asset_watch', ['watch_id' => $mine2, 'reason' => 'no longer needed']);

        $all = $this->callAs(self::OWNER_A, 'list_asset_watches', []);
        $this->assertSame([$mine2, $mine], array_column($all['watches'], 'watch_id'));
        $this->assertSame(['removed', 'active'], array_column($all['watches'], 'status'));
        $this->assertNotContains($theirs, array_column($all['watches'], 'watch_id'));

        $filtered = $this->callAs(self::OWNER_A, 'list_asset_watches', ['client_id' => $client->id]);
        $this->assertSame([$mine], array_column($filtered['watches'], 'watch_id'));

        $bad = $this->callAs(self::OWNER_A, 'list_asset_watches', ['client_id' => 'seven']);
        $this->assertArrayHasKey('error', $bad);
    }

    public function test_another_owner_cannot_remove_a_watch_and_is_told_not_found(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $id = $this->create(self::OWNER_A, $client->id, $asset->id)['watch_id'];

        $foreign = $this->callAs(self::OWNER_B, 'remove_asset_watch', ['watch_id' => $id, 'reason' => 'not mine']);
        $missing = $this->callAs(self::OWNER_B, 'remove_asset_watch', ['watch_id' => 999999, 'reason' => 'not mine']);
        $this->assertSame(['error' => 'Asset watch not found.'], $foreign);
        $this->assertSame($missing, $foreign);
        $this->assertSame('active', $this->watchRow($id)->status());

        $own = $this->callAs(self::OWNER_A, 'remove_asset_watch', ['watch_id' => $id, 'reason' => 'done']);
        $this->assertSame('removed', $own['status']);
        $row = $this->watchRow($id);
        $this->assertSame('done', $row->removed_reason);
        $this->assertNull($row->active_key);
        $this->assertSame(1, TechnicianActionLog::query()->where('action_type', 'remove_asset_watch')->count());
    }

    public function test_remove_refuses_a_supplied_client_id(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client);
        $id = $this->create(self::OWNER_A, $client->id, $asset->id)['watch_id'];
        $out = $this->callAs(self::OWNER_A, 'remove_asset_watch', ['watch_id' => $id, 'reason' => 'x', 'client_id' => $client->id]);
        $this->assertStringContainsString('client_id must be omitted', (string) ($out['error'] ?? ''));
        $this->assertSame('active', $this->watchRow($id)->status());
    }

    public function test_a_token_rename_carries_its_watches(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->create(self::OWNER_A, $client->id, $asset->id)['watch_id'];
        $token = \App\Models\McpToken::query()->where('label', self::OWNER_A)->sole();
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->patch(route('settings.mcp-tokens.rename', $token), ['label' => 'synthetic-renamed']);

        $row = $this->watchRow($id);
        $this->assertSame('synthetic-renamed', $row->owner);
        $this->assertSame(AssetWatch::activeKeyFor('synthetic-renamed', $asset->id, 'online'), $row->active_key);
    }
}
