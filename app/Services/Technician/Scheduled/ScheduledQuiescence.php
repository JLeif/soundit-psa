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

    public function begin(): CarbonImmutable
    {
        $now = app(ScheduledClock::class)->now();
        // Never move the original epoch on a repeated drain invocation.
        DB::table('settings')->insertOrIgnore(['key' => self::KEY, 'value' => $now->format('Y-m-d H:i:s.u')]);

        return $this->at();
    }
}
