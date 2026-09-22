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
            if ($target->tactical_asset_id !== null || TacticalAsset::where('asset_id', $targetId)->exists()) {
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
            $release = ['tactical_asset_id' => null];
            if ($from->ninja_id === null && $from->level_id === null) {
                $release += ['rmm_online' => false, 'last_seen_at' => null, 'last_user' => null, 'last_boot_at' => null];
            }
            $from->update($release);
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

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['binding' => $message]);
    }
}
