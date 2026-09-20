<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\PhoneCall;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card 6aaf510e — the voicemail email could be sent about a call carrying no
 * end evidence: a row with ended_at NULL, which may still be connected.
 *
 * WHY THE GUARD IS NOT AT A CALL SITE. There are TWO entrances to this email
 * and they partition production traffic by recording length:
 *
 *   - PlivoWebhookController:437 sends immediately, but ONLY when
 *     transcription will not run ($willTranscribe false).
 *   - TranscriptionService, from a `finally` block, sends when it will —
 *     on the transcription failure path as well as on success.
 *
 * With the transcription settings measured on production at the time of
 * writing, the deferred entrance carried the large majority of voicemails.
 * A guard on the controller alone would therefore have left most of the
 * traffic ungated while reading, in the diff, exactly like a fix. These tests
 * exercise BOTH entrances for that reason, and the guard itself lives in the
 * one method both of them call.
 *
 * WHAT IS PINNED HERE, stated so a later reader does not over-read it: that an
 * email is withheld without end evidence, that it is RELEASED when end evidence
 * arrives, and that the release cannot fire twice. These tests say nothing
 * about whether Plivo delivers end evidence promptly — that is the routing fix
 * on card 6aade104, and it has its own controls.
 */
class VoicemailNotifyEndEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'email' => 'tech@example.test',
        ]);
    }

    /**
     * A voicemail row as it exists before any terminal callback: recording
     * present, ended_at NULL. This is the shape the guard exists for.
     */
    private function voicemailCall(array $overrides = []): PhoneCall
    {
        $call = PhoneCall::create(array_merge([
            'call_uuid' => 'vm-'.uniqid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+12065550101',
            'to_number' => '+12065550199',
            'status' => CallStatus::Voicemail,
            'started_at' => now()->subMinutes(2),
            'recording_url' => 'https://example.test/r.mp3',
            'recording_duration' => 20,
            'recording_disk_path' => 'call-recordings/seeded.mp3',
        ], $overrides));

        // ended_at / answered_at / duration are not fillable — assign directly
        // or create() drops them silently and the test fails for the wrong
        // reason. Same trap documented in the sibling coalesced-webhook suite.
        $call->ended_at = $overrides['ended_at'] ?? null;
        $call->answered_at = $overrides['answered_at'] ?? null;
        $call->save();

        return $call->refresh();
    }

    /**
     * SendTicketNotification promotes $eventType as a PRIVATE constructor
     * property, so it cannot be read off the job directly. Reflection is used
     * deliberately rather than widening the job's API for a test, and it is
     * pinned by the positive control above: if this read ever silently stopped
     * matching, test_a_voicemail_with_end_evidence_emails_staff_immediately
     * would fail on a count of 0 rather than passing vacuously.
     */
    private function voicemailJobsQueued(): int
    {
        $count = 0;
        Queue::assertPushed(SendTicketNotification::class, function ($job) use (&$count) {
            $prop = new \ReflectionProperty($job, 'eventType');
            $prop->setAccessible(true);
            if ($prop->getValue($job) === NotificationEventType::NewVoicemail->value) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    /**
     * ENTRANCE 1 — the immediate path, reached when transcription will not run.
     * Without end evidence the email must be withheld and the row marked.
     */
    public function test_a_voicemail_with_no_end_evidence_does_not_email_staff(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        $this->assertNull($call->ended_at, 'precondition: the row carries no end evidence');

        app(NotificationService::class)->notifyNewVoicemail($call);

        Queue::assertNotPushed(SendTicketNotification::class);
        $this->assertNotNull(
            $call->refresh()->voicemail_notify_deferred_at,
            'the withheld email must leave a marker, or it can never be released'
        );
    }

    /**
     * The guard must not become a blanket refusal: with end evidence present
     * the email goes out exactly as before. Positive control — without this a
     * "never send anything" implementation would pass the test above.
     */
    public function test_a_voicemail_with_end_evidence_emails_staff_immediately(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall(['ended_at' => now()->subSeconds(5)]);

        app(NotificationService::class)->notifyNewVoicemail($call);

        $this->assertSame(1, $this->voicemailJobsQueued(), 'an ended call must still notify');
        $this->assertNull(
            $call->refresh()->voicemail_notify_deferred_at,
            'nothing was withheld, so no marker should be set'
        );
    }

    /**
     * ENTRANCE 2 — the deferred path. TranscriptionService calls the same
     * method from its finally block; the guard must hold there too. This is the
     * entrance that carried most production voicemails, and the one a call-site
     * fix would have missed.
     */
    public function test_the_transcription_entrance_is_guarded_too(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        // Call exactly as TranscriptionService's finally block does.
        app(NotificationService::class)->notifyNewVoicemail($call->fresh());

        Queue::assertNotPushed(SendTicketNotification::class);
        $this->assertNotNull($call->refresh()->voicemail_notify_deferred_at);
    }

    /**
     * DEFERRAL, NOT SUPPRESSION. The withheld email must actually arrive once
     * end evidence lands, and handleCallEnded() is the release point.
     */
    public function test_end_evidence_arriving_releases_the_withheld_email(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        app(NotificationService::class)->notifyNewVoicemail($call);
        Queue::assertNotPushed(SendTicketNotification::class);

        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '20']);

        $this->assertSame(1, $this->voicemailJobsQueued(), 'the withheld email must be sent on release');
        $fresh = $call->refresh();
        $this->assertNotNull($fresh->ended_at, 'end evidence was recorded');
        $this->assertNull($fresh->voicemail_notify_deferred_at, 'the marker must be cleared by the release');
        $this->assertSame(CallStatus::Voicemail, $fresh->status, 'voicemail status is preserved through the release');
    }

    /**
     * A repeated terminal callback is ordinary on this path. The release must
     * be a no-op the second time, or the fix trades an early email for a
     * duplicate one.
     */
    public function test_a_repeated_terminal_callback_does_not_send_a_second_email(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        app(NotificationService::class)->notifyNewVoicemail($call);
        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '20']);
        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '20']);

        $this->assertSame(1, $this->voicemailJobsQueued(), 'exactly one email across two terminal deliveries');
    }

    /**
     * A call that was never deferred must not acquire an email just because a
     * terminal callback arrived. Guards the release against firing on every
     * ended call in the system.
     */
    public function test_a_call_with_no_withheld_email_is_not_notified_on_release(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall(['ended_at' => now()->subSeconds(5)]);

        $this->assertNull($call->voicemail_notify_deferred_at, 'precondition: nothing withheld');

        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '20']);

        Queue::assertNotPushed(SendTicketNotification::class);
    }
}
