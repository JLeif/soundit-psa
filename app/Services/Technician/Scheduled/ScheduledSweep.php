<?php

namespace App\Services\Technician\Scheduled;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Bounded polling; each installed dispatcher accepts only its exact action subset. */
final class ScheduledSweep
{
    public function __construct(private ScheduledCoordinator $coordinator, private ScheduledOutbox $outbox) {}

    public function run(): array
    {
        // Explicit bounded lease on every store class: a killed holder self-expires
        // instead of stalling recovery and the drain indefinitely. Never force-release
        // here; runLocked() keeps its own work inside the lease (OVERLAP_WORK_SECONDS)
        // so a live holder cannot be overlapped by the next sweep or an operator drain.
        $lock = Cache::lock(ScheduledPolicy::OVERLAP_LOCK, ScheduledPolicy::OVERLAP_LOCK_SECONDS);
        if (! $lock->get()) {
            return ['recovered' => 0, 'notes' => 0, 'deferred' => 0, 'errors' => 1];
        }
        try {
            return $this->runLocked();
        } finally {
            $lock->release();
        }
    }

    private function runLocked(): array
    {
        $counts = ['recovered' => 0, 'notes' => 0, 'deferred' => 0, 'errors' => 0];
        // A single row may legitimately hold the transport open for MAX_TRANSPORT_SECONDS,
        // so 100 rows can far outlast any lease. Start no new unit once the budget is
        // spent; the unit already in flight is itself bounded, so the whole run finishes
        // inside the lock it holds. Unprocessed rows are simply the next sweep's work.
        // The budget is SPLIT, never shared: dispatch stops at DISPATCH_WORK_SECONDS, so a
        // phase that legitimately spends its whole share can never starve note delivery,
        // which is the operator's only ticket-side view of the backlog that stalled it.
        // Whatever either loop leaves is counted, so a truncated run is never reported as
        // an empty one.
        $started = hrtime(true);
        $spent = fn (float $budget) => (hrtime(true) - $started) / 1e9 >= $budget;
        $ids = DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('expires_at')->limit(100)->pluck('id')->all();
        foreach ($ids as $index => $id) {
            if ($spent(ScheduledPolicy::DISPATCH_WORK_SECONDS)) {
                $counts['deferred'] += count($ids) - $index;
                break;
            }
            try {
                $this->coordinator->recover($id);
                $counts['recovered']++;
                // No global switch: each dispatch re-checks the kill switch, clock, grant
                // lineage and target identity itself before any vendor I/O.
                app(MailboxDispatch::class)->run($id);
                app(TacticalDispatch::class)->run($id);
            } catch (\Throwable) {
                $counts['errors']++;
            }
        }
        $pending = DB::table('scheduled_note_outbox')->whereNull('note_id')->orderBy('id')->limit(100)->pluck('id')->all();
        foreach ($pending as $index => $id) {
            if ($spent(ScheduledPolicy::OVERLAP_WORK_SECONDS)) {
                $counts['deferred'] += count($pending) - $index;
                break;
            }
            try {
                $counts['notes'] += (int) $this->outbox->deliver($id);
            } catch (\Throwable) {
                $counts['errors']++;
            }
        }
        $this->outbox->purge();

        return $counts;
    }
}
