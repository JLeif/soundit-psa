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
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * #6508 / #6516, r1 diff:2 / context:4 / contract:4 / contract:5: the outer-transaction guard
 * under the base Illuminate\Database\DatabaseTransactionsManager (#6745: that production binds
 * the same class is inferred, not asserted). This class does NOT use RefreshDatabase: it
 * migrates its own fresh :memory: connection, so 'db.transactions' is the framework's base
 * Illuminate\Database\DatabaseTransactionsManager and no wrapping test transaction exists.
 * #6661: each test method calls assertProductionManager() as its first statement (setUp() has
 * already run the migration), and again immediately before the measured call or the outer
 * transaction that wraps it. That check reads two things only: the class of the container's
 * 'db.transactions' binding, and transactionLevel() on the default connection
 * (DB::connection()), which it asserts is 0; it does not read the run's own connection.
 * #6736: so test (a) below asserts level 0 immediately before its measured calls; each (b) test
 * asserts level 0 before it opens one DB::transaction, then level 1 inside it, where its measured
 * call runs.
 *
 * What it measures: (a) with no outer transaction the guard does not fire, through the real
 * callers (the cockpit approve route, and SweepQueuedActionsForAgent::dispatchSync for the
 * reconnect path); (b) with exactly one outer DB::transaction (level 1) the guard fires. #6660:
 * on the approval path (b) calls TechnicianApprovalService::approveStagedTacticalAction()
 * directly, not the cockpit route. On the reconnect path (b) is measured twice: once calling
 * StaffTacticalActionToolExecutor::runQueuedOnReconnect() directly, and once through
 * SweepQueuedActionsForAgent::dispatchSync() on the sync queue, with the outer transaction
 * around the dispatch.
 * #6739: one more test, under the same one outer DB::transaction around the dispatch, is the
 * positive control for the sweep-job test's 'reconnect-run failed' filter; its runScript() mock
 * throws, and it asserts nothing about the guard.
 * TacticalOfflineQueueApprovalTest measures the same guard under the RefreshDatabase testing
 * manager; a guard reading transactionLevel() > 1 passes there and fails (b) here.
 * #6741: setUp() calls Http::preventStrayRequests(). No test here asserts on it.
 */
class TacticalOfflineQueueProductionTransactionTest extends TestCase
{
    use ReadsTacticalOfflineQueue;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    private function assertProductionManager(): void
    {
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')), 'the bound manager is the base DatabaseTransactionsManager');
        $this->assertSame(0, DB::connection()->transactionLevel(), 'default connection is at transaction level 0');
    }

    /**
     * #6739: the captured MessageLogged records whose message contains 'reconnect-run failed'.
     * Used by the sweep-job test's negative assertion and by its positive control, so both read
     * the same filter.
     *
     * @param  list<MessageLogged>  $logged
     * @return list<MessageLogged>
     */
    private function reconnectRunFailures(array $logged): array
    {
        return array_values(array_filter($logged, fn (MessageLogged $m) => str_contains($m->message, 'reconnect-run failed')));
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
     * (a) The false-positive control: with no outer transaction under the base manager, the
     * cockpit approve route leaves the run in QueuedOffline for 'agent-1'. Then, with the device
     * still offline, captureQueueWrites() records at least one matching statement between its
     * registration and readout, a window that includes the sweep job
     * (SweepQueuedActionsForAgent::dispatchSync()); the run is still QueuedOffline for 'agent-1',
     * and the run has 2 queued_offline audit rows. No 'offline queue refused' record is logged and no audit
     * row says a database transaction was open. #6743: this is also the positive control for
     * captureQueueWrites().
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
        $queueWrites = $this->captureQueueWrites();
        $this->assertProductionManager();
        SweepQueuedActionsForAgent::dispatchSync('agent-1');

        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'state is QueuedOffline after the sweep job');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertNotSame([], $queueWrites(), 'positive control: captureQueueWrites() recorded a matching statement between registration and readout, a window that includes the sweep job');
        $this->assertSame([], $refusals(), "no 'offline queue refused' record was logged");
        $this->assertSame(2, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'two queued_offline audit rows for this run');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%database transaction was open%')->count());
    }

    /**
     * (b) With exactly ONE outer DB::transaction (default connection at level 1) around the
     * approval, the result is gate_declined and its message names the open transaction; the run
     * is in AwaitingApproval with no queued_agent_id, and one 'offline queue refused' record is
     * logged. A guard reading transactionLevel() > 1 would not fire here (inferred: 1 is not > 1).
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
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state, 'state is AwaitingApproval after the call');
        $this->assertNull($fresh->queued_agent_id);
        $this->assertCount(1, $refusals());
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline'], $refusals()[0]->context);
    }

    /**
     * (b) on the reconnect path, calling the executor directly: one outer DB::transaction around
     * StaffTacticalActionToolExecutor::runQueuedOnReconnect() fires the guard, and the run goes
     * back to QueuedOffline. #6735: every field queueFields() reads (state, queued_agent_id,
     * queued_dedup_key, queued_at, expires_at, coalesce_count, queued_approver_id) is equal
     * before and after the call, and expires_at is still later than now. The job is not run
     * here; the test below runs it. #6743: captureQueueWrites() records no matching statement
     * between its registration and readout, a window that includes the call (the two shapes it
     * matches are listed on captureQueueWrites()). That it would also record a matching update
     * whose values equal the stored ones is a reading of the probe's code, which matches SQL
     * text and never reads the bindings; no test here asserts that it records one.
     */
    public function test_one_outer_transaction_on_the_reconnect_run_fires_the_guard(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $before = $this->queuedFieldsBefore($run);
        $refusals = $this->captureRefusals();
        $queueWrites = $this->captureQueueWrites();
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
        $this->assertSame($before, $this->queueFields($run), 'the 7 pinned queue fields are unchanged');
        $this->assertTrue($fresh->expires_at->isFuture(), 'expires_at is in the future');
        $this->assertSame([], $queueWrites(), 'captureQueueWrites() recorded no matching statement');
        $this->assertCount(1, $refusals());
    }

    /**
     * #6660: (b) on the reconnect path through the real caller. One outer DB::transaction around
     * SweepQueuedActionsForAgent::dispatchSync() runs the job on the sync queue inside that
     * transaction, and the guard fires: one refusal line, one audit row saying the offline queue
     * was not updated, no second queued_offline audit row, and the run back in QueuedOffline.
     * #6735: every field queueFields() reads is equal before and after the job, and expires_at is
     * still later than now. #6743: captureQueueWrites() records no matching statement between its
     * registration and readout, a window that includes the job, as in the test above. #6739: the
     * test asserts that reconnectRunFailures() finds no captured MessageLogged record whose message contains 'reconnect-run failed'. The test below is its
     * positive control: there, after runScript() throws inside dispatchSync(), the same filter
     * finds exactly 1 such record, at warning level.
     */
    public function test_one_outer_transaction_around_the_sweep_job_fires_the_guard(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $before = $this->queuedFieldsBefore($run);
        $refusals = $this->captureRefusals();
        $logged = $this->captureLogs();
        $queueWrites = $this->captureQueueWrites();
        $this->assertProductionManager();

        DB::transaction(function () use ($refusals) {
            $this->assertSame(1, DB::connection()->transactionLevel(), 'exactly one outer transaction');
            SweepQueuedActionsForAgent::dispatchSync('agent-1');
            $this->assertCount(1, $refusals(), "one 'offline queue refused' record was logged before the outer transaction closed");
        });

        $this->assertCount(1, $refusals());
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline'], $refusals()[0]->context);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'state is QueuedOffline after the sweep job');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame($before, $this->queueFields($run), 'the 7 pinned queue fields are unchanged');
        $this->assertTrue($fresh->expires_at->isFuture(), 'expires_at is in the future');
        $this->assertSame([], $queueWrites(), 'captureQueueWrites() recorded no matching statement');
        $this->assertSame([], $this->reconnectRunFailures($logged()), "no 'reconnect-run failed' warning was logged");
        $notUpdated = TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%offline queue not updated: a database transaction was open around this request.%')->count();
        $this->assertSame(1, $notUpdated, 'one audit row for this run carries the not-updated summary');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'one queued_offline audit row for this run');
    }

    /**
     * #6739 positive control for the test above, on the same fixture and under one outer
     * DB::transaction around SweepQueuedActionsForAgent::dispatchSync(). After the approval, a
     * TacticalClient mock whose runScript() throws a \RuntimeException (not a
     * TacticalClientException) is bound, so runScript() throws inside dispatchSync(). The test
     * asserts that the mock received runScript() once, and that reconnectRunFailures() then finds
     * exactly 1 captured record whose message contains 'reconnect-run failed'. That record is at
     * warning level, and its context has run_id equal to this run's id and agent_id equal to
     * 'agent-1'. #6748: its context also has exception equal to \RuntimeException::class and has
     * no error key, and the json_encode() of its context does not contain the thrown exception's
     * message string.
     */
    public function test_the_reconnect_run_failed_filter_finds_the_warning_when_the_reconnect_run_throws(): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $this->queuedFieldsBefore($run);
        $throwing = Mockery::mock(TacticalClient::class);
        $thrownMessage = 'positive control 6739 synthetic message b13-6748';
        $throwing->shouldReceive('runScript')->andThrow(new \RuntimeException($thrownMessage));
        $this->app->instance(TacticalClient::class, $throwing);
        $logged = $this->captureLogs();
        $this->assertProductionManager();

        DB::transaction(function () {
            $this->assertSame(1, DB::connection()->transactionLevel(), 'exactly one outer transaction');
            SweepQueuedActionsForAgent::dispatchSync('agent-1');
        });

        $throwing->shouldHaveReceived('runScript')->once();
        $records = $this->reconnectRunFailures($logged());
        $this->assertCount(1, $records, "one 'reconnect-run failed' warning was logged");
        $this->assertSame('warning', $records[0]->level, 'the record is at warning level');
        $this->assertArrayHasKey('run_id', $records[0]->context, 'the record has a run_id');
        $this->assertSame($run->id, $records[0]->context['run_id'], "run_id is this run's id");
        $this->assertArrayHasKey('agent_id', $records[0]->context, 'the record has an agent_id');
        $this->assertSame('agent-1', $records[0]->context['agent_id'], "agent_id is 'agent-1'");
        $this->assertArrayHasKey('exception', $records[0]->context, 'the record has an exception key');
        $this->assertSame(\RuntimeException::class, $records[0]->context['exception'], 'exception is \RuntimeException::class');
        $this->assertArrayNotHasKey('error', $records[0]->context, 'the record has no error key');
        $this->assertStringNotContainsString($thrownMessage, (string) json_encode($records[0]->context), "the json_encode() of the context does not contain the thrown exception's message");
    }
}
