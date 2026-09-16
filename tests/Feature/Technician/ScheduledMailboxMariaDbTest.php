<?php

namespace Tests\Feature\Technician;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Real socket-only MariaDB adapter/UI controls, with hermetic provider transport. */
class ScheduledMailboxMariaDbTest extends ScheduledMailboxTest
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
}
