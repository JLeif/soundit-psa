<?php

namespace App\Services\Assets;

use App\Jobs\DeliverSignal;
use App\Models\Asset;
use App\Models\AssetWatch;
use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Services\Signals\SignalHub;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place an asset watch can fire (card K3VEcxtw). Both writers of an
 * observation call observe(): the full Tactical device sync
 * (TacticalDeviceSyncService::syncDevices) and the bounded per-minute poller
 * (assets:poll-watched). Firing is decided by a single conditional UPDATE per
 * watch, so two observers racing on the same transition fire it once.
 *
 * $online is the value the sync writes to assets.rmm_online for the agent's
 * status (TacticalDeviceSyncService::rmmOnlineFromStatus): true for 'online',
 * false for 'offline' and 'overdue', null for anything else (no observation).
 *
 * last_observed_state means different things per watch state:
 *  - online watch: the last CONFIRMED state. true is recorded only when the
 *    observation is true AND last_seen is fresh, so a stale true neither fires
 *    nor disarms. It fires when a fresh true follows false or null.
 *  - offline watch: the last value the sync would write. It fires only when a
 *    false follows a recorded true.
 */
class AssetWatchEvaluator
{
    public const EVENT_TYPE = 'asset.watch_fired';

    public function __construct(private readonly SignalHub $hub) {}

    /**
     * Asset ids with at least one armed watch. The sync reads this once per run
     * so an unwatched fleet costs one query, not one per agent.
     *
     * @return array<int, true>
     */
    public function watchedAssetIds(): array
    {
        return array_fill_keys(
            AssetWatch::query()->armed()->distinct()->pluck('asset_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );
    }

    /**
     * Evaluate every armed watch on $assetId against one observation. Never
     * throws: a watch failure must not break the sync or the poller.
     *
     * @return int number of watches that fired
     */
    public function observe(int $assetId, ?bool $online, ?CarbonInterface $lastSeen, string $source): int
    {
        try {
            return $this->evaluate($assetId, $online, $lastSeen, $source);
        } catch (\Throwable $e) {
            Log::warning('[AssetWatch] Evaluation failed', [
                'asset_id' => $assetId,
                'source' => $source,
                'error' => class_basename($e),
            ]);

            return 0;
        }
    }

    private function evaluate(int $assetId, ?bool $online, ?CarbonInterface $lastSeen, string $source): int
    {
        if ($online === null) {
            return 0;
        }

        $asset = Asset::query()->find($assetId);
        if ($asset === null || ! self::isTacticalMaintained($asset)) {
            return 0;
        }

        $now = Carbon::now();
        $ageSeconds = $lastSeen !== null ? $now->getTimestamp() - $lastSeen->getTimestamp() : null;
        $fresh = $ageSeconds !== null && abs($ageSeconds) <= self::freshSeconds();

        $fired = 0;
        $watches = AssetWatch::query()->armed()->where('asset_id', $assetId)->get();

        foreach ($watches as $watch) {
            AssetWatch::query()->whereKey($watch->id)->update(['last_checked_at' => $now]);

            if ($watch->state === AssetWatch::STATE_ONLINE) {
                if ($online === false) {
                    $this->record($watch, false);

                    continue;
                }
                if (! $fresh) {
                    continue;
                }
                if ($this->claimFire($watch, true, $now)) {
                    $this->deliver($watch, $asset, $lastSeen, $ageSeconds, $source);
                    $fired++;
                }

                continue;
            }

            // offline watch
            if ($online === true) {
                $this->record($watch, true);

                continue;
            }
            if ($this->claimFire($watch, false, $now)) {
                $this->deliver($watch, $asset, $lastSeen, $ageSeconds, $source);
                $fired++;
            } else {
                $this->record($watch, false);
            }
        }

        return $fired;
    }

    /** Record a non-firing observation; idempotent. */
    private function record(AssetWatch $watch, bool $state): void
    {
        AssetWatch::query()
            ->whereKey($watch->id)
            ->whereNotNull('active_key')
            ->update(['last_observed_state' => $state]);
    }

    /**
     * The fire guard. One UPDATE decides: it matches only while the watch is
     * still armed, unexpired, (one-shot) unfired, and not already in $newState
     * on the edge that fires it. A second observer of the same transition
     * matches zero rows.
     */
    private function claimFire(AssetWatch $watch, bool $newState, CarbonInterface $now): bool
    {
        $query = AssetWatch::query()
            ->whereKey($watch->id)
            ->whereNotNull('active_key')
            ->where('expires_at', '>', $now);

        if ($newState) {
            // online: false or unknown -> confirmed true
            $query->where(fn ($q) => $q->whereNull('last_observed_state')->orWhere('last_observed_state', false));
        } else {
            // offline: recorded true -> false
            $query->where('last_observed_state', true);
        }

        $update = [
            'last_observed_state' => $newState,
            'fired_at' => $now,
            'fire_count' => DB::raw('fire_count + 1'),
        ];

        if (! $watch->repeat) {
            $query->whereNull('fired_at');
            $update['active_key'] = null;
        }

        return $query->update($update) === 1;
    }

    private function deliver(AssetWatch $watch, Asset $asset, ?CarbonInterface $lastSeen, ?int $ageSeconds, string $source): void
    {
        $event = $this->hub->emit(
            self::EVENT_TYPE,
            $asset,
            "Asset watch {$watch->id} fired ({$watch->state}) on asset {$asset->id}",
            [
                'category' => 'asset_watch',
                'client_id' => $watch->client_id,
                'watch_id' => $watch->id,
                'state' => $watch->state,
                'last_seen' => $lastSeen?->copy()->utc()->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'observed_by' => $source,
            ],
        );

        if ($event === null) {
            Log::error('[AssetWatch] Fire recorded but the signal was not emitted', ['watch_id' => $watch->id]);

            return;
        }

        $destination = $this->ownerDestination($watch->owner);

        if (! McpToken::hasLiveLabel($watch->owner)) {
            SignalDelivery::create([
                'event_id' => $event->id,
                'route_id' => null,
                'step_order' => 0,
                'destination_id' => $destination->id,
                'status' => 'suppressed',
                'error' => 'mcp-token-revoked',
            ]);

            return;
        }

        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => null,
            'step_order' => 0,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);

        DeliverSignal::dispatch($delivery->id);
    }

    /**
     * The owner's own MCP destination for watch fires: keyed by the owner's
     * token label, which is what poll_signals drains by. Created on first fire,
     * never re-enabled here (a revoke disables it and that must stick).
     */
    private function ownerDestination(string $owner): SignalDestination
    {
        return SignalDestination::query()->firstOrCreate(
            ['type' => 'mcp', 'mcp_token_label' => $owner, 'label' => self::destinationLabel($owner)],
            ['enabled' => true],
        );
    }

    public static function destinationLabel(string $owner): string
    {
        return mb_substr("Asset watches for {$owner}", 0, 255);
    }

    public static function freshSeconds(): int
    {
        return max(1, (int) config('asset_watch.fresh_seconds', 120));
    }

    /**
     * v1 scope: an asset whose online state only Tactical writes. Ninja and
     * Level write the same column on their own cadence, and the sync never
     * writes a false over their link, so a watch there could not mean what it
     * says.
     */
    public static function isTacticalMaintained(Asset $asset): bool
    {
        return $asset->ninja_id === null
            && $asset->level_id === null
            && $asset->tacticalAsset()->exists();
    }
}
