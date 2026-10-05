<?php

namespace Tests\Feature\Assets;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Services\Assets\AssetWatchEvaluator;
use App\Services\Signals\SignalRelayMatrix;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Firing semantics of asset watches (card K3VEcxtw): the shared evaluator, the
 * per-minute poller (assets:poll-watched), the full sync hook, expiry and
 * owner-only delivery. Fixtures are synthetic (see AssetWatchFixtures).
 */
class AssetWatchEvaluatorTest extends TestCase
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

    private function evaluator(): AssetWatchEvaluator
    {
        return app(AssetWatchEvaluator::class);
    }

    /** Create a watch through the real MCP tool as $owner and return its id. */
    private function watch(string $owner, int $clientId, int $assetId, string $state = 'online', array $extra = []): int
    {
        $out = $this->callAs($owner, 'create_asset_watch', array_merge([
            'client_id' => $clientId, 'asset_id' => $assetId, 'state' => $state, 'reason' => 'synthetic wake window',
        ], $extra));
        $this->assertArrayHasKey('watch_id', $out, json_encode($out));

        return (int) $out['watch_id'];
    }

    private function poll(): void
    {
        $this->artisan('assets:poll-watched')->assertSuccessful();
    }

    // ------------------------------------------------------------- online fires

    public function test_an_online_transition_fires_once_to_the_owner_inbox_with_the_observation(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);

        $seen = Carbon::now()->subSeconds(30);
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, $seen, 'poll'));

        $rows = $this->inboxFor(self::OWNER_A);
        $this->assertCount(1, $rows);
        $this->assertSame('asset.watch_fired', $rows[0]->payload['event']);
        $this->assertSame(['type' => $asset->getMorphClass(), 'id' => $asset->id], $rows[0]->payload['entity']);
        $this->assertSame($id, $rows[0]->payload['watch']['watch_id']);
        $this->assertSame('online', $rows[0]->payload['watch']['state']);
        $this->assertSame(30, $rows[0]->payload['watch']['age_seconds']);
        $this->assertSame($seen->copy()->utc()->toIso8601String(), $rows[0]->payload['watch']['last_seen']);

        // The owner drains it through poll_signals, watch block included.
        $polled = $this->callAs(self::OWNER_A, 'poll_signals', []);
        $this->assertCount(1, $polled['signals']);
        $this->assertSame($id, $polled['signals'][0]['watch']['watch_id']);
    }

    public function test_steady_online_does_not_refire_and_a_one_shot_is_marked_fired(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);

        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now()->subSeconds(5), 'poll'));
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now()->subSeconds(5), 'poll'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, Carbon::now()->subMinutes(6), 'poll'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now()->subSeconds(5), 'poll'));

        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
        $row = $this->watchRow($id);
        $this->assertNotNull($row->fired_at);
        $this->assertNull($row->active_key);
        $this->assertSame(1, $row->fire_count);
        $this->assertSame('fired', $row->status());
    }

    public function test_a_stale_last_seen_does_not_fire_and_the_watch_stays_armed(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);

        // online, but last_seen 121s old: the PSA would read Online; Tactical may not.
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now()->subSeconds(121), 'sync'));
        // online with no last_seen at all: no freshness evidence, no fire.
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, null, 'sync'));

        $this->assertCount(0, $this->inboxFor(self::OWNER_A));
        $this->assertSame('active', $this->watchRow($id)->status());
        $this->assertNull($this->watchRow($id)->fired_at);

        // Exactly at the 120s bound it fires.
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now()->subSeconds(120), 'sync'));
    }

    public function test_a_repeat_watch_rearms_only_after_the_opposite_state(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'online', ['repeat' => true]);

        $fresh = fn () => Carbon::now()->subSeconds(10);
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, $fresh(), 'poll'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, $fresh(), 'poll'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, null, 'poll'));
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, $fresh(), 'poll'));

        $row = $this->watchRow($id);
        $this->assertSame(2, $row->fire_count);
        $this->assertSame('active', $row->status());
        $this->assertCount(2, $this->inboxFor(self::OWNER_A));
    }

    // ------------------------------------------------------------ offline fires

    public function test_an_offline_watch_fires_on_a_true_to_false_transition_only(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', true);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'offline');
        $this->assertTrue($this->watchRow($id)->last_observed_state, 'starts from the stored flag');

        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'sync'));
        $this->assertSame(1, $this->evaluator()->observe($asset->id, false, Carbon::now()->subMinutes(5), 'sync'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, Carbon::now()->subMinutes(6), 'sync'));
        $this->assertSame('fired', $this->watchRow($id)->status());
    }

    public function test_an_offline_watch_on_a_device_already_down_waits_for_it_to_be_seen_online(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $this->watch(self::OWNER_A, $client->id, $asset->id, 'offline');

        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, null, 'sync'));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'sync'));
        $this->assertSame(1, $this->evaluator()->observe($asset->id, false, null, 'sync'));
    }

    // ------------------------------------------------------------------- expiry

    public function test_an_expired_watch_never_fires_and_the_prune_marks_it_expired(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'online', [
            'expires_at' => Carbon::now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        Carbon::setTestNow(Carbon::now()->addHour()->addSecond());
        // Before the prune has run, the evaluator itself refuses a lapsed watch.
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll'));
        $this->assertCount(0, $this->inboxFor(self::OWNER_A));
        $this->assertSame('expired', $this->watchRow($id)->status());

        $this->artisan('assets:expire-watches')->assertSuccessful();
        $row = $this->watchRow($id);
        $this->assertNull($row->active_key);
        $this->assertNotNull($row->expired_at);
        $this->assertSame('expired', $row->status());
    }

    // ---------------------------------------------------- owner-only delivery

    public function test_another_owners_inbox_gets_nothing_and_broadcast_routes_never_pick_it_up(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $this->tokenFor(self::OWNER_B);

        // Every broadcast shape that exists: an operator route on ALL types, an
        // operator route naming the type explicitly (only constructible directly),
        // and B's relay-matrix route, each pointed at B's MCP destination.
        $bDest = SignalDestination::create(['label' => 'B inbox', 'type' => 'mcp', 'mcp_token_label' => self::OWNER_B, 'enabled' => true]);
        foreach ([['types' => 'all'], ['types' => ['asset.watch_fired']]] as $i => $filter) {
            $route = SignalRoute::create(['label' => "broadcast {$i}", 'event_filter' => $filter, 'enabled' => true, 'cooldown_seconds' => 0]);
            SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 1, 'destination_id' => $bDest->id]);
        }
        app(SignalRelayMatrix::class)->setRelay(self::OWNER_B, 'ticket.created', true);

        $this->watch(self::OWNER_A, $client->id, $asset->id);
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll'));

        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
        $this->assertCount(0, $this->inboxFor(self::OWNER_B));
        $event = SignalEvent::query()->where('type_key', 'asset.watch_fired')->sole();
        $this->assertSame(0, SignalDelivery::query()->where('event_id', $event->id)->whereNotNull('route_id')->count());
        $this->assertSame(1, SignalDelivery::query()->where('event_id', $event->id)->count());
        $this->assertSame([], $this->callAs(self::OWNER_B, 'poll_signals', [])['signals']);
    }

    public function test_a_revoked_owner_gets_a_suppressed_delivery_not_an_inbox_row(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $this->watch(self::OWNER_A, $client->id, $asset->id);
        \App\Models\McpToken::query()->where('label', self::OWNER_A)->update(['revoked_at' => now()]);

        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll'));
        $this->assertCount(0, $this->inboxFor(self::OWNER_A));
        $this->assertSame('mcp-token-revoked', SignalDelivery::query()->sole()->error);
    }

    // ------------------------------------------------------------------ poller

    public function test_a_revoke_retires_the_watches_and_a_reminted_label_starts_clean_and_receives_its_fires(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $old = $this->watch(self::OWNER_A, $client->id, $asset->id, 'online', ['repeat' => true]);
        // A fire before the revoke creates the owner's destination, which the revoke disables.
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll'));

        $token = \App\Models\McpToken::query()->where('label', self::OWNER_A)->sole();
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']))
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect();

        $row = $this->watchRow($old);
        $this->assertSame('removed', $row->status());
        $this->assertSame('token-revoked', $row->removed_reason);
        $this->assertNull($row->active_key);
        $this->assertNotSame(self::OWNER_A, $row->owner);
        $this->assertFalse(\App\Models\AssetWatch::query()->armed()->exists(), 'nothing is left for the poller to read');

        // Break-glass rotation mints the same label again.
        $this->tokens[self::OWNER_A] = \App\Support\McpConfig::rotateStaffToken(allowedTools: self::WATCH_TOOLS, label: self::OWNER_A);
        $this->assertSame(0, $this->callAs(self::OWNER_A, 'list_asset_watches', [])['count']);

        $new = $this->watch(self::OWNER_A, $client->id, $asset->id);
        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll'));

        $this->assertNotSame('suppressed', SignalDelivery::query()->latest('id')->first()->status);
        $signals = $this->callAs(self::OWNER_A, 'poll_signals', [])['signals'];
        $this->assertSame([$new], array_map(fn (array $s) => $s['watch']['watch_id'], $signals));
    }

    public function test_the_poller_is_a_no_op_with_no_watches(): void
    {
        // Tactical assets exist, but none has an ARMED watch: one is unwatched,
        // one's watch was removed, one's watch has lapsed (prune not yet run).
        $client = $this->mappedClient();
        $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $removed = $this->tacticalAsset($client, 'AGENT-SYN-2', false);
        $lapsed = $this->tacticalAsset($client, 'AGENT-SYN-3', false);
        $rid = $this->watch(self::OWNER_A, $client->id, $removed->id);
        $this->callAs(self::OWNER_A, 'remove_asset_watch', ['watch_id' => $rid, 'reason' => 'done']);
        $this->watch(self::OWNER_A, $client->id, $lapsed->id, 'online', ['expires_at' => Carbon::now()->addMinute()->utc()->format('Y-m-d\TH:i:s\Z')]);
        Carbon::setTestNow(Carbon::now()->addMinutes(2));

        // Responses are queued so any read WOULD be recorded by the history middleware.
        $this->bindTactical([
            $this->agentResponse('AGENT-SYN-1', 'online', Carbon::now()),
            $this->agentResponse('AGENT-SYN-2', 'online', Carbon::now()),
            $this->agentResponse('AGENT-SYN-3', 'online', Carbon::now()),
        ]);
        $this->poll();
        $this->assertSame([], $this->tacticalRequests);
        $this->assertCount(0, $this->inboxFor(self::OWNER_A));
    }

    public function test_the_poller_fetches_only_watched_agents_bounded_and_writes_no_asset_column(): void
    {
        config(['asset_watch.poll_max_agents' => 2, 'asset_watch.poll_timeout_seconds' => 2]);
        $client = $this->mappedClient();
        $assets = [];
        foreach (['AGENT-SYN-1', 'AGENT-SYN-2', 'AGENT-SYN-3'] as $agent) {
            $assets[] = $this->tacticalAsset($client, $agent, false);
        }
        $this->tacticalAsset($client, 'AGENT-UNWATCHED', false);
        foreach ($assets as $asset) {
            $this->watch(self::OWNER_A, $client->id, $asset->id);
        }
        $before = $assets[0]->fresh()->only(['rmm_online', 'last_seen_at', 'last_boot_at']);
        $taBefore = \App\Models\TacticalAsset::query()->where('agent_id', 'AGENT-SYN-1')->first()->only(['status', 'last_seen_at', 'synced_at']);

        $this->bindTactical([
            $this->agentResponse('AGENT-SYN-1', 'online', Carbon::now()->subSeconds(20)),
            $this->agentResponse('AGENT-SYN-2', 'offline', Carbon::now()->subMinutes(10)),
        ]);
        $this->poll();

        $this->assertCount(2, $this->tacticalRequests);
        $paths = array_map(fn (array $t) => $t['request']->getUri()->getPath(), $this->tacticalRequests);
        $this->assertSame(['/api/agents/AGENT-SYN-1/', '/api/agents/AGENT-SYN-2/'], $paths);
        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
        $this->assertSame($before, $assets[0]->fresh()->only(['rmm_online', 'last_seen_at', 'last_boot_at']));
        $this->assertSame($taBefore, \App\Models\TacticalAsset::query()->where('agent_id', 'AGENT-SYN-1')->first()->only(['status', 'last_seen_at', 'synced_at']));

        // Next run: least recently checked first, so the third agent is reached.
        $this->bindTactical([
            $this->agentResponse('AGENT-SYN-3', 'offline', null),
            $this->agentResponse('AGENT-SYN-2', 'offline', null),
        ]);
        $this->poll();
        $this->assertSame('/api/agents/AGENT-SYN-3/', $this->tacticalRequests[0]['request']->getUri()->getPath());
    }

    public function test_the_poller_survives_an_unreachable_agent_and_says_so_loudly(): void
    {
        Log::spy();
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);
        $this->bindTactical([new Response(503, [], '')]);
        $this->artisan('assets:poll-watched')->assertFailed();
        $this->assertSame('active', $this->watchRow($id)->status());

        Log::shouldHaveReceived('warning')->with('[AssetWatch] Poll read failed', \Mockery::on(fn ($c) => ($c['asset_id'] ?? null) === $asset->id))->once();
        Log::shouldHaveReceived('error')->with('[AssetWatch] Poll degraded: no usable read', \Mockery::any())->once();
    }

    public function test_the_poller_does_not_count_a_read_without_a_usable_status_as_polled(): void
    {
        Log::spy();
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);
        $this->bindTactical([new Response(200, [], json_encode(['agent_id' => 'AGENT-SYN-1', 'hostname' => 'WS-SYN']))]);

        $this->artisan('assets:poll-watched')
            ->expectsOutputToContain('polled 0, failed 0, unusable 1')
            ->assertFailed();

        Log::shouldHaveReceived('warning')->with('[AssetWatch] Poll read returned no usable status', \Mockery::on(fn ($c) => ($c['asset_id'] ?? null) === $asset->id && ($c['status'] ?? null) === 'null'))->once();
        $this->assertCount(0, $this->inboxFor(self::OWNER_A));
        $this->assertSame('active', $this->watchRow($id)->status());
    }

    public function test_an_unreadable_watched_asset_does_not_hold_the_head_of_the_rotation(): void
    {
        config(['asset_watch.poll_max_agents' => 1]);
        $client = $this->mappedClient();
        $broken = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $healthy = $this->tacticalAsset($client, 'AGENT-SYN-2', false);
        $this->watch(self::OWNER_A, $client->id, $broken->id);
        $this->watch(self::OWNER_A, $client->id, $healthy->id);

        $this->bindTactical([new Response(503, [], '')]);
        $this->artisan('assets:poll-watched')->assertFailed();
        $this->assertSame('/api/agents/AGENT-SYN-1/', $this->tacticalRequests[0]['request']->getUri()->getPath());

        // The failed read still counts as a check, so the next run reaches the other asset.
        $this->bindTactical([$this->agentResponse('AGENT-SYN-2', 'online', Carbon::now()->subSeconds(10))]);
        $this->poll();
        $this->assertSame(['/api/agents/AGENT-SYN-2/'], array_map(fn (array $t) => $t['request']->getUri()->getPath(), $this->tacticalRequests));
        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
    }

    // ----------------------------------------------------- sync + poller race

    public function test_sync_and_poller_observing_the_same_transition_fire_once(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);
        $seen = Carbon::now()->subSeconds(15);

        // Full sync sees it online first...
        $this->bindTactical([new Response(200, [], json_encode($this->listAgentsPayload($client, 'AGENT-SYN-1', 'online', $seen, $asset->hostname)))]);
        $sync = app(TacticalDeviceSyncService::class);
        $sync->syncDevices();
        $this->assertTrue((bool) $asset->fresh()->rmm_online, 'the sync wrote the flag the watch reads');

        // ...then the poller reads the same wake.
        $this->bindTactical([$this->agentResponse('AGENT-SYN-1', 'online', $seen)]);
        $this->poll();

        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
        $this->assertSame(1, $this->watchRow($id)->fire_count);
        $this->assertSame(1, SignalEvent::query()->where('type_key', 'asset.watch_fired')->count());
    }

    /**
     * A REPEAT watch keeps its active_key and has no fired_at guard, so only the
     * per-transition last-state condition stops the second observer of the same
     * wake from firing again.
     */
    public function test_sync_and_poller_observing_the_same_transition_fire_a_repeat_watch_once(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'online', ['repeat' => true]);
        $seen = Carbon::now()->subSeconds(15);

        $this->bindTactical([new Response(200, [], json_encode($this->listAgentsPayload($client, 'AGENT-SYN-1', 'online', $seen, $asset->hostname)))]);
        app(TacticalDeviceSyncService::class)->syncDevices();
        $this->bindTactical([$this->agentResponse('AGENT-SYN-1', 'online', $seen)]);
        $this->poll();

        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
        $this->assertSame(1, $this->watchRow($id)->fire_count);
        $this->assertSame('active', $this->watchRow($id)->status());
    }

    /**
     * The sync works through an agent list read at the start of its run while the
     * poller reads live, so an observation can arrive after a newer one.
     */
    public function test_an_older_observation_arriving_after_a_newer_one_does_not_fire_an_offline_watch(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'offline');
        $listReadAt = Carbon::now();

        // The poller reads it online 30s after the sync read its list...
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll', $listReadAt->copy()->addSeconds(30)));
        // ...then the sync reaches the agent with the 'offline' from its list.
        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, null, 'sync', $listReadAt));
        $this->assertTrue($this->watchRow($id)->last_observed_state);
        $this->assertCount(0, $this->inboxFor(self::OWNER_A));

        // A newer offline read still fires.
        $this->assertSame(1, $this->evaluator()->observe($asset->id, false, null, 'poll', $listReadAt->copy()->addSeconds(90)));
    }

    public function test_an_older_offline_observation_does_not_rearm_a_repeat_online_watch(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id, 'online', ['repeat' => true]);
        $t0 = Carbon::now();

        $this->assertSame(1, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll', $t0));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll', $t0->copy()->addSeconds(60)));
        // A sync whose list was read at t0+30 still says offline for this agent.
        $this->assertSame(0, $this->evaluator()->observe($asset->id, false, null, 'sync', $t0->copy()->addSeconds(30)));
        $this->assertSame(0, $this->evaluator()->observe($asset->id, true, Carbon::now(), 'poll', $t0->copy()->addSeconds(120)));

        $this->assertSame(1, $this->watchRow($id)->fire_count);
        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
    }

    public function test_the_full_sync_fires_a_watch_and_an_unwatched_fleet_is_untouched(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $this->watch(self::OWNER_A, $client->id, $asset->id);

        $this->bindTactical([new Response(200, [], json_encode($this->listAgentsPayload($client, 'AGENT-SYN-1', 'online', Carbon::now()->subSeconds(40), $asset->hostname)))]);
        app(TacticalDeviceSyncService::class)->syncDevices();

        $rows = $this->inboxFor(self::OWNER_A);
        $this->assertCount(1, $rows);
        $this->assertSame('sync', $rows[0]->payload['watch']['observed_by']);
    }

    public function test_the_full_sync_does_not_fire_on_a_stale_online_report(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', false);
        $id = $this->watch(self::OWNER_A, $client->id, $asset->id);

        $this->bindTactical([new Response(200, [], json_encode($this->listAgentsPayload($client, 'AGENT-SYN-1', 'online', Carbon::now()->subMinutes(3), $asset->hostname)))]);
        app(TacticalDeviceSyncService::class)->syncDevices();

        $this->assertTrue((bool) $asset->fresh()->rmm_online, 'the PSA reads Online...');
        $this->assertCount(0, $this->inboxFor(self::OWNER_A), '...but the watch does not claim reachable-now');
        $this->assertSame('active', $this->watchRow($id)->status());
    }

    public function test_the_full_sync_offline_overdue_transition_fires_an_offline_watch(): void
    {
        $client = $this->mappedClient();
        $asset = $this->tacticalAsset($client, 'AGENT-SYN-1', true);
        $this->watch(self::OWNER_A, $client->id, $asset->id, 'offline');

        $this->bindTactical([new Response(200, [], json_encode($this->listAgentsPayload($client, 'AGENT-SYN-1', 'overdue', Carbon::now()->subHour(), $asset->hostname)))]);
        app(TacticalDeviceSyncService::class)->syncDevices();

        $this->assertFalse((bool) $asset->fresh()->rmm_online);
        $this->assertCount(1, $this->inboxFor(self::OWNER_A));
    }
}
