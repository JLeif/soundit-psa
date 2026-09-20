<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card 6aafb4ab — the notify guard (PR #2880) withholds a voicemail email until
 * end evidence arrives, and if it never arrives nothing surfaced the withheld
 * row. This is the gauge that surfaces it.
 *
 * WHAT THESE CONTROLS PIN: that an unreleased marker older than the threshold
 * is counted, that a released one and a fresh one are not, that a row already
 * emailed about is not, and that the command writes nothing back to a call.
 *
 * WHAT THEY DO NOT PIN, said plainly: whether the threshold of 60 minutes is
 * the right operational line. That is a reporting choice, and no test can tell
 * a well-chosen threshold from a badly-chosen one.
 */
class StrandedVoicemailDeferralReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deliberately uses array_key_exists rather than `??` for the nullable
     * columns: `??` coalesces an explicitly-passed null, so a test wanting a
     * row with NO deferral marker would silently get the default instead and
     * pass for the wrong reason. That trap is GitHub #2883, found in the
     * sibling suite; it is not being reproduced here.
     */
    private function deferredCall(array $overrides = []): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => 'vm-'.uniqid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+12065550101',
            'to_number' => '+12065550199',
            'status' => CallStatus::Voicemail,
            'started_at' => now()->subHours(3),
        ]);

        $call->voicemail_notify_deferred_at = array_key_exists('deferred_at', $overrides)
            ? $overrides['deferred_at']
            : now()->subHours(2);
        $call->voicemail_notified_at = array_key_exists('notified_at', $overrides)
            ? $overrides['notified_at']
            : null;
        $call->ended_at = array_key_exists('ended_at', $overrides)
            ? $overrides['ended_at']
            : null;
        $call->save();

        return $call->refresh();
    }

    public function test_an_unreleased_deferral_past_the_threshold_is_reported(): void
    {
        $call = $this->deferredCall();

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('1 voicemail notification(s) withheld')
            ->assertExitCode(0);

        // The gauge writes nothing back to the call it reports.
        $this->assertNotNull($call->refresh()->voicemail_notify_deferred_at);
        $this->assertNull($call->refresh()->voicemail_notified_at);
    }

    public function test_a_released_deferral_is_not_reported(): void
    {
        // What a release leaves behind: marker cleared, send claimed.
        $this->deferredCall(['deferred_at' => null, 'notified_at' => now()->subHour(), 'ended_at' => now()->subHour()]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_a_deferral_younger_than_the_threshold_is_not_reported(): void
    {
        // The ordinary case: deferred moments ago, release still in flight.
        $this->deferredCall(['deferred_at' => now()->subMinute()]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    /**
     * The disagreement case that justifies testing BOTH columns. A marker can
     * sit beside a set voicemail_notified_at when one entrance marked a row
     * whose send another entrance had already claimed. That row has been
     * emailed about, so it is not stranded — and a gauge keying on the marker
     * alone would report it.
     */
    public function test_a_row_already_emailed_about_is_not_reported_even_with_a_marker(): void
    {
        $this->deferredCall(['deferred_at' => now()->subHours(2), 'notified_at' => now()->subHours(2)]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_the_threshold_is_overridable(): void
    {
        $this->deferredCall(['deferred_at' => now()->subMinutes(30)]);

        // Outside a 60-minute window...
        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);

        // ...inside a 10-minute one.
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => 10])
            ->expectsOutputToContain('1 voicemail notification(s) withheld')
            ->assertExitCode(0);
    }

    public function test_a_negative_threshold_is_refused(): void
    {
        $this->artisan('calls:report-stranded-voicemail-deferrals', ['--minutes' => -5])
            ->expectsOutputToContain('--minutes must not be negative')
            ->assertExitCode(1);
    }

    /**
     * A row that never carried a marker is invisible to this gauge. Pinned so
     * the limit is a control rather than a sentence in a docblock: this is the
     * pre-existing pool on card 6aade104, and a zero here does not speak for it.
     */
    public function test_a_row_that_was_never_marked_is_not_reported(): void
    {
        $this->deferredCall(['deferred_at' => null]);

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('No voicemail deferral has been outstanding')
            ->assertExitCode(0);
    }

    public function test_the_count_and_oldest_reflect_several_stranded_rows(): void
    {
        $this->deferredCall(['deferred_at' => now()->subHours(5)]);
        $this->deferredCall(['deferred_at' => now()->subHours(2)]);
        $this->deferredCall(['deferred_at' => now()->subMinute()]); // in flight, excluded

        $this->artisan('calls:report-stranded-voicemail-deferrals')
            ->expectsOutputToContain('2 voicemail notification(s) withheld')
            ->assertExitCode(0);
    }
}
