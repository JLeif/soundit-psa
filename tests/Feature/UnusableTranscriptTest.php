<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NotificationEventType;
use App\Enums\TranscriptionStatus;
use App\Jobs\CallIntakeJob;
use App\Jobs\SendTicketNotification;
use App\Jobs\TranscribePhoneCall;
use App\Models\PhoneCall;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TranscriptionService;
use App\Support\TranscriptUsability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class UnusableTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(?string $text, ?int $duration): PhoneCall
    {
        return PhoneCall::forceCreate([
            'call_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'direction' => CallDirection::Inbound,
            'status' => CallStatus::Voicemail,
            'from_number' => '+15555550101',
            'to_number' => '+15555550102',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'recording_duration' => $duration,
            'recording_url' => 'https://example.invalid/audio',
            'transcription' => $text,
            'transcription_status' => TranscriptionStatus::Processing,
        ]);
    }

    private function finalize(PhoneCall $call): void
    {
        Queue::fake();
        (new \ReflectionMethod(TranscriptionService::class, 'finalizeSuccessfulTranscription'))
            ->invoke(app(TranscriptionService::class), $call);
    }

    public function test_you_on_fourteen_seconds_is_not_completed_and_dispatches_no_intake(): void
    {
        $call = $this->makeCall('You', 14);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $this->assertSame('You', $call->fresh()->transcription);
        $this->assertFalse($call->fresh()->isTranscribed());
        $this->assertTrue($call->fresh()->hasTerminalTranscription());
        $this->assertDatabaseHas('signal_events', ['type_key' => 'intake.call_transcribed', 'entity_id' => $call->id]);
        Queue::assertNotPushed(CallIntakeJob::class);
    }

    public function test_null_is_never_transcribed_and_keeps_completed_intake_path(): void
    {
        \App\Models\Setting::setValue('intake_call_enabled', '1');
        $call = $this->makeCall(null, 17);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Completed, $call->fresh()->transcription_status);
        $this->assertNull($call->fresh()->transcription);
        Queue::assertPushed(CallIntakeJob::class);
        $event = \App\Models\SignalEvent::where('entity_id', $call->id)
            ->where('type_key', 'intake.call_transcribed')->sole();
        $this->assertSame([], $event->context);
    }

    public function test_produced_empty_and_whitespace_transcripts_require_a_listen(): void
    {
        foreach (['', '   ', "\n\n"] as $text) {
            foreach ([null, 0, 5, 17] as $duration) {
                $call = $this->makeCall($text, $duration);
                $this->finalize($call);
                $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
                $this->assertSame($text, $call->fresh()->transcription);
                $this->assertTrue((new TranscriptUsability)->isUnusable($text, $duration));
            }
        }
    }

    public function test_short_real_message_is_deliberately_flagged_for_a_listen(): void
    {
        // Accepted false positive: real speech + silence tail, not proof of silence.
        $text = "Call me back, it's Dave";
        $this->assertSame(23, mb_strlen($text));
        $call = $this->makeCall($text, 17);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $this->assertSame($text, $call->fresh()->transcription);
        $this->assertTrue((new TranscriptUsability)->isUnusable($text, 17));
    }

    public function test_normal_density_message_is_unchanged(): void
    {
        $text = 'Please call me back about the printer today.';
        $call = $this->makeCall($text, 10);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Completed, $call->fresh()->transcription_status);
        $this->assertSame($text, $call->fresh()->transcription);
        $this->assertNull($call->fresh()->transcription_error);
    }

    public function test_density_alone_catches_long_repeated_cjk_not_in_stock_list(): void
    {
        // Synthetic structure, not a customer transcript: 420 characters,
        // 1260 bytes in 300s. Byte strlen would miss this at 4.2 bytes/s.
        $text = str_repeat('山川草木天地星', 60);
        $call = $this->makeCall($text, 300);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
    }

    public function test_stock_alone_catches_short_and_unknown_duration_without_density(): void
    {
        foreach ([null, 0, 1, 5] as $duration) {
            $call = $this->makeCall('Thank you.', $duration);
            $this->finalize($call);
            $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        }
    }

    public function test_signal_boundaries_and_whole_transcript_matching(): void
    {
        $guard = new TranscriptUsability;
        $this->assertTrue($guard->isUnusable('  BYE. Bye. ', 1));
        $this->assertTrue($guard->isUnusable('Bye-bye.', 1));
        $this->assertTrue($guard->isUnusable('请不吝点赞 订阅 转发 打赏支持明镜与点点栏目', null));
        $this->assertFalse($guard->isUnusable('Thank you for fixing the printer today.', 5));
        foreach ([null, 0, -1, 1, 5] as $duration) {
            $this->assertFalse($guard->isUnusable('Help!', $duration));
        }
        $this->assertTrue($guard->isUnusable('Help!', 6));
        $this->assertFalse($guard->isUnusable(str_repeat('x', 20), 10));
        $this->assertTrue($guard->isUnusable(str_repeat('x', 19), 10));
    }

    public function test_guard_failure_is_visible_unassessed_not_failed_or_plain_success(): void
    {
        $guard = Mockery::mock(TranscriptUsability::class);
        $guard->shouldReceive('isUnusable')->once()->andThrow(new \RuntimeException('broken guard'));
        $this->app->instance(TranscriptUsability::class, $guard);
        $call = $this->makeCall('Please call me back about the printer today.', 10);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $this->assertSame(TranscriptUsability::WARNING, $call->fresh()->transcription_error);
        $this->assertDatabaseHas('signal_events', ['type_key' => 'intake.call_transcribed', 'entity_id' => $call->id]);
    }

    public function test_unusable_call_renders_listen_marker_and_not_ai_summary(): void
    {
        $call = $this->makeCall('You', 14);
        $call->call_summary = 'UNTRUSTED SUMMARY';
        $call->save();
        $this->finalize($call);
        $this->actingAs(User::factory()->create())
            ->get(route('calls.show', $call))
            ->assertOk()
            ->assertSee(TranscriptUsability::WARNING)
            ->assertSee(route('calls.recording', $call), false)
            ->assertDontSee('UNTRUSTED SUMMARY');
    }

    public function test_notification_context_and_rendered_body_carry_warning(): void
    {
        Queue::fake();
        User::factory()->create(['is_active' => true, 'notification_preferences' => [NotificationEventType::NewVoicemail->value => true]]);
        $call = $this->makeCall('You', 14);
        $this->finalize($call);
        app(NotificationService::class)->notifyNewVoicemail($call->fresh());
        Queue::assertPushed(SendTicketNotification::class, function ($job) {
            $context = json_decode((new \ReflectionProperty($job, 'extraContext'))->getValue($job), true);
            $this->assertTrue($context['transcript_unusable']);
            $body = (new \ReflectionMethod($job, 'buildBody'))->invoke($job, NotificationEventType::NewVoicemail, null, null);
            $this->assertStringContainsString(TranscriptUsability::WARNING, $body);
            $this->assertStringContainsString('Unverified raw transcript', $body);

            return true;
        });
    }

    public function test_duplicate_job_does_not_retry_unusable_but_pending_allows_manual_retry(): void
    {
        $call = $this->makeCall('You', 14);
        $this->finalize($call);
        $service = Mockery::mock(TranscriptionService::class);
        $service->shouldNotReceive('transcribe');
        (new TranscribePhoneCall($call->id))->handle($service);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $call->update(['transcription_status' => TranscriptionStatus::Pending]);
        $this->assertFalse($call->hasTerminalTranscription());
        $retry = Mockery::mock(TranscriptionService::class);
        $retry->shouldReceive('transcribe')->once()->withArgs(fn ($row) => $row->id === $call->id);
        (new TranscribePhoneCall($call->id))->handle($retry);
    }

    public function test_normal_voicemail_email_has_no_unusable_marker(): void
    {
        $call = $this->makeCall('Please call me back about the printer today.', 10);
        $this->finalize($call);
        $job = new SendTicketNotification(1, NotificationEventType::NewVoicemail->value, null, null,
            json_encode(['call_id' => $call->id, 'transcript_unusable' => false]));
        $body = (new \ReflectionMethod($job, 'buildBody'))->invoke($job, NotificationEventType::NewVoicemail, null, null);
        $this->assertStringNotContainsString(TranscriptUsability::WARNING, $body);
        $this->assertStringContainsString('Please call me back about the printer today.', $body);
    }

    public function test_diarized_speaker_labels_do_not_defeat_stock_match_or_inflate_density(): void
    {
        // buildDiarizedTranscript() writes "{label}: words"; no person/client/answerer
        // on these fixtures, so resolveSpeakerLabels() yields Customer / Agent.
        $call = $this->makeCall('Customer: You', 3);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $this->assertSame('Customer: You', $call->fresh()->transcription);
        Queue::assertNotPushed(CallIntakeJob::class);

        // 52 labelled characters over 20s is 2.6/s: only the stripped stock match catches it.
        $text = 'Customer: Thank you for watching, see you next time.';
        $call = $this->makeCall($text, 20);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
        $this->assertSame($text, $call->fresh()->transcription);

        $normal = "Customer: Hi, the printer on the second floor is jammed again.\n\nAgent: Thanks, I will take a look now.";
        $call = $this->makeCall($normal, 20);
        $this->finalize($call);
        $this->assertSame(TranscriptionStatus::Completed, $call->fresh()->transcription_status);

        $guard = new TranscriptUsability;
        $labels = ['Acme Industries Corporation', 'Agent'];
        $this->assertTrue($guard->isUnusable('Acme Industries Corporation: Thank you for watching see you next time', 30, $labels));
        $this->assertFalse($guard->isUnusable('Acme Industries Corporation: Thank you for watching see you next time', 30));
        // Label inflates density: 40 chars/10s unstripped, 11 chars/10s stripped.
        $this->assertTrue($guard->isUnusable('Acme Industries Corporation: Hello there', 10, $labels));
        $this->assertFalse($guard->isUnusable('Acme Industries Corporation: Hello there', 10));
        // Only line-start label prefixes are removed.
        $this->assertFalse($guard->isUnusable('Please tell the Agent: you', 3, $labels));
    }

    public function test_service_short_circuits_unusable_before_api_configuration(): void
    {
        $call = $this->makeCall('You', 14);
        $this->finalize($call);
        app(TranscriptionService::class)->transcribe($call->fresh());
        $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
    }
}
