<?php

namespace Tests\Feature\Technician;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Real socket-only MariaDB adapter/UI controls, with hermetic provider transport. */
class ScheduledMailboxMariaDbTest extends ScheduledMailboxTest
{
    private function child(int $id, string $go, string $posts, string $operation = 'dispatch', string $crash = '')
    {
        $prefix = dirname(getenv('SCHEDULED_TEST_SOCKET')).'/mailbox-child-'.bin2hex(random_bytes(6));
        file_put_contents($prefix.'.json', json_encode(['id' => $id, 'user' => $this->user->id, 'go' => $go,
            'posts' => $posts, 'ready' => $prefix.'.ready', 'operation' => $operation, 'crash' => $crash,
            'tenants' => $this->tenants, 'users' => $this->users]));
        $ext = getenv('SCHEDULED_TEST_EXTENSION_DIR');
        $p = proc_open([PHP_BINARY, '-d', 'extension='.$ext.'/mysqlnd.so', '-d', 'extension='.$ext.'/pdo_mysql.so',
            base_path('tests/Fixtures/scheduled-mailbox-child.php'), $prefix.'.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix.'.out', 'w'], 2 => ['file', $prefix.'.err', 'w']], $pipes,
            base_path(), array_merge(getenv(), ['SCHEDULED_CHILD_KEY' => config('app.key')]));
        $this->assertIsResource($p);
        $deadline = microtime(true) + 15;
        while (! file_exists($prefix.'.ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($prefix.'.ready');

        return $p;
    }

    public static function races(): array
    {
        return [['dispatch'], ['cancel']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('races')]
    public function test_separate_process_dispatch_dispatch_or_cancel_is_at_most_once(string $second): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $id = $this->admit();
        $go = dirname(getenv('SCHEDULED_TEST_SOCKET')).'/mailbox-go-'.bin2hex(random_bytes(6));
        $posts = $go.'.posts';
        $a = $this->child($id, $go, $posts);
        $b = $this->child($id, $go, $posts, $second);
        touch($go);
        $this->assertSame(0, proc_close($a));
        $this->assertSame(0, proc_close($b));
        $state = DB::table('scheduled_authorizations')->value('state');
        $this->assertContains($state, $second === 'cancel' ? ['cancelled', 'completed'] : ['completed']);
        $this->assertSame($state === 'completed' ? 1 : 0, file_exists($posts) ? count(file($posts)) : 0);
    }

    public static function crashes(): array
    {
        return [['before-send', 0], ['after-send', 1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crashes')]
    public function test_real_dispatch_process_death_after_intent_cannot_replay(string $crash, int $sends): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $id = $this->admit();
        $go = dirname(getenv('SCHEDULED_TEST_SOCKET')).'/mailbox-go-'.bin2hex(random_bytes(6));
        $posts = $go.'.posts';
        $p = $this->child($id, $go, $posts, 'dispatch', $crash);
        touch($go);
        $this->assertNotSame(0, proc_close($p));
        $this->assertSame('dispatch_intent', DB::table('scheduled_authorizations')->value('state'));
        $this->time = $this->time->setTime(1, 6);
        app(\App\Services\Technician\Scheduled\ScheduledCoordinator::class)->recover($id);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $p = $this->child($id, $go, $posts);
        $this->assertSame(0, proc_close($p));
        $this->assertSame($sends, file_exists($posts) ? count(file($posts)) : 0);
        $this->assertDatabaseCount('scheduled_target_fences', 1);
    }

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
}
