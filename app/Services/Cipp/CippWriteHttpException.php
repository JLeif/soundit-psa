<?php

namespace App\Services\Cipp;

/** Structured status only: never retain a credential-bearing response body. */
class CippWriteHttpException extends CippClientException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("CIPP write failed: HTTP {$status}");
    }
}
