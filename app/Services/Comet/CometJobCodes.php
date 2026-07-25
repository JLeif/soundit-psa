<?php

namespace App\Services\Comet;

/**
 * The single shared reading of Comet job classification/status codes.
 * CometAlertService (webhook → alerts) and CometJobService (live job history)
 * both delegate here, so the two surfaces can never disagree about what
 * counts as a backup or a failure.
 *
 * Code values come from the vendor SDK, not from guesses:
 * - classifications: vendor/cometbackup/comet-php-sdk/Comet/Def.php:610-701
 *   (a 4000-4999 range; BACKUP = 4001)
 * - statuses: Def.php:708-841 — RANGES, not single codes: success 5000-5999,
 *   running 6000-6999, failed 7000-7999 (timeout/warning/error/quota/
 *   missed-schedule/cancelled/skip-already-running/abandoned)
 *
 * THE DUAL-SCALE BACKUP PREDICATE, AND WHY IT MUST STAY (psa-enpew):
 * production had ZERO Comet alert rows ever, while the webhook received
 * thousands of events per day. That proves the OUTCOME — the old pipeline
 * (string Type 'job.completed', Classification === 4, Status === 7002 exact)
 * matched nothing — but NOT which guard rejected real traffic: the status
 * guard returned before the classification check, so either the exact-7002
 * status match or the classification scale (4 vs SDK 4001) could have been
 * responsible, or both. No captured live webhook payload exists to settle it.
 * The fix is therefore robust to EITHER cause: backup classification accepts
 * BOTH the SDK scale (4001) and the legacy small-int scale (4), and status
 * matching uses the full vendor ranges. Do not "simplify" one scale away, and
 * do not present a single root cause as established.
 */
final class CometJobCodes
{
    /**
     * Legacy small-int classification scale (Backup=4, Restore=5, Retention=7)
     * that the pre-psa-enpew code matched exclusively. Whether any real Comet
     * webhook ever carried it is unverified — accepted alongside the SDK scale
     * so the alert pipeline works whichever scale arrives.
     */
    public const LEGACY_CLASSIFICATION_BACKUP = 4;

    public const LEGACY_CLASSIFICATION_RESTORE = 5;

    public const LEGACY_CLASSIFICATION_RETENTION = 7;

    /** Backup on EITHER scale — SDK 4001 or legacy 4. */
    public static function isBackupClassification(mixed $classification): bool
    {
        return $classification === \Comet\Def::JOB_CLASSIFICATION_BACKUP
            || $classification === self::LEGACY_CLASSIFICATION_BACKUP;
    }

    /**
     * A classification we recognise and DELIBERATELY do not alert on
     * (restore, retention, deep-verify, …). Distinct from an unrecognised
     * value: deliberate ignores are logged quietly, unrecognised values are
     * a loud warning — silent non-matching is the exact psa-enpew defect.
     */
    public static function isKnownNonBackupClassification(mixed $classification): bool
    {
        if (self::isBackupClassification($classification)) {
            return false;
        }

        return (is_int($classification)
                && $classification >= \Comet\Def::JOB_CLASSIFICATION__MIN
                && $classification <= \Comet\Def::JOB_CLASSIFICATION__MAX)
            || $classification === self::LEGACY_CLASSIFICATION_RESTORE
            || $classification === self::LEGACY_CLASSIFICATION_RETENTION;
    }

    /**
     * Canonical dedup/resolve key for one backup series: device + protected
     * item (SourceGUID) + storage vault (DestinationGUID). The classification
     * token is the literal 'backup' — NEVER the raw code — so a failure raised
     * under one classification scale is resolved by a success under the other.
     */
    public static function backupSeriesKey(string $deviceId, string $sourceGuid, string $destinationGuid): string
    {
        return "{$deviceId}:{$sourceGuid}:{$destinationGuid}:backup";
    }

    public static function isSuccessStatus(int $status): bool
    {
        return $status >= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MIN
            && $status <= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MAX;
    }

    public static function isRunningStatus(int $status): bool
    {
        return $status >= \Comet\Def::JOB_STATUS_RUNNING__MIN
            && $status <= \Comet\Def::JOB_STATUS_RUNNING__MAX;
    }

    public static function isFailedStatus(int $status): bool
    {
        return $status >= \Comet\Def::JOB_STATUS_FAILED__MIN
            && $status <= \Comet\Def::JOB_STATUS_FAILED__MAX;
    }

    /**
     * Status RANGES per vendor Comet/Def.php:708-841. An unrecognised code
     * maps to 'unknown', which callers must treat as not-good, never passing.
     */
    public static function statusCategory(?int $status): string
    {
        return match (true) {
            $status === null => 'unknown',
            self::isSuccessStatus($status) => 'success',
            self::isRunningStatus($status) => 'running',
            self::isFailedStatus($status) => 'failed',
            default => 'unknown',
        };
    }

    /** Human labels aligned with CometReadOnlyToolset (psa-z30dv): 'Success', not 'Completed'. */
    public static function statusLabel(?int $status): string
    {
        return match (true) {
            $status === \Comet\Def::JOB_STATUS_STOP_SUCCESS => 'Success',
            $status === \Comet\Def::JOB_STATUS_FAILED_TIMEOUT => 'Failed (timeout)',
            $status === \Comet\Def::JOB_STATUS_FAILED_WARNING => 'Completed with warnings',
            $status === \Comet\Def::JOB_STATUS_FAILED_ERROR => 'Failed (error)',
            $status === \Comet\Def::JOB_STATUS_FAILED_QUOTA => 'Failed (quota exceeded)',
            $status === \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED => 'Failed (missed schedule)',
            $status === \Comet\Def::JOB_STATUS_FAILED_CANCELLED => 'Cancelled',
            $status === \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING => 'Skipped (already running)',
            $status === \Comet\Def::JOB_STATUS_FAILED_ABANDONED => 'Abandoned',
            default => match (self::statusCategory($status)) {
                'success' => "Success (code {$status})",
                'running' => 'Running',
                'failed' => "Failed (code {$status})",
                default => "Unknown (code {$status})",
            },
        };
    }

    /**
     * Classification labels per vendor Comet/Def.php:610-701, plus the legacy
     * small-int scale. An out-of-catalog value says interpretation failed —
     * never a benign-looking 'Other'.
     */
    public static function classificationLabel(?int $classification): string
    {
        return match ($classification) {
            \Comet\Def::JOB_CLASSIFICATION_BACKUP, self::LEGACY_CLASSIFICATION_BACKUP => 'Backup',
            \Comet\Def::JOB_CLASSIFICATION_RESTORE, self::LEGACY_CLASSIFICATION_RESTORE => 'Restore',
            \Comet\Def::JOB_CLASSIFICATION_RETENTION, self::LEGACY_CLASSIFICATION_RETENTION => 'Retention',
            \Comet\Def::JOB_CLASSIFICATION_DEEPVERIFY => 'Deep verify',
            \Comet\Def::JOB_CLASSIFICATION_UNKNOWN => 'Unknown type',
            \Comet\Def::JOB_CLASSIFICATION_UNLOCK => 'Unlock',
            \Comet\Def::JOB_CLASSIFICATION_DELETE_CUSTOM => 'Delete (custom)',
            \Comet\Def::JOB_CLASSIFICATION_REMEASURE => 'Re-measure',
            \Comet\Def::JOB_CLASSIFICATION_UPDATE => 'Software update',
            \Comet\Def::JOB_CLASSIFICATION_IMPORT => 'Import',
            \Comet\Def::JOB_CLASSIFICATION_REINDEX => 'Re-index',
            \Comet\Def::JOB_CLASSIFICATION_UNINSTALL => 'Uninstall',
            null => 'No job type reported',
            default => "Unrecognized job type (code {$classification})",
        };
    }
}
