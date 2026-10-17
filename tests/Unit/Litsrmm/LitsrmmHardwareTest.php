<?php

namespace Tests\Unit\Litsrmm;

use App\Services\Litsrmm\LitsrmmHardware;
use PHPUnit\Framework\TestCase;

/**
 * The `inventory` block of LITSRMM `GET /v1/devices/{id}`, turned into the
 * asset columns a Level-fed asset already carries.
 *
 * The payload below follows the shape of the vendor's inventory. Its identifying values are synthetic: documentation-range MACs, documentation-range or link-local IPs, round byte counts, an all-zero-prefixed GUID, and placeholder CPU, manufacturer and board names. The adapter descriptions and 'System Product Name' are generic driver and firmware defaults that identify no machine. Expected values were worked out by hand from it, not read back
 * from the code.
 */
class LitsrmmHardwareTest extends TestCase
{
    private static function inventory(): array
    {
        return [
            'hardware' => ['collectedAt' => '2026-09-30T10:00:00.000Z', 'payload' => [
                'cpu' => 'Example CPU',
                'ramBytes' => 15000000000,
                'manufacturer' => 'Example Manufacturer',
                'model' => 'System Product Name',
                'board' => 'Example Board',
                'machineGuid' => '00000000-0000-4000-8000-0000000000b1',
            ]],
            'disks' => ['collectedAt' => '2026-09-30T10:00:00.000Z', 'payload' => [
                ['drive' => 'C:', 'label' => null, 'fileSystem' => 'NTFS', 'totalBytes' => 480000000000, 'freeBytes' => 100000000000],
            ]],
            'network' => ['collectedAt' => '2026-09-30T10:00:00.000Z', 'payload' => [
                ['name' => 'Ethernet', 'description' => 'Realtek PCIe GbE Family Controller', 'mac' => '00:00:5E:00:53:01', 'ipv4' => ['192.0.2.10'], 'up' => true],
                ['name' => 'Bluetooth Network Connection', 'description' => 'Bluetooth Device (Personal Area Network)', 'mac' => '00:00:5E:00:53:02', 'ipv4' => ['169.254.0.20'], 'up' => false],
            ]],
            'system' => ['collectedAt' => '2026-09-30T10:00:00.000Z', 'payload' => [
                'bootTimeUtc' => '2026-09-30T03:04:05Z',
                'pendingReboot' => true,
                'pendingRebootReasons' => ['pendingFileRename'],
            ]],
        ];
    }

    public function test_a_full_inventory_maps_to_the_columns_a_level_asset_carries(): void
    {
        $hw = LitsrmmHardware::fromInventory(self::inventory());

        $this->assertSame('Example CPU', $hw->cpu);
        // 15000000000 / 1024^3 = 13.969... -> 13.97
        $this->assertSame(13.97, $hw->ramGb);
        // 447.03 -> 447 GB total, 93.13 -> 93 GB free, 93/447 = 20.8 -> 21% - the same rounding LevelSyncService uses.
        $this->assertSame('C: 447 GB (21% free)', $hw->diskSummary);
        $this->assertSame('192.0.2.10', $hw->ipAddress);
        $this->assertSame('2026-09-30T03:04:05+00:00', $hw->lastBootAt?->format(DATE_ATOM));
        $this->assertTrue($hw->needsReboot);
    }

    public function test_the_columns_it_writes_are_only_the_ones_it_knows(): void
    {
        $this->assertSame(
            ['cpu', 'ram_gb', 'disk_summary', 'ip_address', 'last_boot_at', 'needs_reboot'],
            array_keys(LitsrmmHardware::fromInventory(self::inventory())->columns()),
        );
    }

    public function test_a_category_never_collected_writes_nothing_rather_than_null(): void
    {
        // Absent means nothing ever asked the machine. Writing null would erase
        // what the asset already knows and claim the machine reported nothing.
        $inventory = self::inventory();
        unset($inventory['disks'], $inventory['system']);

        $columns = LitsrmmHardware::fromInventory($inventory)->columns();

        $this->assertArrayNotHasKey('disk_summary', $columns);
        $this->assertArrayNotHasKey('last_boot_at', $columns);
        $this->assertArrayNotHasKey('needs_reboot', $columns);
        $this->assertSame('192.0.2.10', $columns['ip_address']);
    }

    public function test_an_agentless_device_has_no_columns_at_all(): void
    {
        $this->assertSame([], LitsrmmHardware::fromInventory([])->columns());
    }

    public function test_a_link_local_address_is_never_chosen(): void
    {
        // 169.254/16 is Windows' "DHCP failed" address; it identifies nothing.
        // For 169.254/16 the flag and the explicit check overlap, so deleting the explicit check alone is an equivalent mutant. Deleting the flag is not: test_reserved_ipv4_ranges_are_never_chosen kills it.
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Wi-Fi', 'mac' => '00:00:5E:00:53:FF', 'ipv4' => ['169.254.1.2'], 'up' => true],
        ];

        $this->assertNull(LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedIpv4Ranges')]
    public function test_reserved_ipv4_ranges_are_never_chosen(string $ip): void
    {
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Wi-Fi', 'ipv4' => [$ip], 'up' => true],
            ['name' => 'Ethernet', 'ipv4' => ['192.0.2.10'], 'up' => true],
        ];

        $this->assertSame('192.0.2.10', LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    public static function reservedIpv4Ranges(): array
    {
        return [
            '0.0.0.0' => ['0.0.0.0'],
            '0.1.2.3' => ['0.1.2.3'],
            '240.0.0.1' => ['240.0.0.1'],
            '255.255.255.255' => ['255.255.255.255'],
        ];
    }

    public function test_an_adapter_that_is_up_wins_over_one_listed_first_that_is_down(): void
    {
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Old VPN', 'mac' => '00:00:5E:00:53:11', 'ipv4' => ['198.51.100.2'], 'up' => false],
            ['name' => 'Ethernet', 'mac' => '00:00:5E:00:53:12', 'ipv4' => ['192.0.2.10'], 'up' => true],
        ];

        $this->assertSame('192.0.2.10', LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    public function test_an_up_loopback_adapter_does_not_win_over_ethernet(): void
    {
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Loopback', 'ipv4' => ['127.0.0.1'], 'up' => true],
            ['name' => 'Ethernet', 'ipv4' => ['192.0.2.10'], 'up' => true],
        ];

        $this->assertSame('192.0.2.10', LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    public function test_a_calendar_invalid_boot_time_drops_the_system_category(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = '2026-09-31T03:00:00Z';
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->lastBootAt);
        $this->assertNull($hw->needsReboot);
        $this->assertSame(['system'], $hw->malformed);
    }

    public function test_a_boot_time_with_a_trailing_newline_drops_the_system_category(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = '2026-09-30T03:04:05Z'."\n";
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->lastBootAt);
        $this->assertNull($hw->needsReboot);
        $this->assertSame(['system'], $hw->malformed);
    }

    public function test_a_valid_leap_day_boot_time_is_kept(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = '2028-02-29T00:00:00Z';

        $this->assertSame('2028-02-29T00:00:00+00:00', LitsrmmHardware::fromInventory($inventory)->lastBootAt?->format(DATE_ATOM));
    }

    public function test_a_24_hour_boot_time_drops_the_system_category(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = '2026-09-30T24:00:00Z';
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->lastBootAt);
        $this->assertNull($hw->needsReboot);
        $this->assertSame(['system'], $hw->malformed);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bootFractions')]
    public function test_boot_time_fractions_are_padded_and_truncated(string $value, string $expected): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = $value;
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertSame($expected, $hw->lastBootAt?->format('Y-m-d\TH:i:s.u\Z'));
    }

    public static function bootFractions(): array
    {
        return [
            'one digit is tenths' => ['2026-09-24T01:00:50.5Z', '2026-09-24T01:00:50.500000Z'],
            'seven digits' => ['2026-09-24T01:00:50.3310000Z', '2026-09-24T01:00:50.331000Z'],
            'nine digits' => ['2026-09-24T01:00:50.123456789Z', '2026-09-24T01:00:50.123456Z'],
        ];
    }

    public function test_every_fixed_volume_is_listed(): void
    {
        $inventory = self::inventory();
        $inventory['disks']['payload'][] = ['drive' => 'D:', 'label' => 'Data', 'fileSystem' => 'NTFS', 'totalBytes' => 1000500000000, 'freeBytes' => 500000000000];

        // D: 931.79 -> 932 GB, free 465.66 -> 466 GB, 466/932 = 50.0 -> 50%.
        $this->assertSame('C: 447 GB (21% free), D: 932 GB (50% free)', LitsrmmHardware::fromInventory($inventory)->diskSummary);
    }

    public function test_a_malformed_payload_is_ignored_not_fatal(): void
    {
        // One bad category must not cost the asset the others.
        $inventory = self::inventory();
        $inventory['hardware']['payload'] = 'not an object';
        $inventory['disks'] = 'nonsense';

        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->cpu);
        $this->assertNull($hw->ramGb);
        $this->assertNull($hw->diskSummary);
        $this->assertSame('192.0.2.10', $hw->ipAddress);
        $this->assertSame(['hardware', 'disks'], $hw->malformed, 'named, so the sync can log the drift');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driftShapes')]
    public function test_l4_unreadable_shape_drops_the_whole_category(string $category, array $payload, array $fields): void
    {
        $inventory = self::inventory();
        $inventory[$category]['payload'] = $payload;
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertSame([$category], $hw->malformed);
        $stored = array_fill_keys($fields, 'old value');
        $merged = array_merge($stored, $hw->columns());
        foreach ($fields as $field) {
            $this->assertSame('old value', $merged[$field]);
        }
        foreach ([
            'cpu' => 'Example CPU',
            'ram_gb' => 13.97,
            'disk_summary' => 'C: 447 GB (21% free)',
            'ip_address' => '192.0.2.10',
            'needs_reboot' => true,
        ] as $field => $value) {
            if (! in_array($field, $fields, true)) {
                $this->assertSame($value, $hw->columns()[$field]);
            }
        }
        if (! in_array('last_boot_at', $fields, true)) {
            $this->assertNotNull($hw->columns()['last_boot_at']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('absentEntries')]
    public function test_h3_null_missing_and_empty_payloads_are_absent(array $inventory): void
    {
        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertSame([], $hw->malformed);
        $this->assertSame([], $hw->columns());
    }

    public static function absentEntries(): array
    {
        return [
            'null disks' => [['disks' => null]],
            'null system payload' => [['system' => ['collectedAt' => '2026-09-30T10:00:00Z', 'payload' => null]]],
            'missing payload' => [['system' => ['collectedAt' => '2026-09-30T10:00:00Z']]],
            'empty hardware object' => [['hardware' => ['payload' => []]]],
            'empty system object' => [['system' => ['payload' => []]]],
            'payload under another key' => [['hardware' => ['collectedAt' => '2026-09-30T10:00:00Z', 'data' => ['cpu' => 'Example CPU', 'ramBytes' => 15000000000]]]],
            'network payload under another key' => [['network' => ['collectedAt' => '2026-09-30T10:00:00Z', 'data' => [['ipv4' => ['192.0.2.10'], 'up' => true]]]]],
            'system payload under another key' => [['system' => ['collectedAt' => '2026-09-30T10:00:00Z', 'data' => ['bootTimeUtc' => '2026-09-30T03:04:05Z', 'pendingReboot' => true]]]],
            'empty category array' => [['disks' => []]],
            'empty disks list' => [['disks' => ['payload' => []]]],
            'empty network list' => [['network' => ['payload' => []]]],
            'unwrapped hardware payload' => [['hardware' => ['cpu' => 'Example CPU', 'ramBytes' => 15000000000]]],
            'unwrapped disks list' => [['disks' => [['drive' => 'C:', 'totalBytes' => 480000000000, 'freeBytes' => 100000000000]]]],
            'unwrapped network list' => [['network' => [['ipv4' => ['192.0.2.10'], 'up' => true]]]],
            'unwrapped system payload' => [['system' => ['bootTimeUtc' => '2026-09-30T03:04:05Z', 'pendingReboot' => true]]],
        ];
    }

    public function test_h3_non_array_entries_and_payloads_stay_malformed(): void
    {
        $this->assertSame(['disks'], LitsrmmHardware::fromInventory(['disks' => 'x'])->malformed);
        $this->assertSame(['system'], LitsrmmHardware::fromInventory(['system' => ['payload' => 'x']])->malformed);
    }

    /** #7161: a scalar category, or an array category whose payload is a scalar, is malformed; "", 0 and false are not absent. */
    #[\PHPUnit\Framework\Attributes\DataProvider('scalarShapes')]
    public function test_h12a_a_scalar_category_or_payload_is_malformed(array $inventory, string $category): void
    {
        // #7210: a scalar category, or an array category whose payload is a
        // scalar, has no field to write, so a second, readable category is sent
        // beside it and columns() must be exactly that category's columns.
        [$readable, $expected] = $category === 'disks'
            ? [['hardware' => ['payload' => ['cpu' => 'Example CPU', 'ramBytes' => 15000000000]]], ['cpu' => 'Example CPU', 'ram_gb' => 13.97]]
            : [['disks' => ['payload' => [['drive' => 'C:', 'totalBytes' => 480000000000, 'freeBytes' => 100000000000]]]], ['disk_summary' => 'C: 447 GB (21% free)']];
        $hw = LitsrmmHardware::fromInventory($inventory + $readable);

        $this->assertSame([$category], $hw->malformed, 'A category that is neither null nor an array, or whose payload is present and neither null nor an array, must be malformed.');
        $this->assertSame($expected, $hw->columns(), 'A scalar category or scalar payload must add no column, and the readable category beside it must still be read.');
    }

    public static function scalarShapes(): array
    {
        return [
            'empty string category' => [['hardware' => ''], 'hardware'],
            'zero category' => [['system' => 0], 'system'],
            'false category' => [['network' => false], 'network'],
            'empty string payload' => [['disks' => ['payload' => '']], 'disks'],
            'zero payload' => [['hardware' => ['payload' => 0]], 'hardware'],
            'false payload' => [['system' => ['collectedAt' => '2026-09-30T10:00:00Z', 'payload' => false]], 'system'],
        ];
    }

    /** #7163: a disk or adapter sent as a list has none of the keys this class reads, so it is skipped, not drift. */
    public function test_h12a_a_list_shaped_disk_or_adapter_is_skipped_not_drift(): void
    {
        $hw = LitsrmmHardware::fromInventory([
            'disks' => ['payload' => [['C:', 480000000000], ['drive' => 'D:', 'totalBytes' => 1000500000000, 'freeBytes' => 500000000000]]],
            // Neither adapter is up, so the first candidate wins: a read of the
            // list-shaped adapter would put 192.0.2.10 ahead of 192.0.2.11.
            'network' => ['payload' => [['192.0.2.10'], ['ipv4' => ['192.0.2.11']]]],
        ]);

        $this->assertSame([], $hw->malformed, 'A disk or adapter sent as a list must be skipped, not reported as drift.');
        // D: 931.79 -> 932 GB, free 465.66 -> 466 GB, 466/932 = 50.0 -> 50%.
        $this->assertSame('D: 932 GB (50% free)', $hw->diskSummary, 'A list-shaped disk must not stop the other disks in its category from being read.');
        $this->assertSame('192.0.2.11', $hw->ipAddress, 'A list-shaped adapter must be skipped, and the other adapters in its category still read.');
    }

    public function test_h3_filtered_ipv4_strings_are_not_drift(): void
    {
        $hw = LitsrmmHardware::fromInventory([
            'network' => ['payload' => [['ipv4' => ['169.254.1.1', 'not-an-ip']]]],
        ]);

        $this->assertSame([], $hw->malformed);
        $this->assertNull($hw->ipAddress);
    }

    public static function driftShapes(): array
    {
        return [
            'hardware list' => ['hardware', [['cpu' => 'Example CPU', 'ramBytes' => 15000000000]], ['cpu', 'ram_gb']],
            'system list' => ['system', [['bootTimeUtc' => '2026-09-30T03:04:05Z', 'pendingReboot' => true]], ['last_boot_at', 'needs_reboot']],
            'disk total string' => ['disks', [['totalBytes' => '480000000000']], ['disk_summary']],
            'disk string' => ['disks', ['C:'], ['disk_summary']],
            'disk free string' => ['disks', [['totalBytes' => 480000000000, 'freeBytes' => '1']], ['disk_summary']],
            'disk drive int' => ['disks', [['totalBytes' => 480000000000, 'drive' => 3]], ['disk_summary']],
            'reboot string' => ['system', ['bootTimeUtc' => '2026-09-30T03:04:05Z', 'pendingReboot' => 'yes'], ['last_boot_at', 'needs_reboot']],
            'ipv4 string' => ['network', [['ipv4' => '192.0.2.10']], ['ip_address']],
            'ipv4 element int' => ['network', [['ipv4' => [42]]], ['ip_address']],
            'up string' => ['network', [['ipv4' => ['192.0.2.10'], 'up' => 'true']], ['ip_address']],
            'adapter string' => ['network', ['eth0'], ['ip_address']],
            'disk null' => ['disks', [null], ['disk_summary']],
            'adapter null' => ['network', [null], ['ip_address']],
            'ipv4 object' => ['network', [['ipv4' => ['primary' => '192.0.2.10'], 'up' => true]], ['ip_address']],
            'boot leap second' => ['system', ['bootTimeUtc' => '2016-12-31T23:59:60Z', 'pendingReboot' => true], ['last_boot_at', 'needs_reboot']],
            'disks object' => ['disks', ['drive' => 'C:', 'totalBytes' => 480000000000], ['disk_summary']],
            'network object' => ['network', ['ipv4' => ['192.0.2.10'], 'up' => true], ['ip_address']],
            'ram string' => ['hardware', ['ramBytes' => '15000000000', 'cpu' => 'Example CPU'], ['ram_gb', 'cpu']],
            'cpu int' => ['hardware', ['ramBytes' => 15000000000, 'cpu' => 42], ['ram_gb', 'cpu']],
            'boot unreadable' => ['system', ['bootTimeUtc' => 'last Tuesday', 'pendingReboot' => true], ['last_boot_at', 'needs_reboot']],
        ];
    }

    public function test_l4_missing_and_null_known_keys_are_not_drift(): void
    {
        foreach ([[], ['cpu' => null, 'ramBytes' => null]] as $hardware) {
            foreach ([[], ['bootTimeUtc' => null]] as $system) {
                $hw = LitsrmmHardware::fromInventory([
                    'hardware' => ['payload' => $hardware],
                    'system' => ['payload' => $system],
                ]);
                $this->assertSame([], $hw->malformed);
                $this->assertSame([], $hw->columns());
            }
        }
    }

    public function test_an_absent_category_is_not_malformed(): void
    {
        $inventory = self::inventory();
        unset($inventory['disks']);

        $this->assertSame([], LitsrmmHardware::fromInventory($inventory)->malformed);
    }

    public function test_an_unparseable_boot_time_marks_the_system_category_malformed(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = 'last Tuesday';

        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->lastBootAt);
        $this->assertNull($hw->needsReboot, 'a malformed system category writes none of its fields');
        $this->assertSame(['system'], $hw->malformed);
    }
}
