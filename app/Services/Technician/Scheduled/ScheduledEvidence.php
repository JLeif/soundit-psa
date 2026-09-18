<?php

namespace App\Services\Technician\Scheduled;

use App\Models\TechnicianRun;
use App\Models\User;

/**
 * Read-only, action-specific policy, tenant/object and payload validation.
 * Implementations must compare immutable upstream identity, not a domain/UPN alone.
 * No implementation is bound in PR1: admission and preflight refuse unsupported work.
 *
 * $approver is NULL on the immediate lane (ruled design point 3), where an
 * `<tool>:immediate`-granted MCP token queued the row itself and no human ever approved
 * it. An implementation that needs approver-scoped authority MUST refuse a null approver
 * rather than substitute one; the two installed providers bind on the run's own payload
 * and live vendor identity and never read it.
 */
interface ScheduledEvidence
{
    /** Return validated ['payload' => array, 'target' => array] or refuse. */
    public function approve(TechnicianRun $run, ?User $approver, array $humanInputs): array;

    /** Re-read current permissions/identities and return the same exact binding or refuse. */
    public function revalidate(TechnicianRun $run, ?User $approver, array $approved): array;
}
