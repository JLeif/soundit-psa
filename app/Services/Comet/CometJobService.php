<?php

namespace App\Services\Comet;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Live job history for one Comet-linked asset.
 *
 * Field names and code values come from the vendor SDK (psa-enpew — the
 * previous exact-match constants labelled real SDK values 'Other'/'Unknown'
 * and missed most failure subtypes):
 * - classifications: vendor/cometbackup/comet-php-sdk/Comet/Def.php:610-701
 *   (a 4000-4999 range; BACKUP = 4001)
 * - statuses: Def.php:708-841 — RANGES: success 5000-5999, running 6000-6999,
 *   failed 7000-7999 (timeout/warning/error/quota/missed-schedule/cancelled/
 *   skip-already-running/abandoned)
 * - job fields: Comet/BackupJobDetail.php (it has NO FileErrors property —
 *   error text lives in the job log, not the job object)
 */
class CometJobService
{
    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Get recent backup jobs for an asset.
     */
    public function getRecentJobs(Asset $asset, int $days = 7): array
    {
        if (! $asset->comet_username) {
            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        try {
            $allJobs = $this->client->getJobsForUser($asset->comet_username);
        } catch (CometClientException $e) {
            Log::warning("[Comet Jobs] Failed to fetch jobs for {$asset->comet_username}: {$e->getMessage()}");

            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        $cutoff = now()->subDays($days)->timestamp;
        $deviceId = $asset->comet_device_id;

        $jobs = [];
        $lastSuccess = null;
        $lastFailure = null;

        foreach ($allJobs as $job) {
            // The SDK returns BackupJobDetail objects
            $jobDeviceId = $job->DeviceID ?? null;
            $startTime = $job->StartTime ?? 0;

            if ($deviceId && $jobDeviceId !== $deviceId) {
                continue;
            }

            $status = $job->Status ?? null;
            $endTime = $job->EndTime ?? null;
            $classification = $job->Classification ?? null;
            $category = $this->statusCategory($status);

            $formatted = [
                'status' => $this->statusLabel($status),
                'status_code' => $status,
                'category' => $category,
                'classification' => $this->classificationLabel($classification),
                'started' => $startTime ? date('Y-m-d H:i:s', $startTime) : null,
                'ended' => $endTime ? date('Y-m-d H:i:s', $endTime) : null,
                'duration_seconds' => ($startTime && $endTime) ? ($endTime - $startTime) : null,
                'total_size' => $job->TotalSize ?? null,
                'upload_size' => $job->UploadSize ?? null,
                'total_files' => $job->TotalFiles ?? null,
            ];

            // Track last success/failure across all time — BACKUP jobs only, so a
            // successful retention/restore pass can never mask a backup failure
            $isBackup = $classification === \Comet\Def::JOB_CLASSIFICATION_BACKUP;
            if ($isBackup && $category === 'success' && (! $lastSuccess || $startTime > $lastSuccess['started_ts'])) {
                $lastSuccess = $formatted + ['started_ts' => $startTime];
            }
            if ($isBackup && $category === 'failed' && (! $lastFailure || $startTime > $lastFailure['started_ts'])) {
                $lastFailure = $formatted + ['started_ts' => $startTime];
            }

            // Only include recent jobs in the list
            if ($startTime >= $cutoff) {
                $jobs[] = $formatted;
            }
        }

        // Sort by start time descending
        usort($jobs, fn ($a, $b) => ($b['started'] ?? '') <=> ($a['started'] ?? ''));

        return [
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'jobs' => $jobs,
        ];
    }

    /**
     * Status RANGES per vendor Comet/Def.php:708-841. An unrecognised code maps
     * to 'unknown', which callers must treat as not-good, never as passing.
     */
    private function statusCategory(?int $status): string
    {
        return match (true) {
            $status === null => 'unknown',
            $status >= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MIN && $status <= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MAX => 'success',
            $status >= \Comet\Def::JOB_STATUS_RUNNING__MIN && $status <= \Comet\Def::JOB_STATUS_RUNNING__MAX => 'running',
            $status >= \Comet\Def::JOB_STATUS_FAILED__MIN && $status <= \Comet\Def::JOB_STATUS_FAILED__MAX => 'failed',
            default => 'unknown',
        };
    }

    private function statusLabel(?int $status): string
    {
        return match (true) {
            $status === \Comet\Def::JOB_STATUS_STOP_SUCCESS => 'Completed',
            $status === \Comet\Def::JOB_STATUS_FAILED_TIMEOUT => 'Failed (timeout)',
            $status === \Comet\Def::JOB_STATUS_FAILED_WARNING => 'Completed with warnings',
            $status === \Comet\Def::JOB_STATUS_FAILED_ERROR => 'Failed (error)',
            $status === \Comet\Def::JOB_STATUS_FAILED_QUOTA => 'Failed (quota exceeded)',
            $status === \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED => 'Failed (missed schedule)',
            $status === \Comet\Def::JOB_STATUS_FAILED_CANCELLED => 'Cancelled',
            $status === \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING => 'Skipped (already running)',
            $status === \Comet\Def::JOB_STATUS_FAILED_ABANDONED => 'Abandoned',
            default => match ($this->statusCategory($status)) {
                'success' => "Completed (code {$status})",
                'running' => 'Running',
                'failed' => "Failed (code {$status})",
                default => "Unknown (code {$status})",
            },
        };
    }

    /** Classification values per vendor Comet/Def.php:610-701. */
    private function classificationLabel(?int $classification): string
    {
        return match ($classification) {
            \Comet\Def::JOB_CLASSIFICATION_BACKUP => 'Backup',
            \Comet\Def::JOB_CLASSIFICATION_RESTORE => 'Restore',
            \Comet\Def::JOB_CLASSIFICATION_RETENTION => 'Retention',
            \Comet\Def::JOB_CLASSIFICATION_DEEPVERIFY => 'Deep verify',
            default => 'Other'.($classification !== null ? " ({$classification})" : ''),
        };
    }
}
