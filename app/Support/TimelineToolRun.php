<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * A run of two or more tool entries that sit next to each other in the staff
 * timeline's display order (newest first) with nothing else between them. The
 * staff page renders it as one collapsed row that expands to the entries.
 */
final class TimelineToolRun
{
    /** @param list<object> $entries tool entries, newest first */
    public function __construct(public readonly array $entries) {}

    /**
     * Fold adjacent tool entries (stdClass with kind 'tool') into runs. A lone
     * tool entry, and every non-tool item, passes through unchanged and in order.
     */
    public static function fold(Collection $items): Collection
    {
        $out = [];
        $run = [];
        $flush = function () use (&$out, &$run): void {
            if (count($run) >= 2) {
                $out[] = new self($run);
            } else {
                array_push($out, ...$run);
            }
            $run = [];
        };
        foreach ($items as $item) {
            if ($item instanceof \stdClass && ($item->kind ?? null) === 'tool') {
                $run[] = $item;

                continue;
            }
            $flush();
            $out[] = $item;
        }
        $flush();

        return collect($out);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function newest(): object
    {
        return $this->entries[0];
    }

    public function oldest(): object
    {
        return $this->entries[count($this->entries) - 1];
    }

    /** Distinct tool names with their counts, in first-seen (newest-first) order. */
    public function toolCounts(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $name = (string) ($entry->tool ?? '') !== '' ? (string) $entry->tool : 'unknown tool';
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }
}
