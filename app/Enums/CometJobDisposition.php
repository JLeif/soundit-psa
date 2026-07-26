<?php

namespace App\Enums;

/**
 * What actually happened to one Comet job-completed webhook event (psa-enpew).
 *
 * The webhook controller reports this verbatim as the response `status`, so a
 * dropped or ignored event can never be presented as "processed" — the
 * collapsed processed+null response is how four months of dead alert coverage
 * stayed invisible. Every value is an explicit, deliberate outcome:
 *
 * - alert_created / alert_refired / alert_reopened — a failure raised or
 *   refreshed the series' alert row.
 * - alert_resolved — a strictly-newer success resolved the open alert.
 * - no_open_alert — a valid backup success with nothing to resolve (its event
 *   time still advances the series watermark when the series row exists).
 * - stale_ignored — a valid backup event at or before the recorded watermark
 *   (replay / out-of-order delivery), deliberately discarded.
 * - ignored_non_backup — a recognised non-backup classification
 *   (restore/retention/…), deliberately ignored without warning.
 * - dropped_unmatched — the payload could not be routed (malformed status,
 *   unrecognised classification, missing series identity, unorderable
 *   EndTime…). Always WARNING-logged and stamped for the operator card.
 * - alerts_disabled — the operator switch is off; nothing was attempted.
 */
enum CometJobDisposition: string
{
    case AlertCreated = 'alert_created';
    case AlertRefired = 'alert_refired';
    case AlertReopened = 'alert_reopened';
    case AlertResolved = 'alert_resolved';
    case NoOpenAlert = 'no_open_alert';
    case StaleIgnored = 'stale_ignored';
    case IgnoredNonBackup = 'ignored_non_backup';
    case DroppedUnmatched = 'dropped_unmatched';
    case AlertsDisabled = 'alerts_disabled';

    /**
     * The payload was fully understood and deliberately routed — the only
     * dispositions allowed to advance the operator's "last job event
     * recognized" stamp. A drop or a disabled switch is NOT recognition.
     */
    public function wasRouted(): bool
    {
        return ! in_array($this, [self::DroppedUnmatched, self::AlertsDisabled], true);
    }

    /** An active failure signal exists after this event (raised/refreshed/reopened). */
    public function raisedAlert(): bool
    {
        return in_array($this, [self::AlertCreated, self::AlertRefired, self::AlertReopened], true);
    }
}
