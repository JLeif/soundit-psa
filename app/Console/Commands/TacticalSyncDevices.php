<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class TacticalSyncDevices extends Command
{
    protected $signature = 'tactical:sync-devices
        {--client= : Sync devices for a specific client ID}';

    protected $description = 'Sync devices from Tactical RMM into tactical_assets table';

    public function handle(): int
    {
        if (! TacticalConfig::isEnabled()) {
            $this->error('Tactical RMM is disabled or not configured. Check Settings → Integrations.');

            return self::FAILURE;
        }

        $clientId = $this->option('client');

        if ($clientId) {
            $client = Client::find($clientId);
            if (! $client) {
                $this->error("Client {$clientId} not found.");

                return self::FAILURE;
            }
            $this->info("Scoping to client: {$client->name}");
        }

        $service = new TacticalDeviceSyncService(app(TacticalClient::class));

        $this->info('Syncing Tactical RMM devices...');

        $result = $service->syncDevices($clientId ? (int) $clientId : null);

        $this->info("Done: {$result->summary()}");

        if (! empty($result->details['linked'])) {
            $this->info("Linked: {$result->details['linked']}");
        }

        if (! empty($result->details['assets_created'])) {
            $this->info("Assets created: {$result->details['assets_created']}");
        }

        // A skipped device carries no asset link, so its Tactical data surfaces
        // nowhere. The remedy differs by reason though — a hostname conflict
        // means a matching asset IS in the list, held by a stale agent row — so
        // name the reasons rather than assert the device is missing. Warn rather
        // than info, so "0 created" cannot read as "nothing to do here".
        if (! empty($result->details['assets_skipped'])) {
            $this->warn("Assets skipped: {$result->details['assets_skipped']}");

            foreach ($result->details['assets_skipped_reasons'] ?? [] as $reason => $count) {
                $this->warn("  - {$reason}: {$count}");
            }

            // Name each retired asset that blocked a create, one row per asset.
            // The list is capped in the service; soft_deleted_conflict above
            // counts devices, and assets_skipped_retired_total counts the
            // distinct retired assets, listed or not. Hostnames are escaped:
            // warn() wraps the text in console markup without escaping it.
            $retired = $result->details['assets_skipped_retired'] ?? [];
            if ($retired !== []) {
                $this->warn('Retired (soft-deleted) assets blocking a create:');

                foreach ($retired as $row) {
                    $this->warn('  - '.OutputFormatter::escape(TacticalDeviceSyncService::describeRetiredSkip($row)));
                }

                $unlisted = max(count($retired), (int) ($result->details['assets_skipped_retired_total'] ?? 0)) - count($retired);
                if ($unlisted > 0) {
                    $this->warn("  - +{$unlisted} more asset".($unlisted === 1 ? '' : 's').' not listed');
                }
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
