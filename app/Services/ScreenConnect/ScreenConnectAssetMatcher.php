<?php

namespace App\Services\ScreenConnect;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Client-scoped hostname → asset matching shared by the ScreenConnect webhook ingest
 * (ScreenConnectSyncService::resolveAsset) and the read tools
 * (ScreenConnectReadOnlyToolset::findAsset), so the two cannot drift apart.
 *
 * Two rules, always applied in this order and ALWAYS scoped to one PSA client:
 *
 *  1. EXACT — LOWER(hostname) = name OR LOWER(name) = name. The OR is grouped inside
 *     the client predicate: an ungrouped `client_id = ? AND hostname = ? OR name = ?`
 *     lets the name branch match ANY client's asset, because AND binds tighter than OR.
 *
 *  2. FIRST LABEL — the stored hostname is fully qualified and its first DNS label
 *     equals the short name, e.g. a stored "test-mbp.lan" for a webhook machine name
 *     "Test-MBP". Implemented as LOWER(hostname) LIKE '<short>.%' with LIKE
 *     metacharacters in the input escaped, so a machine name carrying % or _ can never
 *     widen the match. Consulted only when rule 1 finds nothing, so an exact match is
 *     always preferred.
 *
 * AMBIGUITY RULE: if more than one of the client's assets matches by first label only
 * (e.g. "test-mbp.lan" and "test-mbp.corp.example"), nothing is picked. Choosing one
 * silently would attach a session — and its online state and activity — to a device
 * that may not be it. The match fails closed (null) and a non-PII reason is logged
 * (client id and candidate count; no hostnames).
 */
final class ScreenConnectAssetMatcher
{
    public const LIKE_ESCAPE = '!';

    /** Exact (rule 1) client-scoped match query; callers choose ordering and ->first(). */
    public static function exactQuery(int $clientId, string $name): Builder
    {
        $lower = mb_strtolower($name);

        return Asset::where('client_id', $clientId)
            ->where(function (Builder $query) use ($lower) {
                $query->whereRaw('LOWER(hostname) = ?', [$lower])
                    ->orWhereRaw('LOWER(name) = ?', [$lower]);
            });
    }

    /**
     * Rule 2: the unique client asset whose stored hostname's first label equals
     * $short, or null when there is none or more than one (see the ambiguity rule).
     */
    public static function uniqueFirstLabelMatch(int $clientId, string $short, string $context): ?Asset
    {
        if ($short === '' || str_contains($short, '.')) {
            return null;
        }

        $candidates = Asset::where('client_id', $clientId)
            ->whereRaw(
                "LOWER(hostname) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'",
                [self::escapeLike(mb_strtolower($short)).'.%'],
            )
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() > 1) {
            Log::info('[ScreenConnect] first-label hostname match is ambiguous; left unmatched (fail closed)', [
                'context' => $context,
                'client_id' => $clientId,
                'candidates' => 'more than one',
            ]);
        }

        return null;
    }

    /** First DNS label of a machine name ("Test-MBP.lan" → "Test-MBP"). */
    public static function firstLabel(string $hostname): string
    {
        return explode('.', $hostname)[0];
    }

    public static function escapeLike(string $value): string
    {
        $e = self::LIKE_ESCAPE;

        return str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $value);
    }
}
