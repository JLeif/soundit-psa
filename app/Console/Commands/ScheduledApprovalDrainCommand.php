<?php

namespace App\Console\Commands;

use App\Services\Technician\Scheduled\ScheduledClock;
use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledOutbox;
use App\Services\Technician\Scheduled\ScheduledPolicy;
use App\Services\Technician\Scheduled\ScheduledQuiescence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ScheduledApprovalDrainCommand extends Command
{
    protected $signature = 'technician:scheduled-drain';

    protected $description = 'Persist quiescence, then after the transport wait recover and drain without sending';

    public function handle(ScheduledQuiescence $quiescence, ScheduledClock $clock, ScheduledCoordinator $coordinator, ScheduledOutbox $outbox): int
    {
        // Write BEFORE attempting the overlap lock: a live sweep must see it too.
        $at = $quiescence->begin();
        $remaining = max(0, (int) ceil($clock->now()->diffInSeconds($at->addSeconds(ScheduledPolicy::MAX_TRANSPORT_SECONDS), false)));
        if ($remaining > 0) {
            $this->line(json_encode(['status' => 'quiesced_wait', 'wait_seconds' => $remaining], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        // Same named lock AND same explicit bounded lease as the sweep: a dead holder
        // cannot make the drain unreachable while admission is already blocked.
        $lock = Cache::lock(ScheduledPolicy::OVERLAP_LOCK, ScheduledPolicy::OVERLAP_LOCK_SECONDS);
        if (! $lock->get()) {
            $this->line('{"status":"overlap_busy"}');

            return self::FAILURE;
        }
        try {
            $errors = 0;
            $notes = 0;
            // The scan is unbounded in row count, so it must be bounded in time: stop
            // STARTING work at OVERLAP_WORK_SECONDS, leaving the reserve for whatever unit
            // is in flight. A drain must never outlive its own lease and let a sweep run
            // against its in-flight rows. A scan cut short this way counts an error, so it
            // exits 1 and is re-run: it is never reported as a clean quiesce.
            $started = hrtime(true);
            $spent = fn () => (hrtime(true) - $started) / 1e9 >= ScheduledPolicy::OVERLAP_WORK_SECONDS;
            DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('id')->chunkById(100, function ($rows) use ($coordinator, &$errors, $spent) {
                foreach ($rows as $row) {
                    if ($spent()) {
                        $errors++;

                        return false;
                    }
                    try {
                        $coordinator->recover($row->id);
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            });
            DB::table('scheduled_note_outbox')->whereNull('note_id')->orderBy('id')->chunkById(100, function ($rows) use ($outbox, &$notes, &$errors, $spent) {
                foreach ($rows as $row) {
                    if ($spent()) {
                        $errors++;

                        return false;
                    }
                    try {
                        $notes += (int) $outbox->deliver($row->id);
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            });
            // Preserve BOTH bounds: drain waits 610 from marker; recovery still
            // waits intent+610+30. Recent intents may need a later drain invocation.
            $intents = DB::table('scheduled_authorizations')->where('state', 'dispatch_intent')->count();
            $pendingNotes = DB::table('scheduled_note_outbox')->whereNull('note_id')->count();
            $this->line(json_encode(['status' => 'quiesced', 'grace_pending_intents' => $intents, 'pending_notes' => $pendingNotes, 'notes' => $notes, 'errors' => $errors], JSON_THROW_ON_ERROR));

            return $intents || $pendingNotes || $errors ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
