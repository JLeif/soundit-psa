<?php

namespace App\Services\ControlD;

/** A POST was explicitly rejected by the vendor envelope (HTTP 4xx), not an unknown write. */
class ControlDWriteRejectedException extends ControlDClientException
{
    public function __construct(string $message, public readonly ?int $reasonCode = null, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public function isReadOnlyKey(): bool
    {
        return $this->httpStatus === 403 && $this->reasonCode === 40301;
    }
}
