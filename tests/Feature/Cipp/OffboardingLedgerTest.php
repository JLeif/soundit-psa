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
        $posts = 0;
        $send = function () use (&$posts, $op): array {
            $posts++;
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('send_intent', DB::table('cipp_offboarding_operations')->where('id', $op['operation_id'])->value('admission'));
            $this->assertSame(2, DB::table('cipp_offboarding_target_fences')->count());

            return ['status' => 503, 'body' => []];
        };
        $first = $this->ledger->dispatch($op['operation_id'], fn () => true, $send);
        $second = $this->ledger->dispatch($op['operation_id'], fn () => throw new \LogicException('Must not preflight'), $send);
        $this->assertSame('ambiguous', $first['admission']);
        $this->assertFalse($second['sent']);
        $this->assertSame(1, $posts);
        $this->assertSame('executing', DB::table('technician_runs')->value('state'));
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

    public function test_encryption_and_no_recovery_safe_reopen(): void
    {
        $this->prepare();
        $this->assertStringNotContainsString('example.test', DB::table('cipp_offboarding_operations')->value('snapshot'));
        $this->assertFalse((new TechnicianRun(['action_type' => 'cipp_stage_offboard_user']))->isRecoverySafeToReopen());
    }
}
