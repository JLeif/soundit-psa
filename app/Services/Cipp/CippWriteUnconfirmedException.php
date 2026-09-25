<?php

namespace App\Services\Cipp;

/**
 * A CIPP write whose request LEFT this process and whose answer did not
 * confirm it. Distinct from a plain CippClientException, which callers on the
 * licence path read as "nothing was sent".
 *
 * $outcome says what the answer does establish:
 *  - NOT_APPLIED: upstream named a reason it made no write for this user.
 *  - UNKNOWN: upstream may have written; nothing in the answer says either way.
 *
 * Only a bounded, control-stripped upstream line is kept — never the body.
 *
 * Used today ONLY by CippRestWriteClient's licence methods. Converting the
 * other post-send throws (setGroupMembership, reassignOneDriveOwnership,
 * editUser) belongs to issue #3709.
 */
final class CippWriteUnconfirmedException extends CippClientException
{
    public const NOT_APPLIED = 'not_applied';

    public const UNKNOWN = 'unknown';

    private const UPSTREAM_LINE_MAX = 200;

    public readonly ?string $upstreamLine;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $outcome,
        ?string $upstreamLine = null,
        public readonly bool $usageLocationMayHaveChanged = false,
    ) {
        if (! in_array($outcome, [self::NOT_APPLIED, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException("Unknown unconfirmed-write outcome {$outcome}");
        }

        $this->upstreamLine = $upstreamLine === null ? null : self::bound($upstreamLine);

        parent::__construct(
            "CIPP write {$endpoint} was sent but not confirmed ({$outcome})"
            .($this->upstreamLine !== null ? '; upstream: '.$this->upstreamLine : '')
        );
    }

    private static function bound(string $line): string
    {
        $flat = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line));

        return mb_substr($flat, 0, self::UPSTREAM_LINE_MAX);
    }
}
