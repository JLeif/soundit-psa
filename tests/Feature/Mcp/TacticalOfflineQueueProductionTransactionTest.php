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
use App\Services\Tactical\OfflineActionSweep;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * #6765 / #6772: asserts that at least one record was captured, then that no
     * MessageLogged record captured by captureLogs() (every level) carries $thrownMessage in
     * its message or in the json_encode() of its context, encoded with JSON_THROW_ON_ERROR so an
     * unencodable context errors the test instead of encoding to false. It also asserts that no
     * top-level context value is a \Throwable. Not asserted: a \Throwable nested deeper in a
     * context, a truncated or transformed copy of the message, or a write that does not dispatch
     * a MessageLogged event.
     *
     * @param  list<MessageLogged>  $logged
     */
    private function assertNoCapturedRecordCarries(array $logged, string $thrownMessage): void
    {
        $this->assertNotSame([], $logged, 'captureLogs() captured at least one record');
        foreach ($logged as $i => $m) {
            $this->assertStringNotContainsString($thrownMessage, $m->message, "captured record {$i}'s message does not contain the thrown exception's message");
            $this->assertStringNotContainsString($thrownMessage, json_encode($m->context, JSON_THROW_ON_ERROR), "captured record {$i}'s JSON_THROW_ON_ERROR-encoded context does not contain the thrown exception's message");
            foreach ($m->context as $key => $value) {
                $this->assertNotInstanceOf(\Throwable::class, $value, "captured record {$i}'s context key {$key} is not a Throwable");
            }
        }
    }

    /**
     * #6766: two distinct throwable classes, so a hard-coded class literal in a catch arm's
     * context fails one of the two data sets.
     *
     * @return array<string, array{class-string<\Throwable>}>
     */
    public static function thrownClasses(): array
    {
        return ['runtime' => [\RuntimeException::class], 'logic' => [\LogicException::class]];
    }

    /**
     * #6739 positive control for the test above, on the same fixture and under one outer
     * DB::transaction around SweepQueuedActionsForAgent::dispatchSync(). After the approval, a
     * TacticalClient mock whose runScript() throws an exception of the data set's class (#6766: a
     * \RuntimeException, then a \LogicException; neither is a TacticalClientException) is bound,
     * so runScript() throws inside dispatchSync(). The test asserts that the mock received
     * runScript() once, and that reconnectRunFailures() then finds exactly 1 captured record whose
     * message contains 'reconnect-run failed'. #6764: that record's message is exactly
     * '[OfflineQueue] reconnect-run failed', and it is at warning level. #6772 / #6765:
     * assertNoCapturedRecordCarries() then finds the thrown exception's message in no captured
     * record's message or JSON_THROW_ON_ERROR-encoded context, at any level, and no top-level
     * context value that is a \Throwable. #6748 / #6767: that record's context is exactly
     * run_id (this run's id), agent_id ('agent-1') and exception (the thrown class), in that
     * order, so it has no error key and no other key. #6768: any other captured record is
     * asserted on only by assertNoCapturedRecordCarries() (scope stated on it) and by the count
     * above, which requires that no other captured record's message contains
     * 'reconnect-run failed'; nothing about the run's state after the job is asserted.
     *
     * @param  class-string<\Throwable>  $thrownClass
     */
    #[DataProvider('thrownClasses')]
    public function test_the_reconnect_run_failed_filter_finds_the_warning_when_the_reconnect_run_throws(string $thrownClass): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $this->queuedFieldsBefore($run);
        $throwing = Mockery::mock(TacticalClient::class);
        $thrownMessage = 'positive control 6739 synthetic message b14-6763';
        $throwing->shouldReceive('runScript')->andThrow(new $thrownClass($thrownMessage));
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
        $this->assertSame('[OfflineQueue] reconnect-run failed', $records[0]->message, 'the message is exactly the reconnect-run failed label');
        $this->assertSame('warning', $records[0]->level, 'the record is at warning level');
        $this->assertNoCapturedRecordCarries($logged(), $thrownMessage);
        $this->assertSame(
            ['run_id' => $run->id, 'agent_id' => 'agent-1', 'exception' => $thrownClass],
            $records[0]->context,
            "the context is exactly run_id, agent_id and the thrown exception's class",
        );
    }

    /**
     * #6763: the positive control for OfflineActionSweep::sweepDue()'s per-agent catch arm (the
     * '[OfflineQueue] agent sweep failed' warning). The run is queued for 'agent-1' through the
     * approval, then agent-1's TacticalAsset row is set to 'online'. The fault is a
     * TechnicianRun 'retrieved' model-event listener that throws an exception of the data set's
     * class (#6766) while armed. Inferred from reading the code, not asserted: sweepAgent()'s run
     * query hydrates TechnicianRun models before its per-run try, so the throw leaves sweepAgent()
     * and reaches sweepDue()'s catch. sweepDue()'s two agent-listing queries run once, before the
     * per-agent loop and outside its try, and pluck(), so they do not hydrate TechnicianRun models.
     * Its expireDue() call runs first, also outside every try, and does hydrate TechnicianRun
     * models, but only expired queued rows; with the listener armed such a row would throw out of
     * sweepDue() uncaught, so sweepDue() returning normally, with the listener having thrown
     * exactly once, is what shows this fixture had none at sweep time. Measured: the
     * listener threw exactly once, and the results below. The test asserts that sweepDue()
     * returns ran 0 and expired 0, and that exactly 1 captured record has a
     * message containing 'agent sweep failed'. #6764: that message is exactly
     * '[OfflineQueue] agent sweep failed', at warning level. #6772 / #6765:
     * assertNoCapturedRecordCarries() finds the thrown message in no captured record. #6767: the
     * context is exactly agent_id ('agent-1') and exception (the thrown class), in that order, so
     * it has no error key and no other key. #6768: any other captured record is asserted on only
     * by assertNoCapturedRecordCarries() (scope stated on it) and by the count above, which
     * requires that no other captured record's message contains 'agent sweep failed'; nothing
     * about the run's state after the sweep is asserted.
     *
     * @param  class-string<\Throwable>  $thrownClass
     */
    #[DataProvider('thrownClasses')]
    public function test_the_agent_sweep_failed_warning_logs_the_exception_class_when_sweep_agent_throws(string $thrownClass): void
    {
        $this->assertProductionManager();
        ['approver' => $approver, 'run' => $run] = $this->stagedOfflineRun();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $this->queuedFieldsBefore($run);
        $this->assertSame(1, TacticalAsset::where('agent_id', 'agent-1')->update(['status' => 'online']), "agent-1's row is set online");
        $thrownMessage = 'positive control 6763 synthetic message b14-sweepdue';
        $armed = true;
        $fired = 0;
        TechnicianRun::retrieved(function () use (&$armed, &$fired, $thrownClass, $thrownMessage): void {
            if ($armed) {
                $fired++;

                throw new $thrownClass($thrownMessage);
            }
        });
        $logged = $this->captureLogs();
        $this->assertProductionManager();

        $result = app(OfflineActionSweep::class)->sweepDue();
        $armed = false;

        $this->assertSame(1, $fired, 'the retrieved listener threw exactly once');
        $this->assertSame(['ran' => 0, 'expired' => 0], $result, 'sweepDue() returned ran 0 and expired 0');
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'agent sweep failed')));
        $this->assertCount(1, $records, "one 'agent sweep failed' warning was logged");
        $this->assertSame('[OfflineQueue] agent sweep failed', $records[0]->message, 'the message is exactly the agent sweep failed label');
        $this->assertSame('warning', $records[0]->level, 'the record is at warning level');
        $this->assertNoCapturedRecordCarries($logged(), $thrownMessage);
        $this->assertSame(
            ['agent_id' => 'agent-1', 'exception' => $thrownClass],
            $records[0]->context,
            "the context is exactly agent_id and the thrown exception's class",
        );
    }
}
