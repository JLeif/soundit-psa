<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
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
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Technician\TechnicianApprovalService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Offline-script queue (bd psa-xr84) — approving a staged script whose device is
 * offline parks it in queued_offline instead of the silent gate_declined dead-end,
 * and a duplicate approval coalesces onto the existing queued row.
 */
class TacticalOfflineQueueApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): User
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array{client: Client, asset: Asset, ticket: Ticket} */
    private function endpointFixture(string $hostname = 'PC-01'): array
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => $hostname, 'name' => $hostname]);
        TacticalAsset::create([
            'asset_id' => $asset->id, 'agent_id' => 'agent-1', 'hostname' => $hostname,
            'status' => 'offline', 'synced_at' => now(),
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id, 'subject' => 'Endpoint issue']);
        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return compact('client', 'asset', 'ticket');
    }

    private function stageScript(Client $client, Ticket $ticket, TacticalScript $script, string $args = '-Check Disk'): TechnicianRun
    {
        $token = $this->token(['tactical_stage_script']);
        $this->callTool($token, 'tactical_stage_script', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'hostname' => 'PC-01',
            'script_id' => $script->id,
            'args' => $args,
            'timeout' => 120,
            'reason' => 'Run a scripted health check when the device is up.',
        ])->assertOk();

        return TechnicianRun::where('action_type', 'tactical_stage_script')
            ->where('ticket_id', $ticket->id)->firstOrFail();
    }

    private function offlineClient(): TacticalClient
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')
            ->andThrow(new TacticalClientException('Tactical agent is unreachable', transportFailure: true));
        $this->app->instance(TacticalClient::class, $tactical);

        return $tactical;
    }

    private function script(): TacticalScript
    {
        return TacticalScript::create([
            'tactical_script_id' => 201, 'name' => 'Disk Health', 'shell' => 'powershell',
            'hidden' => false, 'synced_at' => now(),
        ]);
    }

    public function test_approving_a_script_for_an_offline_device_queues_instead_of_dead_ending(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $script = $this->script();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $this->offlineClient();

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('queued_offline', $result->status);

        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state);
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertNotNull($fresh->queued_at);
        $this->assertNotNull($fresh->expires_at);
        $this->assertNotNull($fresh->queued_dedup_key);

        // Raw offline recorded in the bus ledger; the approval ledger records the queue.
        $this->assertDatabaseHas('tactical_action_logs', ['action_key' => 'tactical.run_script', 'result_status' => 'offline']);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'tactical_stage_script',
            'result_status' => 'queued_offline',
            'approver_user_id' => $approver->id,
        ]);
    }

    public function test_cockpit_approve_route_shows_a_queued_message_for_an_offline_device(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();

        $this->actingAs($approver)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect()
            ->assertSessionHas('success', 'Device offline — queued to run automatically when it comes back online.');

        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state);
    }

    public function test_disabled_feature_falls_back_to_the_dead_end(): void
    {
        $approver = $this->configure();
        Setting::setValue('tactical_offline_queue_enabled', '0');
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_duplicate_offline_approval_coalesces_onto_the_existing_queued_row(): void
    {
        $approver = $this->configure();
        $script = $this->script();
        $fixture = $this->endpointFixture();

        // Second ticket on the SAME device → same (agent, script, args) dedup key,
        // distinct content_hash (ticket-scoped), so two staged runs exist.
        $ticket2 = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same device, another ticket']);
        $ticket2->assets()->attach($fixture['asset']->id, ['is_primary' => true]);

        $runA = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $runB = $this->stageScript($fixture['client'], $ticket2, $script);

        $this->offlineClient();
        $svc = app(TechnicianApprovalService::class);

        $svc->approveStagedTacticalAction($runA, $approver->id);
        // Past the per-asset run cooldown, so the duplicate reaches the queue (rather
        // than being cooldown-blocked) and coalesces onto the still-queued row.
        $this->travel(3)->minutes();
        $svc->approveStagedTacticalAction($runB, $approver->id);

        // First stays queued with a bumped coalesce count; the duplicate is superseded (not a second queued row).
        $this->assertSame(TechnicianRunState::QueuedOffline, $runA->fresh()->state);
        $this->assertSame(1, $runA->fresh()->coalesce_count);
        $this->assertSame(TechnicianRunState::Superseded, $runB->fresh()->state);
        $this->assertSame(1, TechnicianRun::where('state', TechnicianRunState::QueuedOffline->value)->count());
    }

    /**
     * #6197: Tactical answers offline while another approval now holds the run (it was
     * reopened and claimed again during the call). This request's queue write loses the
     * claim-owner fence: the other claim stays Executing, nothing is queued, and the approver
     * is told the action was not queued, not that it was.
     */
    private function offlineClientThatLosesTheClaim(TechnicianRun $run): void
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')->andReturnUsing(function () use ($run) {
            TechnicianRun::whereKey($run->id)->update(['claimed_at' => now()->addMinute()]);

            throw new TacticalClientException('Tactical agent is unreachable', transportFailure: true);
        });
        $this->app->instance(TacticalClient::class, $tactical);
    }

    public function test_an_offline_approval_whose_claim_was_taken_meanwhile_is_not_queued(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClientThatLosesTheClaim($run);

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_TEXT, $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::Executing, $fresh->state, 'the other claim is still in place');
        $this->assertNull($fresh->queued_agent_id, 'nothing was queued');
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::QueuedOffline->value)->count());
        $this->assertNotQueuedAudit($run);
    }

    /**
     * #6255 / #6271: the not-queued text names no actor (the reconnect sweep reaches it too) and
     * does not say Tactical reported anything (a transport failure is classified offline).
     */
    private const NOT_QUEUED_TEXT = 'The Tactical call was classified as device offline, and the action was not queued: this request no longer holds the run. Check its current state before acting on it again.';

    /** #6254: a lost fence leaves no queued_offline audit row, and one row says it was not queued. */
    private function assertNotQueuedAudit(TechnicianRun $run): void
    {
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->get();
        $this->assertSame([], $rows->where('result_status', 'queued_offline')->pluck('summary')->all(), 'no queued_offline row');
        $this->assertSame(['offline'], $rows->pluck('result_status')->all());
        $this->assertStringContainsString('not queued', (string) $rows->first()->summary);
    }

    /** #6254: positive control: a queued run carries exactly one queued_offline row. */
    public function test_a_queued_run_carries_one_queued_offline_audit_row(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();

        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->assertSame(['queued_offline'], TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->pluck('result_status')->all());
    }

    /**
     * #6253 / #6263: the run left Executing during the call (another path reopened it, nobody
     * claimed it again). On the coalesce branch this request must not supersede it, bump the
     * queued row, or report queued_offline.
     */
    public function test_a_duplicate_offline_approval_whose_run_was_reopened_meanwhile_is_not_coalesced(): void
    {
        $approver = $this->configure();
        $script = $this->script();
        $fixture = $this->endpointFixture();
        $ticket2 = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same device, another ticket']);
        $ticket2->assets()->attach($fixture['asset']->id, ['is_primary' => true]);
        $runA = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $runB = $this->stageScript($fixture['client'], $ticket2, $script);
        $svc = app(TechnicianApprovalService::class);
        $this->offlineClient();
        $this->assertSame('queued_offline', $svc->approveStagedTacticalAction($runA, $approver->id)->status, 'positive control: A is queued');
        $this->travel(3)->minutes();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('runScript')->andReturnUsing(function () use ($runB) {
            TechnicianRun::whereKey($runB->id)->update(['state' => TechnicianRunState::AwaitingApproval->value]);

            throw new TacticalClientException('Tactical agent is unreachable', transportFailure: true);
        });
        $this->app->instance(TacticalClient::class, $tactical);
        $result = $svc->approveStagedTacticalAction($runB, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_TEXT, $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runB->fresh()->state, 'not superseded');
        $this->assertSame(0, $runA->fresh()->coalesce_count, 'not counted onto the queued row');
        $this->assertNotQueuedAudit($runB);
    }

    /** #6197: on the coalesce branch too, a stale holder neither supersedes the run nor bumps the queued row. */
    public function test_a_duplicate_offline_approval_whose_claim_was_taken_meanwhile_is_not_coalesced(): void
    {
        $approver = $this->configure();
        $script = $this->script();
        $fixture = $this->endpointFixture();
        $ticket2 = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same device, another ticket']);
        $ticket2->assets()->attach($fixture['asset']->id, ['is_primary' => true]);
        $runA = $this->stageScript($fixture['client'], $fixture['ticket'], $script);
        $runB = $this->stageScript($fixture['client'], $ticket2, $script);
        $svc = app(TechnicianApprovalService::class);
        $this->offlineClient();
        $this->assertSame('queued_offline', $svc->approveStagedTacticalAction($runA, $approver->id)->status, 'positive control: A is queued');
        $this->travel(3)->minutes();

        $this->offlineClientThatLosesTheClaim($runB);
        $result = $svc->approveStagedTacticalAction($runB, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_TEXT, $result->message);
        $this->assertSame(TechnicianRunState::Executing, $runB->fresh()->state, 'not superseded');
        $this->assertSame(0, $runA->fresh()->coalesce_count, 'not counted onto the queued row');
        $this->assertNotQueuedAudit($runB);
    }

    /**
     * #6312: after the queue write committed, the audit insert for result status $status throws.
     * The failure's message carries a synthetic marker, so a line that copied it would show it.
     *
     * @return \Closure(): list<MessageLogged>
     */
    private function failTheAuditInsert(string $status): \Closure
    {
        DB::beforeExecuting(function (string $sql, array $bindings) use ($status): void {
            if (str_contains($sql, 'insert into "technician_action_logs"') && in_array($status, $bindings, true)) {
                throw new \RuntimeException('synthetic audit failure SECRET-6312-MARKER');
            }
        });
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /**
     * #6312 / #6512: the note the catch arm appends to the normal-path result's message. #6507: it
     * names the technician action log row, since the bus wrote a tactical_action_logs row.
     */
    private const AUDIT_NOT_WRITTEN = 'The technician action log row for this request was not written.';

    /**
     * #6312: the queue write ran, and then the audit insert threw. The result is the normal-path
     * queued_offline result (never an error) with the audit-not-written note as its message
     * (#6512: derived, not a second copy of queued text); one error line names the exception's
     * class only (C-56). #6519: under RefreshDatabase this test can observe that the queue write
     * is visible on this connection, not that it committed. The outer-transaction tests below
     * measure the guard under the RefreshDatabase testing manager only; the guard under the
     * base manager production binds (no outer transaction: queued; exactly one: declined) is
     * measured by TacticalOfflineQueueProductionTransactionTest. #6521:
     * the cockpit does not render this message for queued_offline, so this is the service-layer
     * result, not what the approver sees.
     */
    public function test_a_committed_queue_whose_audit_insert_fails_still_reports_queued(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $logged = $this->failTheAuditInsert('queued_offline');

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('queued_offline', $result->status);
        $this->assertSame(self::AUDIT_NOT_WRITTEN, $result->message);
        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state, 'the queue write is visible on this connection');
        $this->assertSame('agent-1', $run->fresh()->queued_agent_id);
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'queued_offline')->count(), 'positive control: the insert really failed');
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame('[Tactical] offline queue audit row not written', $records[0]->message);
        $this->assertSame(['run_id' => $run->id, 'status' => 'queued_offline', 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertStringNotContainsString('SECRET-6312-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())) ?: '');
    }

    /**
     * #6312: on the lost-fence arm nothing was queued; if its audit insert then throws, the
     * approver still gets the not-queued decline (not an exception), plus the audit note.
     * #6517(1): as on the queued arm, the log carries the class only, never the marker (C-56),
     * and the 'offline' audit row really was not written (positive control).
     */
    public function test_a_lost_fence_whose_audit_insert_fails_still_says_not_queued(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClientThatLosesTheClaim($run);
        $logged = $this->failTheAuditInsert('offline');

        $result = app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_TEXT.' '.self::AUDIT_NOT_WRITTEN, $result->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is still in place');
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::QueuedOffline->value)->count());
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline', 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'offline')->count(), 'positive control: the insert really failed');
        $this->assertStringNotContainsString('SECRET-6312-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())) ?: '');
    }

    /** #6508 / #6516: the decline when a database transaction is open around the queue write. */
    private const NOT_QUEUED_OUTER_TRANSACTION_TEXT = "The Tactical call was classified as device offline, and the action was not queued: this request was running inside an open database transaction. Check the run's current state before acting on it again.";

    /** #6508 / #6516: the same decline on a reconnect-run, whose run stays queued. */
    private const QUEUE_NOT_UPDATED_OUTER_TRANSACTION_TEXT = "The Tactical call was classified as device offline on a queued re-run, and this request did not update the offline queue: it was running inside an open database transaction. Check the run's current state before acting on it again.";

    /**
     * #6738: asserts that the bound 'db.transactions' manager is the RefreshDatabase testing
     * Illuminate\Foundation\Testing\DatabaseTransactionsManager and that the default connection is
     * at level 1 (the RefreshDatabase wrapper only). Only the tests that call it measure this.
     */
    private function assertTestingManager(): void
    {
        $this->assertSame(\Illuminate\Foundation\Testing\DatabaseTransactionsManager::class, get_class(app('db.transactions')), 'the RefreshDatabase testing manager');
        $this->assertSame(1, DB::connection()->transactionLevel(), 'only the RefreshDatabase wrapper is open');
    }

    /**
     * #6735: the queue fields parkOrCoalesce() writes (through queueForOffline(), or coalesce_count
     * on a coalesce). OfflineActionSweep::sweepAgent() selects on state, queued_agent_id and
     * expires_at > now(), and de-duplicates on queued_dedup_key.
     *
     * @return array<string, mixed>
     */
    private function queueFields(TechnicianRun $run): array
    {
        $run = $run->fresh();

        return [
            'state' => $run->state,
            'queued_agent_id' => $run->queued_agent_id,
            'queued_dedup_key' => $run->queued_dedup_key,
            'queued_at' => $run->queued_at?->toDateTimeString(),
            'expires_at' => $run->expires_at?->toDateTimeString(),
            'coalesce_count' => $run->coalesce_count,
            'queued_approver_id' => $run->proposed_meta['queued_approver_id'] ?? null,
        ];
    }

    /**
     * #6735: the queue fields of a run the approval queued, read before the reconnect call. It
     * asserts state QueuedOffline, queued_agent_id 'agent-1', and a non-null queued_dedup_key and
     * expires_at, so the before/after comparison covers set values.
     *
     * @return array<string, mixed>
     */
    private function queuedFieldsBefore(TechnicianRun $run): array
    {
        $before = $this->queueFields($run);
        $this->assertSame(TechnicianRunState::QueuedOffline, $before['state']);
        $this->assertSame('agent-1', $before['queued_agent_id']);
        $this->assertNotNull($before['queued_dedup_key']);
        $this->assertNotNull($before['expires_at']);

        return $before;
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /**
     * #6508 / #6516 (Jeeves 2026-10-11 03:07Z, red test on the premise): a caller that opens a
     * database transaction around the approval breaks the premise that parkOrCoalesce() commits
     * before the audit row is written. The guard fires before the queue write: nothing is
     * queued, the claim is released for re-approval, the approver is told it was not queued
     * and why, and the not-queued attempt is audited. #6738: the testing manager is asserted
     * before the outer transaction opens.
     */
    public function test_an_approval_inside_an_outer_transaction_is_not_queued(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $logged = $this->captureLogs();
        $this->assertTestingManager();

        $result = DB::transaction(fn () => app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id));

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_OUTER_TRANSACTION_TEXT, $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state, 'released for re-approval, not queued');
        $this->assertNull($fresh->queued_agent_id, 'nothing was queued');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->get();
        $this->assertSame(['offline'], $rows->pluck('result_status')->all());
        $this->assertStringContainsString('not queued: a database transaction was open around this request.', (string) $rows->first()->summary);
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'offline queue refused')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline'], $records[0]->context);
    }

    /**
     * #6508: the guard counts transactions on the run's connection beyond the RefreshDatabase
     * wrapper, so the same approval with no outer transaction is queued (the guard's negative
     * control on the same fixture, under the testing manager only), and the reconnect path is
     * guarded too. Its run goes back to QueuedOffline, so neither its result nor its audit row
     * may say it was not queued. #6735: every field queueFields() reads is equal before and
     * after the reconnect call, and expires_at is still later than now. #6738: the testing
     * manager is asserted before the approval and before the reconnect call's outer transaction.
     */
    public function test_the_outer_transaction_guard_does_not_fire_without_one_and_guards_the_reconnect_run(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $executor = app(\App\Services\Mcp\StaffTacticalActionToolExecutor::class);
        $this->assertTestingManager();

        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $before = $this->queuedFieldsBefore($run);
        $this->assertTestingManager();

        $result = DB::transaction(fn () => $executor->runQueuedOnReconnect($run->fresh()));

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::QUEUE_NOT_UPDATED_OUTER_TRANSACTION_TEXT, $result->message);
        $this->assertStringNotContainsString('not queued', (string) $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'released back to the queue it came from');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame($before, $this->queueFields($run), 'every queue field is unchanged');
        $this->assertTrue($fresh->expires_at->isFuture(), 'still inside its window for the next sweep');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'offline')->get();
        $this->assertCount(1, $rows, 'the reconnect-run guard decline is audited once');
        $this->assertStringContainsString('offline queue not updated: a database transaction was open around this request.', (string) $rows->first()->summary);
        $this->assertStringNotContainsString('not queued', (string) $rows->first()->summary);
    }

    /**
     * r1 diff:4: on the guard arm, the not-queued audit insert throws. The claim is still
     * released for re-approval, the decline is still returned (not an exception) with the
     * audit-not-written note, and one error line names the exception's class only (C-56).
     */
    public function test_an_outer_transaction_decline_whose_audit_insert_fails_still_declines_and_releases(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $logged = $this->failTheAuditInsert('offline');

        $result = DB::transaction(fn () => app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id));

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::NOT_QUEUED_OUTER_TRANSACTION_TEXT.' '.self::AUDIT_NOT_WRITTEN, $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $fresh->state, 'the claim is released for re-approval');
        $this->assertNull($fresh->queued_agent_id, 'nothing was queued');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'offline')->count(), 'positive control: the insert really failed');
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline', 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertStringNotContainsString('SECRET-6312-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())) ?: '');
    }

    /**
     * #6665: the reconnect (requeued) arm of the same guard-arm audit failure. The audit insert
     * throws on runQueuedOnReconnect() under one outer transaction. The decline is still returned
     * (not an exception) with the queue-not-updated text and the audit-not-written note, the run
     * is released to QueuedOffline, and one error line names the exception's class only (C-56).
     * #6735: every field queueFields() reads is equal before and after the reconnect call, and
     * expires_at is still later than now. #6737 / #6740: a fresh offline TacticalClient mock is
     * bound after the approval and before runQueuedOnReconnect(), so it counts the reconnect
     * path's calls only. It received runScript() exactly once during runQueuedOnReconnect(), so
     * the reconnect path, guard arm included, made no second runScript() call through it.
     */
    public function test_a_reconnect_run_decline_whose_audit_insert_fails_still_declines_and_requeues(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $this->assertSame('queued_offline', app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id)->status);
        $this->travel(3)->minutes();
        $before = $this->queuedFieldsBefore($run);
        $reconnectClient = $this->offlineClient();
        $logged = $this->failTheAuditInsert('offline');

        $result = DB::transaction(fn () => app(\App\Services\Mcp\StaffTacticalActionToolExecutor::class)->runQueuedOnReconnect($run->fresh()));

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(self::QUEUE_NOT_UPDATED_OUTER_TRANSACTION_TEXT.' '.self::AUDIT_NOT_WRITTEN, $result->message);
        $fresh = $run->fresh();
        $this->assertSame(TechnicianRunState::QueuedOffline, $fresh->state, 'released back to the queue it came from');
        $this->assertSame('agent-1', $fresh->queued_agent_id);
        $this->assertSame($before, $this->queueFields($run), 'every queue field is unchanged');
        $this->assertTrue($fresh->expires_at->isFuture(), 'still inside its window for the next sweep');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'offline')->count(), 'positive control: the insert really failed');
        $refused = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'offline queue refused')));
        $this->assertCount(1, $refused, 'the guard arm ran');
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline', 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertStringNotContainsString('SECRET-6312-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())) ?: '');
        $reconnectClient->shouldHaveReceived('runScript')->once();
    }

    /**
     * #6312 scenario (b): the queue write itself throws. The offline-classified bus call still
     * leaves one audit row (bus status, status-only text), the exception is rethrown unchanged,
     * the claim is released for re-approval as before, and the log names the class only.
     */
    public function test_a_queue_write_that_throws_leaves_an_audit_row_and_still_rethrows(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $logged = $this->captureLogs();
        $boom = new \RuntimeException('synthetic queue failure SECRET-6312B-MARKER');
        DB::beforeExecuting(function (string $sql, array $bindings) use ($boom): void {
            if (str_contains($sql, 'update "technician_runs"') && in_array('queued_offline', $bindings, true)) {
                throw $boom;
            }
        });

        try {
            app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);
            $this->fail('the queue write exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e, 'rethrown unchanged');
        }

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'released as before');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->get();
        $this->assertSame(['offline'], $rows->pluck('result_status')->all());
        $this->assertStringContainsString('the offline queue write raised an error.', (string) $rows->first()->summary);
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'offline queue write failed')));
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'exception' => \RuntimeException::class], $records[0]->context);
        $this->assertStringNotContainsString('SECRET-6312B-MARKER', json_encode(array_map(fn (MessageLogged $m) => [$m->message, $m->context], $logged())) ?: '');
    }

    /** #6312 scenario (b): when that best-effort audit insert also throws, the queue write's exception still propagates. */
    public function test_a_queue_write_that_throws_rethrows_its_own_exception_when_the_audit_also_fails(): void
    {
        $approver = $this->configure();
        $fixture = $this->endpointFixture();
        $run = $this->stageScript($fixture['client'], $fixture['ticket'], $this->script());
        $this->offlineClient();
        $logged = $this->failTheAuditInsert('offline');
        $boom = new \RuntimeException('synthetic queue failure');
        DB::beforeExecuting(function (string $sql, array $bindings) use ($boom): void {
            if (str_contains($sql, 'update "technician_runs"') && in_array('queued_offline', $bindings, true)) {
                throw $boom;
            }
        });

        try {
            app(TechnicianApprovalService::class)->approveStagedTacticalAction($run, $approver->id);
            $this->fail('the queue write exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e, 'the queue write exception, not the audit one');
        }

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'audit row not written')));
        $this->assertCount(1, $records);
        $this->assertSame(['run_id' => $run->id, 'status' => 'offline', 'exception' => \RuntimeException::class], $records[0]->context);
    }
}
