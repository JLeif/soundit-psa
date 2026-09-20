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
 * THE THRESHOLD IS BOUNDED AT BOTH ENDS. Carbon does not error on an absurd
 * --minutes: it underflows to a negative year, and at PHP_INT_MAX it wraps
 * back to the present, which would silently report nothing while looking
 * healthy. Both ends are therefore refused explicitly.
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

    /**
     * Most rows listed individually. Under the cap the reported count is the
     * exact total and is derived from the same read as the listing. Above the
     * cap it comes from a second, later query floored at the rows the listing
     * read saw, so it is a lower bound on a moving set -- never an exact total,
     * and never smaller than the rows printed beside it.
     */
    private const SAMPLE_LIMIT = 20;

    /**
     * The status column for display, without letting one unmappable value
     * abort a report whose count has already been logged. Reading the raw
     * attribute bypasses the enum cast that throws.
     */
    private static function statusLabel(PhoneCall $call): string
    {
        try {
            return $call->status->value;
        } catch (\ValueError $e) {
            return self::UNMAPPED_PREFIX.(string) $call->getRawOriginal('status');
        }
    }

    /**
     * Named rather than inlined because handle() detects a degraded label by
     * this prefix; two copies of the literal could drift apart and silently
     * stop the log record reporting a corrupt row.
     */
    private const UNMAPPED_PREFIX = 'unmapped:';

    /** ~10 years. Beyond this, Carbon underflows or wraps instead of erroring. */
    private const MAX_MINUTES = 5256000;

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

        // Zero is refused, not merely negatives. The shape check above rejects
        // 'soon' precisely BECAUSE (int) 'soon' is 0 and a zero-minute
        // threshold reports every marker in flight as a fault -- so accepting
        // an explicit --minutes=0 admitted by the front door exactly what the
        // shape check exists to keep out (round 3 diff:4). A cutoff of now()
        // matches a marker written microseconds ago by a deferral whose
        // release has not run yet, which is a healthy row, not a stranded one.
        if ($minutes < 1) {
            $this->error('--minutes must be at least 1: a zero-minute threshold reports deferrals that are still in flight.');

            return self::FAILURE;
        }

        // An upper bound as well as a lower one. Measured at this tip:
        // now()->subMinutes(999999999999) yields year -1899298, and
        // subMinutes(PHP_INT_MAX) silently WRAPS back to the present -- a
        // wrong answer rather than an error, which is the worse failure for a
        // gauge. A threshold beyond the age of the table cannot express a
        // real question, so it is refused rather than quietly carried into a
        // nonsense cutoff.
        if ($minutes > self::MAX_MINUTES) {
            $this->error(sprintf('--minutes must not exceed %d (about 10 years).', self::MAX_MINUTES));

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
            // Tiebreak: markers written in the same second are common (one
            // release pass touches many rows), and without a second key
            // "the 20 oldest" is whatever the engine happens to return, so
            // consecutive runs can disagree about which rows they name.
            ->orderBy('id')
            ->limit(self::SAMPLE_LIMIT + 1)
            ->get(['id', 'voicemail_notify_deferred_at', 'status', 'started_at']);

        if ($rows->isEmpty()) {
            $this->info("No voicemail deferral has been outstanding longer than {$minutes} minute(s).");

            return self::SUCCESS;
        }

        $truncated = $rows->count() > self::SAMPLE_LIMIT;
        $sample = $rows->take(self::SAMPLE_LIMIT);

        // Only when the listing is truncated is a second query needed, and it
        // runs LATER than the read the listing came from. A burst of releases
        // in between can make it return FEWER rows than are listed, which would
        // print "Showing the 20 oldest of 5" and log a count contradicting its
        // own id list -- the same count/rows disagreement the single read above
        // exists to prevent, relocated above the cap. The total is therefore
        // floored at the number of rows the listing read actually saw
        // (SAMPLE_LIMIT + 1), which is a lower bound held in evidence rather
        // than an assumption about what the later query should have returned.
        // Above the cap the reported total is that floor; under the cap no
        // second query runs and the total is exact.
        $count = $truncated
            ? max((clone $stranded)->count(), $rows->count())
            : $rows->count();

        $oldest = $sample->first()->voicemail_notify_deferred_at;

        $this->warn("{$count} voicemail notification(s) withheld for want of end evidence and never released.");
        $this->warn(sprintf(
            'Oldest outstanding since %s. These rows were withheld because the call showed no sign of having ended; read them before assuming the voicemail is complete.',
            self::forDisplay($oldest)
        ));

        // Status labels are resolved HERE, before the log record is written,
        // rather than inside the table map below. The ordering is the whole
        // point (#2906, round 4 diff:4): degrading an unmappable status to a
        // placeholder kept the listing alive but left the only trace of a
        // corrupt row on stdout, which runInBackground() discards -- so a real
        // enum/column divergence went from a loud uncaught ValueError to no
        // production signal at all. A silent gauge and a dead gauge became
        // byte-identical, which is the fault this command exists to remove.
        // Resolving first lets the unmappable values travel in the log record,
        // the one surface a scheduled run actually produces.
        $labels = [];
        $unmapped = [];

        foreach ($sample as $c) {
            $label = self::statusLabel($c);
            $labels[$c->id] = $label;

            if (str_starts_with($label, self::UNMAPPED_PREFIX)) {
                $unmapped[$c->id] = (string) $c->getRawOriginal('status');
            }
        }

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
            // Keyed by call id, carrying the RAW value: the record names which
            // row and which string, not merely that something was wrong. An
            // empty array on every healthy run.
            'unmapped_statuses' => $unmapped,
        ]);

        // A SEPARATE record at error level, because the warning above is a
        // routine hourly line on a gauge whose whole subject is rows awaiting
        // a human: an unmappable status folded into it reads as part of the
        // expected report. This fires only when a row is genuinely corrupt.
        //
        // The record deliberately carries no explanation of WHICH surfaces
        // the bad value breaks. Three drafts of that sentence were each
        // falsified by execution (rounds 1-3 on this leg), because no test
        // asserts its contents and so nothing can hold it true. The row id
        // and the raw value below are what an operator needs; statusLabel()
        // above is the executable statement of how the cast behaves.
        if ($unmapped !== []) {
            Log::error('[Voicemail] phone_calls rows carry a status no enum case maps', [
                'unmapped_statuses' => $unmapped,
                'detail' => 'CallStatus::from() throws on these values.',
            ]);
        }

        $this->table(
            ['call id', 'deferred at', 'status', 'started at'],
            $sample
                ->map(fn (PhoneCall $c) => [
                    $c->id,
                    self::forDisplay($c->voicemail_notify_deferred_at),
                    // This reads an ALREADY-RESOLVED label. The map was built
                    // before the Log::warning above, by commit af4917ba --
                    // round 1's own commit, which moved label resolution out
                    // of this line. The comment that used to sit here still
                    // described the old ordering and claimed the ValueError
                    // was thrown BY this line; round 1 converged with a
                    // comment its own reorder had falsified (round 2
                    // context:1). Nothing here can throw on an unmapped
                    // status.
                    //
                    // The ordering is the fix: before af4917ba a stranded row
                    // sorted to the front of the sample on every run, so one
                    // bad row killed the listing hourly, forever, while the
                    // count kept being logged (round 3 context:3).
                    $labels[$c->id],
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
