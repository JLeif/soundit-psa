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
$app->instance(App\Services\Cipp\CippRestWriteClient::class, new App\Services\Cipp\CippRestWriteClient([
    'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1', 'client_id' => 'write-client', 'client_secret' => 'synthetic-secret',
], Illuminate\Support\Facades\Cache::store(), fn () => ['93.184.216.34']));
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($r) use ($job) {
    if (str_contains($r->url(), 'login.microsoftonline.com')) {
        return Illuminate\Support\Facades\Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
    }
    if (str_contains($r->url(), '/api/ListTenants')) {
        return Illuminate\Support\Facades\Http::response($job['tenants']);
    }
    if (str_contains($r->url(), '/api/ListUsers')) {
        return Illuminate\Support\Facades\Http::response($job['users']);
    }
    if ($job['crash'] === 'before-send') {
        posix_kill(getmypid(), SIGKILL);
    }
    file_put_contents($job['posts'], json_encode($r->data())."\n", FILE_APPEND | LOCK_EX);
    if ($job['crash'] === 'after-send') {
        posix_kill(getmypid(), SIGKILL);
    }

    return Illuminate\Support\Facades\Http::response(['Results' => 'Successfully converted owner@synthetic.test to a Shared mailbox']);
});
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
    app(App\Services\Technician\Scheduled\MailboxDispatch::class)->run($job['id']);
}
