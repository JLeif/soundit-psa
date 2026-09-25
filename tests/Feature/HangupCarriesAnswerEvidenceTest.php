<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card 57SuhqPY — "phone calls showing as missed even though they were
 * definitely answered", the ORIGINAL ask, driven at the seam the card names:
 * PhoneCallService::handleCallEnded().
 *
 * WHAT IS ALREADY FIXED, so this file does not re-litigate it. Cards 6aac6037 /
 * 6aac6ee7 landed the late-answer repair (deployed): when the ANSWER webhook
 * arrives after hangup with no usable duration, handleCallAnswered() now calls
 * answerIsObserved($data) and stamps answered_at, unfreezing the status.
 * AnsweredCallFrozenAsMissedTest covers that path thoroughly.
 *
 * THE GAP THIS FILE PINS, re-derived from deployed source at 52ec8d94:
 *
 *   answerIsObserved() is private to PhoneCallService and is called from
 *   EXACTLY ONE place — handleCallAnswered() (PhoneCallService.php:278).
 *   handleCallEnded() reads ONE key from its payload, $data['Duration'], and
 *   then decides status from $call->answered_at ALONE.
 *
 *   PlivoWebhookController::handle() returns early on a terminal CallStatus
 *   (`if (in_array($callStatus, self::TERMINAL_CALL_STATUSES, true)) { ...
 *   handleCallEnded(...); return response('OK', 200); }`), BEFORE the
 *   `$dialAction === 'answer'` arm that would call handleCallAnswered(). So for
 *   a webhook that is BOTH terminal AND carries answer evidence, the answer
 *   evidence is never offered to the only method that reads it.
 *
 *   The controller itself proves such payloads exist and are expected: its
 *   terminalPayloadPreservingDuration() reaches into DialBLegDuration on the
 *   terminal path (PlivoWebhookController.php:136-151), i.e. the hangup payload
 *   is known to carry Dial* B-leg fields.
 *
 * So the assertion under test is narrow and behavioural: when a hangup payload
 * carries positive evidence that the B leg connected, but Duration is absent
 * and answered_at was never stamped, what does the operator see?
 *
 * These tests are written to FAIL FIRST against 52ec8d94 (the card's rule: write
 * the failing case first). Each test's docblock names the failure it produced
 * there, and the negatives below are green-by-construction guards on the
 * proposed behaviour rather than on the defect — they say so individually,
 * because a file claiming every test was red-checked when some cannot fail is
 * the exact overstatement corrected on AnsweredCallFrozenAsMissedTest.
 */
class HangupCarriesAnswerEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A ringing inbound call, mid-flight: no answered_at, no duration. This is
     * the row state the hangup webhook lands on.
     */
    private function ringingCall(string $uuid): PhoneCall
    {
        return PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550101',
            'to_number' => '+15555550100',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(5),
        ]);
    }

    // ── the reproduction: answer evidence on the hangup payload ─────────────

    /**
     * THE CARD'S QUESTION. A hangup payload carrying a B-leg UUID — Plivo's own
     * documented "empty if nobody answers" field, non-empty here — with no
     * Duration.
     *
     * RED at 52ec8d94: status === Missed. handleCallEnded() never consults
     * answerIsObserved(), so the evidence sitting in its own $data is ignored
     * and answered_at-alone decides.
     */
    public function test_hangup_with_b_leg_uuid_is_not_reported_as_missed(): void
    {
        $call = $this->ringingCall('hangup-bleg-uuid');

        app(PhoneCallService::class)->handleCallEnded('hangup-bleg-uuid', [
            'CallUUID' => 'hangup-bleg-uuid',
            'CallStatus' => 'completed',
            'Event' => 'Hangup',
            'DialBLegUUID' => 'b-leg-4c7e1a93',
        ]);

        $stored = $call->fresh();

        $this->assertNotSame(CallStatus::Missed, $stored->status,
            'a hangup payload carrying a non-empty DialBLegUUID is the vendor stating the dialled leg connected; '
            .'reporting that call as Missed is the defect on card 57SuhqPY (PhoneCallService.php:481)');
    }

    /**
     * Same gap, different key, so a reader that special-cases one field does not
     * pass the provider. DialBLegDuration > 0 is the same test
     * answerIsObserved() itself applies.
     *
     * RED at 52ec8d94: status === Missed.
     *
     * NOTE the deliberate absence of 'Duration': the controller's
     * terminalPayloadPreservingDuration() would PROMOTE DialBLegDuration into
     * Duration before calling the service — that is the controller's repair, and
     * this test drives the SERVICE directly to show the service itself has no
     * such reasoning. The card's next-step names this seam, not the controller.
     */
    public function test_hangup_with_positive_b_leg_duration_is_not_reported_as_missed(): void
    {
        $call = $this->ringingCall('hangup-bleg-duration');

        app(PhoneCallService::class)->handleCallEnded('hangup-bleg-duration', [
            'CallUUID' => 'hangup-bleg-duration',
            'CallStatus' => 'completed',
            'Event' => 'Hangup',
            'DialBLegDuration' => '184',
        ]);

        $stored = $call->fresh();

        $this->assertNotSame(CallStatus::Missed, $stored->status,
            'a B leg that ran 184 seconds was connected; Missed contradicts the payload (PhoneCallService.php:481)');
    }

    /**
     * The operator-visible contradiction, stated as the fingerprint this card's
     * production rows carried: a call reported Missed while the row itself holds
     * evidence of an answer.
     *
     * RED at 52ec8d94: answered_at is null AND status is Missed, while the
     * payload said DialAction=connected.
     */
    public function test_hangup_with_dial_action_connected_stamps_answer_evidence(): void
    {
        $call = $this->ringingCall('hangup-connected');

        app(PhoneCallService::class)->handleCallEnded('hangup-connected', [
            'CallUUID' => 'hangup-connected',
            'CallStatus' => 'completed',
            'Event' => 'Hangup',
            'DialAction' => 'connected',
        ]);

        $stored = $call->fresh();

        $this->assertFalse(
            $stored->status === CallStatus::Missed && $stored->answered_at === null,
            'DialAction=connected is an explicit bridge event; a row left Missed with a null answered_at '
            .'is the exact contradiction card 57SuhqPY was opened for'
        );
    }

    // ── negatives: green BOTH before and after, by construction ─────────────

    /**
     * GREEN AT 52ec8d94 BY CONSTRUCTION — a guard on the proposed behaviour, not
     * a reproduction of the defect. It exists because the fix must not turn the
     * commonest real case (nobody answered) into a false Completed.
     *
     * The keys here are the ones present on a call NOBODY answered: DialBLegTo
     * names who was dialled, an EMPTY DialBLegUUID is the vendor's documented
     * "nobody answered", and the top-level CallStatus describes the A leg Plivo
     * itself answered. A reader that accepts any Dial* key, or the A-leg status,
     * fails here.
     */
    public function test_unanswered_dial_is_still_missed(): void
    {
        $call = $this->ringingCall('hangup-nobody-answered');

        app(PhoneCallService::class)->handleCallEnded('hangup-nobody-answered', [
            'CallUUID' => 'hangup-nobody-answered',
            'CallStatus' => 'in-progress',
            'Event' => 'Hangup',
            'DialBLegTo' => 'sip:tech@phone.plivo.com',
            'DialBLegUUID' => '',
            'DialBLegDuration' => '0',
        ]);

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Missed, $stored->status,
            'no answer evidence: empty B-leg UUID and a zero B-leg duration must stay Missed — '
            .'failing open here would mislabel every missed call as answered');
    }

    /**
     * GREEN AT 52ec8d94 BY CONSTRUCTION. Voicemail is preserved ahead of every
     * other arm, and must remain so: Plivo's Duration includes voicemail
     * recording time, which is the documented reason status keys on answered_at
     * rather than duration in the first place.
     */
    public function test_voicemail_is_preserved_even_with_answer_evidence(): void
    {
        $call = $this->ringingCall('hangup-voicemail');
        $call->status = CallStatus::Voicemail;
        $call->save();

        app(PhoneCallService::class)->handleCallEnded('hangup-voicemail', [
            'CallUUID' => 'hangup-voicemail',
            'CallStatus' => 'completed',
            'Event' => 'Hangup',
            'DialBLegUUID' => 'b-leg-voicemail',
            'DialBLegDuration' => '31',
        ]);

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Voicemail, $stored->status,
            'voicemail must outrank answer evidence: a voicemail recording is not a conversation');
    }

    /**
     * GREEN AT 52ec8d94 BY CONSTRUCTION. A call already correctly Completed with
     * a real answered_at must not be disturbed by this seam at all.
     */
    public function test_already_completed_call_is_untouched(): void
    {
        $call = $this->ringingCall('hangup-already-complete');
        $call->answered_at = now()->subMinutes(4);
        $call->status = CallStatus::Completed;
        $call->save();

        app(PhoneCallService::class)->handleCallEnded('hangup-already-complete', [
            'CallUUID' => 'hangup-already-complete',
            'CallStatus' => 'completed',
            'Event' => 'Hangup',
            'Duration' => '240',
        ]);

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Completed, $stored->status);
        $this->assertNotNull($stored->answered_at);
    }
}
