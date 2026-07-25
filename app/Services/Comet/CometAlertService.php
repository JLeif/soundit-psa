<?php

namespace App\Services\Comet;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\AlertService;
use App\Support\CometConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns Comet job-completion webhook events into PSA alerts.
 *
 * Code/range reading and the dual-scale backup predicate live in
 * CometJobCodes (single shared seam with CometJobService) — see its docblock
 * for the honest limit: prod zero-alert evidence proves the old pipeline
 * matched nothing, NOT which of its guards (classification scale vs exact
 * status code) was responsible. Everything here is robust to either.
 *
 * Alert series identity (psa-enpew review): one alert row per
 * device + protected item (SourceGUID) + storage vault (DestinationGUID),
 * classification-scale-free ('backup' token). Event ordering is enforced with
 * the job's EndTime — a stale/replayed success can never clear a newer
 * failure, and a stale failure can never clobber newer state. A failure
 * arriving after the series was resolved REOPENS the same row (the alerts
 * table is unique on source + source_alert_id, so create-after-resolve would
 * otherwise 500 on the duplicate key).
 *
 * Every drop path is deliberate and visible: unrecognised shapes log at
 * WARNING (silent non-matching is the exact defect this repairs); recognised
 * non-backup classifications (restore/retention/…) are routine and log at
 * DEBUG by design — warning there would flood at thousands of events/day.
 */
class CometAlertService
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    /**
     * Route a completed job (webhook Data payload) by its vendor status range:
     * failed range raises/refreshes an alert, success range resolves one.
     */
    public function handleJobCompleted(array $data): ?Alert
    {
        $status = $data['Status'] ?? null;

        if (! is_int($status)) {
            $this->warnUnmatched('missing_or_non_integer_status', $data);

            return null;
        }

        if (CometJobCodes::isFailedStatus($status)) {
            return $this->handleJobFailure($data);
        }

        if (CometJobCodes::isSuccessStatus($status)) {
            return $this->handleJobSuccess($data);
        }

        if (CometJobCodes::isRunningStatus($status)) {
            // A recognised range, deliberately not a completion outcome.
            Log::info('[Comet Alert] Job-completed event with running-range status, ignoring', ['status' => $status]);

            return null;
        }

        $this->warnUnmatched('status_outside_known_ranges', $data);

        return null;
    }

    public function handleJobFailure(array $data): ?Alert
    {
        if (! CometConfig::alertsEnabled()) {
            Log::debug('[Comet Alert] Alerts disabled, ignoring');

            return null;
        }

        $status = $data['Status'] ?? null;

        if (! is_int($status) || ! CometJobCodes::isFailedStatus($status)) {
            $this->warnUnmatched('non_failed_status_on_failure_path', $data);

            return null;
        }

        if (! $this->routeBackupClassification($data)) {
            return null;
        }

        $deviceId = $data['DeviceID'] ?? null;
        if (! is_string($deviceId) || $deviceId === '') {
            // Fail loudly: without device identity there is no series to key.
            $this->warnUnmatched('missing_device_identity', $data);

            return null;
        }

        $username = $data['Username'] ?? null;
        $classification = $data['Classification'] ?? null;
        $startTime = $data['StartTime'] ?? null;
        $endTime = $data['EndTime'] ?? null;
        $totalSize = $data['TotalSize'] ?? null;
        $sourceGuid = is_string($data['SourceGUID'] ?? null) ? $data['SourceGUID'] : '';
        $destinationGuid = is_string($data['DestinationGUID'] ?? null) ? $data['DestinationGUID'] : '';

        $sourceAlertId = CometJobCodes::backupSeriesKey($deviceId, $sourceGuid, $destinationGuid);
        $eventTime = (is_int($endTime) && $endTime > 0) ? $endTime : now()->timestamp;

        if ($sourceGuid === '' && $destinationGuid === '') {
            Log::info('[Comet Alert] Job payload carries no SourceGUID/DestinationGUID — series identity degraded to device-level', [
                'device_id' => $deviceId,
            ]);
        }

        $asset = Asset::where('comet_device_id', $deviceId)->first();
        $clientId = $asset?->client_id;

        if (! $clientId) {
            Log::info('[Comet Alert] No client match for device, creating unlinked alert', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
        }

        $hostname = $asset?->hostname ?? $username ?? 'Unknown';
        $jobType = CometJobCodes::classificationLabel(is_int($classification) ? $classification : null);
        $statusLabel = CometJobCodes::statusLabel($status);
        $title = mb_substr("Backup {$statusLabel} on {$hostname}", 0, 255);

        $msgLines = ["Device: {$hostname}", "Job type: {$jobType}", "Status: {$statusLabel}"];
        if ($sourceGuid !== '') {
            $msgLines[] = "Protected item: {$sourceGuid}";
        }
        if ($destinationGuid !== '') {
            $msgLines[] = "Storage vault: {$destinationGuid}";
        }
        if (is_int($startTime) && $startTime > 0) {
            $msgLines[] = 'Started: '.date('Y-m-d H:i:s', $startTime);
        }
        if (is_int($endTime) && $endTime > 0) {
            $msgLines[] = 'Ended: '.date('Y-m-d H:i:s', $endTime);
        }
        if (is_numeric($totalSize) && $totalSize > 0) {
            $msgLines[] = 'Total size: '.number_format($totalSize / (1024 ** 3), 2).' GB';
        }
        $message = implode("\n", $msgLines);

        $metadata = [
            'device_id' => $deviceId,
            'username' => $username,
            'classification' => $classification,
            'status' => $status,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'total_size' => $totalSize,
            'source_guid' => $sourceGuid,
            'destination_guid' => $destinationGuid,
            'last_event_time' => $eventTime,
        ];

        // One row per series regardless of status (unique source + source_alert_id).
        $existing = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $sourceAlertId)
            ->first();

        if (! $existing) {
            try {
                $alert = $this->alertService->upsert(
                    AlertSource::Comet,
                    $sourceAlertId,
                    [
                        'asset_id' => $asset?->id,
                        'client_id' => $clientId,
                        'severity' => AlertSeverity::fromVendor(AlertSource::Comet, null),
                        'title' => $title,
                        'message' => $message,
                        'hostname' => $hostname,
                        'fired_at' => Carbon::createFromTimestamp($eventTime),
                        'metadata' => $metadata,
                    ],
                );

                Log::info('[Comet Alert] Alert created', [
                    'alert_id' => $alert->id,
                    'source_alert_id' => $sourceAlertId,
                    'hostname' => $hostname,
                    'status' => $status,
                    'client_id' => $clientId,
                ]);

                $this->stampLastAlert();

                return $alert;
            } catch (\Illuminate\Database\QueryException $e) {
                // Concurrent webhook created the series row between our lookup
                // and the insert — fall through to the existing-row paths.
                $existing = Alert::where('source', AlertSource::Comet)
                    ->where('source_alert_id', $sourceAlertId)
                    ->first();
                if (! $existing) {
                    throw $e;
                }
            }
        }

        $lastEventTime = (int) ($existing->metadata['last_event_time'] ?? 0);
        if ($eventTime < $lastEventTime) {
            Log::info('[Comet Alert] Stale/replayed failure event ignored — newer state already recorded', [
                'alert_id' => $existing->id,
                'source_alert_id' => $sourceAlertId,
                'event_time' => $eventTime,
                'last_event_time' => $lastEventTime,
            ]);

            return $existing;
        }

        if ($existing->status === AlertStatus::Resolved) {
            // The series failed again after a resolving success: reopen the
            // row (creating would violate the unique key). The prior ack
            // belonged to the previous incident, so it is cleared.
            $existing->update([
                'status' => AlertStatus::Active,
                'resolved_at' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'title' => $title,
                'message' => $message,
                'fired_at' => Carbon::createFromTimestamp($eventTime),
                'refired_count' => $existing->refired_count + 1,
                'metadata' => array_merge($existing->metadata ?? [], $metadata),
            ]);

            Log::info('[Comet Alert] Resolved alert reopened by new failure', [
                'alert_id' => $existing->id,
                'source_alert_id' => $sourceAlertId,
                'status' => $status,
            ]);

            $this->stampLastAlert();

            return $existing;
        }

        // Re-fired while open: refresh title too, so a later quota/timeout
        // failure cannot keep presenting an older outcome's title.
        $existing->update([
            'title' => $title,
            'message' => $message,
            'fired_at' => Carbon::createFromTimestamp($eventTime),
            'refired_count' => $existing->refired_count + 1,
            'metadata' => array_merge($existing->metadata ?? [], $metadata),
        ]);

        Log::info('[Comet Alert] Alert re-fired', [
            'alert_id' => $existing->id,
            'source_alert_id' => $sourceAlertId,
            'status' => $status,
            'refired_count' => $existing->refired_count,
        ]);

        $this->stampLastAlert();

        return $existing;
    }

    public function handleJobSuccess(array $data): ?Alert
    {
        $status = $data['Status'] ?? null;

        if (! is_int($status) || ! CometJobCodes::isSuccessStatus($status)) {
            $this->warnUnmatched('non_success_status_on_success_path', $data);

            return null;
        }

        if (! $this->routeBackupClassification($data)) {
            return null;
        }

        $deviceId = $data['DeviceID'] ?? null;
        if (! is_string($deviceId) || $deviceId === '') {
            $this->warnUnmatched('missing_device_identity', $data);

            return null;
        }

        $sourceGuid = is_string($data['SourceGUID'] ?? null) ? $data['SourceGUID'] : '';
        $destinationGuid = is_string($data['DestinationGUID'] ?? null) ? $data['DestinationGUID'] : '';
        $endTime = $data['EndTime'] ?? null;

        $sourceAlertId = CometJobCodes::backupSeriesKey($deviceId, $sourceGuid, $destinationGuid);
        $eventTime = (is_int($endTime) && $endTime > 0) ? $endTime : now()->timestamp;

        $alert = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if (! $alert) {
            return null;
        }

        $lastEventTime = (int) ($alert->metadata['last_event_time'] ?? 0);
        if ($eventTime < $lastEventTime) {
            // The mirror image of the original defect: a stale/replayed
            // success must never present a broken backup as recovered.
            Log::warning('[Comet Alert] Stale success rejected — it predates the recorded failure, alert stays open', [
                'alert_id' => $alert->id,
                'source_alert_id' => $sourceAlertId,
                'event_time' => $eventTime,
                'last_event_time' => $lastEventTime,
            ]);

            return null;
        }

        $alert->update([
            'metadata' => array_merge($alert->metadata ?? [], [
                'last_event_time' => $eventTime,
                'resolved_by_status' => $status,
                'resolved_by_end_time' => is_int($endTime) ? $endTime : null,
            ]),
        ]);

        $this->alertService->resolve($alert, 'Backup completed successfully.');

        Log::info('[Comet Alert] Alert resolved on job success', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
        ]);

        return $alert;
    }

    /**
     * True when the payload's classification is backup (either scale).
     * Recognised non-backup classifications are deliberately ignored at
     * DEBUG (retention/restore completions are routine traffic); an
     * unrecognised value is a WARNING — it means our reading of the vendor
     * codes has drifted, which must never be silent.
     */
    private function routeBackupClassification(array $data): bool
    {
        $classification = $data['Classification'] ?? null;

        if (CometJobCodes::isBackupClassification($classification)) {
            return true;
        }

        if (CometJobCodes::isKnownNonBackupClassification($classification)) {
            Log::debug('[Comet Alert] Non-backup classification, deliberately ignoring', [
                'classification' => $classification,
            ]);

            return false;
        }

        $this->warnUnmatched('unrecognized_classification', $data);

        return false;
    }

    /**
     * The loud unmatched-event signal (psa-enpew review): a job-completed
     * payload we could not route logs at WARNING with bounded, safe
     * structural fields only — no payload bodies, no customer identifiers.
     */
    private function warnUnmatched(string $reason, array $data): void
    {
        $classification = $data['Classification'] ?? null;
        $status = $data['Status'] ?? null;

        Log::warning('[Comet Alert] Job-completed event matched no route — dropped', [
            'reason' => $reason,
            'classification' => is_scalar($classification) ? $classification : gettype($classification),
            'status' => is_scalar($status) ? $status : gettype($status),
            'has_device_id' => is_string($data['DeviceID'] ?? null) && ($data['DeviceID'] ?? '') !== '',
        ]);
    }

    /** Operator-visible proof-of-life: when did the webhook last write an alert. */
    private function stampLastAlert(): void
    {
        Setting::setValue('comet_webhook_last_alert_at', now()->toIso8601String());
    }
}
