<?php

// Synthetic MariaDB controls only; never reads deployment configuration.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$job = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$socket = getenv('SCHEDULED_TEST_SOCKET');
if (! $socket || ! str_ends_with($socket, '/test.sock') || ! is_file(dirname($socket).'/db-launch.pid')) {
    exit(90);
}
config(['database.default' => 'scheduled_synthetic', 'database.connections.scheduled_synthetic' => [
    'driver' => 'mysql', 'unix_socket' => $socket, 'database' => 'scheduled_synthetic_test',
    'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
], 'app.key' => getenv('SCHEDULED_CHILD_KEY')]);
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT DATABASE() AS d, @@skip_networking AS n')->d !== 'scheduled_synthetic_test' || (int) $db->selectOne('SELECT @@skip_networking AS n')->n !== 1) {
    exit(91);
}
// There is no global scheduling switch any more (the execute_at parameter under the
// per-tool grant is the feature). The kill switch is the only stop: a child running with
// it engaged refuses admission/claim and reports a trivially clean result, so the
// concurrency control would prove nothing. Refuse loudly on THIS verified connection.
if (App\Support\TechnicianConfig::killSwitchEngaged()) {
    exit(94);
}
$app->instance(App\Services\Technician\Scheduled\ScheduledClock::class, new class extends App\Services\Technician\Scheduled\ScheduledClock
{
    public function now(): Carbon\CarbonImmutable
    {
        return Carbon\CarbonImmutable::parse('2026-09-15 01:00:00', 'UTC');
    }

    public function healthy(): bool
    {
        return true;
    }
});
file_put_contents($job['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($job['go']) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($job['go'])) {
    exit(92);
}
$coordinator = app(App\Services\Technician\Scheduled\ScheduledCoordinator::class);
if ($job['operation'] === 'admit') {
    $app->instance(App\Services\Technician\Scheduled\ScheduledClock::class, new class extends App\Services\Technician\Scheduled\ScheduledClock
    {
        public function now(): Carbon\CarbonImmutable
        {
            return Carbon\CarbonImmutable::parse('2026-09-15 00:00:00', 'UTC');
        }

        public function healthy(): bool
        {
            return true;
        }
    });
    $evidence = new class implements App\Services\Technician\Scheduled\ScheduledEvidence
    {
        public function approve(App\Models\TechnicianRun $run, ?App\Models\User $user, array $inputs): array
        {
            return ['payload' => ['forward' => 'synthetic@example.test'], 'target' => ['tenant_id' => 'synthetic-tenant', 'object_id' => 'synthetic-object']];
        }

        public function revalidate(App\Models\TechnicianRun $run, ?App\Models\User $user, array $approved): array
        {
            return $approved;
        }
    };
    $result = app(App\Services\Technician\Scheduled\ScheduledAdmission::class)->admit($job['id'], App\Services\Technician\Scheduled\ScheduledApprover::human($job['user']), str_repeat('a', 64), null,
        '2026-09-15 01:00:00', '2026-09-15 02:00:00', 'UTC', [], $evidence);
} elseif ($job['operation'] === 'claim') {
    $result = $coordinator->claim($job['id']);
} elseif ($job['operation'] === 'cancel') {
    $result = $coordinator->cancel($job['id'], $job['user']);
} elseif ($job['operation'] === 'outbox') {
    if ($job['crash'] ?? false) {
        $db->listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into `ticket_notes`')) {
                // Hard death between insert and acknowledgement. Connection rollback is the guard.
                posix_kill(getmypid(), SIGKILL);
            }
        });
    }
    $result = app(App\Services\Technician\Scheduled\ScheduledOutbox::class)->deliver($job['id']);
} elseif ($job['operation'] === 'recover') {
    $coordinator->recover($job['id']);
    $result = true;
} elseif ($job['operation'] === 'crash-intent') {
    $nonce = $coordinator->claim($job['id']);
    // Synthetic intent models the future adapter boundary, not a production dispatch path.
    $db->table('scheduled_authorizations')->where('id', $job['id'])->where('nonce', $nonce)->update([
        'state' => 'dispatch_intent', 'intent_at' => '2026-09-15 01:00:00',
    ]);
    file_put_contents($job['result'], json_encode($nonce));
    posix_kill(getmypid(), SIGKILL);
} elseif ($job['operation'] === 'crash-claim') {
    $result = $coordinator->claim($job['id']);
    file_put_contents($job['result'], json_encode($result));
    posix_kill(getmypid(), SIGKILL);
} else {
    exit(93);
}
file_put_contents($job['result'], json_encode($result));
