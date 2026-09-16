<?php

namespace App\Console\Commands;

use App\Services\Technician\Scheduled\ScheduledClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScheduledApprovalPreflightCommand extends Command
{
    protected $signature = 'technician:scheduled-preflight';

    protected $description = 'Read-only scheduled clock certification and exhaustive privacy-safe state counts';

    public function handle(ScheduledClock $clock): int
    {
        try {
            // No IDs, nonces, payloads, targets, ticket/client data or exception text.
            // Aggregate the entire table in one query, not the cockpit latest-100 view.
            // `completed` is the normal terminal success state written by ScheduledCoordinator::settle().
            $known = ['waiting', 'claimed', 'dispatch_intent', 'submitted', 'uncertain', 'completed', 'failed', 'blocked', 'expired', 'cancelled', 'abandoned_no_send'];
            $counts = array_fill_keys($known, 0);
            $counts['unknown'] = 0;
            foreach (DB::table('scheduled_authorizations')->selectRaw('state, COUNT(*) AS total')->groupBy('state')->get() as $row) {
                $key = in_array($row->state, $known, true) ? $row->state : 'unknown';
                $counts[$key] += (int) $row->total;
            }
            $operational = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            // Explicitly refuse fallback drivers even if local timedatectl says yes.
            $healthy = $operational && $clock->healthy();
            $pending = $counts['waiting'] + $counts['claimed'] + $counts['dispatch_intent'] + $counts['submitted'] + $counts['uncertain'] + $counts['unknown'];
            $this->line(json_encode([
                'clock' => $healthy ? 'healthy' : 'unverified',
                'inventory' => $counts,
                'total' => array_sum($counts),
                'reconciliation_required' => $pending > 0,
                'activation_authorized' => false,
            ], JSON_THROW_ON_ERROR));

            return $healthy && $pending === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->line('{"status":"unavailable","activation_authorized":false}');

            return self::FAILURE;
        }
    }
}
