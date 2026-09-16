<?php

namespace App\Services\ControlD;

/** No automatic retry or deletion: reconcile this attempt before another create. */
class ControlDOrganizationUncertainException extends ControlDClientException
{
    public function __construct(public readonly ?string $orgPk, public readonly string $phase)
    {
        parent::__construct('Control D organization outcome requires reconciliation; do not retry or delete automatically.');
    }
}
