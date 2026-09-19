<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Services\NotificationService;
use App\Services\PhoneCallService;
use App\Support\PlivoConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PlivoWebhookController extends Controller
{
    public function __construct(
        private readonly PhoneCallService $phoneCallService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * CallStatus values that mean the call is over. Named once because two call
     * sites now read this list — payloadIsTerminal(), used by the coalesced
     * branch, and the terminal-CallStatus branch's own in_array() — and a copy
     * that drifted would silently reopen card 6aade104: a terminal callback the
     * recording branch did not recognise is a callback whose ended_at is never
     * written. (The hangup branch still asks its own question inline, `if
     * ($dialAction === 'hangup')`, and does not consult this list; it is a
     * DialAction test, not a CallStatus one.)
     */
    private const TERMINAL_CALL_STATUSES = ['completed', 'busy', 'failed', 'timeout', 'no-answer', 'cancel'];

    /**
     * Does THIS payload say the call has ended? Independent of whether it also
     * carries a recording — Plivo coalesces the two, and that coalescing is the
     * whole of card 6aade104.
     */
    private function payloadIsTerminal(string $dialAction, string $callStatus): bool
    {
        return $dialAction === 'hangup' || in_array($callStatus, self::TERMINAL_CALL_STATUSES, true);
    }

    /**
     * The payload handleCallEnded() should see, with a Duration it can use.
     *
     * WHY THIS EXISTS, and it is a money guard rather than a tidiness one.
     * handleCallEnded() assigns `duration` unconditionally:
     *
     *     $call->duration = isset($data['Duration']) ? (int) $data['Duration'] : null;
     *
     * — so a payload with no Duration NULLS the column. On the coalesced
     * recording+terminal delivery this method serves, handleRecordingReady() has
     * just written a recording-derived duration into that same column (its own
     * comment: "use its duration as the call duration so UI, reports, and
     * exports all render correctly"). Falling through without this would erase
     * it moments after writing it.
     *
     * The money edge: PrepayService::debitFromPhoneCall() reverses an existing
     * debit outright when effectiveDurationSeconds() comes back falsy
     * (PrepayService.php — the `! $durationSeconds` branch calls
     * reverseDebitForPhoneCall()). effectiveDurationSeconds() does fall back to
     * recording_duration, so a nulled `duration` alone is usually survivable;
     * it is NOT survivable on a row whose recording_duration is 0 — a caller who
     * hung up during the greeting — where nulling duration takes the last
     * non-zero signal away and the call's charge reverses itself. Supplying the
     * Duration closes both the display regression and the reversal.
     *
     * Precedence, most authoritative first:
     *   1. Plivo's own Duration for the call.
     *   2. DialBLegDuration when it is POSITIVE. That field is scoped to the
     *      DIALED (B) leg, and on the unanswered dial that produces a voicemail
     *      — the shape that dominates card 6aade104 — the B leg is exactly the
     *      one that never connected, so Plivo reports 0 there. (The repo's own
     *      answerIsObserved() requires DialBLegDuration > 0 before it will call
     *      a leg answered, and fails closed on everything else — it does not
     *      affirm 0 as a vendor report of anything, so read it as consistent
     *      with treating 0 as no-evidence, not as authority that 0 is a
     *      meaningful duration.) A
     *      presence test would inject that 0 ahead of the recording-derived
     *      duration this method exists to preserve, zeroing a real voicemail's
     *      length and, on a row whose recording_duration is also 0, re-opening
     *      the very debit reversal described above. So a non-positive B-leg
     *      duration does not outrank the stored value.
     *   3. The duration already stored on the row, which on this path is the one
     *      handleRecordingReady() just derived from the recording.
     *   4. Failing both of those, a non-positive DialBLegDuration is still passed
     *      through when Plivo sent one — with nothing stored there is nothing to
     *      protect, and this keeps the Duration-less hangup branch (which passes
     *      no $call) behaving exactly as it did before.
     * If none of those exists the key stays absent, which is the pre-existing
     * behaviour for a Duration-less hangup.
     */
    private function terminalPayloadPreservingDuration(array $data, ?PhoneCall $call): array
    {
        if (isset($data['Duration'])) {
            return $data;
        }

        $bLegDuration = isset($data['DialBLegDuration']) ? (int) $data['DialBLegDuration'] : null;

        if ($bLegDuration !== null && $bLegDuration > 0) {
            $data['Duration'] = $data['DialBLegDuration'];

            return $data;
        }

        if ($call && $call->duration && $call->duration > 0) {
            $data['Duration'] = $call->duration;

            return $data;
        }

        if ($bLegDuration !== null) {
            $data['Duration'] = $data['DialBLegDuration'];
        }

        return $data;
    }

    /**
     * After a call ends, resolve the recording from Plivo's API if none arrived via callback.
     * Plivo's inbound call recording callbacks often don't reach our webhook (configured in the
     * Plivo application, not our code). This queries the API directly after a short delay to
     * give Plivo time to finalize the recording.
     */
    private function resolveRecordingAfterEnd(?PhoneCall $call): void
    {
        if (! $call || $call->recording_url || ! $call->duration || $call->duration < 1) {
            return;
        }

        // Delay 30s — Plivo needs time to process and finalize the recording after hangup
        $cmd = sprintf(
            'sleep 30 && php %s calls:resolve-recording %d > /dev/null 2>&1 &',
            base_path('artisan'),
            $call->id,
        );
        Process::run($cmd);
    }

    /**
     * Reference-only signal emission for the intake call feed (E3 intake.call_received,
     * psa-ip15 W1 Task 3). Wrapped in its own try/catch because SignalHub::emit()'s
     * internal catch only guards its own method body — the app(SignalHub::class)
     * container resolution and the method dispatch happen in the CALLER's frame, so
     * an unwrapped call here could still throw and break the native webhook response.
     * Unwrapped, a throw here would make handle() return HTTP 500, and Plivo retries
     * failed webhooks — a parallel-plane violation. The wrap prevents that.
     *
     * Fires for both inbound and outbound terminal calls (direction is carried in the
     * summary word so downstream consumers can tell them apart).
     */
    private function emitCallReceived(?PhoneCall $call): void
    {
        if (! $call) {
            return;
        }
        try {
            $direction = $call->direction === \App\Enums\CallDirection::Outbound ? 'outbound' : 'inbound';
            app(\App\Services\Signals\SignalHub::class)->emit('intake.call_received', $call, $direction.' call received', ['client_id' => $call->client_id]);
        } catch (\Throwable $e) {
            Log::warning('[PlivoWebhook] intake.call_received emit failed', ['call_uuid' => $call->call_uuid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Answer URL for outbound calls from browser endpoints.
     * Returns Dial XML to connect the browser caller to the destination number.
     */
    public function browserAnswer(Request $request): Response
    {
        $destination = $request->input('ForwardTo') ?? $request->input('To');

        // Strip non-digit/+ chars, require valid phone pattern
        $destination = preg_replace('/[^\d+]/', '', $destination ?? '');
        if (! preg_match('/^\+?1?\d{10,15}$/', $destination)) {
            Log::warning('Invalid outbound destination', ['raw' => $request->input('ForwardTo') ?? $request->input('To')]);

            return response('<?xml version="1.0"?><Response><Hangup/></Response>', 200,
                ['Content-Type' => 'application/xml']);
        }

        // Normalize: ensure country code for US numbers
        if (strlen(ltrim($destination, '+')) === 10) {
            $destination = '1'.ltrim($destination, '+');
        }

        // Log outbound call before returning XML (non-blocking — don't let DB errors break the call)
        try {
            $this->phoneCallService->logOutboundCall([
                'CallUUID' => $request->input('CallUUID'),
                'From' => $request->input('From'),
                'To' => $destination,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log outbound call', ['error' => $e->getMessage()]);
        }

        $callerId = PlivoConfig::get('did_number');
        $callbackUrl = url('/api/plivo/'.PlivoConfig::get('webhook_secret').'/webhook');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<Response>';
        $xml .= '<Record startOnDialAnswer="true" redirect="false" maxLength="14400" callbackUrl="'.htmlspecialchars($callbackUrl).'" callbackMethod="POST" />';
        $xml .= '<Dial callerId="'.htmlspecialchars($callerId).'" callbackUrl="'.htmlspecialchars($callbackUrl).'">';
        $xml .= '<Number>'.htmlspecialchars($destination).'</Number>';
        $xml .= '</Dial>';
        $xml .= '</Response>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Resolve the inbound caller against the PSA phone directory and people table.
     * Designed for a Plivo PHLO HTTP-request node to call during the IVR so the
     * flow can branch on whether the caller is blocked, allow-listed, a known
     * client contact, or unknown.
     *
     * Request body (Plivo standard fields): From, To, CallUUID
     *
     * Response flags:
     *   - known:   true if matched anything in our system (client/blocked/allowed)
     *   - client:  true if matched a Person in the people table
     *   - blocked: true if on the blocked list
     *   - allowed: true if on the allow list
     *
     * Branch priority for the PHLO: blocked -> allowed -> client -> unknown.
     * The endpoint never blocks or fails the call: any error returns
     * everything-false so PHLO routes the caller to the unknown branch.
     */
    public function resolveCaller(Request $request): JsonResponse
    {
        $from = $request->input('From') ?? $request->input('from');
        $callUuid = $request->input('CallUUID') ?? $request->input('callUuid');

        $payload = [
            'known' => false,
            'client' => false,
            'blocked' => false,
            'allowed' => false,
            'person_id' => null,
            'person_name' => null,
            'person_first_name' => null,
            'client_id' => null,
            'client_name' => null,
            'caller_label' => null,
        ];

        if (! $from) {
            Log::info('[PlivoResolveCaller] Missing From — returning unknown', [
                'call_uuid' => $callUuid,
            ]);

            return response()->json($payload);
        }

        // Phone directory lookup takes precedence over person lookup so the PHLO
        // can hang up on blocked callers and ring allow-listed callers through
        // with their label without doing any client matching.
        $directoryEntry = \App\Models\PhoneDirectoryEntry::lookup($from);

        if ($directoryEntry?->isBlocked()) {
            $payload['known'] = true;
            $payload['blocked'] = true;
            Log::info('[PlivoResolveCaller] Blocked', [
                'call_uuid' => $callUuid,
                'from' => $from,
            ]);

            return response()->json($payload);
        }

        if ($directoryEntry?->isAllowed()) {
            $payload['known'] = true;
            $payload['allowed'] = true;
            $payload['caller_label'] = $directoryEntry->label;
            Log::info('[PlivoResolveCaller] Allowed', [
                'call_uuid' => $callUuid,
                'from' => $from,
                'label' => $directoryEntry->label,
            ]);

            return response()->json($payload);
        }

        try {
            $person = $this->phoneCallService->findPersonByPhoneNumber($from);
        } catch (\Throwable $e) {
            Log::warning('[PlivoResolveCaller] Lookup failed', [
                'call_uuid' => $callUuid,
                'from' => $from,
                'error' => $e->getMessage(),
            ]);

            return response()->json($payload);
        }

        if (! $person) {
            Log::info('[PlivoResolveCaller] No match', [
                'call_uuid' => $callUuid,
                'from' => $from,
            ]);

            return response()->json($payload);
        }

        $payload['known'] = true;
        $payload['client'] = true;
        $payload['person_id'] = $person->id;
        $payload['person_name'] = $person->fullName;
        $payload['person_first_name'] = $person->first_name;
        $payload['client_id'] = $person->client_id;
        $payload['client_name'] = $person->client?->name;

        Log::info('[PlivoResolveCaller] Match', [
            'call_uuid' => $callUuid,
            'from' => $from,
            'person_id' => $person->id,
            'client_id' => $person->client_id,
        ]);

        return response()->json($payload);
    }

    /**
     * Single endpoint for all Plivo callbacks.
     * Routes internally based on payload content.
     *
     * Plivo sends various event types with different field structures:
     * - Some have CallStatus (ringing, in-progress, completed)
     * - Some use DialAction (answer, hangup) without CallStatus
     * - Recording callbacks have RecordUrl (may arrive before call is answered)
     * We must handle all variants and ensure a record exists first.
     */
    public function handle(Request $request): Response
    {
        Log::debug('Plivo webhook received', [
            'all' => $request->all(),
        ]);

        $request->validate([
            'CallUUID' => 'required|string|max:100',
        ]);

        $callUuid = $request->input('CallUUID');
        $callStatus = $request->input('CallStatus', '');
        $dialAction = $request->input('DialAction', '');
        $hasRecording = $request->filled('RecordUrl');

        // Ensure a call record exists — Plivo's first callback may already
        // have a status like "in-progress", so we can't rely on an empty
        // status to trigger creation.
        $existing = PhoneCall::where('call_uuid', $callUuid)->exists();
        if (! $existing) {
            $this->phoneCallService->logIncomingCall($request->all());
        }

        // Recording callback — RecordUrl present (may arrive early with duration=-1)
        if ($hasRecording) {
            $request->validate([
                'RecordUrl' => 'required|url|max:2048',
                'RecordingDuration' => 'nullable|integer',
            ]);

            $recordingDuration = $request->integer('RecordingDuration');

            // Only save recording URL when duration >= 0 (recording complete).
            // The duration=-1 callback provides a temporary recording ID that Plivo
            // discards for unanswered calls, replaced by a different permanent ID.
            if ($recordingDuration >= 0) {
                $this->phoneCallService->handleRecordingReady(
                    $callUuid,
                    $request->input('RecordUrl'),
                    $recordingDuration,
                );
            }

            // Auto-detect voicemail: recording completed on an unanswered call.
            // Require minimum 3s duration — callers who hang up during the greeting
            // produce 0-second recordings that aren't real voicemails.
            if ($recordingDuration >= 3) {
                $call = PhoneCall::where('call_uuid', $callUuid)->first();
                if ($call && $call->answered_at === null) {
                    $this->phoneCallService->markAsVoicemail($callUuid);

                    // For voicemails, Plivo's callback URL may differ from the
                    // actual recording. Resolve the real recording via API.
                    $this->phoneCallService->resolveRecordingFromPlivo($call);

                    // If auto-transcribe is going to run for this recording,
                    // defer the notification until transcription completes so
                    // the email can include the AI summary and transcript.
                    // Otherwise notify immediately. TranscriptionService
                    // dispatches notifyNewVoicemail itself in its finally block.
                    $willTranscribe = TranscriptionConfig::autoTranscribeEnabled()
                        && TranscriptionConfig::isConfigured()
                        && $recordingDuration >= TranscriptionConfig::minDurationSeconds();

                    if (! $willTranscribe) {
                        $this->notificationService->notifyNewVoicemail($call->refresh());
                    }
                }
            }

            // Auto-transcribe if enabled and recording is complete (duration >= 0).
            if ($recordingDuration >= 0 && TranscriptionConfig::autoTranscribeEnabled() && TranscriptionConfig::isConfigured()) {
                $minDuration = TranscriptionConfig::minDurationSeconds();
                if ($recordingDuration >= $minDuration) {
                    $call = $call ?? PhoneCall::where('call_uuid', $callUuid)->first();
                    if ($call && ! $call->isTranscribed() && ! $call->isTranscribing()) {
                        $call->update(['transcription_status' => \App\Enums\TranscriptionStatus::Pending]);
                        // Delay 15s — Plivo's CDN needs time to finalize the MP3 after the callback fires
                        $cmd = sprintf('sleep 15 && php %s calls:transcribe %d > /dev/null 2>&1 &', base_path('artisan'), $call->id);
                        Process::run($cmd);
                    }
                }
            }

            // CARD 6aade104 — the recording branch must not swallow a terminal
            // callback. Plivo coalesces the recording and the terminal event into
            // ONE POST (that is when it does; the same account received them as two
            // separate POSTs before mid-May 2026, which is why the older rows are
            // intact and the newer ones are not). What IS established is that the
            // code shape never changed — the early return is in the repo's initial
            // commit, which postdates the onset in the data — so the cause is
            // external to this repo. WHICH external cause is not established: a
            // Plivo product change and a change to the recording callback
            // configuration, which lives in the Plivo application rather than
            // here (see the note at the top of this class), both fit the
            // evidence, and they differ in whether it can silently revert.
            // This branch used to `return response('OK', 200)` here, and
            // handleCallEnded() WAS reachable only from the two branches below
            // this one (the block below is now a third call site). So every
            // coalesced delivery was acknowledged 200 and discarded: 182
            // production rows, all carrying a recording, left with ended_at NULL
            // and still accruing at roughly 17/month.
            //
            // handleCallEnded() is NOT the only writer of ended_at, and a reader
            // reasoning about who may write that column must not assume it is.
            // PhoneCallService::handleRecordingReady() also finalises, through
            // finaliseCallTheHangupNeverClosed(), deriving ended_at from
            // started_at + duration — CLAMPED to now() when that sum is in the
            // future, which it often is — for a call whose hangup webhook never
            // arrived. On a coalesced payload with RecordingDuration >= 0 BOTH
            // run in one request: the recording work above first (derived value),
            // then this branch (ended_at = now()). On RecordingDuration = -1
            // handleRecordingReady() does not run at all and this branch is the
            // ONLY writer. Where both run, this branch WINS the timestamp, which
            // is deliberate — on a coalesced delivery the vendor is reporting the
            // end as it happens, so now() is an observed time, while the service's
            // is documented as an approximation for a hangup that never came, and
            // a derived value should not outlive a real one.
            //
            // TWO ASYMMETRIES TO KNOW BEFORE ADDING A FOURTH CALLER, because the
            // service is the careful writer and this path is not:
            //  1. The service declines when `ended_at !== null`; handleCallEnded()
            //     does not guard at all, so a redelivered coalesced POST moves
            //     ended_at forward and re-runs the debit. That was inert before
            //     this change (the payload returned 200 and did nothing) and is
            //     not inert now. It is filed, not fixed, on this branch.
            //  2. The service declines when the recording hit its maxLength
            //     ceiling (RECORDING_MAX_LENGTH_SECONDS), on the argument that
            //     such a callback proves the RECORDING stopped, not the CALL.
            //     This path has NO analogue: a terminal marker is a stronger and
            //     different fact than a recording ending, so finalising on it is
            //     intended — but nothing here would stop a future caller keying
            //     on the recording instead, which is the over-reach class and is
            //     worse than the defect this branch fixes.
            //
            // Voicemail is over-represented in those rows for a structural reason
            // rather than a vendor one: a voicemail IS a call whose recording ends
            // at the moment the call ends, so it is the shape most likely to have
            // both facts in one payload. Nothing here is voicemail-specific.
            //
            // Ordering is load-bearing and is pinned by a test: the recording work
            // above runs FIRST so the row carries its recording (and, for a
            // voicemail, its Voicemail status, which handleCallEnded() then
            // preserves) before the call is finalised.
            if ($this->payloadIsTerminal($dialAction, $callStatus)) {
                $call = PhoneCall::where('call_uuid', $callUuid)->first();
                $data = $this->terminalPayloadPreservingDuration($request->all(), $call);

                $call = $this->phoneCallService->handleCallEnded($callUuid, $data);

                // resolveRecordingAfterEnd() IS called here, exactly as the two
                // terminal branches below do. "recording_url is always set on
                // this path" is false: the branch predicate is RecordUrl being
                // present in the PAYLOAD, while recording_url is written only by
                // handleRecordingReady(), which runs only when RecordingDuration
                // is >= 0. On Plivo's temporary-recording callback
                // (RecordingDuration = -1) coalesced with a terminal marker the
                // column is still NULL, and finalising without this call would
                // leave the row ended with no recording and no remaining trigger
                // to fetch one. Its own first guard returns when recording_url
                // IS set, so on the ordinary coalesced delivery this costs a
                // guard, not a process.
                $this->resolveRecordingAfterEnd($call);
                $this->emitCallReceived($call);

                return response('OK', 200);
            }

            return response('OK', 200);
        }

        // Hangup — DialAction=hangup (Plivo often omits CallStatus on hangup)
        if ($dialAction === 'hangup') {
            $data = $this->terminalPayloadPreservingDuration($request->all(), null);

            $call = $this->phoneCallService->handleCallEnded($callUuid, $data);
            $this->resolveRecordingAfterEnd($call);
            $this->emitCallReceived($call);

            return response('OK', 200);
        }

        // Terminal states via CallStatus
        if (in_array($callStatus, self::TERMINAL_CALL_STATUSES, true)) {
            $call = $this->phoneCallService->handleCallEnded($callUuid, $request->all());
            $this->resolveRecordingAfterEnd($call);
            $this->emitCallReceived($call);

            return response('OK', 200);
        }

        // Call answered — via DialAction or CallStatus
        if ($dialAction === 'answer' || in_array($callStatus, ['in-progress', 'answered'])) {
            $this->phoneCallService->handleCallAnswered($callUuid, $request->all());

            return response('OK', 200);
        }

        // Ringing or initial answer URL — return Plivo XML
        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Response></Response>',
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
