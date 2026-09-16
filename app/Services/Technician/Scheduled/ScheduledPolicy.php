<?php

namespace App\Services\Technician\Scheduled;

use App\Models\McpToken;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use InvalidArgumentException;

final class ScheduledPolicy
{
    public const MAX_TRANSPORT_SECONDS = 610;

    public const RECEIPT_GRACE_SECONDS = 30;

    public const OVERLAP_LOCK = 'scheduled-approvals:sweep-drain';

    // Explicit bounded lease: only the database store substitutes a default expiry, so
    // on file/redis/memcached a SIGKILLed holder would otherwise block recovery, note
    // delivery and the drain forever. release() remains owner-checked, so an expired
    // lease is never force-released by a peer. Size alone can NEVER make the lease
    // "longer than any run": 100 rows each bounded at MAX_TRANSPORT_SECONDS outlast any
    // sane expiry, so holders bound their own lock-held work below instead.
    public const OVERLAP_LOCK_SECONDS = 3600;

    /**
     * Latest elapsed second at which a lock holder may START another unit of work. The
     * reserve is twice the per-unit worst case (one bounded transport plus its receipt
     * grace), covering the in-flight unit and the local recovery/evidence reads around
     * it, so a live holder finishes inside OVERLAP_LOCK_SECONDS however long its queue
     * is and no second sweep or drain can run against its in-flight rows.
     */
    public const OVERLAP_WORK_SECONDS = self::OVERLAP_LOCK_SECONDS - 2 * (self::MAX_TRANSPORT_SECONDS + self::RECEIPT_GRACE_SECONDS);

    public function approver(int $id): User
    {
        $user = User::find($id);
        if (! $user || ! $user->is_active || ! ($user->isAdmin() || $user->isTech())) {
            throw new InvalidArgumentException('approver_revoked');
        }

        return $user;
    }

    public function lineage(TechnicianRun $run, ?int $tokenId): void
    {
        // No legacy token labels or caller-supplied IDs are grandfathered. Staging
        // instrumentation must persist this provenance before adapters can enroll.
        $provenance = $run->proposed_meta['scheduled_provenance'] ?? null;
        if (! is_array($provenance) || ($provenance['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('provenance_missing');
        }
        if ($tokenId === null) {
            if (($provenance['kind'] ?? null) !== 'native_human' || ! is_int($provenance['user_id'] ?? null)) {
                throw new InvalidArgumentException('provenance_missing');
            }
            $this->approver($provenance['user_id']);

            return;
        }
        if (($provenance['kind'] ?? null) !== 'mcp' || ($provenance['token_id'] ?? null) !== $tokenId) {
            throw new InvalidArgumentException('lineage_mismatch');
        }
        $token = McpToken::find($tokenId);
        $tool = ActionRegistry::directTool($run->action_type);
        if (! $token || ! $token->isActive() || ! is_array($token->tools)
            || ! in_array($tool.':staged', $token->tools, true)) {
            // Deliberately require the explicit staged capability; no null/full-surface fallback.
            throw new InvalidArgumentException('lineage_revoked');
        }
    }

    public function ticket(TechnicianRun $run): void
    {
        $ticket = Ticket::find($run->ticket_id);
        if (! $ticket || (int) $ticket->client_id !== (int) $run->client_id || ! $run->client_id) {
            throw new InvalidArgumentException('ticket_binding_changed');
        }
    }
}
