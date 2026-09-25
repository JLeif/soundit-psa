<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A call that RINGS, records, and never finalises.
 *
 * Measured in production on 2026-09-18: 36 rows at status=ringing with
 * ended_at NULL, oldest 2026-05-05, newest 2026-09-15. Every one of the 36
 * carries BOTH recording_url and recording_duration, and 25 also carry a
 * duration. That combination is the whole diagnosis: the recording webhook
 * arrived, so the call demonstrably ended, while the hangup webhook that
 * would have written ended_at and the final status never did.
 *
 * This is NOT the answered-as-missed redelivery defect (card 6aac6037). That
 * one leaves ringing WITH ended_at set; its count is 0. ringing + answered_at
 * is also 0. This is the opposite shape - nothing finalised the row at all.
 *
 * Why nothing sweeps them: handleRecordingReady() delegates the correcting
 * work to reconcileAnsweredStateWithDuration(), which returns early when
 * ended_at === null. So the one webhook that DID arrive declines to act
 * precisely on the rows that need it. The standing safety net
 * `calls:resolve-recordings` is scoped to status=Completed rows MISSING a
 * recording, which is the complement of this population and can never reach
 * it.
 *
 * What the fix must NOT do, each pinned below:
 *  - it must not invent an answer. A recording proves audio existed, not that
 *    a human picked up - the same inference handleCallEnded() deliberately
 *    refuses to make. A stuck row with no answer fingerprint finalises as
 *    Missed, not Completed.
 *  - it must not overwrite a real ended_at, or re-date a call that already
 *    finalised correctly.
 *  - it must not resurrect or re-stamp a voicemail.
 */
class StuckRingingCallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A call stuck exactly as the production rows are: ringing, no ended_at,
     * and (for the 25-row majority) a duration already backfilled.
     */
    private function stuckRingingCall(
        string $uuid,
        ?int $duration = null,
        ?string $answeredAt = null,
        string $direction = 'inbound',
    ): PhoneCall {
        // ended_at, answered_at and duration are NOT in PhoneCall::$fillable,
        // so they must be assigned directly. Found the hard way: a create()
        // array carrying them silently DROPPED all three, and several tests in
        // this file first went red for that reason instead of for the defect
        // they name. A fixture that fails for the wrong reason is worse than
        // one that fails loudly, because its red reads as proof.
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => $direction,
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(10),
        ]);

        $call->ended_at = null;
        $call->duration = $duration;
        $call->answered_at = $answeredAt;
        $call->save();

        return $call;
    }

    /**
     * THE DEFECT. The recording lands on a row that never got a hangup
     * webhook. Before the fix: duration was backfilled, the prepay debit
     * re-ran, and the row stayed at Ringing with ended_at NULL forever.
     *
     * RED at db9e5d12: status stayed Ringing, ended_at stayed null.
     */
    public function test_a_recording_finalises_a_call_the_hangup_webhook_never_closed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-basic');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-basic',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-stuck.mp3',
            90,
        );

        $stored = $call->fresh();

        $this->assertNotNull($stored->ended_at,
            'the recording proves the call ended; the row must no longer claim it is still ringing');
        $this->assertNotSame(CallStatus::Ringing, $stored->status,
            'a row with a recording is not a ringing call');
    }

    /**
     * The end time must be DERIVED, not stamped at sweep time. These rows can
     * be months old (oldest measured: 2026-05-05), so writing now() would
     * re-date a call to whenever the fix happened to run and silently corrupt
     * every duration-based report that reads the column.
     */
    public function test_the_derived_end_time_is_anchored_to_the_call_not_to_now(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-anchored');
        $startedAt = $call->started_at->copy();

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-anchored',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-anchored.mp3',
            120,
        );

        $stored = $call->fresh();

        $this->assertTrue(
            $stored->ended_at->equalTo($startedAt->copy()->addSeconds(120)),
            'ended_at must be started_at + the recorded length, not the moment the correction ran'
        );
    }

    /**
     * IT MUST NOT INVENT AN ANSWER. This is the same boundary
     * reconcileAnsweredStateWithDuration() already holds: a duration proves
     * audio existed, never that a human picked up. A stuck row carrying no
     * answer fingerprint finalises as Missed.
     */
    public function test_a_stuck_call_with_no_answer_fingerprint_finalises_as_missed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-no-answer');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-no-answer',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-no-answer.mp3',
            45,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Missed, $stored->status,
            'a recording is not an answer - status must follow the same rule handleCallEnded uses');
        $this->assertNull($stored->answered_at,
            'no answer fingerprint existed, so none may be manufactured');
    }

    /**
     * ...and the converse: a stuck row that DOES carry an answer fingerprint
     * (handleCallAnswered ran, the hangup never did) finalises as Completed.
     */
    public function test_a_stuck_call_that_was_answered_finalises_as_completed(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall(
            'stuck-ringing-answered',
            null,
            now()->subMinutes(9)->toDateTimeString(),
        );

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-answered',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-answered.mp3',
            300,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Completed, $stored->status,
            'the row carries a real answer moment, so the finalised call is a conversation');
        $this->assertNotNull($stored->ended_at);
    }

    /**
     * IT MUST NOT TOUCH A ROW THAT ALREADY ENDED. The finalisation is for
     * rows the hangup webhook never closed; a call with a real ended_at is
     * none of its business, and re-deriving one would move a correct
     * timestamp.
     */
    public function test_a_call_that_already_ended_keeps_its_own_end_time(): void
    {
        Queue::fake();
        $call = PhoneCall::create([
            'call_uuid' => 'already-ended',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(10),
        ]);
        $call->ended_at = now()->subMinutes(5);
        $call->answered_at = now()->subMinutes(9);
        $call->duration = 240;
        $call->save();

        $originalEnd = $call->ended_at->copy();

        app(PhoneCallService::class)->handleRecordingReady(
            'already-ended',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-already.mp3',
            240,
        );

        $stored = $call->fresh();

        $this->assertTrue($stored->ended_at->equalTo($originalEnd),
            'a row that already finalised must not be re-dated by the recording');
        $this->assertSame(CallStatus::Completed, $stored->status);
    }

    /**
     * A voicemail that never got its hangup webhook still ends - the row must
     * stop claiming to be ringing - but it stays a VOICEMAIL. The recording
     * that triggered this IS the voicemail.
     */
    public function test_a_stuck_voicemail_finalises_without_being_resurrected(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-voicemail');
        $call->status = CallStatus::Voicemail;
        $call->save();

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-voicemail',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-vm-stuck.mp3',
            30,
        );

        $stored = $call->fresh();

        $this->assertSame(CallStatus::Voicemail, $stored->status,
            'the recording IS the voicemail; it is not evidence of a conversation');
        $this->assertNotNull($stored->ended_at,
            'but the call did end, and the row must say so');
        $this->assertNull($stored->answered_at,
            'a voicemail has no answer moment');
    }

    /**
     * Running it twice changes nothing. The second delivery finds a row that
     * already has ended_at and leaves it entirely alone.
     */
    public function test_finalisation_is_idempotent_across_redeliveries(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-idempotent');
        $service = app(PhoneCallService::class);
        $url = 'https://media.plivo.com/v1/Account/MA/Recording/rec-idem.mp3';

        $service->handleRecordingReady('stuck-ringing-idempotent', $url, 75);
        $afterFirst = $call->fresh();

        $service->handleRecordingReady('stuck-ringing-idempotent', $url, 75);
        $afterSecond = $call->fresh();

        $this->assertTrue($afterFirst->ended_at->equalTo($afterSecond->ended_at),
            'a redelivered recording must not move an end time this method itself derived');
        $this->assertSame($afterFirst->status, $afterSecond->status);
    }

    /**
     * THE REGRESSION THIS CHANGE ITSELF INTRODUCED, caught by three review
     * seats and fixed at source.
     *
     * The recording callback does not only fire at hangup: the <Record>
     * element carries maxLength="14400", and a Record element posts its
     * callback when the RECORDING stops - at that ceiling as well as at
     * hangup. On a call still connected past four hours the callback is
     * mid-conversation. Before this change that was harmless, because
     * reconcileAnsweredStateWithDuration() returns early on a null ended_at;
     * after it, the finalisation would have stamped ended_at and flipped the
     * status on a LIVE call.
     *
     * THE FIXTURE IS INBOUND, matching the production population: 220 of the
     * 221 never-finalised rows are inbound. An earlier version of this test
     * was re-aimed OUTBOUND on the theory that only outbound calls could hold
     * a recording at the ceiling. That theory was wrong and is withdrawn: the
     * predicate this guard protects carries no direction term at all, and
     * inbound calls do carry recordings - 537 of 666 in production, the
     * longest 9712s - because inbound recording is configured in the Plivo
     * application rather than emitted by this code. Flipping the fixture
     * outbound deleted the only executable inbound coverage of a guard whose
     * real population is inbound.
     */
    public function test_a_maxlength_recording_does_not_finalise_a_still_connected_call(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-maxlength');

        $this->assertSame(CallDirection::Inbound, $call->fresh()->direction,
            'this guard protects the inbound population the sweep actually acts on; '
            .'an outbound fixture would leave that population uncovered');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-maxlength',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-maxlen.mp3',
            14400,
        );

        $stored = $call->fresh();

        $this->assertNull($stored->ended_at,
            'a recording at the maxLength ceiling may have stopped while the call continued, '
            .'so it is not treated as evidence the call ended');
        $this->assertSame(CallStatus::Ringing, $stored->status,
            'a call that may still be connected must not be finalised by its own recording rolling over');
        $this->assertSame(14400, $stored->recording_duration,
            'the recording columns are still written - only the finalisation is withheld');
    }

    /**
     * One second under the ceiling is an ordinary completed recording and
     * still finalises. Without this, the guard above could be satisfied by a
     * method that never finalises anything.
     */
    public function test_a_recording_just_under_the_ceiling_still_finalises(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-under-ceiling');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-under-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-under.mp3',
            14399,
        );

        $this->assertNotNull($call->fresh()->ended_at,
            'a recording that stopped short of the ceiling is treated as evidence the call ended; '
            .'without this the guard above could be satisfied by a method that never finalises anything');
    }

    /**
     * The guard reasons about a ceiling the CONTROLLER sets. If the two ever
     * disagree the guard goes silently inert - it would compare against a
     * threshold no recording can reach - so pin the emitted value itself.
     * This is a source assertion on purpose: the number is a contract between
     * two files, and no behavioural test can observe a mismatch.
     *
     * SCOPED TO browserAnswer(). An earlier version of this control read the
     * WHOLE controller file, so it could not tell which method emitted the
     * element and would have been satisfied by a maxLength anywhere in the
     * file. Scoping it to the emitting method is what makes it a contract
     * between the constant and a specific line of XML.
     *
     * This control pins ONE fact and claims nothing beyond it: browserAnswer()
     * emits the only <Record maxLength> element in this application. It does
     * NOT establish which calls can hold a recording at that length. Inbound
     * recording is configured in the Plivo application rather than emitted
     * here - see resolveRecordingAfterEnd() - so the absence of a <Record>
     * element on the inbound path says nothing about inbound recordings.
     */
    public function test_the_recording_ceiling_matches_the_value_browser_answer_emits(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Api/PlivoWebhookController.php'));

        // Take browserAnswer()'s body only: from its signature to the start of
        // the next method declaration at the same indentation.
        $this->assertSame(
            1,
            preg_match(
                '/\n    public function browserAnswer\(.*?\n(?=    (?:public|private|protected) function )/s',
                $controller,
                $m
            ),
            'browserAnswer() must be locatable in the controller for this contract to be checkable; '
            .'if the method was renamed or removed, re-aim this control rather than deleting it'
        );

        $this->assertMatchesRegularExpression(
            '/<Record[^>]*maxLength="14400"/',
            $m[0],
            'the <Record> maxLength browserAnswer() emits must match RECORDING_MAX_LENGTH_SECONDS; '
            .'if this fails, update both together or the rollover guard stops firing. This control '
            .'pins where the element is emitted; it says nothing about which calls can reach that '
            .'length, because inbound recording is configured outside this codebase'
        );
    }

    /**
     * A recording with no usable duration proves the call ended but gives no
     * length to anchor from. The row must still stop claiming to ring, and
     * the end time falls back to the last moment we can defend - the start.
     */
    /**
     * A row whose STORED duration is already at the ceiling must not be
     * finalised by a later short callback.
     *
     * The guard used to test only the incoming $duration. A 30-second callback
     * on a row already carrying duration=20000 therefore read "complete", and
     * finaliseCallTheHangupNeverClosed() then derived ended_at from
     * effectiveDurationSeconds(), which prefers the STORED 20000 - stamping an
     * end time from the very number the ceiling exists to distrust, on a call
     * that may still be connected.
     */
    public function test_a_stored_duration_at_the_ceiling_is_not_finalised_by_a_later_short_callback(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall(
            'stuck-ringing-stored-at-ceiling',
            PhoneCallService::RECORDING_MAX_LENGTH_SECONDS,
        );

        $this->assertNull($call->fresh()->ended_at,
            'precondition: the row starts unfinalised');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-stored-at-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-stored-ceiling.mp3',
            30,
        );

        $stored = $call->fresh();

        $this->assertNull($stored->ended_at,
            'a stored length at the ceiling must withhold finalisation, however short the later callback');
        $this->assertSame(CallStatus::Ringing, $stored->status);
        $this->assertNotNull($stored->recording_url,
            'the recording columns are still written - only the finalisation is declined');
    }

    /**
     * A row whose STORED recording_duration is already at the ceiling must not
     * be finalised by a later short callback either.
     *
     * This arm is reachable, and an earlier version of this branch could not
     * see it: handleRecordingReady() writes the incoming length into
     * recording_duration as its first statement, so a guard that read the
     * column afterwards tested the incoming value twice. The row shape is real
     * - resolveRecordingFromPlivo() backfills recording_duration from the
     * longest recording Plivo holds, writing no duration and no ended_at,
     * which is precisely what FinaliseStuckCalls declines to touch. A later
     * short callback (a redelivery, a second segment, or an omitted
     * RecordingDuration) must not finalise it from a length the ceiling exists
     * to distrust.
     *
     * AND THE WITHHOLDING MUST OUTLIVE THE DELIVERY THAT EARNED IT. The
     * declining path still writes the recording columns, so a version that let
     * it lower recording_duration to its own short incoming value destroyed the
     * evidence the guard rests on: the next redelivery - routine for Plivo -
     * would have read only the short value, passed every arm, and finalised the
     * row, and FinaliseStuckCalls would have swept it in the meantime for the
     * same reason. The second delivery below is that redelivery.
     */
    public function test_a_stored_recording_duration_at_the_ceiling_is_not_finalised_by_a_later_short_callback(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-stored-recording-at-ceiling');
        $call->recording_duration = PhoneCallService::RECORDING_MAX_LENGTH_SECONDS;
        // handleRecordingReady() ends in downloadRecording(), which builds a raw
        // Guzzle client that Queue::fake() does not intercept. A recording
        // already on disk is that method's own documented no-op precondition;
        // this test delivers TWICE, so the short-circuit is seeded rather than
        // left to the proxy env. Not fillable - assign directly.
        $call->recording_disk_path = 'call-recordings/pre-seeded-stored-ceiling.mp3';
        $call->save();

        $this->assertNull($call->fresh()->ended_at,
            'precondition: the row starts unfinalised');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-stored-recording-at-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-stored-recording-ceiling.mp3',
            30,
        );

        $stored = $call->fresh();

        $this->assertNull($stored->ended_at,
            'a stored recording_duration at the ceiling must withhold finalisation, '
            .'however short the later callback - the guard must read the column '
            .'before this method overwrites it');
        $this->assertSame(CallStatus::Ringing, $stored->status);
        $this->assertSame(PhoneCallService::RECORDING_MAX_LENGTH_SECONDS, (int) $stored->recording_duration,
            'the declining delivery must not lower the at-ceiling stored length to its own short '
            .'value: that column is the only durable record of the rollover, and erasing it would '
            .'make the withholding last exactly one delivery');

        // THE REDELIVERY. Plivo re-delivers webhooks, so the same short callback
        // arriving again is routine rather than exotic, and it must reach the
        // same verdict from the same evidence.
        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-stored-recording-at-ceiling',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-stored-recording-ceiling.mp3',
            30,
        );

        $redelivered = $call->fresh();

        $this->assertNull($redelivered->ended_at,
            'the withholding must survive a redelivery, not expire with the delivery that earned it');
        $this->assertSame(CallStatus::Ringing, $redelivered->status);
        $this->assertSame(PhoneCallService::RECORDING_MAX_LENGTH_SECONDS, (int) $redelivered->recording_duration,
            'and the evidence must still be on the row for the sweep, which re-reads this column');
    }

    /**
     * The shared predicate, at its boundary and on null.
     *
     * The two controls above pin the stored operands through the live path.
     * This pins the predicate they both rest on: at the ceiling and above it
     * is false, one second under is true, and null - no length recorded - is
     * not evidence of a rollover, the same reading the sweep's
     * $belowRecordingCeiling predicate gives a null column.
     */
    public function test_the_ceiling_predicate_rejects_an_over_ceiling_recording_duration(): void
    {
        $service = app(PhoneCallService::class);
        $method = new \ReflectionMethod($service, 'lengthIsBelowRecordingCeiling');
        $method->setAccessible(true);

        $ceiling = PhoneCallService::RECORDING_MAX_LENGTH_SECONDS;

        $this->assertFalse($method->invoke($service, $ceiling),
            'a length AT the ceiling is not below it');
        $this->assertFalse($method->invoke($service, $ceiling + 1),
            'a length above the ceiling is not below it');
        $this->assertTrue($method->invoke($service, $ceiling - 1),
            'one second under the ceiling is below it');
        $this->assertTrue($method->invoke($service, null),
            'no recorded length is not evidence of a rollover, matching the sweep predicate');
    }

    public function test_a_recording_without_a_duration_still_finalises_the_row(): void
    {
        Queue::fake();
        $call = $this->stuckRingingCall('stuck-ringing-no-duration');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-ringing-no-duration',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-nodur.mp3',
            null,
        );

        $stored = $call->fresh();

        $this->assertNotNull($stored->ended_at,
            'the recording still proves the call ended, even with no length');
        $this->assertNotSame(CallStatus::Ringing, $stored->status);
    }

    /**
     * The ceiling guard must be impossible to skip by omission.
     *
     * finaliseCallTheHangupNeverClosed() once defaulted $recordingIsComplete
     * to true. The sole caller passes it, so no behavioural test could fail if
     * the default came back - a future second call site would silently inherit
     * the pre-guard behaviour and finalise a still-connected call. There is no
     * safe default (true skips the guard; false declines everything the method
     * exists to do), so the contract is that the caller MUST state it.
     *
     * This asserts the contract itself rather than a behaviour, because the
     * defect it guards is precisely the absence of a caller to observe.
     *
     * WHAT IT CANNOT SEE, stated rather than fixed: it constrains the
     * SIGNATURE, so it cannot catch a future caller that supplies the flag as a
     * literal true and skips the guard that way. Only a behavioural test of
     * such a caller could, and that caller does not exist yet - which is the
     * same reason this asserts a contract at all.
     *
     * It deliberately does NOT assert the required-parameter COUNT. That would
     * go red on any legitimately added required parameter, under a message
     * claiming the ceiling contract was broken when it was intact. Assert what
     * is meant: this flag exists, is named, and is required.
     */
    public function test_the_ceiling_flag_cannot_be_omitted_by_a_future_caller(): void
    {
        $method = new \ReflectionMethod(PhoneCallService::class, 'finaliseCallTheHangupNeverClosed');
        $parameters = $method->getParameters();

        // Assert the parameter EXISTS before dereferencing it, so the loudest
        // failure - someone removing the flag outright - surfaces as this
        // message rather than an undefined-key warning and a call on null.
        $this->assertGreaterThanOrEqual(2, count($parameters),
            'the ceiling flag is gone from finaliseCallTheHangupNeverClosed() entirely');

        $parameter = $parameters[1];

        $this->assertSame('recordingIsComplete', $parameter->getName(),
            'guarding the wrong parameter - the ceiling flag moved position');

        $this->assertFalse($parameter->isOptional(),
            'finaliseCallTheHangupNeverClosed() must REQUIRE $recordingIsComplete: '
            .'a default lets a future caller skip the ceiling guard and finalise a live call, '
            .'and no behavioural test would catch it because the only caller today passes it');
    }

    /**
     * THE ANCHOR IS NOT ALWAYS started_at, AND THE RECORD MUST SAY WHICH.
     *
     * The docblock read "ended_at is DERIVED as started_at plus the recorded
     * length" and "never before started_at". Both are false on a row whose
     * started_at is null: $call->started_at ?? $call->created_at anchors the
     * derivation to created_at instead, and the derived instant then bears no
     * relation to started_at at all - there is none to relate it to.
     *
     * An operator reconciling a derived ended_at against started_at on such a
     * row finds nothing and cannot tell whether the derivation misfired or the
     * column is simply absent. The record now names the anchor, so the two are
     * distinguishable without reading this method - on a row that is not
     * already a voicemail. A stuck voicemail returns before the record is
     * written, so its anchor is not reported.
     */
    public function test_a_row_with_no_started_at_anchors_on_created_at_and_says_so(): void
    {
        Queue::fake();
        Log::spy();

        $call = $this->stuckRingingCall('stuck-no-start');
        // started_at is NOT nullable through create(); clear it on the row the
        // derivation will read, exactly as the production population has it.
        // created_at is backdated too: a freshly-created row carries created_at
        // == now(), so ANY positive length derives an instant in the future and
        // the clamp fires - which would test the clamp, not the anchor. The
        // real stuck population is months old. Measured before relying on it.
        $call->started_at = null;
        $call->created_at = now()->subMinutes(30);
        $call->save();

        $createdAt = $call->fresh()->created_at->copy();

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-no-start',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-no-start.mp3',
            90,
        );

        $stored = $call->fresh();

        $this->assertNotNull($stored->ended_at, 'the row must still finalise on the created_at anchor');
        $this->assertTrue(
            $stored->ended_at->equalTo($createdAt->copy()->addSeconds(90)),
            'with no started_at the derivation must anchor on created_at'
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === '[PhoneCall] Finalised a call the hangup webhook never closed'
                    && ($context['anchor'] ?? null) === 'created_at';
            })
            ->once();
    }

    /**
     * The ordinary arm, asserted so the anchor key can discriminate rather
     * than being a constant that happens to read right on one row.
     */
    public function test_a_row_with_a_started_at_reports_that_anchor(): void
    {
        Queue::fake();
        Log::spy();

        $this->stuckRingingCall('stuck-with-start');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-with-start',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-with-start.mp3',
            90,
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === '[PhoneCall] Finalised a call the hangup webhook never closed'
                    && ($context['anchor'] ?? null) === 'started_at'
                    && ($context['clamped_to_now'] ?? null) === false;
            })
            ->once();
    }

    /**
     * THE FUTURE-CLAMP IS THE ONE ROUTE BY WHICH now() REACHES ended_at, and
     * the docblock said flatly "It does not stamp now()".
     *
     * A recorded length longer than the time since the anchor derives an
     * instant in the future; the clamp replaces it with now(). The stored row
     * afterwards is INDISTINGUISHABLE from an ordinary derived one - same
     * column, plausible value - so an operator auditing a re-dated call had no
     * way to find the rows where the derivation was discarded. The record now
     * carries the flag - on a row that is not already a voicemail. A stuck
     * voicemail returns before the record is written, so a clamp on that arm
     * is still unreported; this test's fixture is ringing, not voicemail.
     */
    public function test_a_future_derivation_is_clamped_to_now_and_the_record_says_so(): void
    {
        Queue::fake();
        Log::spy();

        $call = $this->stuckRingingCall('stuck-clamped');
        $startedAt = $call->started_at->copy();
        $before = now()->subSecond();

        // started_at is 10 minutes ago; a 2-hour recording derives an ended_at
        // well ahead of now, which is exactly the clamp's trigger.
        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-clamped',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-clamped.mp3',
            7200,
        );

        $stored = $call->fresh();

        $this->assertTrue(
            $stored->ended_at->greaterThanOrEqualTo($before),
            'a clamped row must carry now(), not the future derivation'
        );
        $this->assertTrue(
            $stored->ended_at->lessThan($startedAt->copy()->addSeconds(7200)),
            'the clamp must have replaced the derived instant, not kept it'
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === '[PhoneCall] Finalised a call the hangup webhook never closed'
                    && ($context['clamped_to_now'] ?? null) === true;
            })
            ->once();
    }

    /**
     * THE SAME PATH WRITES status, WHICH THE RECORD DID NOT MENTION.
     *
     * "Finalised a call the hangup webhook never closed" reads as one write.
     * It is two: ended_at, and - on a row that is not already a voicemail -
     * status, decided from answered_at alone. An operator reading the record
     * could not see that the status on the row was this path's decision.
     */
    public function test_the_record_names_the_status_this_path_decided(): void
    {
        Queue::fake();
        Log::spy();

        $this->stuckRingingCall('stuck-status-missed');

        app(PhoneCallService::class)->handleRecordingReady(
            'stuck-status-missed',
            'https://media.plivo.com/v1/Account/MA/Recording/rec-status.mp3',
            45,
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === '[PhoneCall] Finalised a call the hangup webhook never closed'
                    && ($context['status'] ?? null) === CallStatus::Missed->value;
            })
            ->once();
    }
}
