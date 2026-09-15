<?php

namespace Tests\Feature\Mcp;

use App\Models\Email;
use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\NotificationService;
use App\Services\TicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Real row locks, disposable socket-only MariaDB; never the PSA runtime DB. */
class EmailCreationContentionTest extends TestCase
{
    public function test_two_stale_callers_serialize_creation_and_return_one_ticket(): void
    {
        $socket = getenv('EMAIL_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('Requires isolated EMAIL_TEST_SOCKET MariaDB; SQLite is not lock evidence.');
        }
        $this->assertSame('email_resolution_synthetic_test', getenv('EMAIL_TEST_DATABASE'));
        $this->assertStringEndsWith('/test.sock', $socket);
        $this->assertFileExists(dirname($socket).'/db-launch.pid');
        config(['database.connections.email_synthetic' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => getenv('EMAIL_TEST_DATABASE'),
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        config(['database.default' => 'email_synthetic']);
        $dir = dirname($socket).'/contention-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $pids = [];
        try {
            $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS v')->v);
            $this->assertSame(1, (int) DB::selectOne('SELECT @@skip_networking AS n')->n);
            foreach (['emails', 'tickets', 'settings'] as $table) {
                Schema::dropIfExists($table);
            }
            Schema::create('emails', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('ticket_id')->nullable();
                $t->unsignedBigInteger('client_id');
                $t->string('from_address');
                $t->string('subject');
                $t->text('body_text');
                $t->boolean('is_read')->default(false);
                $t->timestamps();
            });
            Schema::create('tickets', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->string('subject');
                $t->string('status')->default('new');
                $t->softDeletes();
                $t->timestamps();
            });
            Schema::create('settings', function (Blueprint $t): void {
                $t->id();
                $t->string('key');
                $t->text('value')->nullable();
                $t->timestamps();
            });
            DB::table('settings')->insert(['key' => 'triage_stage_asset_assignment', 'value' => '0']);
            $id = DB::table('emails')->insertGetId(['client_id' => 1, 'from_address' => 'sender@example.test', 'subject' => 'Fixture', 'body_text' => '']);
            DB::disconnect('email_synthetic');
            foreach ([1, 2] as $child) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('email_synthetic');
                        $email = Email::findOrFail($id);
                        file_put_contents($dir.'/ready-'.$child, 'ready');
                        while (! file_exists($dir.'/go-'.$child)) {
                            usleep(10000);
                        }
                        $tickets = \Mockery::mock(TicketService::class);
                        $tickets->shouldReceive('createTicket')->andReturnUsing(function (array $data) use ($dir, $child): Ticket {
                            file_put_contents($dir.'/creating-'.$child, 'creating');
                            if ($child === 1) {
                                while (! file_exists($dir.'/release')) {
                                    usleep(10000);
                                }
                            }
                            $tid = DB::table('tickets')->insertGetId(['client_id' => $data['client_id'], 'subject' => $data['subject']]);

                            return Ticket::findOrFail($tid);
                        });
                        $notifications = \Mockery::mock(NotificationService::class);
                        $notifications->shouldReceive('notifyEmailAdded')->andReturnNull();
                        app()->instance(NotificationService::class, $notifications);
                        $graph = \Mockery::mock(GraphClient::class); // No network method is allowed.
                        $service = new EmailService($graph, $tickets);
                        file_put_contents($dir.'/attempting-'.$child, 'attempting');
                        $ticket = $service->autoCreateTicketFromEmail($email);
                        file_put_contents($dir.'/result-'.$child, json_encode(['id' => $ticket->id]));
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents($dir.'/result-'.$child, json_encode(['error' => get_class($e).': '.$e->getMessage()]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $this->awaitFile($dir.'/ready-1');
            $this->awaitFile($dir.'/ready-2');
            touch($dir.'/go-1');
            $this->awaitFile($dir.'/creating-1');
            touch($dir.'/go-2');
            $this->awaitFile($dir.'/attempting-2');
            usleep(400000);
            $blocked = ! file_exists($dir.'/creating-2') && ! file_exists($dir.'/result-2');
            touch($dir.'/release');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            $pids = [];
            $one = json_decode(file_get_contents($dir.'/result-1'), true);
            $two = json_decode(file_get_contents($dir.'/result-2'), true);
            $this->assertArrayNotHasKey('error', $one, json_encode($one));
            $this->assertArrayNotHasKey('error', $two, json_encode($two));
            $this->assertTrue($blocked, 'Second caller reached creation before the first released its email lock.');
            $this->assertSame($one['id'], $two['id']);
            $this->assertSame(1, Ticket::count());
            $this->assertSame($one['id'], Email::findOrFail($id)->ticket_id);
            $this->assertFileDoesNotExist($dir.'/creating-2');
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
