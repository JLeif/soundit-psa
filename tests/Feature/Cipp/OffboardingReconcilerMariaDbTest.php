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

    private function child(array $extra = []): array
    {
        $dir = dirname(getenv('CIPP_PROGRESS_TEST_SOCKET')).'/recovery-children';
        if (! is_dir($dir)) {
            mkdir($dir, 0700);
        }
        $prefix = $dir.'/'.\Illuminate\Support\Str::uuid();
        $job = $extra + ['run_id' => $this->run->id, 'client_id' => $this->run->client_id, 'observer_id' => $this->observer->id,
            'task' => Fixture::task(), 'progress' => Fixture::progress(), 'marker' => $prefix.'.marker',
            'posts' => $prefix.'.posts', 'result' => $prefix.'.result'];
        file_put_contents($prefix.'.json', json_encode($job, JSON_THROW_ON_ERROR));
        $extensions = getenv('CIPP_TEST_EXTENSION_DIR');
        $command = [PHP_BINARY, '-d', 'extension='.$extensions.'/mysqlnd.so', '-d', 'extension='.$extensions.'/pdo_mysql.so', base_path('tests/Fixtures/offboarding-reconcile-child.php'), $prefix.'.json'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix.'.out', 'w'], 2 => ['file', $prefix.'.err', 'w']], $pipes, base_path(), array_merge(getenv(), ['CIPP_CHILD_KEY' => config('app.key')]));
        $this->assertIsResource($process);

        return [$process, $job];
    }

    private function marker(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }

    public function test_fresh_process_crashes_keep_evidence_and_restart_has_zero_posts(): void
    {
        foreach (['during_read', 'observation_before_commit', 'audit_before_commit', 'after_commit_before_status'] as $point) {
            $before = DB::table('cipp_offboarding_observations')->count();
            [$process, $job] = $this->child(['kill_at' => $point]);
            $this->marker($job['marker']);
            $this->assertNotSame(0, proc_close($process));
            $this->assertFileDoesNotExist($job['posts']);
            $this->assertSame($before + ($point === 'after_commit_before_status' ? 1 : 0), DB::table('cipp_offboarding_observations')->count());
            $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
            $this->assertDatabaseCount('cipp_offboarding_target_fences', 1);
            $this->assertDatabaseCount('cipp_offboarding_spent_plans', 1);
            [$restart, $read] = $this->child();
            $this->assertSame(0, proc_close($restart));
            $this->assertFileDoesNotExist($read['posts']);
            $result = json_decode(file_get_contents($read['result']), true);
            $this->assertSame('send_intent', $result['admission']);
            $this->assertSame('unverified', $result['verification']);
        }
    }

    public function test_two_fresh_process_readers_cannot_silently_replace_each_other(): void
    {
        $barrier = dirname(getenv('CIPP_PROGRESS_TEST_SOCKET')).'/barrier-'.\Illuminate\Support\Str::uuid();
        [$one, $a] = $this->child(['barrier' => $barrier]);
        [$two, $b] = $this->child(['barrier' => $barrier]);
        $this->marker($a['marker']);
        $this->marker($b['marker']);
        touch($barrier);
        $this->assertSame(0, proc_close($one));
        $this->assertSame(0, proc_close($two));
        $this->assertDatabaseCount('cipp_offboarding_observations', 2);
        $this->assertSame(1, DB::table('cipp_offboarding_observations')->where('conflict', true)->count());
        $this->assertFileDoesNotExist($a['posts']);
        $this->assertFileDoesNotExist($b['posts']);
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
