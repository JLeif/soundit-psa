<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Disposition the calls that never finalised.
 *
 * The live fix in PhoneCallService::finaliseCallTheHangupNeverClosed() closes
 * this class going forward, but only on rows whose recording webhook has yet
 * to arrive. Rows whose recording ALREADY landed were finalised by nobody and
 * will never be touched again - they need a one-off sweep. Measured in
 * production 2026-09-18: 36 rows, oldest 2026-05-05, newest 2026-09-15.
 *
 * Deliberately NOT added to the scheduler. This is a backfill over client
 * call records: it runs when a human decides it should, having read the
 * dry-run, and its default mode writes nothing.
 *
 * It is bounded three times, and the three bounds do different jobs. A call
 * that is live at this instant has no ended_at either - that is what
 * in-flight MEANS - so an unbounded population would sweep the calls
 * connected while an operator runs --apply.
 *
 * The first bound is STORED EVIDENCE THAT THE CALL ENDED: a recording_url, a
 * recording_duration, or a duration. A row carrying none of them cannot be
 * told apart from a conversation in progress, and a backfill that cannot tell
 * must not write.
 *
 * THAT BOUND IS NOT SUFFICIENT ALONE, and two earlier versions of this
 * docblock claimed otherwise, each wrongly. The first claimed 'none of the
 * three is ever written while a call is connected' - three review seats said
 * so and they were right: PlivoWebhookController emits
 * <Record ... maxLength="14400" />, and a Record element posts its callback
 * when the RECORDING stops - at that ceiling as well as at hangup - so on a
 * call still connected past four hours the recording columns ARE written
 * mid-call.
 *
 * The second claimed the live guard holds such a row out of here. It does
 * not. handleRecordingReady() writes recording_url, recording_duration and
 * (on a duration-less row) duration BEFORE
 * finaliseCallTheHangupNeverClosed() declines the maxLength case. The row it
 * leaves behind carries a null ended_at, full end evidence, and at four hours
 * an age past every floor - it satisfied every predicate this command had.
 * The live guard moved the defect one command over rather than closing it.
 *
 * So the second bound is that same ceiling test, applied HERE: a row whose
 * stored length is at or above
 * PhoneCallService::RECORDING_MAX_LENGTH_SECONDS is out of the population,
 * because that length says the RECORDING stopped, not the call. It reads the
 * service's constant instead of repeating the number, so the two cannot
 * drift apart and leave this predicate comparing against a threshold no
 * recording can reach.
 *
 * THE CEILING BOUND HAS JURISDICTION OVER OUTBOUND BROWSER CALLS ONLY, and a
 * THIRD version of this docblock - the one this paragraph corrects - was wrong
 * about that in the opposite direction. It described the ceiling as one of the
 * bounds holding this population safe, without saying which rows it can reach.
 * The only <Record maxLength="14400"> this application emits is in
 * PlivoWebhookController::browserAnswer(), which serves OUTBOUND calls from
 * browser endpoints; the inbound handler returns a bare
 * <Response></Response> carrying no <Record> element, so NO INBOUND RECORDING
 * CAN ROLL OVER AT THIS CEILING.
 *
 * That is narrower than it sounds, and a FOURTH version of this paragraph -
 * the one these lines correct - overstated it as "no inbound row can ever
 * reach this ceiling". The predicate below is not a rollover test. It compares
 * the ordinary duration column against the ceiling as well as
 * recording_duration, and duration is written on inbound rows by the normal
 * hangup/answer path, not only by a recording callback. There is no direction
 * term. So an inbound conversation that really lasted four hours and one
 * minute - or a row whose duration was backfilled that long - DOES reach the
 * threshold and IS excluded here. What cannot happen on an inbound row is the
 * ROLLOVER the exclusion is reasoning about; the exclusion is therefore
 * over-broad on inbound rows rather than inapplicable to them. Such a row is
 * declined, counted and reported by the second warning below, not lost
 * silently.
 *
 * Measured against production when this was written: 220 of the 221 rows with
 * a null ended_at are inbound, and the single outbound row carries no
 * recording at all - so this predicate excludes nothing on THAT population,
 * and the largest recording in it is 301s against a 14400s ceiling, a factor
 * of 47.8. That is a measurement of today's rows, not a bound on what a row
 * may carry.
 *
 * The predicate is KEPT, not deleted, because it is correct where it applies:
 * an outbound browser call that rolls over at the ceiling is a real shape this
 * command must not finalise. It is NOT free where it does not apply - a
 * four-hour-plus inbound call is declined by it too - but that cost is visible
 * rather than silent: no such row exists on the measured population, and any
 * that appears is counted and reported by the ceiling warning below. Whether
 * the exclusion should be narrowed to the rows whose rollover it reasons about
 * is an open question for the card owner; this round states the reach rather
 * than changes it. What is withdrawn is the CLAIM that it bounds this
 * population generally. The two bounds actually doing the work on every row
 * here are the stored end-evidence filter and the age floor.
 *
 * The trade is the same one the live path makes, and it is stated rather than
 * hidden: an OUTBOUND BROWSER call that really did end with its recording at
 * the ceiling is declined here too. That false negative is bought against a
 * false positive that would write an end time and a final status onto a live
 * conversation,
 * and it is not a silent loss - those rows are COUNTED and reported on every
 * run, and a later hangup webhook, or a rerun after one arrives, still
 * reaches them.
 *
 * With both bounds in place the evidence filter's job is the narrow one it
 * always really did: it excludes rows that never finalised AND left no trace
 * at all, which is the one shape indistinguishable from a call in progress.
 *
 * On cost, also stated precisely: every row of the 36-row ringing/in-progress
 * class carries a recording, so the filter costs nothing there. That
 * measurement does NOT cover the 182 voicemail and 3 completed rows this
 * command also sweeps - their coverage under this filter is unmeasured, and
 * the declined count below is what reports it rather than an assumption. What it does cost is
 * reach: a row that really did end and left no trace at all is unreachable
 * here, and each run reports how many such rows it declined rather than
 * dropping them silently.
 *
 * The AGE FLOOR is the third bound and it is NOT the liveness test. Age
 * alone cannot separate "started long ago and ended" from "started long ago
 * and still talking": PlivoWebhookController emits <Record maxLength="14400"
 * />, so a four-hour conversation is an in-contract shape and sits past both
 * the one-hour clamp and the two-hour default. The floor's job is narrower -
 * keep rows whose hangup webhook may still be in flight or being retried out
 * of a backfill. Rows whose start is younger than --min-age-hours (default 2,
 * never less than 1) are not in the population at all.
 *
 * It reuses no service method. The one thing it takes from the service is the
 * maxLength constant - a single external fact about the XML the controller
 * emits, which must not exist in two places. The derivation is duplicated
 * here rather than shared because the two callers answer different questions - the
 * service finalises ONE row at webhook time on evidence it was just handed,
 * this sweeps a historical population on evidence already stored - and
 * because a sweep that silently changed behaviour when the service changed
 * would be a worse instrument than one that states its own rule.
 */
class FinaliseStuckCalls extends Command
{
    protected $signature = 'calls:finalise-stuck
                            {--apply : Write the changes. Without this flag nothing is modified.}
                            {--limit=0 : Process at most this many rows (0 = no limit).}
                            {--min-age-hours=2 : Only consider calls that started at least this many hours ago. Never less than 1.}';

    protected $description = 'Finalise calls stuck at ringing/in-progress whose hangup webhook never arrived';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        // A LIVE CALL IS NOT A STUCK CALL. logIncomingCall() writes started_at
        // with no ended_at, so every call in flight at the moment of a run
        // matches "no ended_at" exactly; sweeping one would write a
        // zero-length ended_at and status=Missed onto a conversation still
        // connected, and the real answer webhook would then land on an
        // already-ended row and take handleCallAnswered's late branch,
        // freezing a live call as mis-dispositioned.
        //
        // AGE DOES NOT TELL THEM APART, and this floor no longer claims to.
        // An earlier version of this comment said the flag "cannot be used to
        // aim the command at live traffic", and the predicate did not support
        // it: PlivoWebhookController emits <Record maxLength="14400" />, so a
        // call still connected four hours in is an in-contract shape, older
        // than both the one-hour clamp and the two-hour default, and age alone
        // would have swept it - writing a zero-length end and a final status
        // onto a conversation in progress. What separates live from stuck is
        // the END-EVIDENCE requirement in the population filter below and -
        // for the one shape that satisfies end-evidence mid-call - the
        // maxLength ceiling test beside it.
        //
        // The floor stays for a smaller, real job: a row that ended minutes
        // ago may simply be waiting on a hangup webhook still in flight or
        // being retried, and a backfill has no business racing it. It is a
        // FLOOR, not a preference: a value below one hour is raised to one
        // hour.
        $minAgeHours = max(1, (int) $this->option('min-age-hours'));
        $cutoff = now()->subHours($minAgeHours);

        // The population is defined by the DEFECT, not by the symptom: a row
        // that never finalised is one with no ended_at, whatever its status
        // says. An earlier version of this filter read
        // `whereIn(status, [Ringing, InProgress])` - the shape the card
        // described - and that was wrong twice over. It made this command's
        // own voicemail guard unreachable (a Voicemail row could never enter
        // the population, so the control covering it passed against a build
        // with the guard deleted), and it missed the larger population:
        // measured in production 2026-09-18, 36 ringing/in-progress rows but
        // also 182 voicemail rows and 3 completed rows with no ended_at.
        //
        // The voicemail figure matters for a second reason: its newest row is
        // from TODAY, where the ringing population stops at 2026-09-15. That
        // is an ACTIVE class, not a historical one.
        //
        // END EVIDENCE IS HALF THE LIVENESS TEST. A row is in the population
        // only if it carries a stored fact that the call ended - a
        // recording_url, a recording_duration, or a duration. A row with none
        // of them cannot be told apart from a call in progress, so it is
        // declined and COUNTED rather than guessed at. Rows carrying a
        // recording but no length are still in: the recording is the evidence,
        // and the report says which rows had a length to derive from and which
        // did not.
        //
        // IT IS ONLY HALF. An earlier version of this comment justified it
        // with 'none of the three is ever written while a call is connected',
        // and that is false. <Record maxLength="14400" /> posts its callback
        // when the RECORDING stops, at the ceiling as well as at hangup, and
        // handleRecordingReady() writes all three columns before
        // finaliseCallTheHangupNeverClosed() declines to finalise. So a call
        // still connected past four hours lands here carrying evidence, a null
        // ended_at and an age past the floor: every predicate satisfied by a
        // conversation in progress.
        //
        // THE CEILING IS THE OTHER HALF. A stored length at or above the
        // maxLength the controller emits says the RECORDING stopped, not the
        // call, so the row is out of the population - the same discriminator
        // the live path uses, reading the same constant so the two cannot
        // drift. It costs the row that really did end with its recording at
        // the ceiling: declined, counted below, and left for a later webhook
        // rather than risked.
        //
        // Ageing keys on the same anchor the derivation below uses -
        // started_at, else created_at - so a row can never be aged by one
        // clock and dated by another. A row with NEITHER is deliberately left
        // IN the population rather than filtered out: it cannot be aged, it is
        // never written (the no-anchor branch below skips it), and excluding
        // it here would make that branch unreachable - the same vacuous-control
        // mistake the status filter made.
        $endedEvidence = function ($q) {
            $q->whereNotNull('recording_url')
                ->orWhere('recording_duration', '>', 0)
                ->orWhere('duration', '>', 0);
        };

        // Written as "below the ceiling, or no length at all" rather than as a
        // negated >=, because under SQL NULL semantics NOT (duration >= N)
        // drops every row whose duration is null - which is most of this
        // population, including the 11 measured rows that carry a recording
        // and no duration.
        // Jurisdiction note, and it has two halves that must not be collapsed
        // into one. The shape this REASONS about is a recording that rolled
        // over at the <Record> maxLength ceiling, and that element is emitted
        // ONLY by PlivoWebhookController::browserAnswer() on OUTBOUND browser
        // calls, so no inbound recording can roll over.
        // What it TESTS is broader: a stored length at or above the ceiling in
        // recording_duration OR in the ordinary duration column, with no
        // direction term. duration is written on inbound rows by the normal
        // hangup path, so a genuinely 4h+ inbound call is excluded here too -
        // declined and counted by the ceiling warning below, not dropped.
        // On the measured population (220 of 221 null-ended_at rows inbound,
        // max recording 301s against a 14400s ceiling) it excludes nothing.
        // Retained because it is correct where it applies - not because it
        // bounds this population, and not because it is free elsewhere.
        $belowRecordingCeiling = function ($q) {
            $ceiling = PhoneCallService::RECORDING_MAX_LENGTH_SECONDS;

            $q->where(function ($q) use ($ceiling) {
                $q->whereNull('recording_duration')
                    ->orWhere('recording_duration', '<', $ceiling);
            })->where(function ($q) use ($ceiling) {
                $q->whereNull('duration')
                    ->orWhere('duration', '<', $ceiling);
            });
        };

        $agedEnough = function ($q) use ($cutoff) {
            $q->where('started_at', '<', $cutoff)
                ->orWhere(function ($q) use ($cutoff) {
                    $q->whereNull('started_at')
                        ->where(function ($q) use ($cutoff) {
                            $q->where('created_at', '<', $cutoff)
                                ->orWhereNull('created_at');
                        });
                });
        };

        $query = PhoneCall::whereNull('ended_at')
            ->where($endedEvidence)
            ->where($belowRecordingCeiling)
            ->where($agedEnough)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $calls = $query->get();

        // Rows that never finalised and carry no stored evidence they ended.
        // They are out of the population on purpose - that shape cannot be
        // told apart from a call in progress - but the count is REPORTED,
        // because a sweep that quietly cannot reach part of its own defect
        // class reads as having covered it. Counted without --limit: this is
        // reach the run does not have, not reach it chose. It is derived by
        // subtracting the population's own two predicates rather than by
        // restating them negated, so it cannot drift from them (and a negated
        // restatement would also be wrong under SQL NULL semantics).
        $agedStuck = PhoneCall::whereNull('ended_at')->where($agedEnough);
        $declined = $agedStuck->clone()->count() - $agedStuck->clone()->where($endedEvidence)->count();

        if ($declined > 0) {
            $this->warn(sprintf(
                '%d row(s) with no stored evidence they ended are NOT in the population '
                .'(no recording_url, recording_duration or duration - that shape cannot be '
                .'told apart from a call still in progress).',
                $declined
            ));
        }

        // The second decline, reported for the same reason and derived the
        // same way - by subtracting the predicate from the population that
        // precedes it, so it cannot drift from the filter it reports on. These
        // rows DID leave a trace; the trace is a recording that rolled over at
        // the ceiling, which says the recording stopped and not the call. A
        // genuinely ended call in this shape is reachable again as soon as a
        // hangup webhook lands, and until then this run says out loud that it
        // did not cover it.
        $agedStuckWithEvidence = $agedStuck->clone()->where($endedEvidence);
        $declinedAtCeiling = $agedStuckWithEvidence->clone()->count()
            - $agedStuckWithEvidence->clone()->where($belowRecordingCeiling)->count();

        if ($declinedAtCeiling > 0) {
            $this->warn(sprintf(
                '%d row(s) at or above the maxLength recording ceiling (%ds) are NOT in the '
                .'population (a recording that rolled over says the RECORDING stopped, not '
                .'the call - such a row may still be connected). Only an OUTBOUND browser '
                .'call can roll over at that ceiling; an inbound row counted here carries a '
                .'stored length that long for some other reason - a genuinely long call, or a '
                .'backfilled duration - and is worth looking at directly.',
                $declinedAtCeiling,
                PhoneCallService::RECORDING_MAX_LENGTH_SECONDS
            ));
        }

        if ($calls->isEmpty()) {
            $this->info('No stuck calls found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d stuck call(s) found, started before %s (age floor: %dh). Mode: %s',
            $calls->count(),
            $cutoff->toDateTimeString(),
            $minAgeHours,
            $apply ? 'APPLY (writing)' : 'DRY RUN (no writes)'
        ));

        $rows = [];
        $counts = [
            'completed' => 0,
            'missed' => 0,
            'voicemail' => 0,
            'in-progress' => 0,
            'ringing' => 0,
            'no_anchor' => 0,
        ];

        foreach ($calls as $call) {
            $startedAt = $call->started_at ?? $call->created_at;

            if ($startedAt === null) {
                // Nothing to anchor a derived end time to. Reported, never
                // guessed at: a row with neither a start nor an end is a
                // different defect and this sweep declines it.
                $counts['no_anchor']++;
                $rows[] = [$call->id, $call->status->value, '-', 'SKIPPED: no started_at or created_at'];

                continue;
            }

            $seconds = $call->effectiveDurationSeconds();
            $endedAt = $seconds && $seconds > 0
                ? $startedAt->copy()->addSeconds($seconds)
                : $startedAt->copy();

            if ($endedAt->isFuture()) {
                $endedAt = now();
            }

            if ($call->status === CallStatus::Voicemail) {
                $newStatus = CallStatus::Voicemail;
            } else {
                $newStatus = $call->answered_at === null
                    ? CallStatus::Missed
                    : CallStatus::Completed;
            }

            $counts[$newStatus->value] = ($counts[$newStatus->value] ?? 0) + 1;

            $rows[] = [
                $call->id,
                $call->status->value.' -> '.$newStatus->value,
                $endedAt->toDateTimeString(),
                $seconds ? $seconds.'s' : 'no duration (end = start)',
            ];

            if ($apply) {
                $call->ended_at = $endedAt;
                $call->status = $newStatus;
                $call->save();

                Log::info('[PhoneCall] Stuck call finalised by sweep', [
                    'call_id' => $call->id,
                    'derived_ended_at' => $endedAt->toDateTimeString(),
                    'status' => $newStatus->value,
                ]);
            }
        }

        $this->table(['id', 'status', 'derived ended_at', 'basis'], $rows);

        $this->info(sprintf(
            'completed: %d   missed: %d   voicemail: %d   skipped(no anchor): %d',
            $counts['completed'],
            $counts['missed'],
            $counts['voicemail'],
            $counts['no_anchor']
        ));

        if (! $apply) {
            $this->warn('DRY RUN - nothing was written. Re-run with --apply to commit these changes.');
        }

        return self::SUCCESS;
    }
}
