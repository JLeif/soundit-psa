<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot mapping a PSA client to a UniFi site (psa-jpygj: one client -> MANY
 * sites, for clients with several physical locations). A site maps to at most
 * one client — the UNIQUE on unifi_site_id is preserved on this table.
 * unifi_host_id records the owning console. Supersedes the one-to-one
 * clients.unifi_site_id/unifi_host_id columns, which are backfilled here and
 * dropped once every reader uses the pivot.
 */
class ClientUnifiSite extends Model
{
    protected $fillable = ['client_id', 'unifi_site_id', 'unifi_host_id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Copy legacy clients.unifi_site_id/unifi_host_id into the pivot, idempotently.
     * Invoked by the create-table migration and safe to re-run: a site already
     * present (UNIQUE) is skipped, so it never duplicates. Guarded on the legacy
     * column so it no-ops once that column is dropped. Returns rows copied.
     */
    public static function backfillFromLegacyColumns(): int
    {
        if (! Schema::hasColumn('clients', 'unifi_site_id')) {
            return 0;
        }

        $copied = 0;
        DB::table('clients')
            ->whereNotNull('unifi_site_id')
            ->orderBy('id')
            ->get(['id', 'unifi_site_id', 'unifi_host_id'])
            ->each(function ($row) use (&$copied) {
                if (static::where('unifi_site_id', $row->unifi_site_id)->exists()) {
                    return;
                }

                static::create([
                    'client_id' => $row->id,
                    'unifi_site_id' => $row->unifi_site_id,
                    'unifi_host_id' => $row->unifi_host_id,
                ]);
                $copied++;
            });

        return $copied;
    }
}
