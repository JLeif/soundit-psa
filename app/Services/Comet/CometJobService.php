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
 * AVAILABILITY + POSTURE CONTRACT (psa-enpew review, one dialect with
 * psa-z30dv's CometReadOnlyToolset): a failed or impossible read is NEVER
 * presented as a clean empty history — "no jobs" is an all-clear-shaped
 * answer and must only mean the server really reported none. Callers receive
 * TWO explicit fields:
 *
 * `state` — how the read itself went:
 * - 'ok' — queried, backup jobs observed; `jobs` is the truth.
 * - 'no_backup_jobs_observed' — queried, but the server reported no
 *   backup-classification jobs for this device at all (other job types may
 *   appear in `jobs`). Backups may never have run; unknown is not passing.
 * - 'unavailable' — the history could not be read; `unavailable_reason` says
 *   why: 'lookup_failed' (live read failed) or 'no_synced_username' (the
 *   asset carries no Comet username, so nothing could be asked — the same
 *   condition CometReadOnlyToolset reports as unavailable, psa-enpew.12).
 *
 * `job_state` — the device's backup POSTURE, in comet_get_backup_posture's
 * vocabulary verbatim (CometReadOnlyToolset::devicePosture, psa-z30dv), so
 * the same word can never mean "the read worked" on one surface and "the
 * backup worked" on another: 'last_backup_succeeded' / 'last_backup_failed' /
 * 'last_backup_running' (outcome of the most recent backup-classification
 * job, across all time) / 'last_backup_unknown' (its status code is not
 * recognised — UNKNOWN, not passing, not a confirmed failure) /
 * 'no_backup_jobs_observed' / 'unavailable'. Only 'last_backup_succeeded'
 * means the newest backup worked. `job_state_note` spells out every
 * degraded/unknown value. This service never emits 'not_queried' — that is
 * the fleet posture tool's deliberate-skip state under its lookup cap and
 * circuit breaker; a single-asset read here is always attempted when it can
 * be.
 *
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
     * @return array{state: string, unavailable_reason: ?string, job_state: string, job_state_note: ?string, jobs_checked_at: ?string, last_backup: ?array, last_success: ?array, last_failure: ?array, jobs: array}
     */
    public function getRecentJobs(Asset $asset, int $days = 7): array
    {
        if (! $asset->comet_username) {
            return $this->unavailable(
                'no_synced_username',
                'This asset carries no synced Comet username, so its job history cannot be looked up. '
                .'Re-run the Comet backup sync. Backup state is UNKNOWN, not passing.',
                jobsCheckedAt: null,
            );
        }

        try {
            $allJobs = $this->client->getJobsForUser($asset->comet_username);
        } catch (CometClientException $e) {
            Log::warning("[Comet Jobs] Failed to fetch jobs for {$asset->comet_username}: {$e->getMessage()}");

            return $this->unavailable(
                'lookup_failed',
                'Backup job history could not be fetched from the Comet server — backup state is UNKNOWN: '
                .'not passing, and not a confirmed failure. Retry, or verify in the Comet console.',
                jobsCheckedAt: now()->toIso8601ZuluString(),
            );
        }

        $cutoff = now()->subDays($days)->timestamp;
        $deviceId = $asset->comet_device_id;

        $jobs = [];
        $lastBackup = null;
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

            // Track the newest backup job plus last success/failure across all
            // time — BACKUP jobs only (either classification scale), so a
            // successful retention/restore pass can never mask a backup failure
            $isBackup = CometJobCodes::isBackupClassification($classification);
            if ($isBackup) {
                $backupJobsObserved++;
                if (! $lastBackup || $startTime > $lastBackup['started_ts']) {
                    $lastBackup = $formatted + ['started_ts' => $startTime];
                }
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

        [$jobState, $jobStateNote] = $this->derivePosture($lastBackup);

        return [
            // Shared read-state vocabulary with CometReadOnlyToolset
            // (psa-z30dv): a successful query that observed zero
            // backup-classification jobs is its own first-class state, so
            // "backups may never have run" can never render as a clean
            // all-clear-shaped empty history.
            'state' => $backupJobsObserved === 0 ? 'no_backup_jobs_observed' : 'ok',
            'unavailable_reason' => null,
            'job_state' => $jobState,
            'job_state_note' => $jobStateNote,
            'jobs_checked_at' => now()->toIso8601ZuluString(),
            'last_backup' => $lastBackup,
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'jobs' => $jobs,
        ];
    }

    /**
     * Backup POSTURE from the newest backup-classification job, in
     * CometReadOnlyToolset::devicePosture's vocabulary verbatim (psa-z30dv /
     * psa-enpew.12) — never 'ok': "the read worked" must not be relayable as
     * "the backup worked".
     *
     * @return array{0: string, 1: ?string}
     */
    private function derivePosture(?array $lastBackup): array
    {
        if ($lastBackup === null) {
            return [
                'no_backup_jobs_observed',
                'The Comet server returned no backup jobs for this device — backups may never have run. Unknown is not passing.',
            ];
        }

        return match ($lastBackup['category']) {
            'success' => ['last_backup_succeeded', null],
            'failed' => ['last_backup_failed', null],
            'running' => ['last_backup_running', null],
            default => [
                'last_backup_unknown',
                'The most recent backup job reports a status code this integration does not recognise — its outcome is UNKNOWN: not passing, and not a confirmed failure either. Verify this device in the Comet console.',
            ],
        };
    }

    /**
     * The degraded-read shape: state + posture BOTH scream unavailable, with
     * the machine-readable reason consumers key their copy on.
     */
    private function unavailable(string $reason, string $note, ?string $jobsCheckedAt): array
    {
        return [
            'state' => 'unavailable',
            'unavailable_reason' => $reason,
            'job_state' => 'unavailable',
            'job_state_note' => $note,
            'jobs_checked_at' => $jobsCheckedAt,
            'last_backup' => null,
            'last_success' => null,
            'last_failure' => null,
            'jobs' => [],
        ];
    }
}
