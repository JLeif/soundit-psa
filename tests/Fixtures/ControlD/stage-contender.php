<?php

// Test-only separate-process contender; isolated SQLite fixture, no real transport.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
$transport = new App\Services\ControlD\ControlDClient(['api_key' => 'synthetic-key', 'handler' => new GuzzleHttp\Handler\MockHandler([])]);
$writer = new App\Services\ControlD\ControlDOnboardingStaged($transport, new App\Services\ControlD\ControlDProvisioning($transport));
$actor = App\Models\User::findOrFail((int) $argv[2]);
try {
    $writer->stageOrganization($actor, (int) $argv[3], 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
    echo 'same=accepted';
    exit(2);
} catch (App\Services\ControlD\ControlDClientException $e) {
    if (! str_contains($e->getMessage(), 'already owns')) {
        echo 'same=wrong-refusal';
        exit(3);
    }
}
$writer->stageOrganization($actor, (int) $argv[4], 'Synthetic Other', 'other@example.invalid', 1, 'synthetic-region');
echo 'same=refused other=staged';
