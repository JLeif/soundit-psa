<?php

namespace App\Services\Tactical;

use App\Models\TacticalScript;
use Illuminate\Support\Facades\Log;

class TacticalScriptSyncService
{
    public function __construct(
        private readonly TacticalClient $client,
    ) {}

    public function syncScripts(): array
    {
        $scripts = $this->client->getScripts();

        $stats = ['synced' => 0, 'created' => 0];

        foreach ($scripts as $script) {
            $scriptId = $script['id'] ?? null;
            if (! $scriptId) {
                continue;
            }

            // Skip deprecated scripts
            $category = $script['category'] ?? '';
            if (strtoupper($category) === 'DEPRECATED') {
                continue;
            }

            // `shell` is a PLATFORM-COMPATIBILITY SIGNAL consumed by the
            // check-creation guard and the platform_mismatch annotations — a
            // missing upstream key must be stored as NULL (unknown) and
            // logged, never silently defaulted (psa-0pb9m R3 A5: defaulting
            // absence to 'powershell' turned a drifted response into a usable
            // compatibility verdict). The guard fails closed on a null-shell,
            // no-platforms row.
            $shell = isset($script['shell']) && is_scalar($script['shell']) && trim((string) $script['shell']) !== ''
                ? (string) $script['shell']
                : null;
            if ($shell === null) {
                // One degradation dialect everywhere (psa-0pb9m R4 U5): name
                // the script, say compatibility could not be verified, give
                // the recovery — the same wording the guard refusal and the
                // read-surface annotation use.
                $scriptName = isset($script['name']) && is_scalar($script['name']) && trim((string) $script['name']) !== ''
                    ? (string) $script['name']
                    : 'script '.$scriptId;
                Log::warning("[TacticalSync] Script '{$scriptName}' (id {$scriptId}) has no shell in getScripts, so its platform "
                    .'compatibility could not be verified — stored as NULL (unknown); check creation will refuse it unless '
                    .'supported_platforms carries a signal. Re-run tactical:sync-scripts, or verify the script in Tactical (upstream drift?).', [
                        'tactical_script_id' => $scriptId,
                        'name' => $script['name'] ?? null,
                    ]);
            }

            $tacticalScript = TacticalScript::updateOrCreate(
                ['tactical_script_id' => $scriptId],
                [
                    'name' => $script['name'] ?? 'Unknown',
                    'description' => $script['description'] ?? null,
                    'shell' => $shell,
                    'category' => $category ?: null,
                    'default_timeout' => $script['default_timeout'] ?? 90,
                    'supported_platforms' => $script['supported_platforms'] ?? null,
                    'hidden' => $script['hidden'] ?? false,
                    'synced_at' => now(),
                ]
            );

            if ($tacticalScript->wasRecentlyCreated) {
                $stats['created']++;
            }
            $stats['synced']++;
        }

        // Remove scripts no longer in Tactical
        $syncedIds = collect($scripts)->pluck('id')->filter()->all();
        $removed = TacticalScript::whereNotIn('tactical_script_id', $syncedIds)->delete();
        $stats['removed'] = $removed;

        Log::info('[TacticalSync] Script sync complete', $stats);

        return $stats;
    }
}
