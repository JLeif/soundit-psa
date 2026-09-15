<?php

namespace Tests\Feature\Cipp;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Services\Cipp\Offboarding\OffboardingLedger;
use App\Services\Cipp\Offboarding\OffboardingPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Opt-in MariaDB-only synthetic harness. Never uses the application's default database. */
class OffboardingLedgerTest extends TestCase
{
    private OffboardingLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('CIPP_TEST_SOCKET');
        if (! $socket) {
            $this->markTestSkipped('MariaDB crash controls require an isolated synthetic CIPP_TEST_SOCKET. SQLite does not certify these controls.');
        }
        $expected = getenv('CIPP_TEST_DATABASE');
        if ($expected !== 'cipp_admission_synthetic_test' || ! str_ends_with($socket, '/test.sock') || ! is_file(dirname($socket).'/db-launch.pid')) {
            $this->fail('Refusing a non-synthetic MariaDB test boundary.');
        }
        config(['database.connections.cipp_synthetic' => [
            'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $expected,
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        DB::purge('cipp_synthetic');
        $db = DB::connection('cipp_synthetic');
        $this->assertStringContainsString('MariaDB', $db->selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame($expected, $db->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('1', (string) $db->selectOne('SELECT @@skip_networking AS isolated')->isolated);
        config(['database.default' => 'cipp_synthetic']);
        foreach (['cipp_offboarding_audit', 'cipp_offboarding_spent_plans', 'cipp_offboarding_target_fences', 'cipp_offboarding_operations', 'technician_runs', 'tickets', 'clients', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        // Minimal synthetic parents, actual shipped admission migration and actual ledger.
        foreach (['clients', 'tickets', 'users'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
            DB::table($table)->insert(['id' => 1]);
            DB::table($table)->insert(['id' => 2]);
        }
        Schema::create('technician_runs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('ticket_id');
            $t->string('action_type');
            $t->string('content_hash');
            $t->string('state');
            $t->timestamp('claimed_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_15_090000_create_cipp_offboarding_admission_tables.php'))->up();
        $this->ledger = new OffboardingLedger($db);
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'cipp_synthetic') {
            DB::disconnect('cipp_synthetic');
            config(['database.default' => 'sqlite']);
        }
        parent::tearDown();
    }

    private function snapshot(int $ticket = 1): array
    {
        $input = ['client_id' => 1, 'person_id' => 1, 'ticket_id' => $ticket, 'confirm_upn' => 'leaver@example.test', 'reason' => 'Synthetic test', 'staged' => true, 'actions' => ['revoke_sessions']];

        return ['namespace' => ['installation-test', 'integration-test', 'canonical-tenant-test'],
            'target_id' => 'immutable-target-test', 'target_upn' => 'leaver@example.test',
            'successor_id' => null, 'successor_upn' => null, 'input' => $input,
            'body' => OffboardingPlan::serialize($input, 'example.test', 'leaver@example.test', null, 'soundpsa-offboard:11111111-1111-4111-8111-111111111111:'.Str::uuid()),
        ];
    }

    private function runFor(array $snapshot): int
    {
        return DB::table('technician_runs')->insertGetId([
            'client_id' => $snapshot['input']['client_id'], 'ticket_id' => $snapshot['input']['ticket_id'],
            'action_type' => 'cipp_stage_offboard_user', 'content_hash' => OffboardingPlan::hash($snapshot),
            'state' => TechnicianRunState::AwaitingApproval->value, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function prepare(): array
    {
        $snapshot = $this->snapshot();
        $run = $this->runFor($snapshot);

        return $this->ledger->prepare($run, 1, $snapshot, OffboardingPlan::hash($snapshot));
    }

    public function test_intent_is_committed_before_callback_and_redelivery_never_sends(): void
    {
        $op = $this->prepare();
        $this->assertSame(2, DB::table('cipp_offboarding_target_fences')->count());
        $posts = 0;
        $observed = [];
        $send = function () use (&$posts, &$observed, $op): array {
            $posts++;
            $observed = [DB::transactionLevel(), DB::table('cipp_offboarding_operations')->where('id', $op['operation_id'])->value('admission'), DB::table('cipp_offboarding_target_fences')->count()];

            return ['status' => 503, 'body' => []];
        };
        $first = $this->ledger->dispatch($op['operation_id'], fn () => true, $send);
        $this->assertSame([0, 'send_intent', 2], $observed);
        $preflights = 0;
        $second = $this->ledger->dispatch($op['operation_id'], function () use (&$preflights) {
            $preflights++;

            return true;
        }, $send);
        $this->assertSame(0, $preflights);
        $this->assertSame('ambiguous', $first['admission']);
        $this->assertFalse($second['sent']);
        $this->assertSame(1, $posts);
        $this->assertSame('executing', DB::table('technician_runs')->value('state'));
    }

    public function test_staging_availability_surfaces_prior_operation_ticket_and_date(): void
    {
        $this->ledger->assertAvailable($this->snapshot());
        $op = $this->prepare();
        try {
            $this->ledger->assertAvailable($this->snapshot(2));
            $this->fail('Spent plan was offered as available.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($op['operation_id'], $e->getMessage());
            $this->assertStringContainsString('ticket 1', $e->getMessage());
            $this->assertStringContainsString('UTC', $e->getMessage());
            $this->assertStringContainsString('new card', $e->getMessage());
        }
    }

    public function test_cross_ticket_fence_and_permanent_conflict_receipt(): void
    {
        $first = $this->prepare();
        $this->ledger->dispatch($first['operation_id'], fn () => true, fn () => ['status' => 403]);
        $snapshot = $this->snapshot(2);
        $run = $this->runFor($snapshot);
        try {
            $this->ledger->prepare($run, 1, $snapshot, OffboardingPlan::hash($snapshot));
            $this->fail('A second ticket bypassed the permanent spent plan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($first['operation_id'], $e->getMessage());
            $this->assertStringContainsString('ticket 1', $e->getMessage());
            $this->assertStringContainsString('UTC', $e->getMessage());
            $this->assertStringContainsString('new card', $e->getMessage());
        }
        $this->assertSame(1, DB::table('cipp_offboarding_operations')->count());
        $this->assertSame('awaiting_approval', DB::table('technician_runs')->where('id', $run)->value('state'));
    }

    public function test_preflight_failure_does_not_spend_send_intent(): void
    {
        $op = $this->prepare();
        try {
            $this->ledger->dispatch($op['operation_id'], fn () => false, fn () => $this->fail('Unexpected POST'));
            $this->fail('Unavailable preflight accepted.');
        } catch (\RuntimeException) {
            $this->assertSame('prepared', DB::table('cipp_offboarding_operations')->value('admission'));
            $this->assertNull(DB::table('cipp_offboarding_operations')->value('send_intent_at'));
        }
    }

    public function test_outer_transaction_is_refused(): void
    {
        $op = $this->prepare();
        DB::beginTransaction();
        try {
            $this->ledger->dispatch($op['operation_id'], fn () => true, fn () => $this->fail('Unexpected POST'));
            $this->fail('Nested intent could be rolled back after send.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('independent durable transaction', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_db_failure_before_intent_has_zero_posts_and_rollback(): void
    {
        $op = $this->prepare();
        DB::unprepared("CREATE TRIGGER fail_intent BEFORE INSERT ON cipp_offboarding_audit FOR EACH ROW BEGIN IF NEW.event = 'send_intent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic failure'; END IF; END");
        try {
            $this->ledger->dispatch($op['operation_id'], fn () => true, fn () => $this->fail('Unexpected POST'));
            $this->fail('Intent audit failure swallowed.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame('prepared', DB::table('cipp_offboarding_operations')->value('admission'));
        }
    }

    public function test_receipt_db_failure_preserves_intent_and_fence(): void
    {
        $op = $this->prepare();
        DB::unprepared("CREATE TRIGGER fail_receipt BEFORE INSERT ON cipp_offboarding_audit FOR EACH ROW BEGIN IF NEW.event = 'ambiguous' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic failure'; END IF; END");
        $result = $this->ledger->dispatch($op['operation_id'], fn () => true, fn () => ['status' => 500]);
        $this->assertFalse($result['receipt_persisted']);
        $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
        $this->assertSame(2, DB::table('cipp_offboarding_target_fences')->count());
        $this->assertFalse($this->ledger->dispatch($op['operation_id'], fn () => true, fn () => $this->fail('Duplicate POST'))['sent']);
    }

    private function child(array $job): array
    {
        $dir = dirname(getenv('CIPP_TEST_SOCKET')).'/children';
        if (! is_dir($dir)) {
            mkdir($dir, 0700);
        }
        $prefix = $dir.'/'.Str::uuid();
        $job += ['marker' => $prefix.'.marker', 'posts' => $prefix.'.posts', 'result' => $prefix.'.result'];
        file_put_contents($prefix.'.json', json_encode($job, JSON_THROW_ON_ERROR));
        $extensions = getenv('CIPP_TEST_EXTENSION_DIR');
        $this->assertNotFalse($extensions);
        $command = [PHP_BINARY, '-d', 'extension='.$extensions.'/mysqlnd.so', '-d', 'extension='.$extensions.'/pdo_mysql.so', base_path('tests/Fixtures/offboarding-child.php'), $prefix.'.json'];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CIPP_CHILD_KEY' => config('app.key')]);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix.'.out', 'w'], 2 => ['file', $prefix.'.err', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);

        return [$process, $job, $prefix];
    }

    private function waitMarker(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }

    public function test_fresh_process_kill_before_prepare_commit_rolls_everything_back(): void
    {
        $snapshot = $this->snapshot();
        $run = $this->runFor($snapshot);
        [$child, $job] = $this->child(['snapshot' => $snapshot, 'run_id' => $run, 'kill_at' => 'before_prepared_commit']);
        $this->assertNotSame(0, proc_close($child));
        $this->assertFileExists($job['marker']);
        $this->assertSame(0, DB::table('cipp_offboarding_operations')->count());
        $this->assertSame(0, DB::table('cipp_offboarding_target_fences')->count());
        $this->assertSame('awaiting_approval', DB::table('technician_runs')->where('id', $run)->value('state'));
        $this->assertTrue($this->ledger->prepare($run, 1, $snapshot, OffboardingPlan::hash($snapshot))['created']);
    }

    public function test_fresh_process_kill_after_prepare_can_resume_once(): void
    {
        $snapshot = $this->snapshot();
        $run = $this->runFor($snapshot);
        [$child, $job] = $this->child(['snapshot' => $snapshot, 'run_id' => $run, 'kill_at' => 'after_prepared_commit']);
        $this->assertNotSame(0, proc_close($child));
        $id = DB::table('cipp_offboarding_operations')->value('id');
        $this->assertSame('prepared', DB::table('cipp_offboarding_operations')->value('admission'));
        [$retry, $retryJob] = $this->child(['operation_id' => $id, 'posts' => $job['posts']]);
        $this->assertSame(0, proc_close($retry));
        $this->assertCount(1, file($job['posts']));
    }

    public function test_every_post_intent_crash_boundary_retains_fence_and_never_resends(): void
    {
        foreach (['after_intent_before_network', 'after_bytes', 'after_vendor_persist', 'after_queue', 'after_response', 'after_receipt'] as $point) {
            // Different synthetic identities permit independent operations without clearing evidence.
            $snapshot = $this->snapshot();
            $snapshot['target_id'] .= $point;
            $snapshot['target_upn'] = $point.'@example.test';
            $snapshot['input']['confirm_upn'] = $snapshot['target_upn'];
            $snapshot['body']['user'][0]['value'] = $snapshot['target_upn'];
            $run = $this->runFor($snapshot);
            $op = $this->ledger->prepare($run, 1, $snapshot, OffboardingPlan::hash($snapshot));
            [$child, $job] = $this->child(['operation_id' => $op['operation_id'], 'kill_at' => $point]);
            $this->assertNotSame(0, proc_close($child));
            $this->assertFileExists($job['marker']);
            $before = file_exists($job['posts']) ? count(file($job['posts'])) : 0;
            $this->assertSame($point === 'after_intent_before_network' ? 0 : 1, $before);
            [$retry, $retryJob] = $this->child(['operation_id' => $op['operation_id'], 'posts' => $job['posts']]);
            $this->assertSame(0, proc_close($retry));
            $this->assertFalse(json_decode(file_get_contents($retryJob['result']), true)['sent']);
            $this->assertSame($before, file_exists($job['posts']) ? count(file($job['posts'])) : 0);
            $this->assertSame(2, DB::table('cipp_offboarding_target_fences')->where('operation_id', $op['operation_id'])->count());
        }
    }

    public function test_two_fresh_workers_race_one_intent_winner(): void
    {
        $op = $this->prepare();
        $prefix = dirname(getenv('CIPP_TEST_SOCKET')).'/barrier-'.Str::uuid();
        $job = ['operation_id' => $op['operation_id'], 'barrier' => $prefix, 'posts' => $prefix.'.posts'];
        [$a, $ja] = $this->child($job);
        [$b, $jb] = $this->child($job);
        $this->waitMarker($ja['marker']);
        $this->waitMarker($jb['marker']);
        touch($prefix);
        $this->assertSame(0, proc_close($a));
        $this->assertSame(0, proc_close($b));
        $this->assertCount(1, file($job['posts']));
        $this->assertSame(1, DB::table('cipp_offboarding_audit')->where('event', 'send_intent')->count());
    }

    public function test_expired_lease_during_network_hang_cannot_authorize_successor(): void
    {
        $op = $this->prepare();
        [$sender, $job] = $this->child(['operation_id' => $op['operation_id'], 'hang' => true]);
        $this->waitMarker($job['marker']);
        DB::table('technician_runs')->update(['claimed_at' => now()->subYears(2)]);
        [$a, $ja] = $this->child(['operation_id' => $op['operation_id'], 'posts' => $job['posts']]);
        [$b, $jb] = $this->child(['operation_id' => $op['operation_id'], 'posts' => $job['posts']]);
        $this->assertSame(0, proc_close($a));
        $this->assertSame(0, proc_close($b));
        $this->assertFalse(json_decode(file_get_contents($ja['result']), true)['sent']);
        $this->assertFalse(json_decode(file_get_contents($jb['result']), true)['sent']);
        proc_terminate($sender, SIGKILL);
        proc_close($sender);
        $this->assertCount(1, file($job['posts']));
        $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->value('admission'));
    }

    public function test_encryption_and_no_recovery_safe_reopen(): void
    {
        $this->prepare();
        $this->assertStringNotContainsString('example.test', DB::table('cipp_offboarding_operations')->value('snapshot'));
        $this->assertFalse((new TechnicianRun(['action_type' => 'cipp_stage_offboard_user']))->isRecoverySafeToReopen());
    }
}
