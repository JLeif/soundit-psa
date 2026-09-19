<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Controls for the one-off sweep that dispositions the historical stuck rows.
 *
 * The property that matters most here is the DRY RUN: this command exists to
 * be read before it is trusted, over client call records, and a dry run that
 * quietly wrote would be the worst possible defect in it. That is pinned
 * first and pinned by reading the database back, not by reading the output.
 */
class FinaliseStuckCallsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function stuckCall(
        string $uuid,
        CallStatus $status = CallStatus::Ringing,
        ?int $recordingDuration = null,
        ?string $answeredAt = null,
    ): PhoneCall {
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => $status,
            'started_at' => now()->subDays(30),
        ]);

        // Not fillable - assign directly.
        $call->ended_at = null;
        // The sweep requires stored evidence the call ended, and every row in
        // the measured population carries a recording - so every fixture here
        // does too. The no-duration variant models a recording that landed
        // with no length, NOT a row with no recording at all: that shape is
        // out of the population by design and is controlled separately, in
        // test_a_long_running_live_call_is_not_swept().
        $call->recording_url = 'https://media.example.test/rec-'.$uuid.'.mp3';
        $call->recording_duration = $recordingDuration;
        $call->answered_at = $answeredAt;
        $call->save();

        return $call;
    }

    /**
     * THE CONTROL THAT MATTERS. A dry run must leave every row byte-for-byte
     * as it found it. Asserted against the stored row, never against the
     * command's own report of what it would do.
     */
    public function test_a_dry_run_writes_absolutely_nothing(): void
    {
        $call = $this->stuckCall('sweep-dry-run', CallStatus::Ringing, 90);

        $this->artisan('calls:finalise-stuck')->assertSuccessful();

        $stored = $call->fresh();
        $this->assertNull($stored->ended_at, 'a dry run must not write ended_at');
        $this->assertSame(CallStatus::Ringing, $stored->status, 'a dry run must not write status');
    }

    /**
     * A positive control for the test above: the same row, with --apply, DOES
     * change. Without this, the dry-run test would pass just as well against
     * a command that could never write at all.
     */
    public function test_apply_actually_finalises_the_row(): void
    {
        $call = $this->stuckCall('sweep-apply', CallStatus::Ringing, 90);
        $startedAt = $call->started_at->copy();

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $stored = $call->fresh();
        $this->assertNotNull($stored->ended_at);
        $this->assertTrue(
            $stored->ended_at->equalTo($startedAt->copy()->addSeconds(90)),
            'the sweep must anchor the end time to the call, not to the moment it ran'
        );
        $this->assertSame(CallStatus::Missed, $stored->status,
            'no answer fingerprint, so the finalised call is Missed');
    }

    /**
     * The sweep holds the same no-inventing-an-answer boundary as the live
     * path: a stuck row that DOES carry an answer moment is a conversation.
     */
    public function test_a_swept_call_with_an_answer_moment_becomes_completed(): void
    {
        $call = $this->stuckCall(
            'sweep-answered',
            CallStatus::Ringing,
            120,
            now()->subDays(30)->toDateTimeString(),
        );

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $this->assertSame(CallStatus::Completed, $call->fresh()->status);
    }

    /**
     * A stuck voicemail is finalised without being resurrected.
     */
    public function test_the_sweep_does_not_resurrect_a_voicemail(): void
    {
        $call = $this->stuckCall('sweep-voicemail', CallStatus::Voicemail, 40);

        // A Voicemail row with no ended_at is in the stuck population only if
        // its status is ringing/in-progress, so put it there the way the real
        // data does: status still ringing, then flipped by the auto-detect.
        $call->status = CallStatus::Voicemail;
        $call->save();

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $this->assertSame(CallStatus::Voicemail, $call->fresh()->status);
    }

    /**
     * A row that already ended is not in the population at all, and a second
     * run after an --apply finds nothing left to do.
     */
    public function test_the_sweep_is_idempotent_and_ignores_finalised_rows(): void
    {
        $call = $this->stuckCall('sweep-idempotent', CallStatus::Ringing, 60);

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();
        $firstEnd = $call->fresh()->ended_at->copy();

        $this->artisan('calls:finalise-stuck --apply')
            ->expectsOutputToContain('No stuck calls found.')
            ->assertSuccessful();

        $this->assertTrue($call->fresh()->ended_at->equalTo($firstEnd),
            'a second sweep must not move an end time the first one derived');
    }

    /**
     * A stuck row whose recording landed with no length still finalises - the
     * recording is the evidence it ended, and with nothing to add the end time
     * is the start.
     */
    public function test_a_row_with_no_duration_is_finalised_at_its_start(): void
    {
        $call = $this->stuckCall('sweep-no-duration', CallStatus::Ringing, null);
        $startedAt = $call->started_at->copy();

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $stored = $call->fresh();
        $this->assertTrue($stored->ended_at->equalTo($startedAt),
            'with no length to add, the defensible end time is the start');
    }

    /**
     * in-progress rows are in the population too. Three exist in production
     * with the same never-finalised shape.
     */
    public function test_in_progress_rows_are_swept_as_well(): void
    {
        $call = $this->stuckCall('sweep-in-progress', CallStatus::InProgress, 200,
            now()->subDays(30)->toDateTimeString());

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $stored = $call->fresh();
        $this->assertNotNull($stored->ended_at);
        $this->assertSame(CallStatus::Completed, $stored->status);
    }

    /**
     * --limit bounds the blast radius of a first careful run.
     */
    public function test_limit_bounds_how_many_rows_are_touched(): void
    {
        $first = $this->stuckCall('sweep-limit-1', CallStatus::Ringing, 30);
        $second = $this->stuckCall('sweep-limit-2', CallStatus::Ringing, 30);

        $this->artisan('calls:finalise-stuck --apply --limit=1')->assertSuccessful();

        $this->assertNotNull($first->fresh()->ended_at, 'the first row is within the limit');
        $this->assertNull($second->fresh()->ended_at, 'the second row is beyond it and must be untouched');
    }

    /**
     * A call that is ringing RIGHT NOW is shaped exactly like the defect - no
     * ended_at - and is not the defect. Sweeping it would write a zero-length
     * Missed record over a conversation in progress.
     *
     * The aged row in the same run is the positive control: without it this
     * test would pass just as well against a command that could never write.
     *
     * A 20-second row is out of the population on BOTH bounds - too young, and
     * no stored evidence it ended - so this is the scenario, not the pin for
     * either bound. The floor is pinned by
     * test_the_age_floor_cannot_be_lowered_below_an_hour(), whose recent row
     * carries evidence and so is held out by the floor alone; the end-evidence
     * filter is pinned by test_a_long_running_live_call_is_not_swept(), whose
     * live row is older than every age bound.
     */
    public function test_a_call_that_is_still_live_is_not_swept(): void
    {
        $aged = $this->stuckCall('sweep-floor-aged', CallStatus::Ringing, 60);

        $live = PhoneCall::create([
            'call_uuid' => 'sweep-live',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subSeconds(20),
        ]);

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $storedLive = $live->fresh();
        $this->assertNull($storedLive->ended_at,
            'a call that started 20 seconds ago is live, not stuck');
        $this->assertSame(CallStatus::Ringing, $storedLive->status,
            'a live call must keep the status the hangup webhook will finalise');

        $this->assertNotNull($aged->fresh()->ended_at,
            'positive control: the aged row in the same run must still be finalised');
    }

    /**
     * The floor is a floor: --min-age-hours cannot be lowered below an hour.
     * Be exact about what that buys - it shortens the reach of a careful run,
     * it does NOT keep the sweep off live traffic. Age cannot do that at any
     * value (a four-hour call is in contract here); the end-evidence filter
     * does, and test_a_long_running_live_call_is_not_swept() is its pin.
     */
    public function test_the_age_floor_cannot_be_lowered_below_an_hour(): void
    {
        $aged = $this->stuckCall('sweep-floor-clamp-aged', CallStatus::Ringing, 60);

        $recent = PhoneCall::create([
            'call_uuid' => 'sweep-floor-clamp-recent',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subMinutes(5),
        ]);

        // It carries end evidence, so the ONLY thing holding it out of the
        // population is the floor. Without this the end-evidence filter would
        // be doing the floor's work and this control would pass just as well
        // against a build with the clamp deleted.
        $recent->recording_url = 'https://media.example.test/rec-floor-clamp.mp3';
        $recent->recording_duration = 45;
        $recent->save();

        $this->artisan('calls:finalise-stuck --apply --min-age-hours=0')->assertSuccessful();

        $this->assertNull($recent->fresh()->ended_at,
            '--min-age-hours=0 must be raised to the one-hour floor, not honoured');
        $this->assertNotNull($aged->fresh()->ended_at,
            'positive control: the aged row in the same run must still be finalised');
    }

    /**
     * THE AGE FLOOR IS NOT A LIVENESS TEST, and this is the row that proves
     * it. PlivoWebhookController emits <Record maxLength="14400" />, so a call
     * still connected three hours in is an in-contract shape - older than the
     * two-hour default AND older than the one-hour clamp. Age cannot separate
     * it from a stuck row; only the absence of any stored evidence it ended
     * can, which is what this pins. Delete the end-evidence filter and this
     * row is swept: a zero-length ended_at and a final status written onto a
     * conversation in progress.
     *
     * Its duration and recording columns are all null because that is what a
     * connected call looks like BEFORE its recording callback has fired:
     * duration lands at hangup, and the recording columns land when the
     * recording callback fires.
     *
     * Stated that narrowly on purpose, and twice corrected. An earlier version
     * of this docblock said the recording columns are never written while a
     * call is connected - false for the very maxLength shape this test is
     * about, since a recording rolling over at the ceiling posts its callback
     * mid-call. The version after it was wrong the other way: it said such a
     * row never reaches this command, because handleRecordingReady() either
     * stamps ended_at or leaves the row duration-less. It does neither - it
     * writes all three evidence columns and THEN declines, so the row lands
     * squarely in this population. That is what the maxLength ceiling
     * predicate excludes, and
     * test_a_call_declined_by_the_maxlength_guard_is_not_swept() is its pin.
     * The fixture below is the different, pre-callback shape - no columns at
     * all - which is the one the evidence filter is genuinely load-bearing
     * for.
     */
    public function test_a_long_running_live_call_is_not_swept(): void
    {
        $aged = $this->stuckCall('sweep-longlive-aged', CallStatus::Ringing, 60);

        $live = PhoneCall::create([
            'call_uuid' => 'sweep-longlive',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subHours(3),
        ]);

        $this->artisan('calls:finalise-stuck --apply')
            ->expectsOutputToContain('1 row(s) with no stored evidence they ended')
            ->assertSuccessful();

        $storedLive = $live->fresh();
        $this->assertNull($storedLive->ended_at,
            'a call connected for three hours is past every age bound and is still not stuck');
        $this->assertSame(CallStatus::Ringing, $storedLive->status,
            'the sweep must not disposition a call it cannot prove ended');

        $this->assertNotNull($aged->fresh()->ended_at,
            'positive control: the aged row in the same run must still be finalised');
    }

    /**
     * THE SAME DEFECT, ONE COMMAND OVER. A live call whose recording rolls
     * over at maxLength gets its recording callback mid-conversation.
     * handleRecordingReady() writes recording_url, recording_duration and
     * duration and only THEN declines to finalise - so the row it leaves has a
     * null ended_at, full end evidence, and at four hours an age past every
     * floor. Every predicate this command had was satisfied by a connected
     * call, and --apply would have stamped an end time and a final status onto
     * it.
     *
     * The fixture is produced by running the real webhook path rather than by
     * hand, so it is the shape the service ACTUALLY leaves behind, not the
     * shape this test believes it leaves - the assumption that failed twice
     * already. The three preconditions below pin that shape explicitly: if a
     * future change stops writing those columns before declining, they go red
     * and say why rather than leaving the sweep's predicate silently vacuous.
     */
    public function test_a_call_declined_by_the_maxlength_guard_is_not_swept(): void
    {
        Queue::fake();

        $aged = $this->stuckCall('sweep-ceiling-aged', CallStatus::Ringing, 60);

        $live = PhoneCall::create([
            'call_uuid' => 'sweep-ceiling-live',
            'direction' => 'inbound',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'status' => CallStatus::Ringing,
            'started_at' => now()->subHours(5),
        ]);

        app(PhoneCallService::class)->handleRecordingReady(
            'sweep-ceiling-live',
            'https://media.example.test/rec-ceiling.mp3',
            14400,
        );

        $afterCallback = $live->fresh();
        $this->assertNull($afterCallback->ended_at,
            'precondition: the live guard declined to finalise the rolled-over recording');
        $this->assertNotNull($afterCallback->recording_url,
            'precondition: the recording columns ARE written on the declined row');
        $this->assertEquals(14400, $afterCallback->duration,
            'precondition: duration is backfilled too, so the declined row carries end evidence');

        $this->artisan('calls:finalise-stuck --apply')
            ->expectsOutputToContain('at or above the maxLength recording ceiling')
            ->assertSuccessful();

        $storedLive = $live->fresh();
        $this->assertNull($storedLive->ended_at,
            'a recording that stopped at its ceiling is not evidence the call ended');
        $this->assertSame(CallStatus::Ringing, $storedLive->status,
            'the sweep must not disposition a call that may still be connected');

        $this->assertNotNull($aged->fresh()->ended_at,
            'positive control: the aged row in the same run must still be finalised');
    }
}
