<?php

namespace App\Services\Technician\Scheduled;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ScheduledClock
{
    public const MAX_SKEW_SECONDS = 2;

    public function now(): CarbonImmutable
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // SQLite is for unit controls, not operational clock certification.
            return CarbonImmutable::now('UTC');
        }

        return CarbonImmutable::parse(DB::selectOne('SELECT UTC_TIMESTAMP(6) AS t')->t, 'UTC');
    }

    public function healthy(): bool
    {
        try {
            $start = microtime(true);
            $db = (float) $this->now()->format('U.u');
            $end = microtime(true);
            if (! self::withinThreshold($db, $start, $end)) {
                return false;
            }
            $process = new Process(['timedatectl', 'show', '--property=NTPSynchronized', '--value']);
            $process->setTimeout(1);
            $process->run();

            return $process->isSuccessful() && trim($process->getOutput()) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function withinThreshold(float $database, float $before, float $after): bool
    {
        // Conservative: both edges must be within threshold. Slow/rolled-back reads refuse.
        return $after >= $before
            && abs($database - $before) <= self::MAX_SKEW_SECONDS
            && abs($database - $after) <= self::MAX_SKEW_SECONDS;
    }
}
