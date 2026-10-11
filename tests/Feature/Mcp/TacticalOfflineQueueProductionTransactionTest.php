<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * #6508 / #6516, r1 diff:2 / context:4 / contract:4 / contract:5: the outer-transaction guard
 * under the transaction manager production binds. This class does NOT use RefreshDatabase: it
 * migrates its own fresh :memory: connection, so 'db.transactions' is the framework's base
 * Illuminate\Database\DatabaseTransactionsManager, no wrapping test transaction exists, and the
 * connection's transaction level is 0 at the call, as in production. #6661: each test method calls
 * assertProductionManager() as its first statement (setUp() has already run the migration),
 * and again immediately before the measured call. That check reads two things only: the class of the
 * container's 'db.transactions' binding, and transactionLevel() on the default connection
 * (DB::connection()); it does not read the run's own connection.
 *
 * What it measures: (a) with no outer transaction the guard does not fire, through the real
 * callers (the cockpit approve route, and SweepQueuedActionsForAgent::dispatchSync for the
 * reconnect path); (b) with exactly one outer DB::transaction (level 1) the guard fires. #6660:
 * on the approval path (b) calls TechnicianApprovalService::approveStagedTacticalAction()
 * directly, not the cockpit route. On the reconnect path (b) is measured twice: once calling
 * StaffTacticalActionToolExecutor::runQueuedOnReconnect() directly, and once through
 * SweepQueuedActionsForAgent::dispatchSync() on the sync queue, with the outer transaction
 * around the dispatch.
 * TacticalOfflineQueueApprovalTest measures the same guard under the RefreshDatabase testing
 * manager; a guard reading transactionLevel() > 1 passes there and fails (b) here.
 */
class TacticalOfflineQueueProductionTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    private function assertProductionManager(): void
    {
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')), 'the base manager production binds, not the RefreshDatabase one');
        $this->assertSame(0, DB::connection()->transactionLevel(), 'no wrapping test transaction');
    }

    /** @return array{approver: User, run: TechnicianRun} */
    private function stagedOfflineRun(): array
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'fixture-not-a-key');
        $approver = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $approver->id);

        $client = Client::factory()->create(['name' => 'Acme']);
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User, 'first_name' => 'Client',
            'last_name' => 'Contact', 'email' => 'client@example.test', 'is_active' => true,
        ]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'PC-01', 'name' => 'PC-01']);
        TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'agent-1', 'hostname' => 'PC-01', 'status' => 'offline', 'synced_at' => now()]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Endpoint issue']);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);
        $script = TacticalScript::create(['tactical_script_id' => 201, 'name' => 'Disk Health', 'shell' => 'powershell', 'hidden' => false, 'synced_at' => now()]);

        $token = McpConfig::rotateStaffToken(allowedTools: ['tactical_stage_script'], label: 'opsbot');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'tactical_stage_script', 'arguments' => [
                'client_id' => $client->id, 'ticket_id' => $ticket->id, 'hostname' => 'PC-01', 'script_id' => $script->id,
                'args' => '-Check Disk', 'timeout' => 120, 'reason' => 'Run a scripted health check when the device is up.',
            ]],
        ])->assertOk();
        $this->flushHeaders();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')->andThrow(new TacticalClientException('Tactical agent is unreachable', transportFailure: true));
        $this->app->instance(TacticalClient::class, $tactical);

        $run = TechnicianRun::where('action_type', 'tactical_stage_script')->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return ['approver' => $approver, 'run' => $run];
    }

    /** @return \Closure(): list<MessageLogged> the guard's own refusal lines */
    private function captureRefusals(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            if (str_contains($m->message, 'offline queue refused')) {
                $logged[] = $m;
            }
        });

        return fn () => $logged->getArrayCopy();
    }

    /**
     * (a) The false-positive control: with no outer transaction under the production manager,
     * the cockpit approve route queues the run, and a still-offline reconnect through the
     * SweepQueuedActionsForAgent job re-queues it through the normal path (a second
     * queued_offline audit row), with no refusal line and no not-updated audit row.
     */
    public function test_without_an_outer_transaction_the_real_callers_queue_and_requeue(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $refusals = $this->captureRefusals();
        $this->assertProductionManager();

        $this->actingAs($approver)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect()
            ->assertSessionHas('success', 'Device offline — queued to run automatically when it comes back online.');
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
        $this->assertSame('agent-1', $run->fresh()->queued_agent_id);

        $this->travel(3)->minutes();
        $this->assertProductionManager();
        SweepQueuedActionsForAgent::dispatchSync('agent-1');

        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 're-queued by the reconnect path');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame([], $refusals(), 'the guard did not fire on either path');
        $this->assertSame(2, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'one queued_offline audit row per path');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%database transaction was open%')->count());
    }

    /**
     * (b) With exactly ONE outer DB::transaction (level 1, as a production caller would open),
     * the guard fires on the approval: nothing is queued, the claim is released for
     * re-approval, and the decline names the open transaction. A guard reading
     * transactionLevel() > 1 does not fire here.
     */
    public function test_one_outer_transaction_on_the_approval_fires_the_guard(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $refusals = $this->captureRefusals();
        $this->assertProductionManager();

        $result = DB::transaction(function () use ($run, $approver) {
            $this->assertSame(1, DB::connection()->transactionLevel(), 'exactly one outer transaction');

            return app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);
        });

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('inside an open database transaction', (string) $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state, 'released for re-approval, not queued');
        $this->assertNull($fresh->queued_agent_id);
        $this->assertCount(1, $refusals());
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline'], $refusals()[0]->context);
    }

    /**
     * (b) on the reconnect path, calling the executor directly: one outer DB::transaction around
     * StaffTacticalActionToolExecutor::runQueuedOnReconnect() fires the guard, and the run goes
     * back to QueuedOffline with its queue entry intact. The job is not run here; the test
     * below runs it.
     */
    public function test_one_outer_transaction_on_the_reconnect_run_fires_the_guard(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $refusals = $this->captureRefusals();
        $this->assertProductionManager();

        $result = DB::transaction(function () use ($run) {
            $this->assertSame(1, DB::connection()->transactionLevel(), 'exactly one outer transaction');

            return app(StaffTacticalActionToolExecutor::class)->runQueuedOnReconnect($run->fresh());
        });

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('did not update the offline queue', (string) $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertCount(1, $refusals());
    }

    /**
     * #6660: (b) on the reconnect path through the real caller. One outer DB::transaction around
     * SweepQueuedActionsForAgent::dispatchSync() runs the job on the sync queue inside that
     * transaction, and the guard fires: one refusal line, one audit row saying the offline queue
     * was not updated, no second queued_offline audit row, and the run back in QueuedOffline
     * with its queue entry intact. OfflineActionSweep::sweepAgent() returns a count, not the
     * result, so the decline is observed through the refusal line and the audit row.
     */
    public function test_one_outer_transaction_around_the_sweep_job_fires_the_guard(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $refusals = $this->captureRefusals();
        $this->assertProductionManager();

        DB::transaction(function () use ($refusals) {
            $this->assertSame(1, DB::connection()->transactionLevel(), 'exactly one outer transaction');
            SweepQueuedActionsForAgent::dispatchSync('agent-1');
            $this->assertCount(1, $refusals(), 'the job ran, and the guard fired, inside the outer transaction');
        });

        $this->assertCount(1, $refusals());
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline'], $refusals()[0]->context);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'released back to the queue it came from');
        $this->assertSame('agent-1', $fresh->queued_agent_id, 'its queue entry is untouched');
        $notUpdated = TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%offline queue not updated: a database transaction was open around this request.%')->count();
        $this->assertSame(1, $notUpdated, 'the guard decline is audited once');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'only the approval queued it; the sweep did not re-queue');
    }
}
