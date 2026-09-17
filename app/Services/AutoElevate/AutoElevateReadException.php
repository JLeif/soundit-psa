<?php

namespace App\Services\AutoElevate;

/**
 * A degraded AutoElevate read. Thrown instead of returning a partial or empty list
 * (C-56: a degraded read must SCREAM). The message is a fixed, operator-safe label;
 * vendor bodies, headers, exception text and the key are never carried.
 */
class AutoElevateReadException extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("AutoElevate read failed: {$reason}", 0, $previous);
    }
}
