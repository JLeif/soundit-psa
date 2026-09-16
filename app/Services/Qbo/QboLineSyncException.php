<?php

namespace App\Services\Qbo;

/** Safe boundary for line-write failures: never carry SQL, bindings or a previous exception. */
class QboLineSyncException extends \RuntimeException
{
    public function __construct(
        public readonly int $lineIndex,
        public readonly string $exceptionClass,
    ) {
        parent::__construct('QBO invoice line sync failed.');
    }
}
