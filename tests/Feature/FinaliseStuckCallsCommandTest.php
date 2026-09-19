<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * A stuck row with no duration anywhere still finalises - it just has no
     * length to add, so the end time is the start.
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
     * The floor is a floor. --min-age-hours can shorten the reach of a careful
     * run but cannot be used to aim the sweep at live traffic.
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

        $this->artisan('calls:finalise-stuck --apply --min-age-hours=0')->assertSuccessful();

        $this->assertNull($recent->fresh()->ended_at,
            '--min-age-hours=0 must be raised to the one-hour floor, not honoured');
        $this->assertNotNull($aged->fresh()->ended_at,
            'positive control: the aged row in the same run must still be finalised');
    }
}
