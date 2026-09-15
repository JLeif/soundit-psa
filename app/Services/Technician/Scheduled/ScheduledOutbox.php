<?php

namespace App\Services\Technician\Scheduled;

use App\Enums\NoteType;
use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Support\Facades\DB;

final class ScheduledOutbox
{
    public function deliver(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $item = DB::table('scheduled_note_outbox')->where('id', $id)->lockForUpdate()->first();
            if (! $item || $item->note_id) {
                return $item !== null;
            }
            $row = DB::table('scheduled_authorizations')->find($item->authorization_id);
            if (! $row || ! Ticket::find($row->ticket_id)) {
                DB::table('scheduled_note_outbox')->where('id', $id)->update(['delivery_error' => 'ticket_missing']);

                return false;
            }
            // No raw payload, identity, human text, exception or vendor response is rendered.
            $event = in_array($item->event, ['scheduled', 'cancelled', 'expired', 'blocked', 'submitted', 'completed', 'uncertain'], true) ? $item->event : 'unknown';
            $body = "Scheduled approval #{$row->id}, run #{$row->run_id}: {$event}. Approver #{$row->approver_user_id}.";
            if ($event === 'scheduled') {
                $body .= " Window [{$row->not_before}, {$row->expires_at}) UTC; display zone {$row->display_timezone}.";
            }
            if (in_array($event, ['uncertain', 'submitted'], true)) {
                $body .= ' Do not retry. Staff reconciliation required; cancellation cannot prove prevention.';
            }
            if (in_array($event, ['cancelled', 'expired', 'blocked'], true)) {
                $body .= ' No dispatch intent was issued.';
            }
            $note = TicketNote::create([
                'ticket_id' => $row->ticket_id, 'author_name' => 'Scheduled approval system',
                'body' => $body, 'note_type' => NoteType::System, 'is_private' => true,
                'ai_authored' => false, 'is_billable' => false, 'noted_at' => now(),
            ]);
            // Note insert and acknowledgement are one local transaction. No email method is called.
            DB::table('scheduled_note_outbox')->where('id', $id)->update(['note_id' => $note->id, 'delivery_error' => null]);

            return true;
        }, 3);
    }

    public function purge(): int
    {
        $now = app(ScheduledClock::class)->now();

        return DB::table('scheduled_authorizations')->whereIn('state', ['cancelled', 'expired', 'blocked', 'completed', 'uncertain'])
            ->whereNotNull('finished_at')->where('finished_at', '<=', $now->subDays(30))->whereNotNull('ciphertext')
            ->update(['ciphertext' => null, 'purged_at' => $now]);
    }
}
