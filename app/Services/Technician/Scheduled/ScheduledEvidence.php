<?php

namespace App\Services\Technician\Scheduled;

use App\Models\TechnicianRun;
use App\Models\User;

/**
 * Read-only, action-specific policy, tenant/object and payload validation.
 * Implementations must compare immutable upstream identity, not a domain/UPN alone.
 * No implementation is bound in PR1: admission and preflight refuse unsupported work.
 */
interface ScheduledEvidence
{
    /** Return validated ['payload' => array, 'target' => array] or refuse. */
    public function approve(TechnicianRun $run, User $approver, array $humanInputs): array;

    /** Re-read current permissions/identities and return the same exact binding or refuse. */
    public function revalidate(TechnicianRun $run, User $approver, array $approved): array;
}
