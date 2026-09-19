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
                            {--limit=0 : Process at most this many rows (0 = no limit).}';

    protected $description = 'Finalise calls stuck at ringing/in-progress whose hangup webhook never arrived';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

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
        $query = PhoneCall::whereNull('ended_at')
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
            '%d stuck call(s) found. Mode: %s',
            $calls->count(),
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
