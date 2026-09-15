<?php

namespace App\Console\Commands;

use App\Services\Technician\Scheduled\ScheduledSweep;
use Illuminate\Console\Command;

class ScheduledApprovalSweepCommand extends Command
{
    protected $signature = 'technician:scheduled-sweep';

    protected $description = 'Recover scheduled authorization records and deliver private result notes (no adapters)';

    public function handle(ScheduledSweep $sweep): int
    {
        $counts = $sweep->run();
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
