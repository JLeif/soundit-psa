<?php

namespace App\Services\ControlD;

/** No automatic retry/cleanup: reconcile this organization and PK first. No secrets or chained vendor exceptions. */
class ControlDWriteUncertainException extends ControlDClientException
{
    public function __construct(
        public readonly string $orgPk,
        public readonly ?string $provisionPk,
        public readonly string $phase,
    ) {
        parent::__construct('Control D write outcome is uncertain; reconcile before retrying.');
    }
}
