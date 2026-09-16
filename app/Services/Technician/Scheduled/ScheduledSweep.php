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
        // Use the shared store's default lock lifetime (no explicit short lease).
        // A killed holder requires explicit operator lock recovery, never force-release here.
        $lock = Cache::lock(ScheduledPolicy::OVERLAP_LOCK);
        if (! $lock->get()) {
            return ['recovered' => 0, 'notes' => 0, 'errors' => 1];
        }
        try {
            return $this->runLocked();
        } finally {
            $lock->release();
        }
    }

    private function runLocked(): array
    {
        $counts = ['recovered' => 0, 'notes' => 0, 'errors' => 0];
        foreach (DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('expires_at')->limit(100)->pluck('id') as $id) {
            try {
                $this->coordinator->recover($id);
                $counts['recovered']++;
                if (config('scheduled_approvals.enabled')) {
                    app(MailboxDispatch::class)->run($id);
                    app(TacticalDispatch::class)->run($id);
                }
            } catch (\Throwable) {
                $counts['errors']++;
            }
        }
        foreach (DB::table('scheduled_note_outbox')->whereNull('note_id')->orderBy('id')->limit(100)->pluck('id') as $id) {
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
