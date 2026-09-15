<?php

namespace App\Services\Technician\Scheduled;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Resolve wall times without PHP's silent DST gap/fold normalisation. */
final readonly class ApprovalWindow
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $zone,
        public string $localStart,
        public string $localEnd,
        public int $startOffset,
        public int $endOffset,
    ) {}

    public static function fromLocal(string $start, string $end, string $zone, DateTimeImmutable $now): self
    {
        if (! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }
        $tz = new DateTimeZone($zone);
        $a = self::resolve($start, $tz);
        $b = self::resolve($end, $tz);
        if ($a <= $now || $a->getTimestamp() > $now->getTimestamp() + 604800 || $b <= $a || $b->getTimestamp() - $a->getTimestamp() > 86400) {
            throw new InvalidArgumentException('invalid_window');
        }

        return new self($a, $b, $zone, $start, $end, $tz->getOffset($a), $tz->getOffset($b));
    }

    private static function resolve(string $local, DateTimeZone $zone): DateTimeImmutable
    {
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
        if (! $wall || $wall->format('Y-m-d H:i:s') !== $local) {
            throw new InvalidArgumentException('invalid_local_time');
        }
        $candidates = [];
        foreach ($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) as $transition) {
            $candidate = $wall->setTimestamp($wall->getTimestamp() - $transition['offset']);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $local) {
                $candidates[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($candidates) !== 1) {
            throw new InvalidArgumentException('ambiguous_or_nonexistent_local_time');
        }

        return array_values($candidates)[0];
    }
}
