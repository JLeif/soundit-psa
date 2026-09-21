<?php

namespace App\Services\Agent\Escalation;

/** Verdict for the sanitizer input only, not the surrounding message or transport. */
final class OperatorScanMetadata
{
    /** @param list<string> $classes */
    public function __construct(
        public readonly bool $withheld,
        public readonly bool $truncated,
        public readonly int $totalChars,
        private readonly array $classes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scan_status' => 'assessed',
            'text_withheld' => $this->withheld,
            'text_truncated' => $this->truncated,
            'text_total_chars' => $this->totalChars,
            // Explicit allowlist: never serialize scanner patterns, matches or future classes.
            'scan_classes' => array_values(array_unique(array_intersect($this->classes, ['credential', 'injection', 'marker']))),
        ];
    }
}
