<?php

namespace App\Console\Commands;

use App\Services\AutoElevate\AutoElevateAssetSyncReport;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use App\Support\AutoElevateConfig;
use Illuminate\Console\Command;

/**
 * Stage 3a: link AutoElevate computers to PSA assets (read-only at the vendor).
 * Deliberately NOT scheduled in routes/console.php — turning the nightly run on is an
 * activation decision, not part of this change.
 */
class AutoElevateSyncAssets extends Command
{
    protected $signature = 'autoelevate:sync-assets';

    protected $description = 'Link AutoElevate computers to PSA assets by hostname within each mapped client (never creates assets)';

    public function handle(AutoElevateAssetSyncService $service): int
    {
        if (! AutoElevateConfig::isConfigured()) {
            $this->error('AutoElevate is not configured. Add the API key in Settings → Integrations.');

            return self::FAILURE;
        }

        $report = $service->sync();

        $this->info("Done: {$report->summary()}");
        foreach ($report->perClient as $clientId => $row) {
            if ($row['unmatched'] !== [] || $row['ambiguous'] !== []) {
                $this->line(sprintf('  client #%d: %d linked, %d unmatched, %d ambiguous',
                    $clientId, $row['linked'], count($row['unmatched']), count($row['ambiguous'])));
            }
        }
        foreach ($report->failedClients as $clientId => $reason) {
            $this->warn("  client #{$clientId}: read failed ({$reason}) — its asset links were left unchanged");
        }
        if ($report->stoppedEarly !== null) {
            $why = $report->stoppedEarly === AutoElevateAssetSyncReport::STOP_CONSECUTIVE_429
                ? sprintf('%d clients in a row were rate-limited (http_429)', AutoElevateAssetSyncService::STOP_AFTER_CONSECUTIVE_429)
                : sprintf('the run deadline of %d s was reached', AutoElevateAssetSyncService::MAX_RUN_SECONDS);
            $this->error("Stopped early: {$why}.");
            $this->warn(sprintf('  %d client(s) were not read and their asset links were left unchanged: %s',
                count($report->skippedClients),
                implode(', ', array_map(fn (int $id) => "#{$id}", $report->skippedClients))));
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
