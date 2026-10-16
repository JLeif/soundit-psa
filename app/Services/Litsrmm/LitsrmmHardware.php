<?php

namespace App\Services\Litsrmm;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What LITSRMM's agent reported about a machine's insides, as the asset
 * columns a Level-fed asset already carries. Pure: no I/O, no model.
 *
 * WHERE IT COMES FROM. `GET /v1/devices/{id}` carries an `inventory` object
 * keyed by category, each `{collectedAt, payload}`. Four categories are
 * served: hardware, disks, network, system. The list endpoint carries none of
 * this, which is why the detail call exists at all.
 *
 * ABSENT IS NOT NULL. A category that was never collected is left out of the
 * detail response entirely, and a device without the vendor's agent has no
 * inventory at all. columns() therefore returns ONLY the columns this read
 * actually knows, so a sync never overwrites a value the asset already holds
 * with a null that means "nobody asked".
 *
 * A malformed category writes none of its fields. A category is malformed
 * when it is present and not null but its payload is not a JSON object
 * (hardware, system) or list (disks, network), or when a key this class
 * reads is present, not null and of another type: ramBytes, totalBytes and
 * freeBytes numbers; cpu and drive strings; pendingReboot and up booleans;
 * bootTimeUtc a time instant() can read; each disk and adapter an object;
 * ipv4 a list of strings. An IPv4 string that is not usable (link-local,
 * reserved, not an address) is skipped, not drift. A null category, a
 * missing, null or empty payload, and missing or null keys are absent, not
 * drift. Other categories remain readable. $malformed names each category
 * once for drift reporting.
 */
final class LitsrmmHardware
{
    private const GIB = 1024 * 1024 * 1024;

    private function __construct(
        public readonly ?string $cpu,
        public readonly ?float $ramGb,
        public readonly ?string $diskSummary,
        public readonly ?string $ipAddress,
        public readonly ?DateTimeImmutable $lastBootAt,
        public readonly ?bool $needsReboot,
        /** @var list<string> skipped categories, ordered hardware, disks, network, system */
        public readonly array $malformed = [],
    ) {}

    public static function fromInventory(array $inventory): self
    {
        $hardware = self::payload($inventory, 'hardware');
        $disks = self::payload($inventory, 'disks');
        $network = self::payload($inventory, 'network');
        $system = self::payload($inventory, 'system');

        $invalid = [
            'hardware' => (isset($hardware['ramBytes']) && ! is_int($hardware['ramBytes']) && ! is_float($hardware['ramBytes']))
                || (isset($hardware['cpu']) && ! is_string($hardware['cpu']))
                || ($hardware !== null && $hardware !== [] && array_is_list($hardware)),
            'disks' => $disks !== null && (! array_is_list($disks) || array_filter($disks, static fn ($disk) => ! is_array($disk)
                || (isset($disk['totalBytes']) && ! is_int($disk['totalBytes']) && ! is_float($disk['totalBytes']))
                || (isset($disk['freeBytes']) && ! is_int($disk['freeBytes']) && ! is_float($disk['freeBytes']))
                || (isset($disk['drive']) && ! is_string($disk['drive']))) !== []),
            'network' => $network !== null && (! array_is_list($network) || array_filter($network, static fn ($adapter) => ! is_array($adapter)
                || (isset($adapter['ipv4']) && (! is_array($adapter['ipv4']) || ! array_is_list($adapter['ipv4'])
                    || array_filter($adapter['ipv4'], static fn ($ip) => ! is_string($ip)) !== []))
                || (isset($adapter['up']) && ! is_bool($adapter['up']))) !== []),
            'system' => (isset($system['bootTimeUtc']) && self::instant($system['bootTimeUtc']) === null)
                || (isset($system['pendingReboot']) && ! is_bool($system['pendingReboot']))
                || ($system !== null && $system !== [] && array_is_list($system)),
        ];
        $malformed = [];
        foreach ($invalid as $category => $unreadable) {
            $entry = $inventory[$category] ?? null;
            $present = $entry !== null && (! is_array($entry) || ($entry['payload'] ?? null) !== null);
            if ($unreadable || ($present && self::payload($inventory, $category) === null)) {
                $malformed[] = $category;
            }
        }
        $hardware = in_array('hardware', $malformed, true) ? null : $hardware;
        $disks = in_array('disks', $malformed, true) ? null : $disks;
        $network = in_array('network', $malformed, true) ? null : $network;
        $system = in_array('system', $malformed, true) ? null : $system;

        return new self(
            cpu: self::nonEmptyString($hardware['cpu'] ?? null),
            ramGb: self::ramGb($hardware['ramBytes'] ?? null),
            diskSummary: self::diskSummary($disks),
            ipAddress: self::ipAddress($network),
            lastBootAt: self::instant($system['bootTimeUtc'] ?? null),
            needsReboot: is_bool($system['pendingReboot'] ?? null) ? $system['pendingReboot'] : null,
            malformed: $malformed,
        );
    }

    /**
     * Only the columns this read knows. Keys are asset column names.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return array_filter([
            'cpu' => $this->cpu,
            'ram_gb' => $this->ramGb,
            'disk_summary' => $this->diskSummary,
            'ip_address' => $this->ipAddress,
            'last_boot_at' => $this->lastBootAt,
            'needs_reboot' => $this->needsReboot,
        ], static fn ($value) => $value !== null);
    }

    /** The payload of one category, or null when absent or not a JSON object/list. */
    private static function payload(array $inventory, string $category): ?array
    {
        $entry = $inventory[$category] ?? null;

        if (! is_array($entry) || ! is_array($entry['payload'] ?? null)) {
            return null;
        }

        return $entry['payload'];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function ramGb(mixed $bytes): ?float
    {
        if (! is_int($bytes) && ! is_float($bytes)) {
            return null;
        }

        return $bytes > 0 ? round($bytes / self::GIB, 2) : null;
    }

    /**
     * "C: 447 GB (21% free), D: 932 GB (50% free)". The same format and the
     * same rounding as LevelSyncService::resolveDiskSummary() (free percent is
     * taken from the ROUNDED gigabytes), so an asset moved from Level to
     * LITSRMM does not change its disk line for no reason.
     */
    private static function diskSummary(?array $volumes): ?string
    {
        if ($volumes === null || ! array_is_list($volumes)) {
            return null;
        }

        $parts = [];

        foreach ($volumes as $volume) {
            if (! is_array($volume)) {
                continue;
            }

            $total = $volume['totalBytes'] ?? null;

            if (! is_int($total) && ! is_float($total)) {
                continue;
            }

            $sizeGb = round($total / self::GIB);
            $part = "{$sizeGb} GB";

            $free = $volume['freeBytes'] ?? null;

            if ((is_int($free) || is_float($free)) && $sizeGb > 0) {
                $freeGb = round($free / self::GIB);
                $part .= ' ('.round(($freeGb / $sizeGb) * 100).'% free)';
            }

            $drive = self::nonEmptyString($volume['drive'] ?? null);
            $parts[] = $drive !== null ? "{$drive} {$part}" : $part;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The first IPv4 on an adapter that is up, then on any adapter.
     * Never a loopback (127/8), 0/8, 240/4 or 169.254/16 link-local address:
     * link-local is what Windows assigns when DHCP failed, and none of them
     * says where the machine is.
     */
    private static function ipAddress(?array $adapters): ?string
    {
        if ($adapters === null || ! array_is_list($adapters)) {
            return null;
        }

        $candidates = [];

        foreach ($adapters as $adapter) {
            if (! is_array($adapter) || ! is_array($adapter['ipv4'] ?? null)) {
                continue;
            }

            foreach ($adapter['ipv4'] as $ip) {
                if (is_string($ip)
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) !== false
                    && ! str_starts_with($ip, '169.254.')) {
                    $candidates[] = ['ip' => $ip, 'up' => ($adapter['up'] ?? null) === true];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['up']) {
                return $candidate['ip'];
            }
        }

        return $candidates[0]['ip'] ?? null;
    }

    /** ISO 8601 UTC ending in Z, a real calendar date and time; anything else, a leap second (:60) included, is dropped rather than guessed at. */
    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?Z$/D', $value, $m) !== 1) {
            return null;
        }

        $fraction = str_pad(substr($m[2] ?? '', 0, 6), 6, '0');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u', $m[1].'.'.$fraction, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }
}
