<?php

namespace App\Console\Commands;

use App\Models\PhoneCall;
use Carbon\CarbonInterface;
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
 * TIMEZONE. Stored values are UTC; every timestamp printed or logged is
 * converted with toAppTz() and carries its zone abbreviation (C-14).
 *
 * WHERE THE OUTPUT GOES. The scheduled entry runs in the background with no
 * output redirection, so the printed table exists only for an operator running
 * the command by hand. The log record is the production signal and therefore
 * carries the call ids, not just the count.
 *
 * WHAT THE AGE MEASURES. The clock runs from the marker column itself, so a
 * row whose marker is re-stamped is aged from the newest stamp. If a later
 * notify entrance can re-mark an already-marked call, a permanently stranded
 * row could keep resetting its own clock and stay below the threshold; nothing
 * here pins that the marker is written once, and that is a property of
 * NotificationService rather than of this command.
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

    /** Most rows listed individually; the count reported is always the true total. */
    private const SAMPLE_LIMIT = 20;

    /**
     * C-14: the database stores UTC and every display surface converts through
     * toAppTz(). A console table and a log record are both read by a human
     * deciding which call to listen to, so an unlabelled UTC timestamp here is
     * an hours-wrong answer to "how long has this been sitting?". The zone
     * abbreviation is printed because a converted time with no marker is the
     * same trap one step further on.
     */
    private static function forDisplay(?CarbonInterface $at): string
    {
        return $at?->toAppTz()->format('Y-m-d H:i:s T') ?? '';
    }

    public function handle(): int
    {
        $raw = $this->option('minutes');

        // Validated for SHAPE before value: (int) 'soon' is 0, which is a
        // valid-looking zero-minute threshold that would report every marker
        // in flight as a fault. A typo must refuse, not silently widen the
        // gauge to its most alarming setting.
        if (! is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
            $this->error('--minutes must be a whole number of minutes.');

            return self::FAILURE;
        }

        $minutes = (int) $raw;

        if ($minutes < 0) {
            $this->error('--minutes must not be negative.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        $stranded = PhoneCall::query()
            ->whereNotNull('voicemail_notify_deferred_at')
            ->whereNull('voicemail_notified_at')
            ->where('voicemail_notify_deferred_at', '<=', $cutoff);

        // ONE read, and the count is DERIVED from it rather than queried
        // separately. The previous revision read the count first and the rows
        // second and claimed in this comment to be a single read; it was two,
        // so a release landing between them could still produce a non-zero
        // count with an empty row set and print "Oldest outstanding since ."
        // with nothing after it. Reading rows first and counting them closes
        // that window for real instead of describing it as closed.
        //
        // The cap applies to the LISTING only, so the reported total cannot
        // come from the capped set. SAMPLE_LIMIT + 1 rows are fetched: the
        // extra row is never displayed and exists only to distinguish "exactly
        // SAMPLE_LIMIT outstanding" from "more than SAMPLE_LIMIT", which is
        // the difference between an exact total and a floor.
        $rows = (clone $stranded)
            ->orderBy('voicemail_notify_deferred_at')
            ->limit(self::SAMPLE_LIMIT + 1)
            ->get(['id', 'voicemail_notify_deferred_at', 'status', 'started_at']);

        if ($rows->isEmpty()) {
            $this->info("No voicemail deferral has been outstanding longer than {$minutes} minute(s).");

            return self::SUCCESS;
        }

        $truncated = $rows->count() > self::SAMPLE_LIMIT;
        $sample = $rows->take(self::SAMPLE_LIMIT);

        // Only when the listing is truncated is a second query needed, and by
        // then the report is explicitly approximate anyway.
        $count = $truncated ? (clone $stranded)->count() : $rows->count();

        $oldest = $sample->first()->voicemail_notify_deferred_at;

        $this->warn("{$count} voicemail notification(s) withheld for want of end evidence and never released.");
        $this->warn(sprintf(
            'Oldest outstanding since %s. These rows were withheld because the call showed no sign of having ended; read them before assuming the voicemail is complete.',
            self::forDisplay($oldest)
        ));

        // THE LOG LINE IS THE ONLY PRODUCTION OUTPUT. The schedule entry uses
        // runInBackground() with no output redirection, so everything written
        // to stdout below is discarded on every run that actually happens.
        // The ids therefore have to travel in the log record, not just in the
        // table -- a count with no ids tells an operator a problem exists and
        // nothing about which calls to read.
        Log::warning('[Voicemail] Stranded notification deferrals outstanding', [
            'count' => $count,
            'threshold_minutes' => $minutes,
            'oldest_deferred_at' => self::forDisplay($oldest),
            'call_ids' => $sample->pluck('id')->all(),
            'call_ids_truncated' => $truncated,
        ]);

        $this->table(
            ['call id', 'deferred at', 'status', 'started at'],
            $sample
                ->map(fn (PhoneCall $c) => [
                    $c->id,
                    self::forDisplay($c->voicemail_notify_deferred_at),
                    $c->status?->value ?? (string) $c->status,
                    self::forDisplay($c->started_at),
                ])
                ->all()
        );

        if ($truncated) {
            $this->warn(sprintf(
                'Showing the %d oldest of %d; the list above is not the whole set.',
                $sample->count(),
                $count
            ));
        }

        // A report that found something still exits 0: this is a gauge, and a
        // nonzero exit here would read as a broken scheduled command rather
        // than as calls needing a human.
        return self::SUCCESS;
    }
}
