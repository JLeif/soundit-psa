<?php

namespace App\Services\Technician\Scheduled;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Live DB marker: deliberately no process-local settings/config cache. */
final class ScheduledQuiescence
{
    public const KEY = 'scheduled_approvals.quiesced_at';

    public function at(): ?CarbonImmutable
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * In-transaction marker read. at() is a plain consistent read, so under the server
     * default REPEATABLE READ it is served from the snapshot the transaction's FIRST
     * ordinary read opened — a marker committed after that read, but before this check,
     * would be invisible for the rest of the transaction. A locking read always sees the
     * latest committed row and holds the key until commit, so a marker cannot land
     * between the check and the state change it guards. Only call this inside a
     * transaction; outside one it would lock and release immediately.
     */
    public function atForUpdate(): ?CarbonImmutable
    {
        $value = DB::table('settings')->where('key', self::KEY)->lockForUpdate()->value('value');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function begin(): CarbonImmutable
    {
        $now = app(ScheduledClock::class)->now();
        // Never move the original epoch on a repeated drain invocation.
        DB::table('settings')->insertOrIgnore(['key' => self::KEY, 'value' => $now->format('Y-m-d H:i:s.u')]);

        return $this->at();
    }
}
