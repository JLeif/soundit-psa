<?php

namespace App\Services\Tactical;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Local identity correction only: no vendor write, merge, retirement or history move. */
class TacticalAssetRebindService
{
    public function rebind(int $fromId, int $targetId, int $clientId, string $actorLabel): array
    {
        return DB::transaction(function () use ($fromId, $targetId, $clientId, $actorLabel) {
            // Same serialization key as discovery's linkOrCreateAsset. Lock before
            // reading either direction: otherwise two agents can adopt one target.
            if (! DB::table('clients')->where('id', $clientId)->lockForUpdate()->first()) {
                $this->refuse('A current client is required.');
            }
            $assets = Asset::withTrashed()->whereIn('id', [$fromId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $assets->get($fromId);
            $target = $assets->get($targetId);
            if (! $from || ! $target || $from->client_id !== $clientId || $target->client_id !== $clientId) {
                $this->refuse('Both assets must belong to the same client.');
            }
            if ($fromId === $targetId) {
                $this->refuse('Choose a different target asset.');
            }
            if ($target->trashed()) {
                $this->refuse('The target asset is retired; choose a live asset.');
            }
            $agent = TacticalAsset::whereKey($from->tactical_asset_id)->lockForUpdate()->first();
            if (! $agent || $agent->asset_id !== $fromId
                || TacticalAsset::where('asset_id', $fromId)->whereKeyNot($agent->id)->exists()
                || Asset::withTrashed()->where('tactical_asset_id', $agent->id)->whereKeyNot($fromId)->exists()) {
                $this->refuse('The current binding is missing or inconsistent; reconcile it before rebinding.');
            }
            $displaced = null;
            if ($target->tactical_asset_id !== null) {
                $displaced = TacticalAsset::whereKey($target->tactical_asset_id)->lockForUpdate()->first();
                if (! $displaced || $displaced->asset_id !== $targetId
                    || ! $this->hasFreshDeadEvidence($displaced)
                    || Asset::withTrashed()->where('tactical_asset_id', $displaced->id)->whereKeyNot($targetId)->exists()) {
                    $this->refuse('The target is already bound to a Tactical agent.');
                }
            }
            // Only the proven reciprocal pair is exempt. Any OTHER reverse
            // pointer still refuses, including when the target has no forward FK.
            $reverse = TacticalAsset::where('asset_id', $targetId);
            if ($displaced) {
                $reverse->whereKeyNot($displaced->id);
            }
            if ($reverse->exists()) {
                $this->refuse('The target is already bound to a Tactical agent.');
            }
            if (trim($actorLabel) === '') {
                $this->refuse('An authenticated audit actor is required.');
            }

            // Both back-links and the audit commit together; an audit write
            // failure must roll back the repoint rather than leave it unrecorded.
            //
            // Clearing the FK alone would STRAND this agent's observations on the
            // source: rmm_online/last_seen_at/last_user are written by syncDevices'
            // per-run refresh and last_boot_at by refreshAssetBootTime, and after the
            // repoint neither touches the source again — the not-seen sweep only
            // writes tactical_assets. The source would keep asserting another
            // machine's connectivity and logged-in user with no writer left to
            // correct it. Release them here, unless another RMM still maintains this
            // row (Ninja/Level write these same columns on their own cadence and
            // would be the remaining source of truth).
            $this->release($from);
            if ($displaced) {
                $this->release($target);
                $displaced->update(['asset_id' => null]);
                TacticalActionLog::create([
                    'actor_label' => $actorLabel,
                    'action_key' => 'tactical.release_dead_binding',
                    'agent_id' => $displaced->agent_id,
                    'asset_id' => $targetId,
                    'target_label' => 'Asset #'.$targetId,
                    'params' => ['from_asset_id' => $targetId, 'to_asset_id' => null],
                    'result_status' => 'success',
                    'correlation_id' => (string) Str::uuid(),
                ]);
            }
            $target->update(['tactical_asset_id' => $agent->id]);
            $agent->update(['asset_id' => $targetId]);
            $log = TacticalActionLog::create([
                'actor_label' => $actorLabel,
                'action_key' => 'tactical.rebind_asset',
                'agent_id' => $agent->agent_id,
                'asset_id' => $targetId,
                'target_label' => 'Asset #'.$targetId,
                'params' => ['from_asset_id' => $fromId, 'to_asset_id' => $targetId],
                'result_status' => 'success',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return ['success' => true, 'from_asset_id' => $fromId, 'to_asset_id' => $targetId, 'audit_id' => $log->id];
        });
    }

    private function release(Asset $asset): void
    {
        $release = ['tactical_asset_id' => null];
        if ($asset->ninja_id === null && $asset->level_id === null) {
            // No longer observed is Unknown, not a permanent Offline / -30.
            $release += ['rmm_online' => null, 'last_seen_at' => null, 'last_user' => null, 'last_boot_at' => null];
        }
        $asset->update($release);
    }

    private function hasFreshDeadEvidence(TacticalAsset $agent): bool
    {
        // last_seen_at freezes when the OBSERVER stops too. Without a fresh
        // snapshot, a 30-day sync outage would authorize displacement fleet-wide
        // exactly when our evidence is worthless. Freshness makes this evidence
        // about the device, not our observer; never-stamped means age-unknown.
        // Reuse the public guard's shared 48h, not the read tool's private constant.
        // Thirty days is conservative versus EndpointInsight::LONG_OFFLINE_AFTER_DAYS
        // (7): the ruling's census found 24 vs 29 candidates, leaving the five in
        // the 7–30-day band refused. Eligibility is not proof it can never return.
        $seen = $this->persistedTimestamp($agent->getRawOriginal('last_seen_at'));
        $synced = $this->persistedTimestamp($agent->getRawOriginal('synced_at'));
        // Persisted timestamps have second precision; compare at that precision
        // so exactly 30 days cannot slip through on the clock's microseconds.
        $now = now()->startOfSecond();

        return $seen !== null && $synced !== null
            && $seen < $now->copy()->subDays(30)
            && $synced > $now->copy()->subHours(TacticalCheckPlatformGuard::FRESH_EVIDENCE_MAX_HOURS)
            && $synced <= $now;
    }

    private function persistedTimestamp(mixed $raw): ?\DateTimeImmutable
    {
        // Do not let permissive date parsing turn malformed persisted evidence
        // (relative strings or normalized invalid dates) into authorization.
        if (! is_string($raw)) {
            return null;
        }
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new \DateTimeZone(config('app.timezone')));

            return $date && $date->format('Y-m-d H:i:s') === $raw ? $date : null;
        } catch (\ValueError) {
            return null;
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['binding' => $message]);
    }
}
