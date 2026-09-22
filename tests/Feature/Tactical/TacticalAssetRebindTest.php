<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TacticalAssetRebindTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Asset $from;

    private Asset $target;

    private TacticalAsset $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->client = Client::factory()->create(['tactical_site_id' => 'Example|Main', 'is_active' => true]);
        $this->from = Asset::factory()->create([
            'client_id' => $this->client->id, 'rmm_online' => true, 'last_seen_at' => now()->subMinutes(5),
            'last_user' => 'fixture-source-user', 'last_boot_at' => now()->subDay(),
        ]);
        $this->target = Asset::factory()->create(['client_id' => $this->client->id, 'rmm_online' => false, 'last_seen_at' => null, 'last_user' => null]);
        $this->agent = TacticalAsset::create(['agent_id' => 'fixture-agent', 'hostname' => 'fixture-host', 'asset_id' => $this->from->id]);
        $this->from->update(['tactical_asset_id' => $this->agent->id]);
    }

    private function runRebind(): array
    {
        return app(StaffPsaActionToolExecutor::class)->execute('rebind_tactical_asset', [
            'asset_id' => $this->from->id, 'target_asset_id' => $this->target->id,
        ], $this->client->id, 'mcp:fixture-operator');
    }

    private function assertUnchanged(): void
    {
        $this->assertSame($this->from->id, $this->agent->fresh()->asset_id);
        $this->assertSame($this->agent->id, $this->from->fresh()->tactical_asset_id);
        $this->assertSame(0, TacticalActionLog::where('action_key', 'tactical.rebind_asset')->count());
    }

    private function occupyTarget(): TacticalAsset
    {
        $this->freezeTime();
        $dead = TacticalAsset::create([
            'agent_id' => 'fixture-displaced', 'hostname' => 'fixture-displaced',
            'asset_id' => $this->target->id,
            'last_seen_at' => now()->subDays(31), 'synced_at' => now()->subHour(),
        ]);
        $this->target->update([
            'tactical_asset_id' => $dead->id, 'rmm_online' => false,
            'last_seen_at' => now()->subDays(31), 'last_user' => 'fixture-old-user',
            'last_boot_at' => now()->subDays(40),
        ]);

        return $dead;
    }

    public function test_dead_reciprocal_target_is_released_and_audited_atomically(): void
    {
        $dead = $this->occupyTarget();
        $evidence = $dead->getRawOriginal();
        $result = $this->runRebind();
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertNull($dead->fresh()->asset_id);
        $this->assertSame($evidence['last_seen_at'], $dead->fresh()->getRawOriginal('last_seen_at'));
        $this->assertSame($evidence['synced_at'], $dead->fresh()->getRawOriginal('synced_at'));
        $this->assertSame($this->agent->id, $this->target->fresh()->tactical_asset_id);
        $this->assertSame($this->target->id, $this->agent->fresh()->asset_id);
        foreach ([$this->from->fresh(), $this->target->fresh()] as $asset) {
            foreach (['rmm_online', 'last_seen_at', 'last_user', 'last_boot_at'] as $field) {
                $this->assertNull($asset->$field, $field);
            }
        }
        $this->assertNull($this->from->fresh()->tactical_asset_id);
        $this->assertSame(2, TacticalActionLog::count());
        $log = TacticalActionLog::where('action_key', 'tactical.release_dead_binding')->sole();
        $this->assertSame($dead->agent_id, $log->agent_id);
        $this->assertSame($this->target->id, $log->asset_id);
        $this->assertSame('mcp:fixture-operator', $log->actor_label);
        $this->assertSame(['from_asset_id' => $this->target->id, 'to_asset_id' => null], $log->params);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedEvidence')]
    public function test_occupied_target_requires_valid_dead_and_fresh_evidence(string $field, ?string $value): void
    {
        $dead = $this->occupyTarget();
        $raw = $value === null || str_starts_with($value, 'invalid')
            ? $value : now()->modify($value)->format('Y-m-d H:i:s');
        // Raw update exercises malformed persisted data without a model cast
        // rejecting the fixture before it reaches the production guard.
        \Illuminate\Support\Facades\DB::table('tactical_assets')->where('id', $dead->id)->update([$field => $raw]);
        $this->assertStringContainsString('already bound', $this->runRebind()['error'] ?? '');
        $this->assertUnchanged();
        $this->assertSame($dead->id, $this->target->fresh()->tactical_asset_id);
        $this->assertSame($this->target->id, $dead->fresh()->asset_id);
        $this->assertSame(0, TacticalActionLog::count());
    }

    public static function refusedEvidence(): array
    {
        return [
            'fresh device' => ['last_seen_at', '-1 day'],
            'exactly thirty days' => ['last_seen_at', '-30 days'],
            'seven to thirty band' => ['last_seen_at', '-10 days'],
            'future device' => ['last_seen_at', '+1 second'],
            'unknown device' => ['last_seen_at', null],
            'malformed device' => ['last_seen_at', 'invalid-date'],
            'stale observer' => ['synced_at', '-49 hours'],
            'exactly forty eight hours' => ['synced_at', '-48 hours'],
            'unknown observer' => ['synced_at', null],
            'malformed observer' => ['synced_at', 'invalid-date'],
            'future observer' => ['synced_at', '+1 second'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inconsistentTargets')]
    public function test_dead_target_still_requires_exclusive_reciprocity(string $shape): void
    {
        $dead = $this->occupyTarget();
        if ($shape === 'forward only') {
            $dead->update(['asset_id' => null]);
        } elseif ($shape === 'wrong reverse') {
            $dead->update(['asset_id' => Asset::factory()->create()->id]);
        } elseif ($shape === 'extra reverse') {
            TacticalAsset::create(['agent_id' => 'fixture-extra', 'hostname' => 'fixture-extra', 'asset_id' => $this->target->id]);
        } else {
            $duplicate = Asset::factory()->create(['tactical_asset_id' => $dead->id]);
            $duplicate->delete();
        }
        $before = $dead->fresh()->asset_id;
        $this->assertStringContainsString('already bound', $this->runRebind()['error'] ?? '');
        $this->assertUnchanged();
        $this->assertSame($dead->id, $this->target->fresh()->tactical_asset_id);
        $this->assertSame($before, $dead->fresh()->asset_id);
        $this->assertSame(0, TacticalActionLog::count());
    }

    public static function inconsistentTargets(): array
    {
        return array_map(fn ($shape) => [$shape], ['forward only', 'wrong reverse', 'extra reverse', 'retired duplicate']);
    }

    public function test_displacement_rolls_back_on_second_audit_failure(): void
    {
        $dead = $this->occupyTarget();
        $before = $this->target->fresh()->getRawOriginal();
        $event = 'eloquent.creating: '.TacticalActionLog::class;
        $writes = 0;
        \Illuminate\Support\Facades\Event::listen($event, function () use (&$writes): void {
            if (++$writes === 2) {
                throw new \RuntimeException('fixture second audit failure');
            }
        });
        try {
            $this->runRebind();
            $this->fail('Expected second audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture second audit failure', $e->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
        $this->assertSame(2, $writes);
        $this->assertUnchanged();
        $this->assertSame($before, $this->target->fresh()->getRawOriginal());
        $this->assertSame($this->target->id, $dead->fresh()->asset_id);
        $this->assertSame(0, TacticalActionLog::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('otherRmm')]
    public function test_displacement_preserves_observations_maintained_by_other_rmm(string $field): void
    {
        $this->occupyTarget();
        $this->target->update([$field => '123']);
        $before = $this->target->fresh()->getRawOriginal();
        $this->assertTrue($this->runRebind()['success'] ?? false);
        foreach (['rmm_online', 'last_seen_at', 'last_user', 'last_boot_at'] as $column) {
            $this->assertSame($before[$column], $this->target->fresh()->getRawOriginal($column));
        }
    }

    public static function otherRmm(): array
    {
        return [['ninja_id'], ['level_id']];
    }

    public function test_staff_mcp_publishes_and_executes_only_with_explicit_grant(): void
    {
        $token = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['rebind_tactical_asset'], label: 'fixture-operator');
        $rpc = fn (string $method, array $params) => $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
        $tools = collect($rpc('tools/list', [])->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools->has('rebind_tactical_asset'));
        $schema = $tools['rebind_tactical_asset']['inputSchema'];
        $this->assertContains('asset_id', $schema['required']);
        $this->assertContains('target_asset_id', $schema['required']);
        $this->assertArrayNotHasKey('client_id', $schema['properties']);
        $args = ['asset_id' => $this->from->id, 'target_asset_id' => $this->target->id];
        $bad = $rpc('tools/call', ['name' => 'rebind_tactical_asset', 'arguments' => $args + ['client_id' => $this->client->id]]);
        $this->assertStringContainsString('client_id must be omitted', $bad->json('result.content.0.text'));
        $this->assertUnchanged();
        $good = $rpc('tools/call', ['name' => 'rebind_tactical_asset', 'arguments' => $args]);
        $result = json_decode($good->json('result.content.0.text'), true);
        $this->assertTrue($result['success'] ?? false, $good->getContent());
        $this->assertSame('mcp-staff:fixture-operator', TacticalActionLog::findOrFail($result['audit_id'])->actor_label);
        $legacy = \App\Support\McpConfig::rotateStaffToken();
        $this->withHeaders(['Authorization' => 'Bearer '.$legacy]);
        $denied = $this->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'rebind_tactical_asset', 'arguments' => $args]]);
        $this->assertTrue($denied->json('result.isError') === true || $denied->json('error') !== null, $denied->getContent());
        $this->assertSame(1, TacticalActionLog::where('action_key', 'tactical.rebind_asset')->count());
    }

    public function test_same_client_refusal(): void
    {
        $this->target->update(['client_id' => Client::factory()->create()->id]);
        $this->assertStringContainsString('same client', $this->runRebind()['error'] ?? '');
        $this->assertUnchanged();
    }

    public function test_forward_occupied_target_refusal(): void
    {
        $other = TacticalAsset::create(['agent_id' => 'other', 'hostname' => 'other']);
        $this->target->update(['tactical_asset_id' => $other->id]);
        $this->assertStringContainsString('already bound', $this->runRebind()['error'] ?? '');
        $this->assertSame($other->id, $this->target->fresh()->tactical_asset_id);
        $this->assertUnchanged();
    }

    public function test_reverse_only_occupied_target_refusal(): void
    {
        $other = TacticalAsset::create(['agent_id' => 'other', 'hostname' => 'other', 'asset_id' => $this->target->id]);
        $this->assertStringContainsString('already bound', $this->runRebind()['error'] ?? '');
        $this->assertSame($this->target->id, $other->fresh()->asset_id);
        $this->assertUnchanged();
    }

    public function test_retired_target_refusal(): void
    {
        $this->target->delete();
        $this->assertStringContainsString('retired', $this->runRebind()['error'] ?? '');
        $this->assertUnchanged();
    }

    public function test_inconsistent_source_refusal(): void
    {
        TacticalAsset::create(['agent_id' => 'other', 'hostname' => 'other', 'asset_id' => $this->from->id]);
        $this->assertStringContainsString('inconsistent', $this->runRebind()['error'] ?? '');
        $this->assertUnchanged();
    }

    public function test_moves_both_links_and_records_actor_time_from_to(): void
    {
        $this->freezeTime();
        $result = $this->runRebind();
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $stranded = $this->from->fresh();
        $this->assertNull($stranded->tactical_asset_id);
        // The agent's own observations must not stay behind on the source: after the
        // repoint no writer touches them there, so they would assert another
        // machine's connectivity and logged-in user indefinitely.
        $this->assertNull($stranded->rmm_online);
        $this->assertNull($stranded->last_seen_at);
        $this->assertNull($stranded->last_user);
        $this->assertNull($stranded->last_boot_at);
        $this->assertSame($this->agent->id, $this->target->fresh()->tactical_asset_id);
        $this->assertSame($this->target->id, $this->agent->fresh()->asset_id);
        $log = TacticalActionLog::findOrFail($result['audit_id']);
        $this->assertSame('tactical.rebind_asset', $log->action_key);
        $this->assertSame('mcp:fixture-operator', $log->actor_label);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $log->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['from_asset_id' => $this->from->id, 'to_asset_id' => $this->target->id], $log->params);
        $this->assertSame(2, Asset::withTrashed()->count());
    }

    public function test_audit_failure_rolls_back_all_links(): void
    {
        // Fail at the LAST write, not at validation: without the transaction
        // every backlink has already moved by the time this exception fires.
        $event = 'eloquent.creating: '.TacticalActionLog::class;
        \Illuminate\Support\Facades\Event::listen($event, function (): void {
            throw new \RuntimeException('fixture audit failure');
        });
        try {
            $this->runRebind();
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture audit failure', $e->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
        $this->assertUnchanged();
        $this->assertNull($this->target->fresh()->tactical_asset_id);
    }

    public function test_retired_source_rebind_recovers_actual_next_sync_refresh(): void
    {
        $this->from->delete();
        $result = $this->runRebind();
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        // Producer: amidaware/tacticalrmm e56ebd3e, agents/serializers.py
        // AgentTableSerializer.Meta.fields + get_logged_username, agents/models.py
        // Agent.last_seen DateTimeField and status property. Synthetic values,
        // subset of that producer's list projection; never a live vendor call.
        $payload = [[
            'agent_id' => 'fixture-agent', 'hostname' => 'fixture-host',
            'client_name' => 'Example', 'site_name' => 'Main',
            'status' => 'online', 'last_seen' => '2026-09-22T03:00:00Z',
            'logged_username' => 'fixture-user', 'plat' => 'windows',
            'monitoring_type' => 'workstation',
        ]];
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($payload))])),
        ]);
        $sync = new TacticalDeviceSyncService(new TacticalClient($http));
        $syncResult = $sync->syncDevices();
        $this->assertSame(0, $syncResult->errors);
        $this->target->refresh();
        $this->assertSame($this->agent->id, $this->target->tactical_asset_id);
        $this->assertTrue((bool) $this->target->rmm_online);
        $this->assertSame('2026-09-22 03:00:00', $this->target->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('fixture-user', $this->target->last_user);
        $old = Asset::withTrashed()->findOrFail($this->from->id);
        $this->assertTrue($old->trashed());
        $this->assertNull($old->tactical_asset_id);
        // The real sync refreshed the TARGET above; it must not restore the source's
        // released observations, which is the only writer that could have.
        $this->assertNull($old->rmm_online);
        $this->assertNull($old->last_seen_at);
        $this->assertNull($old->last_user);
        $this->assertSame(2, Asset::withTrashed()->count());
    }

    public function test_sync_refresh_rereads_the_binding_when_a_rebind_commits_mid_run(): void
    {
        // Stands in for the second process we cannot schedule here: the rebind lands
        // after this run has already read the binding, so only the re-read INSIDE the
        // refresh transaction keeps the run off the released source. Row-lock
        // scheduling itself is not certifiable on SQLite and is not claimed.
        $fired = false;
        $rebind = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$fired, &$rebind): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $rebind = $this->runRebind();
        });

        // Same synthetic subset of amidaware/tacticalrmm e56ebd3e's
        // AgentTableSerializer list projection as the refresh control above.
        $payload = [[
            'agent_id' => 'fixture-agent', 'hostname' => 'fixture-host',
            'client_name' => 'Example', 'site_name' => 'Main',
            'status' => 'online', 'last_seen' => '2026-09-22T03:00:00Z',
            'logged_username' => 'fixture-user', 'plat' => 'windows',
            'monitoring_type' => 'workstation',
        ]];
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($payload))])),
        ]);

        try {
            $syncResult = (new TacticalDeviceSyncService(new TacticalClient($http)))->syncDevices();
        } finally {
            \Illuminate\Support\Facades\Event::forget(\Illuminate\Database\Events\TransactionBeginning::class);
        }

        $this->assertTrue($rebind['success'] ?? false, 'The interleaved rebind did not commit: '.json_encode($rebind));
        $this->assertSame(0, $syncResult->errors);
        $source = $this->from->fresh();
        $this->assertNull($source->tactical_asset_id);
        $this->assertNull($source->rmm_online);
        $this->assertNull($source->last_seen_at);
        $this->assertNull($source->last_user);
        $target = $this->target->fresh();
        $this->assertTrue((bool) $target->rmm_online);
        $this->assertSame('2026-09-22 03:00:00', $target->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('fixture-user', $target->last_user);
    }
}
