<?php

namespace App\Jobs;

use App\Models\PhoneCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResolveCallerFromPeople implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(\App\Services\PhoneCallService $phoneCallService): void
    {
        $call = PhoneCall::find($this->callId);
        if (! $call) {
            return;
        }

        // Already fully resolved or manually confirmed by a tech
        if ($call->client_id !== null || $call->person_confirmed) {
            return;
        }

        $person = $phoneCallService->findPersonByPhoneNumber($call->from_number);

        if ($person) {
            $call->person_id = $person->id;
            $call->client_id = $person->client_id;
            $call->save();

            Log::info('[CallerResolve] Match found', [
                'call_id' => $call->id,
                'person_id' => $person->id,
                'client' => $person->client?->name,
            ]);

            // Both call directions are attributed here, so the auto-link is tried
            // here. It decides for itself whether to act: with the setting off,
            // with 0 or 2+ open tickets, or with the call not Completed, the call
            // stays unlinked. This job is dispatched while the call is ringing, so
            // it can run before the call has ended; PhoneCallService then makes
            // the attempt from handleCallEnded() or handleRecordingReady(),
            // whichever moves the call into Completed, and this one covers the job
            // running after that. A failure there is logged and swallowed so
            // it cannot undo the resolution saved above.
            try {
                $phoneCallService->autoLinkToSoleOpenTicket($call);
            } catch (\Throwable $e) {
                Log::warning('[CallerResolve] Auto-link failed; call left unlinked', [
                    'call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        Log::warning('[CallerResolve] No match found', [
            'call_id' => $call->id,
            'from_number' => $call->from_number,
        ]);
    }
}
