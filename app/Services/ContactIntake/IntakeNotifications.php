<?php

namespace App\Services\ContactIntake;

use App\Models\ContactSubmission;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Support\ContactIntakeConfig;
use Illuminate\Support\Facades\DB;

/** Durable internal-only outbox; no submitted text is passed to the notifier. */
final class IntakeNotifications
{
    public function __construct(private readonly EmailService $email) {}

    public static function record(ContactSubmission $submission, string $event): void
    {
        DB::table('contact_intake_notifications')->updateOrInsert(
            ['contact_submission_id' => $submission->id, 'event' => $event],
            ['updated_at' => now(), 'created_at' => now()],
        );
    }

    public function drain(): int
    {
        if (! ContactIntakeConfig::enabled()) {
            return 0;
        }
        $count = 0;
        // lazyById walks EVERY unsent row in id-ordered pages. A fixed first-100 window let
        // 100 undeliverable alerts (no active recipient) hide every later one forever (r1 diff:6).
        foreach (DB::table('contact_intake_notifications')->whereNull('sent_at')->lazyById(100) as $item) {
            $row = ContactSubmission::findOrFail($item->contact_submission_id);
            $ticket = $row->ticket_id ? Ticket::find($row->ticket_id) : null;
            $recipient = $ticket?->assignee_id ?? ContactIntakeConfig::ownerId();
            $user = $recipient ? User::whereKey($recipient)->where('is_active', true)->first() : null;
            if (! $user || ! $user->email) {
                continue; // Retain the row; owner configuration must never discard an alert.
            }
            // Sent here and stamped only after delivery (context:5). The generic ticket notifier
            // drops extra context when there is no ticket and skips users who have opted out,
            // yet the outbox row was stamped at enqueue. Called by the post-commit drain, never
            // in the CRM transaction. A crash after sending but before stamping may repeat an
            // INTERNAL alert, not a CRM write.
            try {
                $this->email->sendNew($user->email, 'Contact intake alert: '.$item->event,
                    "Contact intake event: {$item->event}\n\nStaff review:\n".route('contact-intake.show', $row->id), $user->name);
            } catch (\Throwable) {
                continue; // Never log exception text here; the row is retained for the next drain.
            }
            DB::table('contact_intake_notifications')->where('id', $item->id)->update(['sent_at' => now()]);
            $count++;
        }

        return $count;
    }
}
