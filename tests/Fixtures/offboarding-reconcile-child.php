<?php

// Fresh-process controls, synthetic DB and fake vendor only. Never loads a deploy tree.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$socket = getenv('CIPP_PROGRESS_TEST_SOCKET');
if (! $socket || ! str_ends_with($socket, '/test.sock') || ! is_file(dirname($socket).'/db-launch.pid')) {
    exit(90);
}
config(['app.key' => getenv('CIPP_CHILD_KEY'), 'database.default' => 'cipp_progress_synthetic',
    'database.connections.cipp_progress_synthetic' => ['driver' => 'mysql', 'unix_socket' => $socket,
        'database' => 'cipp_progress_synthetic_test', 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
]);
$db = Illuminate\Support\Facades\DB::connection();
if (! str_starts_with($db->selectOne('SELECT VERSION() AS v')->v, '10.11.')
    || $db->selectOne('SELECT DATABASE() AS d')->d !== 'cipp_progress_synthetic_test'
    || (string) $db->selectOne('SELECT @@skip_networking AS n')->n !== '1') {
    exit(91);
}
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$mark = function (string $point) use ($job): void {
    if (($job['kill_at'] ?? '') === $point) {
        file_put_contents($job['marker'], $point);
        posix_kill(getmypid(), SIGKILL);
        exit(92);
    }
};
$vendor = new class($job, $mark) extends App\Services\Cipp\CippRestWriteClient
{
    public function __construct(private array $job, private Closure $mark) {}

    public function offboardingRead(string $kind, array $query = []): array
    {
        ($this->mark)('during_read');
        if (isset($this->job['barrier']) && $kind === 'scheduled' && ! isset($query['ShowHidden'])) {
            file_put_contents($this->job['marker'], 'reading');
            $deadline = microtime(true) + 15;
            while (! file_exists($this->job['barrier']) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! file_exists($this->job['barrier'])) {
                throw new RuntimeException('Synthetic barrier timeout');
            }
        }

        return $kind === 'progress' ? [$this->job['progress']] : (isset($query['ShowHidden']) ? [] : [$this->job['task']]);
    }

    public function submitOffboardingOnce(array $body): array
    {
        file_put_contents($this->job['posts'], 'FORBIDDEN', FILE_APPEND);
        throw new RuntimeException('Forbidden mutation during reconciliation');
    }
};
$scope = new class extends App\Services\Cipp\Offboarding\OffboardingScope
{
    public function __construct() {}

    public function recoveryIntegration(array $snapshot): void {} // Synthetic transport, no configured integration.
};
$app->instance(App\Services\Cipp\CippRestWriteClient::class, $vendor);
$app->instance(App\Services\Cipp\Offboarding\OffboardingScope::class, $scope);
$db->listen(function ($query) use ($mark): void {
    if (str_starts_with($query->sql, 'insert into `cipp_offboarding_observations`')) {
        $mark('observation_before_commit');
    }
    if (str_starts_with($query->sql, 'insert into `cipp_offboarding_audit`')) {
        $mark('audit_before_commit');
    }
});
$app['events']->listen(Illuminate\Database\Events\TransactionCommitted::class, function () use ($mark): void {
    $mark('after_commit_before_status');
});
try {
    $result = $app->make(App\Services\Cipp\Offboarding\OffboardingReconciler::class)->reconcile($job['run_id'], $job['client_id'], $job['observer_id']);
    file_put_contents($job['result'], json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    file_put_contents($job['result'], json_encode(['error_class' => get_class($e)]));
    exit(2);
}
