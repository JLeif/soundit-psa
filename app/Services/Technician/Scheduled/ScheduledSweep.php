<?php

namespace App\Services\Technician\Scheduled;

use Illuminate\Support\Facades\DB;

/** Bounded polling; no adapter execution exists in this increment. */
final class ScheduledSweep
{
    public function __construct(private ScheduledCoordinator $coordinator, private ScheduledOutbox $outbox) {}

    public function run(): array
    {
        $counts = ['recovered' => 0, 'notes' => 0, 'errors' => 0];
        foreach (DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('expires_at')->limit(100)->pluck('id') as $id) {
            try {
                $this->coordinator->recover($id);
                // No evidence provider or mutation adapter wired in PR1, so do not claim work.
                $counts['recovered']++;
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
