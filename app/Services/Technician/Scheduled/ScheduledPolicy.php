<?php

namespace App\Services\Technician\Scheduled;

use App\Models\McpToken;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use InvalidArgumentException;

final class ScheduledPolicy
{
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
