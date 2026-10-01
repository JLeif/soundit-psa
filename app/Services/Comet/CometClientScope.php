<?php

namespace App\Services\Comet;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE client-scope rule for every Comet read surface: the staff MCP
 * toolset (CometReadOnlyToolset) and triage (TriageToolExecutor) both call
 * this, so the two surfaces cannot drift apart (card 6abe578e, C-1/C-2).
 *
 *  - unmappedError(): a client without clients.comet_group_id is refused. An
 *    unmapped client drops out of the Comet sync loop, so any comet columns it
 *    still carries are no longer refreshed; serving them would serve rot.
 *  - linkedAssetByHostname(): the Comet-linked asset (comet_device_id set) of
 *    THIS client whose hostname matches case-insensitively. Exactly one match is
 *    returned. Two or more fail closed with an error that lists only this
 *    client's candidate asset ids (the ScreenConnect preferLinked shape) and
 *    never silently picks the lowest id.
 */
final class CometClientScope
{
    /** At most this many candidates are listed in an ambiguity error. */
    public const MAX_CANDIDATES = 11;

    /**
     * The refusal text for a client that is not mapped to a Comet group, or
     * null when the client is mapped.
     */
    public static function unmappedError(Client $client): ?string
    {
        if (! empty($client->comet_group_id)) {
            return null;
        }

        $error = "{$client->name} is not mapped to a Comet organization, so Comet backup state cannot be read for this client. "
            .'Map the client in Settings, or treat this client as not covered by Comet Backup.';

        // An unmapped client drops out of the sync loop, so leftover comet
        // columns stop being refreshed forever. Refuse rather than serve rot.
        if (self::withCometBackupState(Asset::where('client_id', $client->id))->exists()) {
            $error .= ' Note: this client still carries leftover Comet backup data from a previous mapping; it is ignored because it is no longer being refreshed.';
        }

        return $error;
    }

    /**
     * @return Asset|array{error: string, candidates: array<int, array<string, mixed>>}|null
     */
    public static function linkedAssetByHostname(int $clientId, string $hostname): Asset|array|null
    {
        $matches = Asset::where('client_id', $clientId)
            ->whereNotNull('comet_device_id')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        if ($matches->count() > 1) {
            return [
                'error' => "Hostname '{$hostname}' matches more than one Comet-linked device for this client, so none was picked. "
                    .'Its backup state is UNKNOWN, not passing — resolve the duplicate asset rows, then retry.',
                'candidates' => $matches->map(fn (Asset $asset): array => [
                    'asset_id' => $asset->id,
                    'hostname' => $asset->hostname,
                    'asset_name' => $asset->name,
                ])->values()->all(),
            ];
        }

        return $matches->first();
    }

    /**
     * The Comet-state predicate: registered devices (comet_device_id) plus
     * enable-pending ones (comet_backup_enabled with no registration).
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function withCometBackupState(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query->whereNotNull('comet_device_id')
                ->orWhere('comet_backup_enabled', true);
        });
    }
}
