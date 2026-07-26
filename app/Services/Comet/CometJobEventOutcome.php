<?php

namespace App\Services\Comet;

use App\Enums\CometJobDisposition;
use App\Models\Alert;

/**
 * The structured result of routing one Comet job-completed event: which
 * disposition it took, the alert row it touched (if any), and — for drops —
 * the bounded machine reason. The controller reports these fields verbatim,
 * so the HTTP response can never claim more than the service actually did.
 */
final class CometJobEventOutcome
{
    private function __construct(
        public readonly CometJobDisposition $disposition,
        public readonly ?Alert $alert = null,
        public readonly ?string $reason = null,
    ) {}

    public static function alertCreated(Alert $alert): self
    {
        return new self(CometJobDisposition::AlertCreated, $alert);
    }

    public static function alertRefired(Alert $alert): self
    {
        return new self(CometJobDisposition::AlertRefired, $alert);
    }

    public static function alertReopened(Alert $alert): self
    {
        return new self(CometJobDisposition::AlertReopened, $alert);
    }

    public static function alertResolved(Alert $alert): self
    {
        return new self(CometJobDisposition::AlertResolved, $alert);
    }

    public static function noOpenAlert(): self
    {
        return new self(CometJobDisposition::NoOpenAlert);
    }

    public static function staleIgnored(?Alert $alert = null): self
    {
        return new self(CometJobDisposition::StaleIgnored, $alert);
    }

    public static function ignoredNonBackup(): self
    {
        return new self(CometJobDisposition::IgnoredNonBackup);
    }

    public static function droppedUnmatched(string $reason): self
    {
        return new self(CometJobDisposition::DroppedUnmatched, null, $reason);
    }

    public static function alertsDisabled(): self
    {
        return new self(CometJobDisposition::AlertsDisabled);
    }
}
