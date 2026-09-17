<?php

// Synthetic-only separate-process dispatch/crash controls. No production config or network.
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
], 'app.key' => getenv('SCHEDULED_CHILD_KEY'), 'cache.default' => 'array']);
$db = Illuminate\Support\Facades\DB::connection();
if ($db->selectOne('SELECT DATABASE() AS d')->d !== 'scheduled_synthetic_test' || (int) $db->selectOne('SELECT @@skip_networking AS n')->n !== 1) {
    exit(91);
}
// The retired config flag was set here; the persisted setting replaces it. Write it on
// THIS verified synthetic connection rather than inheriting whatever the parent left:
// a child running with scheduling off refuses admission/claim and sends nothing, so a
// dark transport would look like a passing control. Refuse loudly instead.
App\Models\Setting::setValue('scheduled_approvals_enabled', '1');
if (! App\Support\TechnicianConfig::scheduledApprovalsEnabled()) {
    exit(94);
}
$app->instance(App\Services\Technician\Scheduled\ScheduledClock::class, new class extends App\Services\Technician\Scheduled\ScheduledClock
{
    public function now(): Carbon\CarbonImmutable
    {
        return Carbon\CarbonImmutable::parse('2026-09-16 01:00:00', 'UTC');
    }

    public function healthy(): bool
    {
        return true;
    }
});
$http = new GuzzleHttp\Client(['base_uri' => 'https://tactical.example.test/', 'handler' => GuzzleHttp\HandlerStack::create(function ($request) use ($job) {
    if ($request->getMethod() === 'GET') {
        $path = $request->getUri()->getPath();
        $body = match (true) {
            str_starts_with($path, '/services/') => $job['services'],
            str_starts_with($path, '/clients/') => $job['clients'],
            default => $job['agent'],
        };

        return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, [], json_encode($body)));
    }
    if ($job['crash'] === 'before-send') {
        posix_kill(getmypid(), SIGKILL);
    }
    file_put_contents($job['posts'], (string) $request->getBody()."\n", FILE_APPEND | LOCK_EX);
    if ($job['crash'] === 'after-send') {
        posix_kill(getmypid(), SIGKILL);
    }

    return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, [], json_encode('The agent was updated successfully')));
})]);
$app->instance(App\Services\Tactical\TacticalClient::class, new App\Services\Tactical\TacticalClient($http));
file_put_contents($job['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($job['go']) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($job['go'])) {
    exit(92);
}
if ($job['operation'] === 'cancel') {
    app(App\Services\Technician\Scheduled\ScheduledCoordinator::class)->cancel($job['id'], $job['user']);
} else {
    app(App\Services\Technician\Scheduled\TacticalDispatch::class)->run($job['id']);
}
