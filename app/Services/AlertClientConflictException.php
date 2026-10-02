<?php

namespace App\Services;

/**
 * AlertService::upsert refused to revive a resolved alert because the incoming
 * occurrence names a different client than the row that owns the key, or
 * names no client while that row has one.
 *
 * A RuntimeException subclass, so a caller that already catches
 * RuntimeException keeps working; a caller that wants to answer with a clean
 * refusal (an HTTP 422, say) can catch this class specifically. It is thrown
 * before any write, so nothing about the existing row changes.
 */
class AlertClientConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $alertId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
