<?php

namespace App\Console\Commands;

use App\Models\AssetWatch;
use App\Models\TacticalAsset;
use App\Services\Assets\AssetWatchEvaluator;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Support\TacticalConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Per-minute status read for WATCHED assets only (card K3VEcxtw).
 *
 * The full device sync runs every tactical_sync_interval_seconds (default 300),
 * so a device that wakes for three minutes can fall between two syncs. While at
 * least one watch is armed, this reads GET agents/{agent_id}/ for the watched
 * agents only — at most asset_watch.poll_max_agents per run, least recently
 * checked first, each with asset_watch.poll_timeout_seconds — and hands the
 * observation to AssetWatchEvaluator, the same evaluator the full sync calls.
 *
 * It WRITES NOTHING ELSE. It deliberately does not reuse
 * TacticalDeviceSyncService::syncDeviceDetail(), which also rewrites the
 * tactical_assets snapshot, assets.last_boot_at and dispatches the offline-action
 * sweep: those belong to the sync's cadence and their own guards. The full
 * sync still owns assets.rmm_online / last_seen_at.
 *
 * With no armed watch it returns before any HTTP request.
 */
class PollWatchedAssets extends Command
{
    protected $signature = 'assets:poll-watched';

    protected $description = 'Read the Tactical status of watched assets only, and fire any asset watch whose state changed.';

    public function handle(AssetWatchEvaluator $evaluator, TacticalDeviceSyncService $sync): int
    {
        $assetIds = AssetWatch::query()
            ->armed()
            // Never-checked first, then the oldest check, then id: a fair rotation
            // when more assets are watched than one run may read.
            ->selectRaw('asset_id, MAX(CASE WHEN last_checked_at IS NULL THEN 1 ELSE 0 END) as never_checked, MIN(last_checked_at) as checked')
            ->groupBy('asset_id')
            ->orderByDesc('never_checked')
            ->orderBy('checked')
            ->orderBy('asset_id')
            ->limit(max(1, (int) config('asset_watch.poll_max_agents', 25)))
            ->pluck('asset_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($assetIds === []) {
            $this->info('No armed asset watches; nothing polled.');

            return self::SUCCESS;
        }

        if (! TacticalConfig::isEnabled()) {
            $this->warn('Tactical RMM is not enabled; armed asset watches were not polled.');

            return self::SUCCESS;
        }

        $agents = TacticalAsset::query()
            ->whereIn('asset_id', $assetIds)
            ->pluck('agent_id', 'asset_id');

        $client = app(TacticalClient::class);
        $timeout = max(1, (int) config('asset_watch.poll_timeout_seconds', 3));
        $polled = 0;
        $failed = 0;
        $fired = 0;

        foreach ($assetIds as $assetId) {
            $agentId = $agents[$assetId] ?? null;
            if ($agentId === null || $agentId === '') {
                continue;
            }

            try {
                $agent = $client->getAgent((string) $agentId, timeout: $timeout);
            } catch (\Throwable $e) {
                $failed++;
                Log::debug('[AssetWatch] Poll read failed', ['asset_id' => $assetId, 'error' => class_basename($e)]);

                continue;
            }

            $polled++;
            $status = is_string($agent['status'] ?? null) ? $agent['status'] : null;
            $lastSeen = $this->parseLastSeen($agent['last_seen'] ?? null);

            $fired += $evaluator->observe($assetId, $sync->rmmOnlineFromStatus($status), $lastSeen, 'poll');
        }

        $this->info("Asset watch poll: polled {$polled}, failed {$failed}, fired {$fired}.");

        return self::SUCCESS;
    }

    private function parseLastSeen(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
