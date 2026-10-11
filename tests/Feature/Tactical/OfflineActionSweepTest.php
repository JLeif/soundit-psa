<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Tactical\OfflineActionSweep;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Reconnect-run + expiry sweep for the offline-script queue (bd psa-xr84). A queued
 * action runs through the SAME gate-checked path as a live approval when its device
 * returns online; it never auto-runs past its safety window.
 */
class OfflineActionSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        $this->approver = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->approver->id);
        TacticalScript::firstOrCreate(['tactical_script_id' => 201], [
            'name' => 'Disk Health', 'shell' => 'powershell', 'hidden' => false, 'synced_at' => now(),
        ]);
    }

    /** @var array<string, array{0: Client, 1: Asset}> agentId → [client, asset], so multiple queued runs on one agent share the one device (agent_id is unique). */
    private array $endpoints = [];

    private function endpointFor(string $agentId, string $status): array
    {
        if (! isset($this->endpoints[$agentId])) {
            $client = Client::factory()->create();
            $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-'.$agentId, 'name' => 'PC-'.$agentId]);
            TacticalAsset::create([
                'asset_id' => $asset->id, 'agent_id' => $agentId, 'hostname' => 'PC-'.$agentId,
                'status' => $status, 'synced_at' => now(),
            ]);
            $this->endpoints[$agentId] = [$client, $asset];
        }

        return $this->endpoints[$agentId];
    }

    private function queuedRun(string $agentId = 'agent-1', string $status = 'online', ?CarbonInterface $expiresAt = null, ?string $dedupKey = null, string $args = '-Check Disk'): TechnicianRun
    {
        [$client, $asset] = $this->endpointFor($agentId, $status);
        $ticket = Ticket::factory()->for($client)->create();
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        $payload = [
            'direct_tool' => 'tactical_run_script',
            'asset_id' => $asset->id,
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            // args stored as the raw string exactly as staging persists it; the action
            // argv-tokenizes it at dispatch time.
            'params' => ['tactical_script_id' => 201, 'args' => $args, 'timeout' => 120],
        ];

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => hash('sha256', 'run-'.$ticket->id),
            'state' => TechnicianRunState::QueuedOffline,
            'proposed_meta' => [
                'encrypted_payload' => Crypt::encryptString((string) json_encode($payload)),
                'queued_approver_id' => $this->approver->id,
            ],
            'queued_agent_id' => $agentId,
            'queued_dedup_key' => $dedupKey ?? hash('sha256', 'dedup-'.$ticket->id),
            'queued_at' => now()->subMinutes(30),
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);
    }

    private function fakeClient(callable $expect): void
    {
        $mock = Mockery::mock(TacticalClient::class);
        $expect($mock);
        $this->app->instance(TacticalClient::class, $mock);
    }

    public function test_sweep_runs_a_queued_action_when_the_agent_is_online(): void
    {
        $run = $this->queuedRun('agent-1', 'online');
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()
            ->with('agent-1', 201, ['-Check', 'Disk'], 120)->andReturn(['stdout' => 'Healthy', 'retcode' => 0]));

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(1, $ran);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_script', 'agent_id' => 'agent-1', 'result_status' => 'ok',
        ]);
    }

    public function test_still_offline_reconnect_requeues_preserving_the_expiry(): void
    {
        $run = $this->queuedRun('agent-1', 'online');
        $originalExpiry = $run->expires_at->timestamp;
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()
            ->andThrow(new TacticalClientException('offline', transportFailure: true)));

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(0, $ran);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame($originalExpiry, $fresh->expires_at->timestamp, 'expiry window must not reset on a failed reconnect');
    }

    /**
     * #6506: the reconnect-sweep arm of the AUDIT_NOT_WRITTEN note (#6312 / #6507). The sweep
     * re-runs a queued action, the device is still offline, the run is queued again, and then the
     * technician_action_logs insert throws. The sweep reads only the result's status, so the
     * executor is wrapped to record what runQueuedOnReconnect() returned to it: the queued_offline
     * result with the note as its whole message. One error line names the exception's class only
     * (C-56); the run is back in the queue with its window kept; the bus's own tactical_action_logs
     * row for the call exists, which is why the note names the technician action log row.
     */
    public function test_a_reconnect_run_whose_audit_insert_fails_still_reports_queued_with_the_note(): void
    {
        $run = $this->queuedRun('agent-1', 'online');
        $originalExpiry = $run->expires_at->timestamp;
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()
            ->andThrow(new TacticalClientException('offline', transportFailure: true)));
        DB::beforeExecuting(function (string $sql, array $bindings): void {
            if (str_contains($sql, 'insert into "technician_action_logs"') && in_array('queued_offline', $bindings, true)) {
                throw new \RuntimeException('synthetic audit failure SECRET-6506-MARKER');
            }
        });
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });
        $real = app(StaffTacticalActionToolExecutor::class);
        $results = new \ArrayObject;
        $executor = Mockery::mock(StaffTacticalActionToolExecutor::class);
        $executor->shouldReceive('runQueuedOnReconnect')->once()->andReturnUsing(function (TechnicianRun $queued) use ($real, $results) {
            return $results[] = $real->runQueuedOnReconnect($queued);
        });

        $ran = (new OfflineActionSweep($executor))->sweepAgent('agent-1');

        $this->assertSame(0, $ran);
        $this->assertCount(1, $results);
        $this->assertSame('queued_offline', $results[0]->status);
        $this->assertSame('The technician action log row for this request was not written.', $results[0]->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'queued again for the next sweep');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame($originalExpiry, $fresh->expires_at->timestamp, 'the window is kept');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'positive control: the insert really failed');
        $this->assertDatabaseHas('tactical_action_logs', ['action_key' => 'tactical.run_script', 'agent_id' => 'agent-1', 'result_status' => 'offline']);
        $records = array_values(array_filter($logged->getArrayCopy(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame('[Tactical] offline queue audit row not written', $records[0]->message);
        $this->assertSame(['run_id' => $run->id, 'status' => 'queued_offline', 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertSame([], array_values(array_filter($logged->getArrayCopy(), fn (MessageLogged $m) => str_contains($m->message, 'reconnect-run failed'))), 'nothing escaped to the sweep');
        $this->assertStringNotContainsString('SECRET-6506-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged->getArrayCopy())) ?: '');
    }

    public function test_expire_due_marks_past_window_runs_without_running_them(): void
    {
        $run = $this->queuedRun('agent-1', 'online', now()->subDay());
        $this->fakeClient(fn ($m) => $m->shouldNotReceive('runScript'));

        // sweepAgent skips a run past its window (never auto-runs stale)...
        $this->assertSame(0, app(OfflineActionSweep::class)->sweepAgent('agent-1'));
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);

        // ...expireDue terminates it as expired for cockpit re-confirm.
        $this->assertSame(1, app(OfflineActionSweep::class)->expireDue());
        $this->assertSame(TechnicianRunState::Expired, $run->fresh()->state);
    }

    public function test_sweep_due_runs_online_agents_and_leaves_offline_ones_queued(): void
    {
        $online = $this->queuedRun('agent-online', 'online');
        $stillOffline = $this->queuedRun('agent-offline', 'offline');
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()->andReturn(['stdout' => 'ok', 'retcode' => 0]));

        $summary = app(OfflineActionSweep::class)->sweepDue();

        $this->assertSame(1, $summary['ran']);
        $this->assertSame(TechnicianRunState::Done, $online->fresh()->state);
        $this->assertSame(TechnicianRunState::QueuedOffline, $stillOffline->fresh()->state);
    }

    public function test_sweep_is_a_no_op_when_the_feature_is_disabled(): void
    {
        Setting::setValue('tactical_offline_queue_enabled', '0');
        $run = $this->queuedRun('agent-1', 'online');
        $this->fakeClient(fn ($m) => $m->shouldNotReceive('runScript'));

        $this->assertSame(0, app(OfflineActionSweep::class)->sweepAgent('agent-1'));
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_run_time_dedup_runs_one_and_supersedes_a_duplicate_dedup_key(): void
    {
        // Two rows with the SAME dedup key (a concurrent parking race that slipped past
        // the enqueue-time coalesce) — only one runs; the duplicate is superseded.
        $first = $this->queuedRun('agent-1', 'online', dedupKey: 'shared-key');
        $second = $this->queuedRun('agent-1', 'online', dedupKey: 'shared-key');
        $this->fakeClient(fn ($m) => $m->shouldReceive('runScript')->once()->andReturn(['stdout' => 'ok', 'retcode' => 0]));

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(1, $ran);
        $this->assertSame(TechnicianRunState::Done, $first->fresh()->state);
        $this->assertSame(TechnicianRunState::Superseded, $second->fresh()->state);
    }

    public function test_a_failing_reconnect_run_does_not_abort_the_rest_of_the_sweep(): void
    {
        // Distinct dedup keys → both processed. The first throws a non-offline error;
        // the second must still run (per-item isolation, mirrors EmergencySweep).
        $failing = $this->queuedRun('agent-1', 'online', dedupKey: 'k1', args: 'FAIL');
        $healthy = $this->queuedRun('agent-1', 'online', dedupKey: 'k2', args: 'OK');
        $this->fakeClient(function ($m) {
            $m->shouldReceive('runScript')->with('agent-1', 201, ['FAIL'], 120)->andThrow(new \RuntimeException('boom'));
            $m->shouldReceive('runScript')->with('agent-1', 201, ['OK'], 120)->andReturn(['stdout' => 'ok', 'retcode' => 0]);
        });

        $ran = app(OfflineActionSweep::class)->sweepAgent('agent-1');

        $this->assertSame(1, $ran, 'the healthy run still executes despite the failing one');
        $this->assertSame(TechnicianRunState::Done, $healthy->fresh()->state);
        // The thrower was released back to the queue for a later retry.
        $this->assertSame(TechnicianRunState::QueuedOffline, $failing->fresh()->state);
    }
}
