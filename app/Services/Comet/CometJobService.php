<?php

namespace App\Services\Comet;

use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Live job history for one Comet-linked asset.
 *
 * Code/range reading is delegated to CometJobCodes (the single shared seam
 * with CometAlertService — see its docblock for the dual-scale rationale and
 * the psa-enpew honest limit). Job payload fields per the vendor SDK's
 * Comet/BackupJobDetail.php (note: it has NO FileErrors property — error text
 * lives in the job log, not the job object).
 *
 * AVAILABILITY CONTRACT (psa-enpew review): a failed or impossible read is
 * NEVER presented as a clean empty history — "no jobs" is an all-clear-shaped
 * answer and must only mean the server really reported none. Callers receive
 * an explicit `state`, in the same vocabulary as CometReadOnlyToolset
 * (psa-z30dv):
 * - 'ok' — queried, backup jobs observed; `jobs` is the truth.
 * - 'no_backup_jobs_observed' — queried, but the server reported no
 *   backup-classification jobs for this device at all (other job types may
 *   appear in `jobs`). Backups may never have run; unknown is not passing.
 * - 'unavailable' — live read failed; backup state UNKNOWN, never passing.
 * - 'not_queried' — asset has no synced Comet username, nothing was asked.
 * Timestamps (`started`/`ended`/`jobs_checked_at`) are ISO-8601 UTC (Zulu) —
 * display surfaces convert via ->toAppTz() per CLAUDE.md; tool surfaces relay
 * the unambiguous UTC form.
 */
class CometJobService
{
    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Get recent backup jobs for an asset.
     *
     * @return array{state: string, jobs_checked_at: ?string, last_success: ?array, last_failure: ?array, jobs: array}
     */
    public function getRecentJobs(Asset $asset, int $days = 7): array
    {
        if (! $asset->comet_username) {
            return [
                'state' => 'not_queried',
                'jobs_checked_at' => null,
                'last_success' => null,
                'last_failure' => null,
                'jobs' => [],
            ];
        }

        try {
            $allJobs = $this->client->getJobsForUser($asset->comet_username);
        } catch (CometClientException $e) {
            Log::warning("[Comet Jobs] Failed to fetch jobs for {$asset->comet_username}: {$e->getMessage()}");

            return [
                'state' => 'unavailable',
                'jobs_checked_at' => now()->toIso8601ZuluString(),
                'last_success' => null,
                'last_failure' => null,
                'jobs' => [],
            ];
        }

        $cutoff = now()->subDays($days)->timestamp;
        $deviceId = $asset->comet_device_id;

        $jobs = [];
        $lastSuccess = null;
        $lastFailure = null;
        $backupJobsObserved = 0;

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
            $category = CometJobCodes::statusCategory($status);

            $formatted = [
                'status' => CometJobCodes::statusLabel($status),
                'status_code' => $status,
                'category' => $category,
                'classification' => CometJobCodes::classificationLabel($classification),
                'started' => $startTime ? Carbon::createFromTimestamp($startTime)->toIso8601ZuluString() : null,
                'ended' => $endTime ? Carbon::createFromTimestamp($endTime)->toIso8601ZuluString() : null,
                'duration_seconds' => ($startTime && $endTime) ? ($endTime - $startTime) : null,
                'total_size' => $job->TotalSize ?? null,
                'upload_size' => $job->UploadSize ?? null,
                'total_files' => $job->TotalFiles ?? null,
            ];

            // Track last success/failure across all time — BACKUP jobs only
            // (either classification scale), so a successful retention/restore
            // pass can never mask a backup failure
            $isBackup = CometJobCodes::isBackupClassification($classification);
            if ($isBackup) {
                $backupJobsObserved++;
            }
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

        // Sort by start time descending (ISO-8601 Zulu sorts chronologically)
        usort($jobs, fn ($a, $b) => ($b['started'] ?? '') <=> ($a['started'] ?? ''));

        return [
            // Shared read-state vocabulary with CometReadOnlyToolset
            // (psa-z30dv): a successful query that observed zero
            // backup-classification jobs is its own first-class state, so
            // "backups may never have run" can never render as a clean
            // all-clear-shaped empty history.
            'state' => $backupJobsObserved === 0 ? 'no_backup_jobs_observed' : 'ok',
            'jobs_checked_at' => now()->toIso8601ZuluString(),
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'jobs' => $jobs,
        ];
    }
}
