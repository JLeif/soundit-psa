<?php

namespace Tests\Feature\Cipp;

use Illuminate\Support\Facades\DB;
use Tests\Unit\Cipp\OffboardingProgressTest as Fixture;

/** Same service controls on the production engine, plus transaction/crash boundaries. */
class OffboardingReconcilerMariaDbTest extends OffboardingReconcilerTest
{
    private string $originalConnection;

    protected function configureDatabase(): void
    {
        $socket = getenv('CIPP_PROGRESS_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('Requires isolated synthetic MariaDB 10.11.x; sqlite cannot certify recovery transactions.');
        }
        $this->assertStringEndsWith('/test.sock', $socket);
        $this->assertFileExists(dirname($socket).'/db-launch.pid');
        $this->originalConnection = config('database.default');
        config(['database.connections.cipp_progress_synthetic' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => 'cipp_progress_synthetic_test',
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true,
        ]]);
        DB::purge('cipp_progress_synthetic');
        $db = DB::connection('cipp_progress_synthetic');
        $this->assertStringStartsWith('10.11.', $db->selectOne('SELECT VERSION() AS v')->v);
        $this->assertSame('cipp_progress_synthetic_test', $db->selectOne('SELECT DATABASE() AS d')->d);
        $this->assertSame('1', (string) $db->selectOne('SELECT @@skip_networking AS n')->n);
        config(['database.default' => 'cipp_progress_synthetic']);
        parent::configureDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->originalConnection)) {
            DB::disconnect('cipp_progress_synthetic');
            config(['database.default' => $this->originalConnection]);
        }
        parent::tearDown();
    }

    public function test_audit_commit_failure_rolls_back_observation_but_never_intent_or_fences(): void
    {
        $this->nameReads([Fixture::task()]);
        $this->progressReads([Fixture::progress()]);
        DB::unprepared("CREATE TRIGGER fail_reconcile BEFORE INSERT ON cipp_offboarding_audit FOR EACH ROW BEGIN IF NEW.event = 'reconciled_read_only' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic crash'; END IF; END");
        $failed = false;
        try {
            $this->reconcile();
        } catch (\Illuminate\Database\QueryException) {
            $failed = true;
        } finally {
            DB::unprepared('DROP TRIGGER fail_reconcile');
        }
        $this->assertTrue($failed);
        $this->assertDatabaseCount('cipp_offboarding_observations', 0);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
        $this->assertDatabaseCount('cipp_offboarding_spent_plans', 1);
        $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
    }

    public function test_overlapping_observers_retain_both_but_refuse_to_overwrite_without_ordering(): void
    {
        $entered = false;
        $q = ['tenantFilter' => 'example.test', 'Name' => 'Offboarding: leaver@example.test', 'Type' => 'Invoke-CIPPOffboardingJob'];
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', $q)->twice()->andReturnUsing(function () use (&$entered) {
            if (! $entered) {
                $entered = true;
                $this->reconcile(); // Inner observation commits while outer vendor read is in flight.
            }

            return [Fixture::task()];
        });
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', [...$q, 'ShowHidden' => 'true'])->twice()->andReturn([]);
        $this->vendor->shouldReceive('offboardingRead')->with('progress', ['DeploymentId' => Fixture::task()['Parameters']['DeploymentId']])->twice()->andReturn([Fixture::progress()]);
        $result = $this->reconcile();
        $this->assertSame('conflicting_observations', $result['evidence']);
        $this->assertDatabaseCount('cipp_offboarding_observations', 2);
        $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
        $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
    }

    public function test_outer_transaction_cannot_hide_durable_observation(): void
    {
        DB::beginTransaction();
        $failed = false;
        try {
            $this->reconcile();
        } catch (\RuntimeException $e) {
            $failed = str_contains($e->getMessage(), 'independent durable transaction');
        } finally {
            DB::rollBack();
        }
        $this->assertTrue($failed);
        $this->assertDatabaseCount('cipp_offboarding_observations', 0);
    }
}
