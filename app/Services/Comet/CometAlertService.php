<?php

namespace App\Services\Comet;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Services\AlertService;
use App\Support\CometConfig;
use Illuminate\Support\Facades\Log;

/**
 * Turns Comet job-completion webhook events into PSA alerts.
 *
 * Job field names and code values come from the vendor SDK, not from guesses
 * (psa-enpew — the previous constants CLASS_BACKUP=4 / STATUS_ERROR=7002-exact
 * matched nothing Comet ever sends, so no backup-failure alert ever fired):
 * - classifications: vendor/cometbackup/comet-php-sdk/Comet/Def.php:610-701
 *   (a 4000-4999 range; BACKUP = 4001)
 * - statuses: Def.php:708-841 — RANGES, not single codes: success 5000-5999,
 *   running 6000-6999, failed 7000-7999 (timeout/warning/error/quota/
 *   missed-schedule/cancelled/skip-already-running/abandoned)
 * - job payload fields: Comet/BackupJobDetail.php (note: it has NO FileErrors
 *   property — error text lives in the job log, not the job object)
 */
class CometAlertService
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    /**
     * Route a completed job (webhook Data payload) by its vendor status range:
     * failed range raises/refreshes an alert, success range resolves one,
     * anything else (running/unknown) is not a completion outcome and is ignored.
     */
    public function handleJobCompleted(array $data): ?Alert
    {
        $status = $data['Status'] ?? null;

        if (! is_int($status)) {
            Log::debug('[Comet Alert] Job payload without integer Status, ignoring', ['status' => $status]);

            return null;
        }

        if ($this->isFailedStatus($status)) {
            return $this->handleJobFailure($data);
        }

        if ($this->isSuccessStatus($status)) {
            return $this->handleJobSuccess($data);
        }

        Log::debug('[Comet Alert] Job status outside success/failed ranges, ignoring', ['status' => $status]);

        return null;
    }

    public function handleJobFailure(array $data): ?Alert
    {
        if (! CometConfig::alertsEnabled()) {
            Log::debug('[Comet Alert] Alerts disabled, ignoring');

            return null;
        }

        $username = $data['Username'] ?? null;
        $deviceId = $data['DeviceID'] ?? null;
        $status = $data['Status'] ?? null;
        $classification = $data['Classification'] ?? null;
        $startTime = $data['StartTime'] ?? null;
        $endTime = $data['EndTime'] ?? null;
        $totalSize = $data['TotalSize'] ?? null;

        if (! is_int($status) || ! $this->isFailedStatus($status)) {
            Log::debug('[Comet Alert] Non-failed status, ignoring', ['status' => $status]);

            return null;
        }

        if ($classification !== \Comet\Def::JOB_CLASSIFICATION_BACKUP) {
            Log::debug('[Comet Alert] Non-backup classification, ignoring', ['classification' => $classification]);

            return null;
        }

        $asset = $deviceId ? Asset::where('comet_device_id', $deviceId)->first() : null;
        $clientId = $asset?->client_id;

        if (! $clientId) {
            Log::info('[Comet Alert] No client match for device, creating unlinked alert', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
        }

        $hostname = $asset?->hostname ?? $username ?? 'Unknown';
        $jobType = $this->classificationLabel($classification);
        $statusLabel = $this->statusLabel($status);

        // source_alert_id: {DeviceID}:{Classification} — Comet has no unique job failure ID
        $sourceAlertId = "{$deviceId}:{$classification}";

        // Build message
        $msgLines = ["Device: {$hostname}", "Job type: {$jobType}", "Status: {$statusLabel}"];
        if ($startTime) {
            $msgLines[] = 'Started: '.date('Y-m-d H:i:s', $startTime);
        }
        if ($endTime) {
            $msgLines[] = 'Ended: '.date('Y-m-d H:i:s', $endTime);
        }
        if ($totalSize) {
            $msgLines[] = 'Total size: '.number_format($totalSize / (1024 ** 3), 2).' GB';
        }

        $alert = $this->alertService->upsert(
            AlertSource::Comet,
            $sourceAlertId,
            [
                'asset_id' => $asset?->id,
                'client_id' => $clientId,
                'severity' => AlertSeverity::fromVendor(AlertSource::Comet, null),
                'title' => mb_substr("Backup {$statusLabel} on {$hostname}", 0, 255),
                'message' => implode("\n", $msgLines),
                'hostname' => $hostname,
                'fired_at' => $endTime ? \Illuminate\Support\Carbon::createFromTimestamp($endTime) : now(),
                'metadata' => [
                    'device_id' => $deviceId,
                    'username' => $username,
                    'classification' => $classification,
                    'status' => $status,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'total_size' => $totalSize,
                ],
            ],
        );

        Log::info('[Comet Alert] Alert upserted', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
            'hostname' => $hostname,
            'status' => $status,
            'client_id' => $clientId,
        ]);

        return $alert;
    }

    public function handleJobSuccess(array $data): ?Alert
    {
        $deviceId = $data['DeviceID'] ?? null;
        $status = $data['Status'] ?? null;
        $classification = $data['Classification'] ?? null;

        if (! is_int($status) || ! $this->isSuccessStatus($status)) {
            return null;
        }

        if ($classification !== \Comet\Def::JOB_CLASSIFICATION_BACKUP) {
            return null;
        }

        // Match the same source_alert_id format used in handleJobFailure
        $sourceAlertId = "{$deviceId}:{$classification}";

        $alert = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->latest()
            ->first();

        if (! $alert) {
            return null;
        }

        $this->alertService->resolve($alert, 'Backup completed successfully.');

        Log::info('[Comet Alert] Alert resolved on job success', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
        ]);

        return $alert;
    }

    private function isSuccessStatus(int $status): bool
    {
        return $status >= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MIN
            && $status <= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MAX;
    }

    private function isFailedStatus(int $status): bool
    {
        return $status >= \Comet\Def::JOB_STATUS_FAILED__MIN
            && $status <= \Comet\Def::JOB_STATUS_FAILED__MAX;
    }

    private function statusLabel(int $status): string
    {
        return match (true) {
            $status === \Comet\Def::JOB_STATUS_FAILED_TIMEOUT => 'Failed (timeout)',
            $status === \Comet\Def::JOB_STATUS_FAILED_WARNING => 'Completed with warnings',
            $status === \Comet\Def::JOB_STATUS_FAILED_ERROR => 'Failed (error)',
            $status === \Comet\Def::JOB_STATUS_FAILED_QUOTA => 'Failed (quota exceeded)',
            $status === \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED => 'Failed (missed schedule)',
            $status === \Comet\Def::JOB_STATUS_FAILED_CANCELLED => 'Cancelled',
            $status === \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING => 'Skipped (already running)',
            $status === \Comet\Def::JOB_STATUS_FAILED_ABANDONED => 'Abandoned',
            $this->isFailedStatus($status) => "Failed (code {$status})",
            default => "Unknown (code {$status})",
        };
    }

    /** Classification values per vendor Comet/Def.php:610-701. */
    private function classificationLabel(int $classification): string
    {
        return match ($classification) {
            \Comet\Def::JOB_CLASSIFICATION_BACKUP => 'Backup',
            \Comet\Def::JOB_CLASSIFICATION_RESTORE => 'Restore',
            \Comet\Def::JOB_CLASSIFICATION_RETENTION => 'Retention',
            \Comet\Def::JOB_CLASSIFICATION_DEEPVERIFY => 'Deep verify',
            default => "Other ({$classification})",
        };
    }
}
