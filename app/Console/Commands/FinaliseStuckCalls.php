<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
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
 * It is bounded twice, and the two bounds do different jobs. A call that is
 * live at this instant has no ended_at either - that is what in-flight MEANS
 * - so an unbounded population would sweep the calls connected while an
 * operator runs --apply.
 *
 * The bound that actually separates live from stuck is STORED EVIDENCE THAT
 * THE CALL ENDED: a recording_url, a recording_duration, or a duration. None
 * of the three is ever written while a call is connected - duration lands at
 * hangup in handleCallEnded(), the recording columns land when the recording
 * callback fires - so a row carrying none of them cannot be told apart from a
 * conversation in progress, and a backfill that cannot tell must not write.
 * Every row in the measured population carries a recording, so this costs
 * nothing against the rows this command exists for. What it does cost is
 * reach: a row that really did end and left no trace at all is unreachable
 * here, and each run reports how many such rows it declined rather than
 * dropping them silently.
 *
 * The AGE FLOOR is the second bound and it is NOT the liveness test. Age
 * alone cannot separate "started long ago and ended" from "started long ago
 * and still talking": PlivoWebhookController emits <Record maxLength="14400"
 * />, so a four-hour conversation is an in-contract shape and sits past both
 * the one-hour clamp and the two-hour default. The floor's job is narrower -
 * keep rows whose hangup webhook may still be in flight or being retried out
 * of a backfill. Rows whose start is younger than --min-age-hours (default 2,
 * never less than 1) are not in the population at all.
 *
 * It reuses no private service method. The derivation is duplicated here
 * rather than shared because the two callers answer different questions - the
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
        // the END-EVIDENCE requirement in the population filter below.
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
        // END EVIDENCE IS THE LIVENESS TEST. A row is in the population only
        // if it carries a stored fact that the call ended - a recording_url, a
        // recording_duration, or a duration - because none of the three is
        // ever written while a call is connected. A row with none of them
        // cannot be told apart from a call in progress, so it is declined and
        // COUNTED rather than guessed at. Rows carrying a recording but no
        // length are still in: the recording is the evidence, and the report
        // says which rows had a length to derive from and which did not.
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
