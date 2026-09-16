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
        $lock = Cache::lock(ScheduledPolicy::OVERLAP_LOCK);
        if (! $lock->get()) {
            $this->line('{"status":"overlap_busy"}');

            return self::FAILURE;
        }
        try {
            $errors = 0;
            $notes = 0;
            DB::table('scheduled_authorizations')->whereIn('state', ['waiting', 'claimed', 'dispatch_intent'])->orderBy('id')->chunkById(100, function ($rows) use ($coordinator, &$errors) {
                foreach ($rows as $row) {
                    try {
                        $coordinator->recover($row->id);
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            });
            DB::table('scheduled_note_outbox')->whereNull('note_id')->orderBy('id')->chunkById(100, function ($rows) use ($outbox, &$notes, &$errors) {
                foreach ($rows as $row) {
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
