<?php

namespace App\Console\Commands;

use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use App\Services\AppRiver\AppRiverManualSync;
use Illuminate\Console\Command;

class AppRiverSyncLicenses extends Command
{
    protected $signature = 'appriver:sync-licenses
        {--manual : Started by "Sync Licenses Now"; record the outcome for the Integrations page}';

    protected $description = 'Sync M365 subscription seat counts from AppRiver for mapped clients';

    public function handle(): int
    {
        $manual = (bool) $this->option('manual');

        if (! AppRiverClient::isConnected()) {
            $this->error('AppRiver is not connected. Connect via Settings > Integrations first.');
            if ($manual) {
                (new AppRiverManualSync)->recordFailure('AppRiver is not connected. Reconnect in Settings > Integrations > AppRiver.');
            }

            return self::FAILURE;
        }

        $client = new AppRiverClient;
        $service = new AppRiverLicenseSyncService($client);

        $this->info('Syncing AppRiver subscriptions...');

        try {
            $result = $service->syncLicenses(function ($r) {
                // Progress callback — silent for now
            });
        } catch (\Throwable $e) {
            // A manual run must never leave the page reading "running" forever.
            if ($manual) {
                (new AppRiverManualSync)->recordFailure(class_basename($e).': '.$e->getMessage());
            }

            throw $e;
        }

        $this->info("Done: {$result->summary()}");

        if ($manual) {
            $recorder = new AppRiverManualSync;
            $result->errors > 0
                ? $recorder->recordFailure("{$result->errors} error(s): ".implode('; ', array_slice($result->errorMessages, 0, 3)))
                : $recorder->recordSuccess($result->summary());
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
