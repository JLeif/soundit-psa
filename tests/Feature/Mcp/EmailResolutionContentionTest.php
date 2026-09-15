<?php

namespace Tests\Feature\Mcp;

use App\Models\Email;
use App\Models\EmailResolutionProposal;
use App\Models\User;
use App\Services\Email\EmailResolutionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Opt-in disposable MariaDB; no application/runtime connection is ever used for fixtures. */
class EmailResolutionContentionTest extends TestCase
{
    public function test_double_approval_contends_and_one_atomic_resolution_commits(): void
    {
        $socket = getenv('EMAIL_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('Isolated EMAIL_TEST_SOCKET MariaDB required.');
        }
        $this->assertSame('email_resolution_synthetic_test', getenv('EMAIL_TEST_DATABASE'));
        $this->assertStringEndsWith('/test.sock', $socket);
        $this->assertFileExists(dirname($socket).'/db-launch.pid');
        config(['database.connections.email_synthetic' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => getenv('EMAIL_TEST_DATABASE'),
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'database.default' => 'email_synthetic']);
        $dir = dirname($socket).'/approval-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $pids = [];
        try {
            $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS v')->v);
            $this->assertSame(1, (int) DB::selectOne('SELECT @@skip_networking AS n')->n);
            Schema::disableForeignKeyConstraints();
            foreach (['email_resolution_proposals', 'emails', 'clients', 'users', 'settings'] as $table) {
                Schema::dropIfExists($table);
            }
            Schema::enableForeignKeyConstraints();
            Schema::create('clients', function (Blueprint $t): void {
                $t->id();
                $t->softDeletes();
            });
            Schema::create('users', function (Blueprint $t): void {
                $t->id();
                $t->string('role');
                $t->boolean('is_active');
            });
            Schema::create('emails', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id')->nullable();
                $t->string('from_address')->index();
                $t->timestamps();
            });
            Schema::create('settings', function (Blueprint $t): void {
                $t->id();
                $t->string('key');
                $t->text('value')->nullable();
            });
            (require database_path('migrations/2026_09_15_150000_create_email_resolution_proposals_table.php'))->up();
            DB::table('clients')->insert(['id' => 1]);
            DB::table('users')->insert(['id' => 1, 'role' => 'admin', 'is_active' => true]);
            DB::table('emails')->insert([['id' => 1, 'from_address' => 'sender@example.test'], ['id' => 2, 'from_address' => 'sender@example.test']]);
            $staged = app(EmailResolutionService::class)->stage(['email_id' => 1, 'reason' => 'Fixture'], 1, 'fixture');
            $this->assertSame(2, $staged['sender_wide_count']);
            DB::disconnect('email_synthetic');
            foreach ([1, 2] as $child) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('email_synthetic');
                        // Hold the real proposal lock AFTER the SELECT returned,
                        // before any cohort update. The contender must wait here.
                        DB::listen(function (QueryExecuted $query) use ($child, $dir): void {
                            if (str_contains($query->sql, 'email_resolution_proposals') && str_contains($query->sql, 'for update')) {
                                file_put_contents($dir.'/locked-'.$child, 'locked');
                                if ($child === 1) {
                                    while (! file_exists($dir.'/release')) {
                                        usleep(10000);
                                    }
                                }
                            }
                        });
                        $user = User::findOrFail(1);
                        file_put_contents($dir.'/ready-'.$child, 'ready');
                        while (! file_exists($dir.'/go-'.$child)) {
                            usleep(10000);
                        }
                        file_put_contents($dir.'/attempting-'.$child, 'attempting');
                        $result = app(EmailResolutionService::class)->approve($staged['proposal_id'], $user);
                        file_put_contents($dir.'/result-'.$child, json_encode($result));
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents($dir.'/result-'.$child, json_encode(['exception' => get_class($e).': '.$e->getMessage()]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $this->awaitFile($dir.'/ready-1');
            $this->awaitFile($dir.'/ready-2');
            touch($dir.'/go-1');
            $this->awaitFile($dir.'/locked-1');
            touch($dir.'/go-2');
            $this->awaitFile($dir.'/attempting-2');
            usleep(400000);
            $blocked = ! file_exists($dir.'/locked-2') && ! file_exists($dir.'/result-2');
            touch($dir.'/release');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            $pids = [];
            $one = json_decode(file_get_contents($dir.'/result-1'), true);
            $two = json_decode(file_get_contents($dir.'/result-2'), true);
            $this->assertArrayNotHasKey('exception', $one, json_encode($one));
            $this->assertArrayNotHasKey('exception', $two, json_encode($two));
            $this->assertTrue($blocked, 'Second approval did not contend on the held proposal lock.');
            $this->assertTrue($one['success'] ?? false, json_encode($one));
            $this->assertStringContainsString('already handled', $two['error'] ?? '');
            $this->assertSame([1, 2], $one['email_ids']);
            $this->assertSame(2, Email::where('client_id', 1)->count());
            $proposal = EmailResolutionProposal::findOrFail($staged['proposal_id']);
            $this->assertSame('done', $proposal->state);
            $this->assertSame(1, $proposal->approved_by);
        } finally {
            touch($dir.'/release');
            touch($dir.'/go-1');
            touch($dir.'/go-2');
            foreach ($pids as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            DB::disconnect('email_synthetic');
            config(['database.default' => 'sqlite']);
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }
}
