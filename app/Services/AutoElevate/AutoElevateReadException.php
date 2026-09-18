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

    /**
     * One plain sentence for the operator staring at a label, or null when the label has no
     * useful elaboration and should stand alone. Only the reasons whose meaning is NOT
     * evident from the label itself are listed; a hint that merely restates the label is
     * noise. Operator-safe by construction: fixed strings, no vendor text, no key.
     */
    public function operatorHint(): ?string
    {
        return self::hintFor($this->reason);
    }

    /** @see operatorHint() — the same text, for a surface that only carries the label string. */
    public static function hintFor(string $reason): ?string
    {
        return match ($reason) {
            'paging_over_cap' => 'This company reports more machines than one read can collect (over 10,000). '
                .'Nothing was listed because a partial list would look complete.',
            'paging_count_mismatch' => 'AutoElevate returned a different number of distinct machines than it said it held, '
                .'so at least one machine is missing from what it sent. Retry; if it persists, the vendor is paging inconsistently.',
            'timestamp_implausible' => 'AutoElevate reported a check-in time outside any believable range, '
                .'so no machine was shown rather than showing a wrong date.',
            default => null,
        };
    }
}
