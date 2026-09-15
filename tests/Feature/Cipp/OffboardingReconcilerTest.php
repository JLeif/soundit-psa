<?php

namespace Tests\Feature\Cipp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\Offboarding\OffboardingPlan;
use App\Services\Cipp\Offboarding\OffboardingReconciler;
use App\Services\Cipp\Offboarding\OffboardingScope;
use App\Services\Cipp\Offboarding\OffboardingStatus;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Tests\Unit\Cipp\OffboardingProgressTest as Fixture;

class OffboardingReconcilerTest extends TestCase
{
    protected TechnicianRun $run;

    protected User $observer;

    protected array $snapshot;

    protected CippRestWriteClient $vendor;

    protected string $operation = '66666666-6666-4666-8666-666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDatabase();
        $client = Client::factory()->create();
        $this->observer = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'assignee_id' => $this->observer->id, 'created_by' => null]);
        $this->snapshot = Fixture::snapshot();
        $this->snapshot['input'] += ['client_id' => $client->id, 'ticket_id' => $ticket->id];
        $seal = OffboardingPlan::hash($this->snapshot);
        $this->run = TechnicianRun::create(['client_id' => $client->id, 'ticket_id' => $ticket->id,
            'action_type' => 'cipp_stage_offboard_user', 'state' => TechnicianRunState::Executing, 'content_hash' => $seal]);
        DB::table('cipp_offboarding_operations')->insert(['id' => $this->operation, 'staged_run_id' => $this->run->id,
            'client_id' => $client->id, 'ticket_id' => $ticket->id, 'plan_hash' => $seal, 'revision' => 1,
            'snapshot' => Crypt::encryptString(OffboardingPlan::canonical($this->snapshot)), 'reference' => 'synthetic-reference',
            'admission' => 'send_intent', 'approver_id' => $this->observer->id, 'approved_at' => now(),
            'send_intent_at' => now(), 'dispatch_generation' => '77777777-7777-4777-8777-777777777777', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cipp_offboarding_target_fences')->insert(['fence_key' => str_repeat('a', 64), 'operation_id' => $this->operation, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cipp_offboarding_spent_plans')->insert(['plan_key' => str_repeat('b', 64), 'operation_id' => $this->operation, 'created_at' => now(), 'updated_at' => now()]);
        $this->vendor = Mockery::mock(CippRestWriteClient::class);
        $this->vendor->shouldReceive('submitOffboardingOnce')->never();
        $this->app->instance(CippRestWriteClient::class, $this->vendor);
        $scope = Mockery::mock(OffboardingScope::class)->makePartial();
        $scope->shouldReceive('recoveryIntegration')->with($this->snapshot)->andReturnNull();
        $this->app->instance(OffboardingScope::class, $scope);
    }

    protected function configureDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    protected function reconcile(): array
    {
        return app(OffboardingReconciler::class)->reconcile($this->run->id, $this->run->client_id, $this->observer->id);
    }

    protected function nameReads(array $visible, array $hidden = []): void
    {
        $q = ['tenantFilter' => 'example.test', 'Name' => 'Offboarding: leaver@example.test', 'Type' => 'Invoke-CIPPOffboardingJob'];
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', $q)->once()->andReturn($visible);
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', [...$q, 'ShowHidden' => 'true'])->once()->andReturn($hidden);
    }

    protected function progressReads(array $rows): void
    {
        $this->vendor->shouldReceive('offboardingRead')->with('progress', ['DeploymentId' => Fixture::task()['Parameters']['DeploymentId']])->once()->andReturn($rows);
    }

    public function test_recovery_reads_both_partitions_then_stored_id_and_never_changes_admission_or_fences(): void
    {
        $before = (array) DB::table('cipp_offboarding_operations')->first();
        $this->nameReads([], [Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $first = $this->reconcile();
        $this->assertSame('reported_succeeded', $first['execution']);
        $this->assertSame('unverified', $first['verification']);
        $this->assertSame('send_intent', $first['admission']);
        $this->assertFalse($first['queue_acceptance_reported']);
        $this->assertFalse($first['can_retry']);
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', ['tenantFilter' => 'example.test', 'Id' => Fixture::task()['RowKey']])->once()->andReturn([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $second = $this->reconcile();
        $this->assertSame('reported_succeeded', $second['execution']);
        $this->assertSame($before, (array) DB::table('cipp_offboarding_operations')->first());
        $this->assertSame('executing', $this->run->fresh()->state->value);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
        $this->assertDatabaseCount('cipp_offboarding_spent_plans', 1);
        $this->assertDatabaseCount('cipp_offboarding_observations', 2);
        $this->assertDatabaseCount('cipp_offboarding_audit', 2);
        $stored = DB::table('cipp_offboarding_observations')->first()->observation;
        $this->assertStringNotContainsString('example.test', $stored);
        $this->assertStringNotContainsString(Fixture::task()['RowKey'], json_encode($second));
    }

    public function test_repeated_empty_evidence_keeps_intent_and_is_not_success(): void
    {
        $this->nameReads([]);
        $this->assertSame('no_task_evidence', $this->reconcile()['evidence']);
        $this->nameReads([]);
        $result = $this->reconcile();
        $this->assertSame('unknown', $result['execution']);
        $this->assertSame('send_intent', $result['admission']);
        $this->assertFalse($result['can_retry']);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
        $this->assertDatabaseCount('cipp_offboarding_spent_plans', 1);
    }

    public function test_late_regression_retains_both_observations_and_flags_conflict(): void
    {
        $this->nameReads([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $this->reconcile();
        $task = Fixture::task();
        $task['TaskState'] = 'Planned';
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', ['tenantFilter' => 'example.test', 'Id' => $task['RowKey']])->once()->andReturn([$task]);
        $row = Fixture::progress();
        $row['Status'] = 'running';
        $row['Steps'][0]['Status'] = 'pending';
        $this->progressReads([$row]);
        $result = $this->reconcile();
        $this->assertSame('conflicting_observations', $result['evidence']);
        $this->assertSame('unknown', $result['execution']);
        $this->assertDatabaseCount('cipp_offboarding_observations', 2);
        $old = DB::table('cipp_offboarding_observations')->orderBy('id')->first();
        $this->assertSame('reported_succeeded', json_decode(Crypt::decryptString($old->observation), true)['execution']);
    }

    public function test_transient_unavailability_is_not_a_regression_and_never_poisons_later_reads(): void
    {
        $this->nameReads([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $this->assertSame('reported_succeeded', $this->reconcile()['execution']);
        $id = ['tenantFilter' => 'example.test', 'Id' => Fixture::task()['RowKey']];
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', $id)->once()->andThrow(new \RuntimeException('synthetic timeout'));
        $unavailable = $this->reconcile();
        $this->assertSame('read_unavailable', $unavailable['evidence']);
        $this->assertSame(0, DB::table('cipp_offboarding_observations')->where('conflict', true)->count());
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', $id)->once()->andReturn([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $recovered = $this->reconcile();
        $this->assertSame('progress_bound', $recovered['evidence']);
        $this->assertSame('reported_succeeded', $recovered['execution']);
        $this->assertSame('unverified', $recovered['verification']);
        $this->assertDatabaseCount('cipp_offboarding_observations', 3);
        $this->assertSame(0, DB::table('cipp_offboarding_observations')->where('conflict', true)->count());
        $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
    }

    public function test_normal_queued_to_in_progress_advance_is_not_a_conflict(): void
    {
        $planned = Fixture::task();
        $planned['TaskState'] = 'Planned';
        $planned['Parameters']['DeploymentId'] = null;
        $this->nameReads([$planned]);
        $first = $this->reconcile();
        $this->assertSame('queued', $first['execution']);
        $running = Fixture::task();
        $running['TaskState'] = 'Running';
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', ['tenantFilter' => 'example.test', 'Id' => $running['RowKey']])->once()->andReturn([$running]);
        $row = Fixture::progress();
        $row['Status'] = 'running';
        $row['Steps'][1]['Status'] = 'pending';
        $this->progressReads([$row]);
        $second = $this->reconcile();
        $this->assertSame('progress_bound', $second['evidence']);
        $this->assertSame('partial_or_incomplete', $second['execution']);
        $this->assertSame('unverified', $second['verification']);
        $this->assertDatabaseCount('cipp_offboarding_observations', 2);
        $this->assertSame(0, DB::table('cipp_offboarding_observations')->where('conflict', true)->count());
    }

    public function test_scoped_detail_can_read_terminal_receipt_without_any_network_or_foreign_disclosure(): void
    {
        $this->run->update(['state' => TechnicianRunState::Done]);
        $status = app(OffboardingStatus::class);
        $this->assertArrayHasKey('error', $status->detail($this->run->id, null));
        $this->assertArrayHasKey('error', $status->detail($this->run->id, $this->run->client_id + 100));
        $result = (new AssistantToolExecutor(clientId: $this->run->client_id))->execute('get_staged_action_status', ['run_id' => $this->run->id]);
        $this->assertSame('done', $result['run_state']);
        $this->assertSame('unverified', $result['verification']);
        $this->assertStringNotContainsString('example.test', json_encode($result));
        $this->assertArrayHasKey('error', (new AssistantToolExecutor)->execute('get_staged_action_status', ['run_id' => $this->run->id]));
        $this->assertDatabaseCount('cipp_offboarding_observations', 0);
    }

    public function test_scheduler_failure_conflicting_with_success_progress_stays_uncertain(): void
    {
        $task = Fixture::task();
        $task['TaskState'] = 'Failed';
        $this->nameReads([$task]);
        $this->progressReads([Fixture::progress()]);
        $result = $this->reconcile();
        $this->assertSame('conflicting_observations', $result['evidence']);
        $this->assertSame('unknown', $result['execution']);
        $this->assertSame('unverified', $result['verification']);
    }

    public function test_failed_hidden_partition_is_unavailable_not_empty(): void
    {
        $q = ['tenantFilter' => 'example.test', 'Name' => 'Offboarding: leaver@example.test', 'Type' => 'Invoke-CIPPOffboardingJob'];
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', $q)->once()->andReturn([]);
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', [...$q, 'ShowHidden' => 'true'])->once()->andThrow(new \RuntimeException('synthetic private error'));
        $result = $this->reconcile();
        $this->assertSame('read_unavailable', $result['evidence']);
        $this->assertStringNotContainsString('private error', json_encode($result));
        $this->assertSame('send_intent', $result['admission']);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
    }

    public function test_multiple_reference_rows_do_not_poll_progress_and_stay_uncertain(): void
    {
        $this->nameReads([Fixture::task()], [Fixture::task()]);
        $result = $this->reconcile();
        $this->assertSame('conflicting_observations', $result['evidence']);
        $this->assertSame('unknown', $result['execution']);
        $this->assertFalse($result['task_persisted']);
    }

    public function test_prepare_without_send_intent_is_read_only_and_never_resumed(): void
    {
        DB::table('cipp_offboarding_operations')->update(['admission' => 'prepared', 'send_intent_at' => null]);
        $this->nameReads([]);
        $this->assertSame('prepared', $this->reconcile()['admission']);
        $this->assertNull(DB::table('cipp_offboarding_operations')->value('send_intent_at'));
        $this->assertDatabaseCount('cipp_offboarding_spent_plans', 1);
    }

    public function test_unknown_integration_does_not_retarget_reads(): void
    {
        $scope = Mockery::mock(OffboardingScope::class)->makePartial();
        $scope->shouldReceive('recoveryIntegration')->once()->andThrow(new \RuntimeException('changed integration'));
        $this->app->instance(OffboardingScope::class, $scope);
        $this->assertSame('read_unavailable', $this->reconcile()['evidence']);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
    }

    public function test_client_mapping_drift_keeps_original_tenant_query(): void
    {
        Client::whereKey($this->run->client_id)->update(['cipp_tenant_domain' => 'remapped.example.test']);
        $this->nameReads([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        $this->assertSame('reported_succeeded', $this->reconcile()['execution']);
    }

    public function test_unassigned_or_wrong_client_observer_is_denied_before_reads(): void
    {
        $other = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('same-client');
        app(OffboardingReconciler::class)->reconcile($this->run->id, $this->run->client_id, $other->id);
    }
}
