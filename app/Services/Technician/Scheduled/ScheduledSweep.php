<?php

namespace App\Services\Technician\Scheduled;

use Illuminate\Support\Facades\DB;

/** Bounded polling with an exact mailbox-only dispatch lane. */
final class ScheduledSweep
{
    public function __construct(private ScheduledCoordinator $coordinator, private ScheduledOutbox $outbox) {}

    public function run(): array
    {
        $counts = ['recovered' => 0, 'notes' => 0, 'errors' => 0];
        foreach (DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('expires_at')->limit(100)->pluck('id') as $id) {
            try {
                $this->coordinator->recover($id);
                $counts['recovered']++;
                if (config('scheduled_approvals.enabled')) {
                    app(MailboxDispatch::class)->run($id);
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
