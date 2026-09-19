<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card 6aade104 — 182 production rows left with ended_at NULL because ONE Plivo
 * POST carried both the recording and the terminal event, and the controller's
 * recording branch answered 200 and returned before either branch that writes
 * ended_at could run.
 *
 * THE MECHANISM, verified at source rather than inferred. In
 * app/Http/Controllers/Api/PlivoWebhookController.php the `if ($hasRecording)`
 * branch ends `return response('OK', 200)`. handleCallEnded() is reachable only
 * from the two branches BELOW it, on DialAction=hangup and on a terminal
 * CallStatus. A payload carrying RecordUrl AND a terminal marker therefore
 * cannot reach it, so the call's real, delivered end time is acknowledged 200
 * and discarded.
 *
 * ONE PREMISE OF THIS FILE HAS SINCE CHANGED, and it is recorded here rather
 * than silently absorbed because two of these tests were built on it. When this
 * file was written against 53829d85, handleCallEnded() was the SOLE writer of
 * ended_at in app/. It is not any more: card 6aadb3c4 (PR 2693) landed as
 * f4fa7452 — this branch's base after a rebase — adding
 * PhoneCallService::handleRecordingReady() -> finaliseCallTheHangupNeverClosed(),
 * which derives ended_at from started_at + duration for a call whose hangup
 * webhook never arrived. The two legs do not overlap textually (this one is
 * controller-side, that one service-side), which is why `git merge-tree` is
 * clean and only a merged-tree test run sees the interaction at all.
 *
 * What that costs this file is an INSTRUMENT, not a property. The two scope
 * guards at the bottom used `ended_at` as a proxy for "the controller did not
 * finalise"; that proxy was only ever valid while one writer existed. They are
 * re-aimed at the controller's non-action directly (see spyingPhoneCallService()),
 * and a new test pins the ORDERING the two writers now imply. The defect above,
 * and every other test here, is unchanged.
 *
 * WHAT THIS IS NOT, because the distinction changes what these tests may assume.
 * It is not a code regression with a datable window. The onset in the data is
 * 2026-05-13, but `git log` on the controller shows the early `return` present
 * in the repo's initial public commit (e4073ef5, 2026-05-28) — the repo's own
 * history begins AFTER the onset date, so git cannot date this defect and the
 * code shape has never changed. The May boundary is a change in Plivo's
 * delivery behaviour (when it began coalescing the two callbacks into one POST),
 * not in ours. So no test here may assert "correct before date X"; the March and
 * April rows are intact because they arrived as two POSTs, and the two-POST path
 * must keep working — which test_two_separate_posts_still_work pins.
 *
 * WHY VOICEMAIL DOMINATES THE 182 and yet nothing here is voicemail-specific: a
 * voicemail IS a call whose recording ends when the call ends, so it is the
 * shape most likely to produce both facts in one payload. recording_url is
 * 182/182 on the broken set and 78/78 on the working set, so carrying a
 * recording is not the discriminator — arriving coalesced is.
 *
 * RED-CHECKED against the unfixed controller at this branch's base f4fa7452:
 * EIGHT of the ten tests below FAIL there, and the two that pass are exactly the
 * two scope guards, which are labelled as such on the method itself — a control
 * that cannot fail is not proof of anything and is worth having only if it says
 * so out loud.
 *
 * A STANDING CAVEAT ON `assertNotNull($call->ended_at)` IN THIS FILE, because it
 * would otherwise be read as evidence it is not. On the merged tree the service
 * finalises ANY recording delivery with RecordingDuration >= 0, so in every test
 * here whose payload carries a non-negative RecordingDuration that assertion is
 * satisfied by the service ALONE, whether or not the controller branch runs. It
 * is retained as a statement of the user-visible outcome, NOT as a control on
 * this branch's behaviour. Only two ended_at assertions in this file can fail on
 * the controller's account: the RecordingDuration = -1 test (where the service
 * never runs) and the ordering test's explicit two-candidate pair. Everything
 * else that discriminates does so through the spy. This is the same degeneracy
 * that made the ordering assertion inert, named here rather than left for the
 * next reader to rediscover.
 *
 * That red-check is the reason several tests here assert a spy count rather than
 * a column, and the finding is worth stating plainly because it nearly escaped.
 * Re-running the red-check at the NEW base, with only the two scope guards
 * re-aimed, five of the seven original tests PASSED against the unfixed
 * controller — they had been silently disarmed by the rebase. Nothing about
 * them changed; the sibling's new writer simply satisfies an `ended_at`
 * assertion without the fix present. A stale premise does not only break tests
 * loudly, as CI showed; it can also make them pass quietly, which is worse,
 * because a green control reads as proof. The columns are still asserted — they
 * are the user-visible outcome — but the DEFECT is now asserted where only this
 * branch can satisfy it: the controller routing a coalesced payload to
 * handleCallEnded() with a usable Duration.
 *
 * FIXTURES: every call row carries recording_disk_path pre-seeded, so
 * downloadRecording() returns at its first guard and no socket is opened. This
 * is deliberate — without it these tests would reach for media.plivo.com.
 */
class CoalescedRecordingTerminalWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A call already in flight: created by an earlier callback, no ended_at.
     * This is the production shape of all 182 rows before their final delivery.
     */
    private function ringingCall(array $overrides = []): PhoneCall
    {
        $call = PhoneCall::create(array_merge([
            'call_uuid' => 'coalesced-'.uniqid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+12065550101',
            'to_number' => '+12065550199',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(2),
            // Keeps downloadRecording() off the network — its first guard
            // returns when this column is already set.
            'recording_disk_path' => 'call-recordings/seeded.mp3',
        ], $overrides));

        // ended_at, answered_at and duration are NOT in PhoneCall::$fillable, so
        // create() silently drops them. Assign directly. (Learned the hard way
        // on the sibling leg: fixtures that lose columns make tests fail for the
        // wrong reason, and a red for the wrong reason reads as proof.)
        //
        // All THREE are honoured from $overrides, not just ended_at. An earlier
        // version assigned a hardcoded `ended_at = null` and silently dropped
        // answered_at and duration overrides — the exact trap this comment warns
        // about, reintroduced one line below the warning. Callers currently pass
        // none of the three, so nothing was wrong on this branch; it was a
        // loaded gun for the next caller.
        $call->ended_at = $overrides['ended_at'] ?? null;
        $call->answered_at = $overrides['answered_at'] ?? null;
        if (array_key_exists('duration', $overrides)) {
            $call->duration = $overrides['duration'];
        }
        $call->save();

        return $call->refresh();
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/plivo/test-secret/webhook', $payload);
    }

    /**
     * AN INSTRUMENT FOR THE CONTROLLER'S NON-ACTION, and why the obvious one no
     * longer works.
     *
     * The two scope guards at the bottom of this file originally asserted that
     * ended_at stays NULL after a recording-only POST, using the column as a
     * proxy for "the coalesced branch was not entered". That proxy was sound
     * only while handleCallEnded() was the SOLE writer of ended_at, which it was
     * at 53829d85 and is not any more: card 6aadb3c4 (PR 2693, merged as
     * f4fa7452) added a second writer, PhoneCallService::handleRecordingReady()
     * -> finaliseCallTheHangupNeverClosed(), which DELIBERATELY finalises a
     * recording-only delivery. So on the merged tree ended_at is non-null after
     * a recording-only POST, legitimately, and by a collaborator that owns that
     * decision.
     *
     * The property those guards exist to protect did not die with the proxy. It
     * is "THIS CONTROLLER does not treat a recording as a terminal marker" — the
     * over-reach class, where keying on the recording rather than on the
     * terminal marker would end calls that are still connected. So the guards
     * are re-aimed at the controller's own non-action, observed directly, rather
     * than at a row-level end state another writer now legitimately owns.
     *
     * This spy is a real PhoneCallService subclass bound into the container, not
     * a mock: every call still runs parent::, so the rest of the request behaves
     * exactly as in production and the surrounding assertions stay meaningful. A
     * `shouldReceive(...)->never()` mock would have stubbed the very service
     * whose work these tests also check.
     */
    private function spyingPhoneCallService(): PhoneCallService
    {
        $service = new class extends PhoneCallService
        {
            /** @var list<array<string, mixed>> Every payload the controller handed to handleCallEnded(), in order. */
            public array $handleCallEndedPayloads = [];

            public function handleCallEnded(string $callUuid, array $data): ?PhoneCall
            {
                $this->handleCallEndedPayloads[] = $data;

                return parent::handleCallEnded($callUuid, $data);
            }
        };

        $this->app->instance(PhoneCallService::class, $service);

        return $service;
    }

    /**
     * THE DEFECT, in its exact production shape: one POST carrying both
     * RecordUrl and CallStatus=completed.
     *
     * RED at 53829d85 on `ended_at` alone. NOT RED on `ended_at` alone at
     * f4fa7452, and that is the single most important thing the rebase taught
     * this file: the sibling's finaliseCallTheHangupNeverClosed() stamps a
     * DERIVED ended_at on this same payload, so at the new base this test's
     * original assertion is satisfied by a writer that is not the fix. The
     * symptom is masked; the defect is not. The controller still discards the
     * vendor's delivered end time and its Duration.
     *
     * So the defect assertion is the controller's ACTION, observed directly,
     * exactly as the two scope guards below assert its non-action. Red at
     * f4fa7452: handleCallEnded() is called ZERO times.
     */
    public function test_a_coalesced_recording_and_completed_status_finalises_the_call(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/abc.mp3',
            'RecordingDuration' => 22,
            'Duration' => 25,
        ])->assertOk();

        $this->assertCount(
            1,
            $service->handleCallEndedPayloads,
            'THE DEFECT: the coalesced delivery carries CallStatus=completed and the recording branch returned 200 without ever routing it to handleCallEnded().'
        );

        $call->refresh();

        $this->assertNotNull($call->ended_at);
        $this->assertSame(25, (int) $call->duration, "The vendor's own Duration must reach the row; the recording-derived value is the fallback, not the answer.");
        $this->assertSame('https://media.plivo.com/v1/rec/abc.mp3', $call->recording_url);
        $this->assertSame(22, $call->recording_duration);
    }

    /**
     * The same coalescing with DialAction=hangup instead of CallStatus — the
     * other terminal marker. A fix written for only one of the two markers
     * passes half of this file.
     *
     * RED at f4fa7452: handleCallEnded() is called zero times. (Its original
     * ended_at assertion is masked at the new base by the sibling's derived
     * stamp — see the completed-status test above for the full reasoning.)
     */
    public function test_a_coalesced_recording_and_hangup_finalises_the_call(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'DialAction' => 'hangup',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/def.mp3',
            'RecordingDuration' => 14,
            'Duration' => 19,
        ])->assertOk();

        $this->assertCount(1, $service->handleCallEndedPayloads, 'DialAction=hangup is the other terminal marker and must reach handleCallEnded() from the recording branch too.');

        $call->refresh();
        $this->assertNotNull($call->ended_at);
        $this->assertSame(19, (int) $call->duration);
    }

    /**
     * An unanswered call with a recording is a voicemail, and finalising it must
     * not overwrite that status. handleCallEnded() preserves Voicemail only if
     * the row ALREADY carries it — which is exactly why the recording work has
     * to run before the finalisation and not after.
     *
     * RED at f4fa7452 on the handleCallEnded() call: the status was already
     * right there, so this test measures the ORDERING within the controller, not
     * the status rule. The status assertion is kept because it is what the
     * ordering is FOR — it would break if the finalisation were moved above the
     * recording work — and the spy is what makes the test fail against a
     * controller that never finalises at all.
     */
    public function test_a_coalesced_voicemail_keeps_its_voicemail_status_and_gains_an_ended_at(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/vm.mp3',
            'RecordingDuration' => 31,
            'Duration' => 34,
        ])->assertOk();

        $this->assertCount(1, $service->handleCallEndedPayloads, 'The coalesced voicemail must still reach handleCallEnded() from the recording branch.');

        $call->refresh();

        $this->assertSame(CallStatus::Voicemail, $call->status, 'The recording work must run before the finalisation, or the voicemail status is never set for handleCallEnded() to preserve.');
        $this->assertNotNull($call->ended_at);
    }

    /**
     * THE MONEY GUARD, and the sharpest test in the file.
     *
     * handleCallEnded() assigns `duration` unconditionally and nulls it when the
     * payload has no Duration. Plivo's coalesced delivery frequently omits
     * Duration — the recording callback's own field is RecordingDuration. So a
     * naive fall-through nulls a duration that handleRecordingReady() derived
     * from the recording seconds earlier.
     *
     * On a row that is ticket+billable, PrepayService::debitFromPhoneCall()
     * REVERSES the debit outright when effectiveDurationSeconds() is falsy. Here
     * recording_duration is 0 — a caller who hung up during the greeting — so
     * effectiveDurationSeconds() has no fallback left once duration is nulled,
     * and the call's charge would reverse itself. That is a write to a client's
     * prepay balance caused by a webhook that reported nothing new.
     *
     * RED against a fix that falls through WITHOUT preserving the duration — the
     * naive version of this fix, which is the dangerous one. Both assertions are
     * wanted: the duration assertion names the mechanism, the
     * surviving-transaction assertion names the harm.
     *
     * NOT red at f4fa7452, and that is stated rather than left to be discovered:
     * an unfixed controller never calls handleCallEnded() on this payload, so it
     * never nulls the column and the debit survives by inaction. This test is
     * aimed at the naive FIX, not at the base — which is why the spy assertion
     * below is here too, so the test cannot be satisfied by a controller that
     * simply does nothing.
     */
    public function test_a_coalesced_delivery_without_duration_does_not_reverse_the_prepay_debit(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();

        $client = Client::create(['name' => 'Coalesced Duration Co']);
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Prepay hours',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'prepay_total' => 10,
            'prepay_used' => 0,
            'prepay_balance' => 10,
            'prepay_as_amount' => false,
        ]);
        $ticket = Ticket::create([
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'subject' => 'Coalesced duration ticket',
            'type' => TicketType::Incident,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
        ]);

        $call = $this->ringingCall([
            'ticket_id' => $ticket->id,
            'is_billable' => true,
        ]);
        // A duration already established by an earlier recording callback, with
        // recording_duration 0 so effectiveDurationSeconds() has no fallback.
        $call->duration = 180;
        $call->recording_duration = 0;
        $call->save();

        // The debit that already exists for this call, written the ordinary way
        // rather than hand-inserted, so the test cannot pass against a service
        // that never writes one.
        app(PrepayService::class)->debitFromPhoneCall($call->refresh());
        $this->assertSame(
            1,
            PrepayTransaction::where('phone_call_id', $call->id)->count(),
            'Precondition: a debit must exist before the webhook, or this test proves nothing.'
        );

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/money.mp3',
            'RecordingDuration' => 0,
            // NO Duration key — the shape that nulls the column.
        ])->assertOk();

        $this->assertCount(
            1,
            $service->handleCallEndedPayloads,
            'The call must be finalised BY THE CONTROLLER — otherwise the surviving debit below proves only that nothing happened.'
        );

        $call->refresh();

        $this->assertNotNull($call->ended_at, 'The call must still be finalised.');
        $this->assertSame(
            180,
            (int) $call->duration,
            'Falling through to handleCallEnded() without supplying a Duration nulls the column it just wrote.'
        );
        $this->assertSame(
            1,
            PrepayTransaction::where('phone_call_id', $call->id)->count(),
            'THE HARM: with duration nulled and recording_duration 0, effectiveDurationSeconds() returns null and debitFromPhoneCall() deletes the client\'s charge for this call.'
        );
    }

    /**
     * DialBLegDuration is the fallback the hangup branch already used, and the
     * coalesced path must honour the same precedence: Plivo's own Duration is
     * more authoritative than the stored one.
     *
     * RED at the current base on the spy assertion: an unfixed controller never
     * routes this payload to handleCallEnded() at all.
     *
     * This test was the last one in the file still citing the ORIGINAL base
     * 53829d85 and the only one not installing the spy — which made its
     * duration assertion the weakest kind of evidence, since 47 is a value only
     * the fix can produce but nothing here proved the fix was what produced it.
     * Both corrected.
     */
    public function test_a_coalesced_delivery_prefers_the_payload_duration_over_the_stored_one(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();
        $call->duration = 180;
        $call->save();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'DialAction' => 'hangup',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/bleg.mp3',
            'RecordingDuration' => 12,
            'DialBLegDuration' => 47,
        ])->assertOk();

        $this->assertCount(1, $service->handleCallEndedPayloads, 'The coalesced hangup must reach handleCallEnded() through the recording branch.');
        $this->assertSame(
            47,
            (int) ($service->handleCallEndedPayloads[0]['Duration'] ?? null),
            'The precedence acts on the payload handed over, so assert it there: the positive B-leg duration must outrank the stored 180.'
        );

        $call->refresh();

        $this->assertSame(47, (int) $call->duration, 'DialBLegDuration is Plivo reporting the call length and outranks the stored value.');
        $this->assertNotNull($call->ended_at);
    }

    /**
     * THE OTHER HALF OF THAT PRECEDENCE, and the one the 47-second case cannot
     * see. DialBLegDuration is scoped to the DIALED (B) leg, and on the
     * unanswered dial that produces a voicemail — the shape that dominates the
     * 182 rows — that leg is exactly the one that never connected, so Plivo
     * reports 0. A presence test on the key would promote that 0 over the
     * duration handleRecordingReady() derived from the recording seconds
     * earlier, reporting a 95-second voicemail as a zero-length call.
     *
     * RED against a presence-tested fallback: duration comes back 0. Like the
     * money guard above it is aimed at a wrong FIX rather than at the base, so it
     * also asserts the controller actually finalised — at f4fa7452 it does not,
     * and a 95-second duration that survives because nothing ran would otherwise
     * read as a pass.
     */
    public function test_a_zero_b_leg_duration_does_not_overwrite_the_recording_derived_duration(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'DialAction' => 'hangup',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/zerobleg.mp3',
            'RecordingDuration' => 95,
            'DialBLegDuration' => 0,
            // NO Duration key — the coalesced voicemail shape.
        ])->assertOk();

        $this->assertCount(1, $service->handleCallEndedPayloads, 'The coalesced hangup must reach handleCallEnded(); a duration that survives because nothing ran proves nothing.');

        $call->refresh();

        $this->assertSame(
            95,
            (int) $call->duration,
            'A zero B-leg duration is the unanswered-dial shape and must not outrank the recording-derived duration the guard exists to preserve.'
        );
        $this->assertSame(95, $call->recording_duration);
        $this->assertNotNull($call->ended_at);
    }

    /**
     * THE SHAPE THE FIRST DRAFT ASSUMED AWAY. handleRecordingReady() — the only
     * writer of recording_url — runs only when RecordingDuration >= 0, so on
     * Plivo's temporary-recording callback (RecordingDuration = -1) the column
     * is never written even though RecordUrl is in the payload and the branch is
     * entered. Coalesce that callback with a terminal marker and the call is
     * finalised with no recording; resolveRecordingAfterEnd() — which both
     * sibling terminal branches call — is the only thing left that would fetch
     * one, and its own guard passes precisely here because recording_url is NULL.
     *
     * RED against a version that skips resolveRecordingAfterEnd() on this path:
     * no resolve process is ever scheduled and the recording is lost for good.
     */
    public function test_a_coalesced_temporary_recording_callback_still_schedules_recording_resolution(): void
    {
        Queue::fake();
        Process::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/temp.mp3',
            'RecordingDuration' => -1,
            'Duration' => 40,
        ])->assertOk();

        $call->refresh();

        $this->assertNull(
            $call->recording_url,
            'RecordingDuration=-1 is the temporary-recording callback, so handleRecordingReady() never runs and recording_url is never written — the premise that it is "always" set on this path is false.'
        );
        $this->assertNotNull($call->ended_at);
        Process::assertRan(fn ($process) => str_contains($process->command, 'calls:resolve-recording '.$call->id));
    }

    /**
     * PASSES UNFIXED BY DESIGN — a boundary guard, not a defect test.
     *
     * The two-POST sequence is how this account's traffic arrived before mid-May
     * 2026 and how the intact 78 rows were written. The fix must not disturb it,
     * and since the terminal POST carries no RecordUrl it never enters the
     * changed branch at all.
     *
     * RE-AIMED at f4fa7452 (this branch's new base) and the reason is recorded
     * rather than quietly absorbed. The middle assertion used to read
     * `assertNull($call->refresh()->ended_at)` after the FIRST post. That premise
     * died when card 6aadb3c4 landed: handleRecordingReady() now finalises a
     * recording-only delivery on purpose, so ended_at is non-null there and CI
     * was right to fail this test. What the assertion was really protecting is
     * that the CONTROLLER did not finalise, so it now observes the controller
     * directly — handleCallEnded() is not called by the first post — and the
     * two-POST outcome it was always about is still asserted at the end.
     * Deleting it instead would have discarded a live scope guarantee under
     * cover of "the test was stale", and a deleted test's tally is
     * indistinguishable from a test that never existed.
     */
    public function test_two_separate_posts_still_work(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'RecordUrl' => 'https://media.plivo.com/v1/rec/two.mp3',
            'RecordingDuration' => 18,
        ])->assertOk();

        $this->assertSame(
            [],
            $service->handleCallEndedPayloads,
            'A recording-only POST carries no terminal marker, so the coalesced branch must not be entered and the controller must not finalise. (ended_at itself is no longer a valid instrument here: since f4fa7452, handleRecordingReady() legitimately writes it on this very shape.)'
        );

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'Duration' => 20,
        ])->assertOk();

        $this->assertCount(
            1,
            $service->handleCallEndedPayloads,
            'The SECOND post is the terminal one and is what finalises through the controller.'
        );

        $call->refresh();
        $this->assertNotNull($call->ended_at);
        $this->assertSame(20, (int) $call->duration);
    }

    /**
     * PASSES UNFIXED BY DESIGN — the complementary boundary, and the one whose
     * instrument the new base took away.
     *
     * THE PROPERTY, unchanged: this controller must not treat a recording as a
     * terminal marker. The fix keys on the terminal marker, not on the presence
     * of a recording; a version that finalised every recording delivery from the
     * CONTROLLER would end calls that are still connected — the over-reach class,
     * which is worse than the defect being fixed here.
     *
     * THE INSTRUMENT, changed, and this is the whole of the re-aim. It used to
     * assert `ended_at` stays NULL. Since f4fa7452 that is false for a reason
     * that is not a defect: handleRecordingReady() calls
     * finaliseCallTheHangupNeverClosed(), which derives ended_at from
     * started_at + duration for a row whose hangup webhook never arrived. That is
     * card 6aadb3c4's deliberate contract, and it is guarded on ITS side by the
     * RECORDING_MAX_LENGTH_SECONDS ceiling — a recording that hit maxLength is
     * evidence the RECORDING stopped, not the CALL, and is declined there. So the
     * over-reach hazard on the recording-only shape is now owned, and guarded, by
     * the service.
     *
     * What is still THIS branch's to guarantee is that the controller adds no
     * second, unguarded route to the same outcome — one that would bypass that
     * ceiling entirely, because handleCallEnded() applies no such test. So the
     * assertion is now on the controller's non-action, observed directly.
     *
     * Measured on the merged tree rather than assumed (the values are in this
     * card's record): after this exact POST, handleCallEnded() is called ZERO
     * times and ended_at is nevertheless stamped at started_at + 9s by the
     * service. Both facts are true at once, which is precisely why the old
     * instrument had to be replaced rather than merely re-expected.
     */
    public function test_a_recording_without_a_terminal_marker_does_not_finalise(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'RecordUrl' => 'https://media.plivo.com/v1/rec/live.mp3',
            'RecordingDuration' => 9,
        ])->assertOk();

        $this->assertSame(
            [],
            $service->handleCallEndedPayloads,
            'THE OVER-REACH GUARD: no terminal marker is in this payload, so the controller must not route it to handleCallEnded(). Finalising on the recording alone is the service\'s decision to make under its maxLength ceiling, not a second unguarded route through this controller.'
        );

        $call->refresh();
        $this->assertSame('https://media.plivo.com/v1/rec/live.mp3', $call->recording_url);
    }

    /**
     * ORDERING, NOT IDEMPOTENCY — the question the new base raises and that no
     * receipt on this card answered before it.
     *
     * Two writers of ended_at now exist on a payload carrying a recording: the
     * service's finaliseCallTheHangupNeverClosed() (card 6aadb3c4) and this
     * branch's coalesced controller branch. Double-finalisation is already closed
     * on the SIBLING's side — its first statement is `if ($call->ended_at !==
     * null) { return; }` — so whichever writer is second is a no-op there. That
     * was read at source on the merged tree, not inherited from a comment, and it
     * means the hazard is not "do both fire". It is WHICH ONE WINS, and whether
     * this branch's duration precedence still holds now that
     * handleRecordingReady() has already derived BOTH a duration and an ended_at
     * before the controller code runs.
     *
     * THE ANSWER THIS PINS. On a coalesced POST the recording work runs first, so
     * the service finalises to started_at + duration; the controller then calls
     * handleCallEnded(), whose FIRST statement is `$call->ended_at = now()` with
     * no ended_at guard of its own. So the CONTROLLER WINS the timestamp, and
     * that is the correct outcome rather than a tolerated one: on a coalesced
     * delivery the vendor is reporting the end as it happens, so now() is an
     * observed end time, while the service's value is explicitly documented as a
     * derivation for a hangup webhook that never came. The derived value must not
     * outlive the real one.
     *
     * THE CASE THAT MAKES IT MATTER, and it is the money one:
     * recording_duration = 0, a caller who hung up during the greeting. There
     * effectiveDurationSeconds() has no recording fallback, so if the payload's
     * absent Duration reached handleCallEnded() the column would be nulled and
     * PrepayService would reverse the client's charge. This asserts the payload
     * the controller actually hands over — Duration = the stored 180, supplied by
     * terminalPayloadPreservingDuration() — so the precedence is observed at the
     * boundary where it acts, not merely inferred from the column afterwards.
     *
     * RED against a fix that drops the duration preservation: the handed-over
     * payload has no Duration key and the surviving-transaction assertion in
     * test_a_coalesced_delivery_without_duration_does_not_reverse_the_prepay_debit
     * fails with it.
     */
    public function test_the_controller_wins_the_ordering_and_keeps_the_duration_on_a_coalesced_payload(): void
    {
        Queue::fake();
        $service = $this->spyingPhoneCallService();

        // The call must have STARTED long enough ago that the service's derived
        // end time and the controller's now() cannot coincide. This is not
        // cosmetic: with the file's default started_at of now()-2min and a
        // duration of 180, the derived value is started_at + 180 = now() + 60,
        // which finaliseCallTheHangupNeverClosed() CLAMPS to now() because it
        // refuses to date a hangup in the future. Both writers would then produce
        // exactly now() and no assertion could tell them apart. Starting the call
        // an hour ago puts the derived value at start + 180s, ~57 minutes before
        // now(), so the two candidates are unambiguously distinct.
        $call = $this->ringingCall(['started_at' => now()->subHour()]);
        // The greeting-hangup row: a duration already derived from an earlier
        // recording callback, and a recording_duration of 0 that leaves
        // effectiveDurationSeconds() no fallback of its own.
        $call->duration = 180;
        $call->recording_duration = 0;
        $call->save();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/ordering.mp3',
            'RecordingDuration' => 0,
            // NO Duration key — the coalesced shape that nulls the column.
        ])->assertOk();

        $this->assertCount(
            1,
            $service->handleCallEndedPayloads,
            'The coalesced payload carries a terminal marker, so the controller must finalise exactly once — not zero times (deferring to the service) and not twice.'
        );
        $this->assertSame(
            180,
            (int) ($service->handleCallEndedPayloads[0]['Duration'] ?? null),
            'THE PRECEDENCE, observed at the boundary: the controller must hand handleCallEnded() the recording-derived duration even though handleRecordingReady() has already run and already written both a duration and an ended_at. Without it the column is nulled and, with recording_duration 0, the prepay debit reverses.'
        );

        $call->refresh();

        $this->assertSame(180, (int) $call->duration);
        $this->assertNotNull($call->ended_at);

        // ORDERING, asserted so that it can actually FAIL on the thing it names.
        //
        // An earlier version of this assertion read `ended_at > started_at + 60s`
        // and was NOT a discriminator: the row still holds duration = 180 when
        // the service runs, so the service's DERIVED value is started_at + 180s,
        // which cleared that 60s threshold just as the controller's now() did
        // (the fixture then started the call 2 minutes back; it now starts an
        // hour back, for the clamp reason given at the top of this test). Proven
        // by mutation rather than by reading: a controller that finalises,
        // preserves the duration, and then restores the service's derived
        // ended_at PASSED the old assertion. A control that cannot fail on its
        // own claim is worse than no control, because the tally looks identical.
        //
        // So both candidates are named explicitly below. With started_at an hour
        // back they are ~3420 seconds apart, and the discriminating assertion is
        // the exact-inequality one: it is deterministic and needs no clock
        // budget. The proximity-to-now() assertion is deliberately SECOND and
        // deliberately loose — it states the positive half of the claim (the
        // surviving value IS the observed one, not merely not-the-derived-one),
        // and 5s is ample because the two candidates are three orders of
        // magnitude further apart than that.
        $derivedByService = $call->started_at->copy()->addSeconds(180);
        $this->assertFalse(
            $call->ended_at->equalTo($derivedByService),
            'ORDERING: ended_at is the value the SERVICE derived (started_at + duration). The controller ran after it and must have overwritten it with the observed end time.'
        );
        $this->assertTrue(
            $call->ended_at->greaterThan($derivedByService->copy()->addMinutes(5)),
            'ORDERING, clock-free: the surviving ended_at is not merely unequal to the servicederived value but far later than it, which only the controller writing now() produces.'
        );
        $this->assertEqualsWithDelta(
            now()->timestamp,
            $call->ended_at->timestamp,
            5,
            'ORDERING: the controller writes ended_at = now() and runs AFTER the service derived started_at + duration, so the observed end time must survive rather than the derived one.'
        );
    }
}
