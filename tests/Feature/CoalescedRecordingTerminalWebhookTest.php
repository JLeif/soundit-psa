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
 * branch ends `return response('OK', 200)`. handleCallEnded() — which `git grep`
 * confirms is the sole writer of ended_at in app/ (PhoneCallService.php, the one
 * `$call->ended_at = now()` assignment) — is reachable only from the two
 * branches BELOW it, on DialAction=hangup and on a terminal CallStatus. A
 * payload carrying RecordUrl AND a terminal marker therefore cannot reach it.
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
 * RED-CHECKED against 53829d85 (origin/main at the time of writing). Five of the
 * seven tests below FAIL there. The two that pass unfixed are labelled as such
 * on the method itself and are guards on behaviour the fix must not break, not
 * evidence of the defect — a control that cannot fail is not proof of anything
 * and is worth having only if it says so out loud.
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
        $call->ended_at = null;
        $call->save();

        return $call->refresh();
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/plivo/test-secret/webhook', $payload);
    }

    /**
     * THE DEFECT, in its exact production shape: one POST carrying both
     * RecordUrl and CallStatus=completed.
     *
     * RED at 53829d85: ended_at stays NULL.
     */
    public function test_a_coalesced_recording_and_completed_status_finalises_the_call(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/abc.mp3',
            'RecordingDuration' => 22,
            'Duration' => 25,
        ])->assertOk();

        $call->refresh();

        $this->assertNotNull(
            $call->ended_at,
            'The coalesced delivery carried CallStatus=completed and was discarded by the recording branch.'
        );
        $this->assertSame('https://media.plivo.com/v1/rec/abc.mp3', $call->recording_url);
        $this->assertSame(22, $call->recording_duration);
    }

    /**
     * The same coalescing with DialAction=hangup instead of CallStatus — the
     * other terminal marker. A fix written for only one of the two markers
     * passes half of this file.
     *
     * RED at 53829d85: ended_at stays NULL.
     */
    public function test_a_coalesced_recording_and_hangup_finalises_the_call(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'DialAction' => 'hangup',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/def.mp3',
            'RecordingDuration' => 14,
            'Duration' => 19,
        ])->assertOk();

        $this->assertNotNull($call->refresh()->ended_at);
    }

    /**
     * An unanswered call with a recording is a voicemail, and finalising it must
     * not overwrite that status. handleCallEnded() preserves Voicemail only if
     * the row ALREADY carries it — which is exactly why the recording work has
     * to run before the finalisation and not after.
     *
     * RED at 53829d85: ended_at stays NULL (the status was already right there,
     * so this test measures the ORDERING, not the status rule).
     */
    public function test_a_coalesced_voicemail_keeps_its_voicemail_status_and_gains_an_ended_at(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/vm.mp3',
            'RecordingDuration' => 31,
            'Duration' => 34,
        ])->assertOk();

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
     * RED at 53829d85 for the ended_at assertion. The duration and prepay
     * assertions are what fail against a fix that falls through WITHOUT
     * preserving the duration — they are aimed at the naive version of this fix,
     * not at main. Both are wanted: the duration assertion names the mechanism,
     * the surviving-transaction assertion names the harm.
     */
    public function test_a_coalesced_delivery_without_duration_does_not_reverse_the_prepay_debit(): void
    {
        Queue::fake();

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
     * PASSES UNFIXED ONLY IN THE SENSE THAT main never reaches this code at all —
     * it fails at 53829d85 on the ended_at assertion. Stated so the next reader
     * does not treat it as a second red-check of the same thing.
     */
    public function test_a_coalesced_delivery_prefers_the_payload_duration_over_the_stored_one(): void
    {
        Queue::fake();
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
     * RED against a presence-tested fallback: duration comes back 0.
     */
    public function test_a_zero_b_leg_duration_does_not_overwrite_the_recording_derived_duration(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'DialAction' => 'hangup',
            'RecordUrl' => 'https://media.plivo.com/v1/rec/zerobleg.mp3',
            'RecordingDuration' => 95,
            'DialBLegDuration' => 0,
            // NO Duration key — the coalesced voicemail shape.
        ])->assertOk();

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
     */
    public function test_two_separate_posts_still_work(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'RecordUrl' => 'https://media.plivo.com/v1/rec/two.mp3',
            'RecordingDuration' => 18,
        ])->assertOk();

        $this->assertNull($call->refresh()->ended_at, 'A recording-only POST says nothing about the call having ended.');

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'CallStatus' => 'completed',
            'Duration' => 20,
        ])->assertOk();

        $call->refresh();
        $this->assertNotNull($call->ended_at);
        $this->assertSame(20, (int) $call->duration);
    }

    /**
     * PASSES UNFIXED BY DESIGN — the complementary boundary.
     *
     * A recording callback with no terminal marker must NOT finalise the call.
     * This is the guard against over-reach: the fix keys on the terminal marker,
     * not on the presence of a recording, and a version that finalised every
     * recording delivery would end calls that are still connected. That is the
     * defect class the sibling leg (card 6aadb3c4) had to fix in its sweep, and
     * it is worse than the one being fixed here.
     */
    public function test_a_recording_without_a_terminal_marker_does_not_finalise(): void
    {
        Queue::fake();
        $call = $this->ringingCall();

        $this->postWebhook([
            'CallUUID' => $call->call_uuid,
            'RecordUrl' => 'https://media.plivo.com/v1/rec/live.mp3',
            'RecordingDuration' => 9,
        ])->assertOk();

        $call->refresh();

        $this->assertNull($call->ended_at, 'A live call whose recording rolled over must not be finalised.');
        $this->assertSame('https://media.plivo.com/v1/rec/live.mp3', $call->recording_url);
    }
}
