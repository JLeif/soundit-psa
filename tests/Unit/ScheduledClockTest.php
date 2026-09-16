<?php

namespace Tests\Unit;

use App\Services\Technician\Scheduled\ScheduledClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledClockTest extends TestCase
{
    public function test_both_operational_drivers_use_database_utc_not_local_time(): void
    {
        CarbonImmutable::setTestNow('2030-01-01 00:00:00 UTC');
        try {
            foreach (['mysql', 'mariadb'] as $driver) {
                DB::shouldReceive('connection->getDriverName')->once()->andReturn($driver);
                DB::shouldReceive('selectOne')->with('SELECT UTC_TIMESTAMP(6) AS t')->once()
                    ->andReturn((object) ['t' => '2026-09-16 12:00:00.123456']);
                $this->assertSame('2026-09-16 12:00:00.123456', (new ScheduledClock)->now()->format('Y-m-d H:i:s.u'), $driver);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sqlite_fallback_is_local_utc_without_database_clock_query(): void
    {
        CarbonImmutable::setTestNow('2030-01-01 00:00:00 UTC');
        try {
            DB::shouldReceive('connection->getDriverName')->once()->andReturn('sqlite');
            DB::shouldReceive('selectOne')->never();
            $this->assertSame('2030-01-01 00:00:00', (new ScheduledClock)->now()->format('Y-m-d H:i:s'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
