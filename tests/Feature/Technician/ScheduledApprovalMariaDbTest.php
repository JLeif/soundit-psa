<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledOutbox;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Separate connections/processes on an isolated real MariaDB, not SQLite certification. */
class ScheduledApprovalMariaDbTest extends ScheduledApprovalTest
{
    public function refreshDatabase(): void
    {
        $socket = getenv('SCHEDULED_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('Requires isolated MariaDB via SCHEDULED_TEST_SOCKET.');
        }
        $this->assertStringEndsWith('/test.sock', $socket);
        $this->assertFileExists(dirname($socket).'/db-launch.pid');
        config(['database.default' => 'scheduled_synthetic', 'database.connections.scheduled_synthetic' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => 'scheduled_synthetic_test',
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        DB::purge('scheduled_synthetic');
        $this->assertStringStartsWith('10.11.', DB::selectOne('SELECT VERSION() AS v')->v);
        $this->assertSame('scheduled_synthetic_test', DB::selectOne('SELECT DATABASE() AS d')->d);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@skip_networking AS n')->n);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
    }

    private function child(string $operation, int $id, string $go, bool $crash = false): array
    {
        $prefix = dirname(getenv('SCHEDULED_TEST_SOCKET')).'/child-'.bin2hex(random_bytes(8));
        $job = ['operation' => $operation, 'id' => $id, 'user' => $this->user->id, 'go' => $go,
            'ready' => $prefix.'.ready', 'result' => $prefix.'.result', 'crash' => $crash];
        file_put_contents($prefix.'.json', json_encode($job));
        $ext = getenv('SCHEDULED_TEST_EXTENSION_DIR');
        $process = proc_open([PHP_BINARY, '-d', 'extension='.$ext.'/mysqlnd.so', '-d', 'extension='.$ext.'/pdo_mysql.so',
            base_path('tests/Fixtures/scheduled-approval-child.php'), $prefix.'.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix.'.out', 'w'], 2 => ['file', $prefix.'.err', 'w']], $pipes,
            base_path(), array_merge(getenv(), ['SCHEDULED_CHILD_KEY' => config('app.key')]));
        $this->assertIsResource($process);
        $deadline = microtime(true) + 15;
        while (! file_exists($job['ready']) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($job['ready']);

        return [$process, $job];
    }

    private function go(): string
    {
        return dirname(getenv('SCHEDULED_TEST_SOCKET')).'/go-'.bin2hex(random_bytes(8));
    }

    public function test_two_real_process_claimers_have_one_winner(): void
    {
        $id = $this->admit();
        $go = $this->go();
        [$a, $ja] = $this->child('claim', $id, $go);
        [$b, $jb] = $this->child('claim', $id, $go);
        touch($go);
        $this->assertSame(0, proc_close($a));
        $this->assertSame(0, proc_close($b));
        $results = [json_decode(file_get_contents($ja['result'])), json_decode(file_get_contents($jb['result']))];
        $this->assertCount(1, array_filter($results));
        $this->assertSame(1, (int) DB::table('scheduled_authorizations')->value('attempt'));
    }

    public function test_real_claim_cancel_race_never_leaves_dispatchable_cancelled_row(): void
    {
        $id = $this->admit();
        $go = $this->go();
        [$a, $ja] = $this->child('claim', $id, $go);
        [$b, $jb] = $this->child('cancel', $id, $go);
        touch($go);
        $this->assertSame(0, proc_close($a));
        $this->assertSame(0, proc_close($b));
        $this->assertTrue(json_decode(file_get_contents($jb['result'])));
        $this->assertSame('cancelled', DB::table('scheduled_authorizations')->value('state'));
        $this->assertNull(DB::table('scheduled_authorizations')->value('intent_at'));
        $nonce = json_decode(file_get_contents($ja['result']));
        if ($nonce) {
            $this->assertFalse(app(ScheduledCoordinator::class)->intent($id, $nonce, $this->evidence));
        }
    }

    public function test_process_death_after_note_insert_rolls_back_and_redelivery_is_once(): void
    {
        $this->admit();
        $id = DB::table('scheduled_note_outbox')->value('id');
        $go = $this->go();
        [$p] = $this->child('outbox', $id, $go, true);
        touch($go);
        $this->assertNotSame(0, proc_close($p));
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertNull(DB::table('scheduled_note_outbox')->value('note_id'));
        $this->assertTrue(app(ScheduledOutbox::class)->deliver($id));
        $this->assertTrue(app(ScheduledOutbox::class)->deliver($id));
        $this->assertDatabaseCount('ticket_notes', 1);
    }

    public function test_process_death_after_claim_reclaims_only_with_new_nonce(): void
    {
        $id = $this->admit();
        $go = $this->go();
        [$p, $job] = $this->child('crash-claim', $id, $go);
        touch($go);
        $this->assertNotSame(0, proc_close($p));
        $old = json_decode(file_get_contents($job['result']));
        $this->assertNotNull($old);
        $this->time = $this->time->setTime(1, 5);
        $c = app(ScheduledCoordinator::class);
        $c->recover($id);
        $this->time = $this->time->addMinute();
        $new = $c->claim($id);
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertFalse($c->intent($id, $old, $this->evidence));
    }

    public function test_outbox_insert_fault_rolls_back_admission(): void
    {
        DB::unprepared("CREATE TRIGGER fail_scheduled_note BEFORE INSERT ON scheduled_note_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic crash'");
        try {
            $this->admit();
            $this->fail('admission unexpectedly committed');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('scheduled_authorizations', 0);
            $this->assertDatabaseCount('scheduled_run_fences', 0);
            $this->assertDatabaseCount('scheduled_target_fences', 0);
            $this->assertSame('awaiting_approval', $this->run->fresh()->state->value);
        } finally {
            DB::unprepared('DROP TRIGGER fail_scheduled_note');
        }
    }
}
