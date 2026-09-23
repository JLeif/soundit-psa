<?php

namespace Tests\Feature\Signals;

use App\Enums\CallStatus;
use App\Enums\TranscriptionStatus;
use App\Jobs\CallIntakeJob;
use App\Jobs\SendTicketNotification;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\SignalEvent;
use App\Models\User;
use App\Services\Signals\SignalHub;
use App\Services\TranscriptionService;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnusableTranscriptSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_exits_emit_flagged_and_normal_signals_with_intake_on_and_off(): void
    {
        foreach ([false, true] as $fullPath) {
            foreach (['0', '1'] as $enabled) {
                foreach ([true, false] as $flagged) {
                    Queue::fake();
                    Setting::setValue('intake_call_enabled', $enabled);
                    $text = $flagged ? 'You' : 'Please call me back about the printer today.';
                    $call = $this->makeTranscriptCall($text);
                    $this->runPath($call, $fullPath, $text);
                    $events = SignalEvent::where('entity_id', $call->id)->where('type_key', 'intake.call_transcribed')->get();
                    $this->assertCount(1, $events);
                    $this->assertSame($flagged ? ['transcript_unusable' => true] : [], $events[0]->context);
                    $this->assertSame($flagged ? 'call transcribed — transcript unusable, listen to the recording' : 'call transcribed', $events[0]->summary);
                    $this->assertSame($flagged ? TranscriptionStatus::Unusable : TranscriptionStatus::Completed, $call->fresh()->transcription_status);
                    $this->assertSame($text, $call->fresh()->transcription);
                    if ($flagged || $enabled === '0') {
                        Queue::assertNotPushed(CallIntakeJob::class);
                    } else {
                        Queue::assertPushed(CallIntakeJob::class);
                    }
                }
            }
        }
    }

    public function test_produced_empty_text_is_flagged_at_both_exits_with_intake_on_and_off(): void
    {
        foreach (['', '   ', "\n\n", '...', "\u{3000}"] as $text) {
            foreach ([false, true] as $fullPath) {
                foreach (['0', '1'] as $enabled) {
                    Queue::fake();
                    Setting::setValue('intake_call_enabled', $enabled);
                    $call = $this->makeTranscriptCall($text);
                    $this->runPath($call, $fullPath, $text);
                    $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
                    $this->assertSame($text, $call->fresh()->transcription);
                    Queue::assertNotPushed(CallIntakeJob::class);
                    $event = SignalEvent::where('entity_id', $call->id)
                        ->where('type_key', 'intake.call_transcribed')->sole();
                    $this->assertSame(['transcript_unusable' => true], $event->context);
                }
            }
        }
    }

    public function test_throwing_signal_is_fail_soft_on_both_flagged_exits_and_finally_still_emails(): void
    {
        User::factory()->create(['is_active' => true, 'notification_preferences' => ['new_voicemail' => true]]);
        $hub = \Mockery::mock(SignalHub::class);
        $hub->shouldReceive('emit')->twice()->andThrow(new \RuntimeException('synthetic signal failure'));
        $this->app->instance(SignalHub::class, $hub);
        foreach ([false, true] as $fullPath) {
            Queue::fake();
            $call = $this->makeTranscriptCall('You');
            $this->runPath($call, $fullPath, 'You');
            $this->assertSame(TranscriptionStatus::Unusable, $call->fresh()->transcription_status);
            $this->assertSame('You', $call->fresh()->transcription);
            Queue::assertNotPushed(CallIntakeJob::class);
            if ($fullPath) {
                Queue::assertPushed(SendTicketNotification::class);
            }
        }
    }

    private function makeTranscriptCall(string $text): PhoneCall
    {
        return PhoneCall::forceCreate([
            'call_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'direction' => 'inbound', 'status' => CallStatus::Voicemail, 'ended_at' => now(),
            'from_number' => '+15555550101', 'to_number' => '+15555550102',
            'recording_url' => 'https://example.invalid/audio',
            'transcription' => $text, 'recording_duration' => 14,
            'transcription_status' => TranscriptionStatus::Processing,
        ]);
    }

    private function runPath(PhoneCall $call, bool $fullPath, string $text): void
    {
        if (! $fullPath) {
            (new \ReflectionMethod(TranscriptionService::class, 'finalizeSuccessfulTranscription'))->invoke(app(TranscriptionService::class), $call);

            return;
        }
        Setting::setEncrypted('openai_api_key', 'synthetic-no-network');
        Setting::setValue('ai_enabled', '0');
        $service = new class($text) extends TranscriptionService
        {
            public function __construct(private string $fixture) {}

            protected function downloadRecording(string $url, ?Client $client = null): string
            {
                $file = tempnam(sys_get_temp_dir(), 'transcript-fixture-');
                file_put_contents($file, 'synthetic audio boundary');

                return $file;
            }

            protected function isFfmpegAvailable(): bool
            {
                return false;
            }

            protected function whisperTranscribeAll(string $filePath, string $apiKey, array &$tempFiles, ?string $namePrompt = null): string
            {
                return $this->fixture;
            }
        };
        $service->transcribe($call);
    }
}
