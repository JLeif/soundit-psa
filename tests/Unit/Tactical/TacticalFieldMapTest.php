<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalFieldMap;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Amendment E (P4): the single source of truth for total_ram->GB,
 * boot_time->uptime, and the checks failing/total summary. Behaviour is locked
 * to what TriageToolExecutor produced before extraction (no AI-visible drift).
 */
class TacticalFieldMapTest extends TestCase
{
    public function test_ram_gb_reads_total_ram_as_gb_integer_not_bytes(): void
    {
        // Tactical's agent `total_ram` is an INTEGER COUNT OF GIGABYTES (source
        // v1.5.0 + live VM 105), NOT a byte count. 4 => 4.0 GB, 16 => 16.0 GB.
        $this->assertSame(4.0, TacticalFieldMap::ramGb(4));
        $this->assertSame(16.0, TacticalFieldMap::ramGb(16));
        // A string GB value (some serializers stringify it) still maps directly.
        $this->assertSame(8.0, TacticalFieldMap::ramGb('8'));
    }

    public function test_ram_gb_handles_null_and_zero(): void
    {
        $this->assertNull(TacticalFieldMap::ramGb(null));
        // 0 GB is a present-but-empty reading; treat as null (no RAM known).
        $this->assertNull(TacticalFieldMap::ramGb(0));
    }

    public function test_disk_size_to_gb_parses_formatted_strings(): void
    {
        // Tactical disk total/used/free are FORMATTED STRINGS ("X.Y GB"/TB/MB),
        // not byte counts (source v1.5.0 + live VM 105). Parse leading number+unit.
        $this->assertSame(19.3, TacticalFieldMap::diskSizeToGb('19.3 GB'));
        $this->assertSame(32.0, TacticalFieldMap::diskSizeToGb('32.0 GB'));
        // TB -> *1024, MB -> /1024, rounded to 1 decimal.
        $this->assertSame(2048.0, TacticalFieldMap::diskSizeToGb('2.0 TB'));
        $this->assertSame(0.5, TacticalFieldMap::diskSizeToGb('512.0 MB'));
        // A bare/unitless number is read as GB.
        $this->assertSame(100.0, TacticalFieldMap::diskSizeToGb('100'));
    }

    public function test_disk_size_to_gb_handles_null_and_garbage(): void
    {
        $this->assertNull(TacticalFieldMap::diskSizeToGb(null));
        $this->assertNull(TacticalFieldMap::diskSizeToGb(''));
        $this->assertNull(TacticalFieldMap::diskSizeToGb('n/a'));
    }

    public function test_uptime_from_boot_time_formats_days_hours(): void
    {
        $boot = Carbon::now()->subDays(3)->subHours(5)->subMinutes(12);

        $this->assertSame('3d 5h', TacticalFieldMap::uptimeFromBootTime($boot->timestamp));
    }

    public function test_uptime_from_boot_time_minutes_only_when_under_an_hour(): void
    {
        $boot = Carbon::now()->subMinutes(42);

        $this->assertSame('42m', TacticalFieldMap::uptimeFromBootTime($boot->timestamp));
    }

    public function test_uptime_from_boot_time_handles_null(): void
    {
        $this->assertNull(TacticalFieldMap::uptimeFromBootTime(null));
        $this->assertNull(TacticalFieldMap::uptimeFromBootTime(0));
    }

    public function test_checks_summary_counts_every_status_bucket_explicitly(): void
    {
        // getAgentChecks() shape: a LIST of checks, each with check_result.status
        // (vendor CheckStatus: passing|failing|pending; a NEVER-run check
        // serializes check_result as an EMPTY object — see the pinned fixture).
        // Every bucket is explicit: passing is only ever a counted
        // status=passing, never inferred by subtraction (psa-0pb9m revise).
        $checks = [
            ['name' => 'Disk C', 'check_result' => ['status' => 'failing']],
            ['name' => 'Ping', 'check_result' => ['status' => 'passing']],
            ['name' => 'CPU', 'check_result' => ['status' => 'failing']],
            ['name' => 'New check', 'check_result' => ['status' => 'pending']],
            ['name' => 'Never ran', 'check_result' => []],
        ];

        $summary = TacticalFieldMap::checksSummary($checks);

        $this->assertSame(2, $summary['failing']);
        $this->assertSame(1, $summary['passing']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['unknown']);
        $this->assertSame(5, $summary['total']);
    }

    public function test_checks_summary_empty_is_zero_zero(): void
    {
        $summary = TacticalFieldMap::checksSummary([]);

        $this->assertSame(0, $summary['failing']);
        $this->assertSame(0, $summary['passing']);
        $this->assertSame(0, $summary['total']);
    }

    public function test_checks_from_agent_summary_maps_the_vendor_dict(): void
    {
        // Producer: calculate_agent_checks (agents/utils.py:146-184 @ the
        // pinned upstream commit). failing / warning / info are the SEVERITY
        // SPLIT of status=failing results, so the mapped failing count sums
        // them — dict-derived and list-derived counts must agree (a
        // warning-severity failure is still a failure). `passing` is NEVER
        // mapped (psa-0pb9m R2): the producer counts a check with NO result
        // row as passing, so the vendor number is a claim, not evidence.
        $mapped = TacticalFieldMap::checksFromAgentSummary([
            'total' => 6,
            'passing' => 2,
            'failing' => 1,
            'warning' => 2,
            'info' => 1,
            'has_failing_checks' => true,
        ]);

        $this->assertSame(6, $mapped['total']);
        $this->assertSame(4, $mapped['failing']);
        $this->assertNull($mapped['passing'], 'the vendor aggregate is never passing evidence');

        // Absent/malformed dict maps to all-null — unknown, never clean.
        $this->assertSame(
            ['total' => null, 'failing' => null, 'passing' => null],
            TacticalFieldMap::checksFromAgentSummary(null),
        );
        $this->assertSame(
            ['total' => null, 'failing' => null, 'passing' => null],
            TacticalFieldMap::checksFromAgentSummary(['detail' => 'Not found.']),
        );
    }

    public function test_checks_from_agent_summary_never_passes_the_never_run_manufactured_claim_through(): void
    {
        // The producer's documented never-run shape: ONE check with NO
        // CheckResult row arrives as {total: 1, passing: 1, failing: 0}
        // (the `not hasattr(check.check_result, "status")` branch). This is
        // the exact device psa-0pb9m exists for — it must classify UNKNOWN,
        // never verified.
        $mapped = TacticalFieldMap::checksFromAgentSummary([
            'total' => 1,
            'passing' => 1,
            'failing' => 0,
            'warning' => 0,
            'info' => 0,
            'has_failing_checks' => false,
        ]);

        $this->assertSame(['total' => 1, 'failing' => 0, 'passing' => null], $mapped);
        $this->assertSame(
            TacticalFieldMap::COVERAGE_UNKNOWN,
            TacticalFieldMap::checksCoverage($mapped['total'], $mapped['failing'], $mapped['passing']),
        );
    }

    public function test_disk_volume_mapping_can_include_filesystem_type_for_read_tools(): void
    {
        $volumes = TacticalFieldMap::mapDiskVolumes([
            [
                'device' => 'C:',
                'total' => '100.0 GB',
                'free' => '25.0 GB',
                'percent' => 75,
                'fstype' => 'NTFS',
            ],
        ], includeFilesystemType: true);

        $this->assertSame([
            [
                'drive' => 'C:',
                'total_gb' => 100.0,
                'free_gb' => 25.0,
                'percent_used' => 75,
                'fstype' => 'NTFS',
            ],
        ], $volumes);
    }

    public function test_software_rows_unwraps_the_installed_software_wrapper(): void
    {
        // GET software/{agent}/ serializes the inventory as {id, agent,
        // software: [...]} — the rows live under the `software` key. Mapping
        // the wrapper itself yields three phantom {name: "Unknown"} rows.
        $rows = TacticalFieldMap::softwareRows([
            'id' => 4,
            'agent' => 12,
            'software' => [
                ['name' => 'Mozilla Firefox', 'version' => '128.0.3', 'publisher' => 'Mozilla'],
                ['name' => '7-Zip', 'version' => '24.07', 'publisher' => 'Igor Pavlov'],
            ],
        ]);

        $this->assertSame(['Mozilla Firefox', '7-Zip'], array_column($rows, 'name'));
    }

    public function test_software_rows_passes_a_bare_list_through_and_drops_non_row_entries(): void
    {
        $rows = TacticalFieldMap::softwareRows([
            ['name' => 'Google Chrome', 'version' => '126.0'],
            'not-a-row',
            ['name' => 'Zoom Workplace'],
        ]);

        $this->assertSame(['Google Chrome', 'Zoom Workplace'], array_column($rows, 'name'));
    }

    public function test_software_rows_treats_empty_and_unknown_object_shapes_as_no_inventory(): void
    {
        // An agent with no inventory record returns []; an unrecognized object
        // must map to no rows, never to placeholder rows.
        $this->assertSame([], TacticalFieldMap::softwareRows([]));
        $this->assertSame([], TacticalFieldMap::softwareRows(['id' => 4, 'agent' => 12]));
        $this->assertSame([], TacticalFieldMap::softwareRows(['detail' => 'Not found.']));
    }

    // ── checks coverage (psa-0pb9m) ──────────────────────────────────────────

    public function test_checks_coverage_requires_explicit_passing_evidence(): void
    {
        // No snapshot/live read at all — we do not know (never "clean").
        $this->assertSame(TacticalFieldMap::COVERAGE_UNKNOWN, TacticalFieldMap::checksCoverage(null, null, null));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNKNOWN, TacticalFieldMap::checksCoverage(null, 3, null));

        // ZERO checks: nothing verifies this device. Must never read as clean —
        // deleting a broken check must not turn a Mac green (psa-0pb9m).
        $this->assertSame(TacticalFieldMap::COVERAGE_NONE, TacticalFieldMap::checksCoverage(0, 0, 0));
        $this->assertSame(TacticalFieldMap::COVERAGE_NONE, TacticalFieldMap::checksCoverage(0, null, null));

        // ALL checks failing: indistinguishable from a broken/wrong-platform
        // check — nothing currently demonstrates working monitoring. The
        // "one check on every Mac, fails on all of them" case is exactly 1/1.
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(1, 1, 0));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(3, 3, 0));
        // Defensive: failing above total still means nothing passes.
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(3, 5, 0));

        // VERIFIED requires an EXPLICITLY passing check (psa-0pb9m revise) —
        // failing < total is NOT evidence. The reviewer's exact repro: one
        // check whose result is unknown/never-reported mapped to
        // {failing: 0, total: 1} and read "verified" at 276c20e.
        $this->assertSame(TacticalFieldMap::COVERAGE_VERIFIED, TacticalFieldMap::checksCoverage(8, 1, 7));
        $this->assertSame(TacticalFieldMap::COVERAGE_VERIFIED, TacticalFieldMap::checksCoverage(8, 0, 8));
        $this->assertSame(TacticalFieldMap::COVERAGE_VERIFIED, TacticalFieldMap::checksCoverage(8, 7, 1));

        // Nothing passing (pending / never-reporting / warning-severity gaps
        // included): unverified, never verified-by-subtraction.
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(1, 0, 0));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(4, 2, 0));

        // No passing evidence at all (legacy snapshot, partial payload):
        // honestly UNKNOWN — unless every check is failing, which PROVES
        // nothing passes.
        $this->assertSame(TacticalFieldMap::COVERAGE_UNKNOWN, TacticalFieldMap::checksCoverage(8, 1, null));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNKNOWN, TacticalFieldMap::checksCoverage(8, 0, null));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNKNOWN, TacticalFieldMap::checksCoverage(4, null, null));
        $this->assertSame(TacticalFieldMap::COVERAGE_UNVERIFIED, TacticalFieldMap::checksCoverage(3, 3, null));
    }

    public function test_checks_summary_line_makes_every_dangerous_shape_explicit(): void
    {
        // Unknown-with-no-total stays null (callers render their own
        // "—"/unavailable state).
        $this->assertNull(TacticalFieldMap::checksSummaryLine(null, null, null));

        // Zero checks must SAY unmonitored, not render a clean-looking count.
        $line = TacticalFieldMap::checksSummaryLine(0, 0, 0);
        $this->assertIsString($line);
        $this->assertStringContainsStringIgnoringCase('unmonitored', $line);

        // All failing carries the unverified warning inline.
        $line = TacticalFieldMap::checksSummaryLine(1, 1, 0);
        $this->assertStringContainsString('1 failing / 1 total', $line);
        $this->assertStringContainsStringIgnoringCase('all checks failing', $line);

        // Nothing passing (never-reporting gap) says so — never a clean count.
        $line = TacticalFieldMap::checksSummaryLine(3, 1, 0);
        $this->assertStringContainsStringIgnoringCase('no check currently passing', $line);
        $this->assertStringContainsString('2 not reporting', $line);

        // Positive total with NO passing evidence says the coverage is
        // unknown rather than rendering a clean-looking legacy count.
        $line = TacticalFieldMap::checksSummaryLine(8, 1, null);
        $this->assertStringContainsStringIgnoringCase('passing count unavailable', $line);

        // Verified shapes keep the legacy count spine, annotated with the
        // explicit passing evidence.
        $this->assertSame('1 failing / 8 total (7 passing)', TacticalFieldMap::checksSummaryLine(8, 1, 7));
        $this->assertSame('0 failing / 8 total (8 passing)', TacticalFieldMap::checksSummaryLine(8, 0, 8));
    }
}
