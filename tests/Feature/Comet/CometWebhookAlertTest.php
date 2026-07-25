<?php

namespace Tests\Feature\Comet;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Comet\CometAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Comet webhook → alert lifecycle (psa-enpew).
 *
 * Vendor-shaped payloads here are serialized by the VENDOR's own SDK classes
 * (\Comet\StreamableEvent + \Comet\BackupJobDetail via toArray()), so the
 * fixtures carry exactly the wire shape the vendored SDK defines — integer
 * Type 4201 (Def.php:2115 SEVT_JOB_COMPLETED), Data = the job object with
 * Classification on the 4000-range and Status on the 5000/6000/7000 ranges
 * (Def.php:610-841) — and cannot drift into the shape our code merely wishes
 * for (CLAUDE.md fixture rule).
 *
 * HONEST LIMIT: an SDK-serialized fixture proves what the SDK defines, not
 * what a live Comet webhook sends — no captured live payload exists, and prod
 * evidence (zero Comet alerts ever, thousands of webhook POSTs a day) proves
 * only that the OLD pipeline matched nothing, not which of its guards
 * (string Type, classification scale 4-vs-4001, exact status 7002) rejected
 * the traffic. These tests therefore pin BOTH classification scales, the full
 * status ranges, and the loud-drop behaviour, so the pipeline works whichever
 * shape arrives; the settings delivery stamps close the loop against real
 * traffic post-deploy.
 */
class CometWebhookAlertTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookKey = 'test-comet-webhook-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'admin');
        Setting::setEncrypted('comet_admin_password', 'pw');
        Setting::setEncrypted('comet_webhook_key', $this->webhookKey);
    }

    /** Vendor-serialized SEVT_JOB_COMPLETED event wrapping a vendor-serialized job. */
    private function jobCompletedEvent(array $attrs = []): array
    {
        $job = new \Comet\BackupJobDetail;
        $job->GUID = $attrs['guid'] ?? 'f0000000-0000-4000-8000-000000000001';
        $job->Username = $attrs['username'] ?? 'acme-backup';
        $job->DeviceID = $attrs['device'] ?? 'dev-1';
        $job->SourceGUID = $attrs['source_guid'] ?? 'item-1';
        $job->DestinationGUID = $attrs['destination_guid'] ?? 'vault-1';
        $job->Classification = $attrs['classification'] ?? \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $attrs['status'] ?? \Comet\Def::JOB_STATUS_FAILED_ERROR;
        $job->StartTime = $attrs['start'] ?? now()->subHours(2)->timestamp;
        $job->EndTime = $attrs['end'] ?? now()->subHour()->timestamp;
        $job->TotalSize = $attrs['total_size'] ?? 5 * (1024 ** 3);

        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_COMPLETED;
        $event->Timestamp = now()->timestamp;
        $event->Data = $job->toArray(false);

        return $event->toArray(false);
    }

    private function postEvent(array $payload)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->webhookKey}"])
            ->postJson('/api/webhooks/comet', $payload);
    }

    private function linkedAsset(array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'hostname' => 'ACME-SRV-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'dev-1',
        ], $overrides));
    }

    // ── The headline regression: real vendor events must land ─────────────────

    public function test_vendor_shaped_backup_failure_event_creates_an_alert(): void
    {
        $asset = $this->linkedAsset();

        $response = $this->postEvent($this->jobCompletedEvent());

        $response->assertOk()->assertJsonPath('status', 'processed');
        $this->assertNotNull($response->json('alert_id'));

        $alert = Alert::sole();
        $this->assertSame(AlertSource::Comet, $alert->source);
        $this->assertSame('dev-1:item-1:vault-1:backup', $alert->source_alert_id);
        $this->assertSame($asset->id, $alert->asset_id);
        $this->assertSame($asset->client_id, $alert->client_id);
        $this->assertSame('ACME-SRV-01', $alert->hostname);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame('Backup Failed (error) on ACME-SRV-01', $alert->title);
        $this->assertStringContainsString('Status: Failed (error)', $alert->message);
        $this->assertStringContainsString('Protected item: item-1', $alert->message);
        $this->assertStringContainsString('Storage vault: vault-1', $alert->message);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_ERROR, $alert->metadata['status']);
        $this->assertSame('item-1', $alert->metadata['source_guid']);
    }

    public function test_every_vendor_failure_subtype_raises_an_alert(): void
    {
        // The old code alerted on 7002 alone (and even that never fired in
        // prod). The vendor defines failure as the whole 7000-7999 range —
        // timeout, quota, and missed-schedule failures are exactly the ones
        // an MSP must see.
        $subtypes = [
            \Comet\Def::JOB_STATUS_FAILED_TIMEOUT,
            \Comet\Def::JOB_STATUS_FAILED_WARNING,
            \Comet\Def::JOB_STATUS_FAILED_ERROR,
            \Comet\Def::JOB_STATUS_FAILED_QUOTA,
            \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED,
            \Comet\Def::JOB_STATUS_FAILED_CANCELLED,
            \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING,
            \Comet\Def::JOB_STATUS_FAILED_ABANDONED,
        ];

        foreach ($subtypes as $i => $status) {
            $this->postEvent($this->jobCompletedEvent(['status' => $status, 'device' => "dev-{$i}"]))
                ->assertOk()->assertJsonPath('status', 'processed');
        }

        $this->assertSame(count($subtypes), Alert::count(), 'every failed-range subtype must raise an alert');
    }

    // ── Dual-scale classification: the pinned psa-enpew requirement ──────────
    // Production evidence proves the old pipeline matched nothing, NOT which
    // guard was responsible, so backup classification is accepted on BOTH the
    // SDK scale (4001) and the legacy small-int scale (4) — not a swap.

    public function test_legacy_scale_classification_4_with_integer_type_creates_an_alert(): void
    {
        $this->linkedAsset();

        $response = $this->postEvent($this->jobCompletedEvent(['classification' => 4]));

        $response->assertOk()->assertJsonPath('status', 'processed');
        $alert = Alert::sole();
        $this->assertSame(AlertStatus::Active, $alert->status);
        // Canonical key carries the scale-free 'backup' token, not the raw code
        $this->assertSame('dev-1:item-1:vault-1:backup', $alert->source_alert_id);
        $this->assertStringContainsString('Job type: Backup', $alert->message);
    }

    public function test_success_under_one_scale_resolves_a_failure_raised_under_the_other(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        // Raised under the SDK scale (4001)…
        $this->postEvent($this->jobCompletedEvent(['classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'end' => $failEnd]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        // …resolved by a success carrying the legacy scale (4).
        $this->postEvent($this->jobCompletedEvent([
            'classification' => 4,
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'end' => $failEnd + 600,
        ]))->assertOk();

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);
    }

    public function test_backup_success_resolves_the_open_alert_for_that_device(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $failEnd]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd + 600]))
            ->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);
    }

    // ── Series identity, recurrence and event ordering ───────────────────────

    public function test_failure_success_later_failure_reopens_the_series_without_a_duplicate_key_error(): void
    {
        // The alerts table is unique on (source, source_alert_id): a second
        // incident on the same series must reopen the resolved row, not 500.
        $this->linkedAsset();
        $t0 = now()->subHours(3)->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 600]));
        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0 + 1200]))
            ->assertOk()->assertJsonPath('status', 'processed');

        $alert = Alert::sole()->fresh();
        $this->assertSame(AlertStatus::Active, $alert->status, 'a new failure after resolution reopens the series');
        $this->assertNull($alert->resolved_at);
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title);
        $this->assertSame(1, $alert->refired_count);
    }

    public function test_refire_with_a_different_outcome_refreshes_the_alert_title(): void
    {
        // warning (7001) then quota (7003): one deduped alert whose title must
        // say quota — a stale title misstates the real failure state.
        $this->linkedAsset();
        $t0 = now()->subHours(2)->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'end' => $t0]));
        $this->assertSame('Backup Completed with warnings on ACME-SRV-01', Alert::sole()->title);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0 + 600]));

        $alert = Alert::sole()->fresh();
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title);
        $this->assertStringContainsString('Failed (quota exceeded)', $alert->message);
        $this->assertSame(1, $alert->refired_count);
        $this->assertSame(AlertStatus::Active, $alert->status);
    }

    public function test_a_stale_success_cannot_resolve_a_newer_failure(): void
    {
        // Out-of-order/replayed delivery: a success that ENDED before the
        // recorded failure must not present a broken backup as recovered.
        Log::spy();
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd - 3600]))
            ->assertOk();

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status, 'stale success must be rejected');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Stale success rejected'))
            ->atLeast()->once();
    }

    public function test_a_stale_replayed_failure_does_not_clobber_newer_state(): void
    {
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'end' => $t0 - 7200]))
            ->assertOk();

        $alert = Alert::sole()->fresh();
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title, 'older replay must not rewrite the newer outcome');
        $this->assertSame(0, $alert->refired_count);
        $this->assertSame($t0, $alert->fired_at->timestamp);
    }

    public function test_success_for_one_protected_item_does_not_resolve_another_items_failure(): void
    {
        // A device can back up multiple protected items to multiple vaults;
        // item B succeeding says nothing about item A.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-A', 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent([
            'source_guid' => 'item-B',
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'end' => $t0 + 600,
        ]))->assertOk();

        $alert = Alert::sole();
        $this->assertSame('dev-1:item-A:vault-1:backup', $alert->source_alert_id);
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status, "item B's success must not clear item A's alert");
    }

    public function test_two_protected_items_failing_raise_two_separate_alerts(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-A']));
        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-B']));

        $this->assertSame(2, Alert::count());
        $this->assertEqualsCanonicalizing(
            ['dev-1:item-A:vault-1:backup', 'dev-1:item-B:vault-1:backup'],
            Alert::pluck('source_alert_id')->all(),
        );
    }

    public function test_unmatched_device_still_creates_an_unlinked_alert(): void
    {
        $this->postEvent($this->jobCompletedEvent(['device' => 'never-synced', 'username' => 'orphan-user']))
            ->assertOk();

        $alert = Alert::sole();
        $this->assertNull($alert->asset_id);
        $this->assertNull($alert->client_id);
        $this->assertSame('orphan-user', $alert->hostname);
    }

    public function test_failure_without_device_identity_is_dropped_loudly_not_keyed_blind(): void
    {
        // Without a DeviceID there is no series to key — fail loud, no write.
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['device' => '']))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_device_identity')
            ->atLeast()->once();
    }

    // ── The loud unmatched-event signal (silent non-matching was the defect) ──

    public function test_job_completed_with_non_integer_status_warns_and_is_dropped(): void
    {
        Log::spy();

        $this->postEvent([
            'Type' => \Comet\Def::SEVT_JOB_COMPLETED,
            'Data' => ['DeviceID' => 'dev-1', 'Classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'Status' => 'failed'],
        ])->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_or_non_integer_status')
            ->atLeast()->once();
    }

    public function test_job_completed_with_status_outside_every_known_range_warns(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['status' => 4242]))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'status_outside_known_ranges')
            ->atLeast()->once();
    }

    public function test_job_completed_with_unrecognized_classification_warns(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['classification' => 9999]))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'unrecognized_classification')
            ->atLeast()->once();
    }

    public function test_the_warning_context_carries_only_safe_structural_fields(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['classification' => 9999, 'username' => 'real-customer-name']));

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                if (! str_contains($message, 'matched no route')) {
                    return false;
                }
                // Bounded: reason + classification + status + device presence,
                // never usernames/hostnames/payload bodies.
                $this->assertEqualsCanonicalizing(
                    ['reason', 'classification', 'status', 'has_device_id'],
                    array_keys($context),
                );

                return true;
            })
            ->atLeast()->once();
    }

    // ── Deliberate ignores must NOT warn (8.6k events/day would flood) ───────

    public function test_known_non_backup_classifications_are_ignored_without_warning(): void
    {
        Log::spy();
        $this->linkedAsset();

        foreach ([
            \Comet\Def::JOB_CLASSIFICATION_RESTORE,
            \Comet\Def::JOB_CLASSIFICATION_RETENTION,
            5, // legacy restore
            7, // legacy retention
        ] as $classification) {
            $this->postEvent($this->jobCompletedEvent(['classification' => $classification]))->assertOk();
        }

        $this->assertSame(0, Alert::count());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_running_status_event_does_not_create_an_alert_and_does_not_warn(): void
    {
        Log::spy();
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_RUNNING_ACTIVE]))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_other_sevt_event_families_are_ignored_without_warning(): void
    {
        Log::spy();

        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_NEW;
        $this->postEvent($event->toArray(false))->assertOk()->assertJsonPath('status', 'ignored');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_non_backup_success_does_not_resolve_a_backup_alert(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd]));

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION,
            'end' => $failEnd + 600,
        ]))->assertOk();

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status,
            'a successful retention pass must not clear a backup-failure alert');
    }

    public function test_a_string_type_event_is_not_routed_and_warns_as_unrecognized(): void
    {
        // The old code matched Type === 'job.completed'; the vendored SDK
        // defines Type as an integer SEVT_ code. Whether any Comet build ever
        // sent the string form is unverified — what is pinned here is that an
        // out-of-catalog Type is never processed as a job AND never dropped
        // silently.
        Log::spy();
        $this->linkedAsset();

        $this->postEvent([
            'Type' => 'job.completed',
            'Data' => [
                'Username' => 'acme-backup',
                'DeviceID' => 'dev-1',
                'Classification' => 4,
                'Status' => 7002,
            ],
        ])->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unrecognized event Type'))
            ->atLeast()->once();
    }

    public function test_job_completed_event_without_data_payload_is_ignored(): void
    {
        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_COMPLETED;

        $this->postEvent($event->toArray(false))->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame(0, Alert::count());
    }

    public function test_alerts_disabled_setting_suppresses_alert_creation(): void
    {
        Setting::setValue('comet_alert_enabled', '0');
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent())->assertOk();

        $this->assertSame(0, Alert::count());
    }

    public function test_success_without_prior_alert_is_a_noop(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS]))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
    }

    public function test_non_integer_status_in_job_payload_is_ignored(): void
    {
        $this->assertNull(app(CometAlertService::class)->handleJobCompleted(['Status' => 'failed']));
        $this->assertNull(app(CometAlertService::class)->handleJobCompleted([]));
    }

    // ── Operator-visible delivery proof ───────────────────────────────────────

    public function test_webhook_delivery_stamps_advance_with_real_traffic(): void
    {
        $this->linkedAsset();
        $this->assertNull(Setting::getValue('comet_webhook_last_received_at'));

        // A non-job event advances "received" but not "recognized".
        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_NEW;
        $this->postEvent($event->toArray(false));

        $this->assertNotNull(Setting::getValue('comet_webhook_last_received_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_recognized_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_alert_at'));

        // A job-completed failure advances all three.
        $this->postEvent($this->jobCompletedEvent());

        $this->assertNotNull(Setting::getValue('comet_webhook_last_recognized_at'));
        $this->assertNotNull(Setting::getValue('comet_webhook_last_alert_at'));
    }

    // ── Auth + error handling ─────────────────────────────────────────────────

    public function test_missing_or_wrong_webhook_key_is_rejected(): void
    {
        $this->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer wrong-key'])
            ->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->assertSame(0, Alert::count());
    }

    public function test_processing_errors_return_a_generic_message_not_exception_details(): void
    {
        $this->mock(CometAlertService::class)
            ->shouldReceive('handleJobCompleted')
            ->andThrow(new \RuntimeException('SQLSTATE[42S02]: secret table detail'));

        $response = $this->postEvent($this->jobCompletedEvent());

        $response->assertStatus(500)->assertJsonPath('message', 'Internal error processing webhook.');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
