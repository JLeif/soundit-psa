<?php

// Fresh-process synthetic crash/concurrency driver. Never load a deployment .env.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$socket = getenv('CIPP_TEST_SOCKET');
if (! $socket || getenv('CIPP_TEST_DATABASE') !== 'cipp_admission_synthetic_test' || ! str_ends_with($socket, '/test.sock')) {
    exit(90);
}
config(['app.key' => getenv('CIPP_CHILD_KEY'), 'database.default' => 'cipp_synthetic',
    'database.connections.cipp_synthetic' => ['driver' => 'mysql', 'unix_socket' => $socket,
        'database' => 'cipp_admission_synthetic_test', 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
]);
$db = Illuminate\Support\Facades\DB::connection();
if (! str_contains($db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')
    || (string) $db->selectOne('SELECT @@skip_networking AS n')->n !== '1') {
    exit(91);
}
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$ledger = new App\Services\Cipp\Offboarding\OffboardingLedger($db);
$mark = function (string $point) use ($job): void {
    if (($job['kill_at'] ?? '') === $point) {
        file_put_contents($job['marker'], $point);
        posix_kill(getmypid(), SIGKILL);
        exit(92);
    }
};
try {
    $db->listen(function ($query) use ($mark): void {
        if (str_starts_with($query->sql, 'insert into `cipp_offboarding_audit`') && in_array('prepared', $query->bindings, true)) {
            $mark('before_prepared_commit');
        }
    });
    if (isset($job['snapshot'])) {
        $op = $ledger->prepare($job['run_id'], 1, $job['snapshot'], App\Services\Cipp\Offboarding\OffboardingPlan::hash($job['snapshot']));
        $id = $op['operation_id'];
    } else {
        $id = $job['operation_id'];
    }
    $mark('after_prepared_commit');
    $result = $ledger->dispatch($id, function () use ($mark, $job): bool {
        $mark('before_intent');
        if (isset($job['barrier'])) {
            file_put_contents($job['marker'], 'preflight');
            $deadline = microtime(true) + 10;
            while (! file_exists($job['barrier']) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! file_exists($job['barrier'])) {
                throw new RuntimeException('Synthetic barrier timeout');
            }
        }

        return true;
    }, function (array $body) use ($mark, $job): array {
        $mark('after_intent_before_network');
        file_put_contents($job['posts'], json_encode($body)."\n", FILE_APPEND | LOCK_EX);
        foreach (['after_bytes', 'after_vendor_persist', 'after_queue', 'after_response'] as $point) {
            // These are simulated remote boundaries, not actual CIPP execution.
            $mark($point);
        }
        if (($job['hang'] ?? false) === true) {
            file_put_contents($job['marker'], 'network_hang');
            sleep(15);
        }

        return ['status' => $job['status'] ?? 503, 'body' => []];
    });
    $mark('after_receipt');
    file_put_contents($job['result'], json_encode($result));
} catch (Throwable $e) {
    file_put_contents($job['result'], json_encode(['error_class' => get_class($e)]));
    exit(2);
}
