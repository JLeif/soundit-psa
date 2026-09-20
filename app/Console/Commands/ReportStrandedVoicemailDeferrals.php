<?php

namespace App\Console\Commands;

use App\Models\PhoneCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Counts voicemail notifications withheld for want of end evidence and never
 * released — the observability the notify guard's accepted bound was traded
 * against (card 6aafb4ab, PR #2880, merged 30fccb4a).
 *
 * WHAT A STRANDED ROW IS. NotificationService::notifyNewVoicemail() stamps
 * `voicemail_notify_deferred_at` on a voicemail carrying no end evidence, and
 * each of the three documented ended_at writers calls
 * releaseDeferredVoicemailNotification() once its own write has committed. A
 * release clears the marker in the same statement that claims
 * `voicemail_notified_at`, so a row still holding a marker is one no writer
 * ever reached. The predicate is therefore marker NOT NULL and
 * `voicemail_notified_at` NULL, which reads the guard's own two columns rather
 * than restating its reasoning.
 *
 * WHY `voicemail_notified_at` IS IN THE PREDICATE AND NOT ASSUMED. A release
 * both clears the marker and claims the send, so in the ordinary case the two
 * conditions agree and either alone would do. They can disagree: a marker
 * written by one entrance while another entrance's claim is in flight can
 * briefly sit beside a set `voicemail_notified_at`. That row has been emailed
 * about and is not stranded, so testing both columns is what keeps a sent
 * voicemail out of the count.
 *
 * WHY AN AGE THRESHOLD. Deferral is usually released microseconds later in the
 * same request, so counting every marker would report ordinary traffic
 * mid-flight as a fault. The threshold is the line between "in flight" and
 * "nobody is coming", defaults to 60 minutes, and is overridable with
 * --minutes for an operator who wants a tighter or wider window. It is a
 * reporting choice, not a claim about how long a release can legitimately take.
 *
 * THIS COMMAND WRITES NOTHING TO A CALL. It does not release, resend or clear a
 * marker. Releasing a withheld email without end evidence is precisely the
 * defect the guard exists to prevent, so the remedy for a stranded row is a
 * human reading a call that may still have been connected — not this command.
 *
 * THE LIMIT. It reports rows the guard marked. It cannot see a voicemail that
 * was never marked, and rows predating the guard carry a NULL marker and are
 * invisible here by construction; that pre-existing pool is card 6aade104's,
 * not this gauge's. A zero here means no marker is outstanding past the
 * threshold, which is narrower than "no voicemail is stranded".
 */
class ReportStrandedVoicemailDeferrals extends Command
{
    protected $signature = 'calls:report-stranded-voicemail-deferrals {--minutes=60 : Age in minutes past which an unreleased deferral is reported}';

    protected $description = 'Count voicemail notifications deferred for want of end evidence and never released.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        if ($minutes < 0) {
            $this->error('--minutes must not be negative.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        $stranded = PhoneCall::query()
            ->whereNotNull('voicemail_notify_deferred_at')
            ->whereNull('voicemail_notified_at')
            ->where('voicemail_notify_deferred_at', '<=', $cutoff);

        $count = (clone $stranded)->count();

        if ($count === 0) {
            $this->info("No voicemail deferral has been outstanding longer than {$minutes} minute(s).");

            return self::SUCCESS;
        }

        $oldest = (clone $stranded)->min('voicemail_notify_deferred_at');

        $this->warn("{$count} voicemail notification(s) withheld for want of end evidence and never released.");
        $this->warn("Oldest outstanding since {$oldest}. These rows were withheld because the call showed no sign of having ended; read them before assuming the voicemail is complete.");

        // Logged as well as printed: a scheduled run has no terminal attached,
        // and this count is the whole reason the command exists.
        Log::warning('[Voicemail] Stranded notification deferrals outstanding', [
            'count' => $count,
            'threshold_minutes' => $minutes,
            'oldest_deferred_at' => (string) $oldest,
        ]);

        $this->table(
            ['call id', 'deferred at', 'status', 'started at'],
            (clone $stranded)
                ->orderBy('voicemail_notify_deferred_at')
                ->limit(20)
                ->get(['id', 'voicemail_notify_deferred_at', 'status', 'started_at'])
                ->map(fn (PhoneCall $c) => [
                    $c->id,
                    (string) $c->voicemail_notify_deferred_at,
                    $c->status?->value ?? (string) $c->status,
                    (string) $c->started_at,
                ])
                ->all()
        );

        // A report that found something still exits 0: this is a gauge, and a
        // nonzero exit here would read as a broken scheduled command rather
        // than as calls needing a human.
        return self::SUCCESS;
    }
}
