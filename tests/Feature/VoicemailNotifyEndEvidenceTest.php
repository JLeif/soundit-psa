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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'recording_disk_path' => 'call-recordings/seeded.mp3',
        ], $overrides));

        // recording_url / recording_duration / ended_at / answered_at / duration
        // are not fillable — assign directly or create() drops them silently and
        // the test fails for the wrong reason. Same trap documented in the
        // sibling coalesced-webhook suite, and it bit the two recording columns
        // here: they are the stored end evidence FinaliseStuckCalls selects its
        // population on, so a fixture that dropped them left the sweep control
        // asserting against a row the sweep could never have seen.
        $call->recording_url = $overrides['recording_url'] ?? 'https://example.test/r.mp3';
        $call->recording_duration = $overrides['recording_duration'] ?? 20;
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

    /**
     * THE DISPATCH MUST NOT HAPPEN INSIDE THE TRANSACTION. handleCallEnded()
     * does its work in updateCallSafely()'s DB::transaction, and a queued job
     * is not rolled back with one: a dispatch made before the commit emails
     * staff about end evidence the database can still discard, and a throw from
     * the queue at that point would roll back ended_at, the final status and
     * the prepay debit. Pinned by recording the transaction nesting level at
     * the moment the release is called and comparing it with the level outside
     * handleCallEnded(): equal means the commit already happened.
     *
     * THE BASELINE IS NOT ZERO, and asserting zero pins nothing. RefreshDatabase
     * opens a transaction on the connection before the test body runs and rolls
     * it back afterwards, so the level is 1 throughout this method;
     * updateCallSafely()'s DB::transaction() nests a savepoint to 2 and returns
     * to 1 when that savepoint is released. No placement of the release could
     * satisfy an assertion of 0, which is why it is compared against the level
     * observed here instead.
     */
    public function test_the_release_runs_after_the_transaction_has_committed(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        app(NotificationService::class)->notifyNewVoicemail($call);

        $observed = new \stdClass;
        $observed->transactionLevel = null;

        $this->app->bind(NotificationService::class, fn () => new class($observed) extends NotificationService
        {
            public function __construct(private \stdClass $observed) {}

            public function releaseDeferredVoicemailNotification(PhoneCall $call): void
            {
                $this->observed->transactionLevel = DB::transactionLevel();

                parent::releaseDeferredVoicemailNotification($call);
            }
        });

        $baseline = DB::transactionLevel();

        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '20']);

        $this->assertSame(
            $baseline,
            $observed->transactionLevel,
            'the release must be called with no transaction open beyond the one the test harness holds'
        );
        $this->assertSame(1, $this->voicemailJobsQueued(), 'and it must still send the withheld email');
    }

    /**
     * handleCallEnded() is NOT the only writer of ended_at. The recording
     * callback finalises a call whose hangup webhook never arrived, and on an
     * ordinary voicemail that is the writer a deferral is most likely waiting
     * on. Without a release here the email is stranded: nothing queries the
     * marker, and the row leaves the sweep's whereNull('ended_at') population
     * the moment this path writes.
     */
    public function test_end_evidence_from_the_recording_path_releases_the_withheld_email(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        app(NotificationService::class)->notifyNewVoicemail($call);
        Queue::assertNotPushed(SendTicketNotification::class);

        app(PhoneCallService::class)->handleRecordingReady($call->call_uuid, 'https://media.example.test/r.mp3', 20);

        $fresh = $call->refresh();
        $this->assertNotNull($fresh->ended_at, 'the recording path finalised the row');
        $this->assertSame(1, $this->voicemailJobsQueued(), 'that writer must release the withheld email too');
        $this->assertNull($fresh->voicemail_notify_deferred_at, 'and claim the marker while doing it');
    }

    /**
     * ONE EMAIL PER VOICEMAIL ACROSS TWO SENDERS THAT BOTH RUN IN ONE REQUEST.
     * PlivoWebhookController's recording branch calls handleRecordingReady()
     * first - which finalises the row and, once committed, releases the withheld
     * email - and then, when transcription will not run, calls
     * notifyNewVoicemail() on a refreshed row. That row now reads ended_at set,
     * marker cleared, which the marker alone cannot tell apart from a call
     * nothing was ever withheld for: without a record of the send, this sequence
     * queues the same email twice.
     *
     * The same (ended, unmarked) row arrives across processes rather than across
     * two lines: the transcription entrance writes the marker, a terminal webhook
     * claims and sends it, and the transcription process's own re-read then sees
     * it. One claim on the send covers both, so one control pins both.
     */
    public function test_a_release_followed_by_the_controller_entrance_sends_one_email(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        app(NotificationService::class)->notifyNewVoicemail($call);

        // The controller's own order: the recording work, which releases, and
        // then the immediate notification on a refreshed row.
        app(PhoneCallService::class)->handleRecordingReady($call->call_uuid, 'https://media.example.test/r.mp3', 20);
        app(NotificationService::class)->notifyNewVoicemail($call->refresh());

        $this->assertSame(1, $this->voicemailJobsQueued(), 'one voicemail, one email, across both senders');
    }

    /**
     * The third writer: the FinaliseStuckCalls sweep, which keeps Voicemail
     * rows in its population by design.
     */
    public function test_end_evidence_from_the_sweep_releases_the_withheld_email(): void
    {
        Queue::fake();
        $this->staffUser();
        // Past the sweep's --min-age-hours floor; recording evidence is already
        // on the fixture, which is what puts the row in the population.
        $call = $this->voicemailCall(['started_at' => now()->subDays(30)]);

        app(NotificationService::class)->notifyNewVoicemail($call);
        Queue::assertNotPushed(SendTicketNotification::class);

        $this->artisan('calls:finalise-stuck --apply')->assertSuccessful();

        $fresh = $call->refresh();
        $this->assertNotNull($fresh->ended_at, 'the sweep finalised the row');
        $this->assertSame(1, $this->voicemailJobsQueued(), 'the sweep must release the withheld email too');
        $this->assertNull($fresh->voicemail_notify_deferred_at);
    }

    /**
     * THE LOST WAKEUP. Neither entrance holds the row lock handleCallEnded()
     * takes, and the transcription entrance runs in a detached process, so end
     * evidence can commit between the read of ended_at and the write of the
     * marker. A marker written after that commit would be released by nobody -
     * every writer had already run - and the email would be lost silently.
     *
     * The committed row is written directly here because that is exactly what
     * the other process's COMMIT looks like from this one: the row has ended,
     * the in-memory model does not know it.
     */
    public function test_end_evidence_landing_during_the_deferral_window_still_emails(): void
    {
        Queue::fake();
        $this->staffUser();
        $call = $this->voicemailCall();

        PhoneCall::whereKey($call->id)->update(['ended_at' => now()]);

        app(NotificationService::class)->notifyNewVoicemail($call);

        $this->assertSame(1, $this->voicemailJobsQueued(), 'the email must not be lost to the race');
        $this->assertNull(
            $call->refresh()->voicemail_notify_deferred_at,
            'and no marker may be left outstanding on a row that has already ended'
        );
    }

    /**
     * Replaces the real bus with a dispatcher that records each command and
     * throws on the Nth, so a throw can be placed PARTWAY through
     * dispatchVoicemailNotification()'s per-recipient loop. Queue::fake()
     * cannot do this: it records pushes but never fails one.
     */
    private function throwOnNthDispatch(int $n, array &$accepted): void
    {
        $seen = 0;

        $this->app->bind(\Illuminate\Contracts\Bus\Dispatcher::class, function () use ($n, &$seen, &$accepted) {
            return new class($n, $seen, $accepted) implements \Illuminate\Contracts\Bus\Dispatcher
            {
                public function __construct(private int $n, private int &$seen, private array &$accepted) {}

                public function dispatch($command)
                {
                    return $this->dispatchToQueue($command);
                }

                public function dispatchSync($command, $handler = null)
                {
                    return $this->dispatchToQueue($command);
                }

                public function dispatchNow($command, $handler = null)
                {
                    return $this->dispatchToQueue($command);
                }

                public function dispatchToQueue($command)
                {
                    $this->seen++;

                    if ($this->seen === $this->n) {
                        throw new \RuntimeException('queue connection lost');
                    }

                    $this->accepted[] = $command;

                    return null;
                }

                public function hasCommandHandler($command)
                {
                    return false;
                }

                public function getCommandHandler($command)
                {
                    return false;
                }

                public function pipeThrough(array $pipes)
                {
                    return $this;
                }

                public function map(array $map)
                {
                    return $this;
                }

                public function findBatch(string $batchId) {}

                public function batch($jobs) {}

                public function chain($jobs) {}
            };
        });
    }

    /**
     * K5qwIx3B #17. dispatchVoicemailNotification() queues one job per opted-in
     * recipient, so a throw partway through the loop leaves the earlier jobs
     * QUEUED. The old record said 'could not be queued', which is false on
     * exactly that arm - measured here, not argued: two jobs are accepted by
     * the bus before the third throws.
     *
     * What is pinned is the STATE (how many were queued before the failure),
     * not a cause. 'will not be retried' is kept because it is true on every
     * arm: the claim at sendVoicemailNotificationOnce() is already stamped
     * before the dispatch runs, and the stranded-deferral gauge excludes a
     * claimed row, so no writer comes back for it.
     *
     * Every level the logger exposes is captured, per G-14 step 2: a record
     * moved to another level must not pass unseen.
     */
    public function test_a_partial_dispatch_failure_records_how_many_were_queued(): void
    {
        $records = [];
        $capture = function ($message, $context = []) use (&$records) {
            $records[] = ['message' => $message, 'context' => $context];
        };

        foreach (['error', 'warning', 'info', 'debug', 'notice', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldReceive($level)->andReturnUsing($capture);
        }

        for ($i = 0; $i < 4; $i++) {
            User::factory()->create(['is_active' => true, 'email' => "tech{$i}@example.test"]);
        }

        $accepted = [];
        $this->throwOnNthDispatch(3, $accepted);

        $call = $this->voicemailCall(['ended_at' => now()]);

        app(NotificationService::class)->notifyNewVoicemail($call);

        // The arm exists: jobs really were queued before the throw.
        $this->assertCount(2, $accepted, 'two recipients were queued before the dispatch threw');

        $failures = array_values(array_filter(
            $records,
            fn ($r) => str_contains($r['message'], '[Voicemail]') && str_contains($r['message'], 'will not be retried')
        ));

        $this->assertCount(1, $failures, 'exactly one failure record, at any level');

        $this->assertStringNotContainsStringIgnoringCase(
            'could not be queued',
            $failures[0]['message'],
            'the record must not claim nothing was queued when two jobs were'
        );

        $this->assertArrayHasKey(
            'queued_before_failure',
            $failures[0]['context'],
            'the count must travel in the structured record'
        );
        $this->assertSame(
            2,
            $failures[0]['context']['queued_before_failure'],
            'and it must be the number actually handed to the queue'
        );
    }

    /**
     * The counterpart: when the FIRST dispatch throws, nothing was queued and
     * the count must say so. Without this, a mutant hard-coding a non-zero
     * count would pass the partial-arm control above.
     */
    public function test_a_dispatch_failing_on_the_first_recipient_records_zero_queued(): void
    {
        $records = [];
        $capture = function ($message, $context = []) use (&$records) {
            $records[] = ['message' => $message, 'context' => $context];
        };

        foreach (['error', 'warning', 'info', 'debug', 'notice', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldReceive($level)->andReturnUsing($capture);
        }

        $this->staffUser();

        $accepted = [];
        $this->throwOnNthDispatch(1, $accepted);

        $call = $this->voicemailCall(['ended_at' => now()]);

        app(NotificationService::class)->notifyNewVoicemail($call);

        $this->assertCount(0, $accepted, 'nothing reached the queue on this arm');

        $failures = array_values(array_filter(
            $records,
            fn ($r) => str_contains($r['message'], '[Voicemail]') && str_contains($r['message'], 'will not be retried')
        ));

        $this->assertCount(1, $failures, 'exactly one failure record, at any level');
        $this->assertSame(
            0,
            $failures[0]['context']['queued_before_failure'] ?? null,
            'a failure on the first recipient must record zero, not a constant'
        );
    }
}
