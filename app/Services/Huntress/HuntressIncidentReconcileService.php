<?php

namespace App\Services\Huntress;

/** Incident lifecycle polling requires a signed-event-validated link; text is never authority. */
class HuntressIncidentReconcileService extends HuntressLinkedReconcileService
{
    protected function recordType(): string
    {
        return 'incident_report';
    }
}
