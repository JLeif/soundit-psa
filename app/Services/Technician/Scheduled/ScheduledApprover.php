<?php

namespace App\Services\Technician\Scheduled;

use InvalidArgumentException;

/**
 * WHO authorised a scheduled authorization — a human approver, or the MCP token itself.
 *
 * The ruled design's immediate lane (point 3) admits a `:immediate`-granted token's
 * `execute_at` call straight into `scheduled_authorizations` with `approver_user_id`
 * NULL: there is no human in the loop, by Charlie's explicit instruction. That absence
 * must be EXPLICIT and TYPED rather than smuggled through the admission API as a
 * stand-in user id. A `0`, the system user or the AI actor passed as the approver would
 * forge human provenance into an authenticated, sealed envelope and would defeat the
 * very lineage rule the token lane depends on, because a row that claims a human
 * approver gets the looser `:staged`-or-`:immediate` fire-time check.
 *
 * So the two modes are one closed value type. `userId` and `tokenId` are mutually
 * exclusive and neither can be zero; the mode is read from the object, never inferred
 * from a sentinel. Every consumer that must branch on "was a human involved" reads
 * isToken() and cannot accidentally get the wrong answer from a falsy integer.
 */
final readonly class ScheduledApprover
{
    private function __construct(public ?int $userId, public ?int $tokenId) {}

    /** The ordinary cockpit Approve: a named, active Admin/Tech clicked it. */
    public static function human(int $userId): self
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('approver_revoked');
        }

        return new self($userId, null);
    }

    /**
     * The immediate lane: the MCP token that holds `<tool>:immediate` queued this itself.
     * No human approved it — the token's own standing grant is the whole authority, and
     * ScheduledPolicy::lineage() therefore requires that exact grant to still be held at
     * fire time.
     */
    public static function token(int $tokenId): self
    {
        if ($tokenId <= 0) {
            throw new InvalidArgumentException('lineage_mismatch');
        }

        return new self(null, $tokenId);
    }

    /**
     * Rehydrate from a persisted authorization row. A row whose approver_user_id is NULL
     * is token-approved BY DEFINITION — that column is the record of the decision, so
     * reading the mode back from it can never disagree with what was sealed.
     */
    public static function fromRow(?int $approverUserId, ?int $originatingTokenId): self
    {
        if ($approverUserId !== null) {
            return self::human($approverUserId);
        }
        if ($originatingTokenId === null) {
            // Neither a human nor a token: an unauthorised row. Refuse rather than
            // resolve to some permissive default.
            throw new InvalidArgumentException('approver_revoked');
        }

        return self::token($originatingTokenId);
    }

    public function isToken(): bool
    {
        return $this->userId === null;
    }
}
