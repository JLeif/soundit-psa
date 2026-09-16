<?php

namespace Tests\Unit;

use App\Services\Technician\Scheduled\ScheduledPolicy;
use PHPUnit\Framework\TestCase;

class ScheduledWorkBudgetTest extends TestCase
{
    public function test_note_reserve_covers_last_inflight_unit_and_positive_note_budget(): void
    {
        $unit = ScheduledPolicy::MAX_TRANSPORT_SECONDS + ScheduledPolicy::RECEIPT_GRACE_SECONDS;
        $this->assertGreaterThanOrEqual($unit + 300, ScheduledPolicy::NOTE_WORK_SECONDS);
        $this->assertGreaterThan(0, ScheduledPolicy::DISPATCH_WORK_SECONDS);
        $this->assertLessThanOrEqual(ScheduledPolicy::OVERLAP_WORK_SECONDS - 300, ScheduledPolicy::DISPATCH_WORK_SECONDS + $unit);
    }
}
