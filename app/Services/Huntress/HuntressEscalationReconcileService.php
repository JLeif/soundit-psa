<?php

namespace App\Services\Huntress;

/** Escalation lifecycle polling requires a signed-event-validated link, never fuzzy matching. */
class HuntressEscalationReconcileService extends HuntressLinkedReconcileService
{
    protected function recordType(): string
    {
        return 'escalation';
    }
}
