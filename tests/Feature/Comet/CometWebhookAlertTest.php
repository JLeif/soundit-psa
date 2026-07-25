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
use Tests\TestCase;

/**
 * Comet webhook → alert lifecycle (psa-enpew).
 *
 * Every payload here is serialized by the VENDOR's own SDK classes
 * (\Comet\StreamableEvent + \Comet\BackupJobDetail via toArray()), so the
 * fixtures carry exactly the wire shape a real Comet server POSTs — integer
 * Type 4201 (Def.php:2115 SEVT_JOB_COMPLETED), Data = the job object with
 * Classification on the 4000-range and Status on the 5000/6000/7000 ranges
 * (Def.php:610-841) — and cannot drift into the shape our code merely wishes
 * for (CLAUDE.md fixture rule). The previous controller/service matched an
 * invented string Type 'job.completed' and Classification === 4, so NO real
 * event was ever processed; these tests pin the vendor shape end-to-end.
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
        $this->assertSame('dev-1:'.\Comet\Def::JOB_CLASSIFICATION_BACKUP, $alert->source_alert_id);
        $this->assertSame($asset->id, $alert->asset_id);
        $this->assertSame($asset->client_id, $alert->client_id);
        $this->assertSame('ACME-SRV-01', $alert->hostname);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame('Backup Failed (error) on ACME-SRV-01', $alert->title);
        $this->assertStringContainsString('Status: Failed (error)', $alert->message);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_ERROR, $alert->metadata['status']);
    }

    public function test_every_vendor_failure_subtype_raises_an_alert(): void
    {
        // The old code alerted on 7002 alone (and its Classification === 4 guard
        // meant even that never fired). The vendor defines failure as the whole
        // 7000-7999 range — timeout, quota, and missed-schedule failures are
        // exactly the ones an MSP must see.
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

    public function test_backup_success_resolves_the_open_alert_for_that_device(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS]))
            ->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);
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

    // ── Events that must NOT alert ────────────────────────────────────────────

    public function test_running_status_event_does_not_create_an_alert(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_RUNNING_ACTIVE]))
            ->assertOk()->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
    }

    public function test_non_backup_classification_failure_does_not_create_an_alert(): void
    {
        $this->linkedAsset();

        foreach ([
            \Comet\Def::JOB_CLASSIFICATION_RESTORE,
            \Comet\Def::JOB_CLASSIFICATION_RETENTION,
        ] as $classification) {
            $this->postEvent($this->jobCompletedEvent(['classification' => $classification]))->assertOk();
        }

        $this->assertSame(0, Alert::count());
    }

    public function test_non_backup_success_does_not_resolve_a_backup_alert(): void
    {
        $this->linkedAsset();
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR]));

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION,
        ]))->assertOk();

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status,
            'a successful retention pass must not clear a backup-failure alert');
    }

    public function test_the_legacy_invented_shape_is_not_accepted(): void
    {
        // The shape the old code was written against — string Type, small-int
        // Classification — is not something Comet has ever sent. Pin it as
        // rejected so "compatibility" with the fiction is never restored.
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

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_missing_or_wrong_webhook_key_is_rejected(): void
    {
        $this->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer wrong-key'])
            ->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->assertSame(0, Alert::count());
    }
}
