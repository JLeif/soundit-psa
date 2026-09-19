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
 * It is also bounded by an AGE FLOOR, and that bound is load-bearing rather
 * than cosmetic. A call that is live at this instant has no ended_at either -
 * that is what in-flight MEANS - so an unbounded population would sweep the
 * calls ringing while an operator runs --apply. Rows whose start is younger
 * than --min-age-hours (default 2, never less than 1) are not in the
 * population at all.
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

        // A LIVE CALL IS NOT A STUCK CALL, and nothing else in this command
        // can tell them apart. logIncomingCall() writes started_at with no
        // ended_at, so every call in flight at the moment of a run matches the
        // defect predicate exactly; without this floor an --apply during
        // business hours would write a zero-length ended_at and status=Missed
        // onto a conversation still connected, and the real answer webhook
        // would then land on an already-ended row and take handleCallAnswered's
        // late branch, freezing a live call as mis-dispositioned.
        //
        // It is a FLOOR, not a preference: a value below one hour is raised to
        // one hour. No row this sweep exists for is younger than that - the
        // measured population's newest member is days old - so the flag can
        // shorten the reach of a careful run but cannot be used to aim the
        // command at live traffic.
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
        // A recording is NOT required - a row with no recording is just as
        // stuck - but rows carrying one are the only ones where a length can
        // be derived, and the report says which is which.
        // Ageing keys on the same anchor the derivation below uses -
        // started_at, else created_at - so a row can never be aged by one
        // clock and dated by another. A row with NEITHER is deliberately left
        // IN the population rather than filtered out: it cannot be aged, it is
        // never written (the no-anchor branch below skips it), and excluding
        // it here would make that branch unreachable - the same vacuous-control
        // mistake the status filter made.
        $query = PhoneCall::whereNull('ended_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('started_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('started_at')
                            ->where(function ($q) use ($cutoff) {
                                $q->where('created_at', '<', $cutoff)
                                    ->orWhereNull('created_at');
                            });
                    });
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $calls = $query->get();

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
