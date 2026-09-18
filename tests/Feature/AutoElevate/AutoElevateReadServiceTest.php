<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateClient;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoElevateReadServiceTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    private function service(): AutoElevateReadService
    {
        return app(AutoElevateReadService::class);
    }

    /** @return array<string, string> */
    private static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_envelope_is_unwrapped_and_request_contract_is_read_only(): void
    {
        Http::fake([self::BASE.'/api/v1/companies*' => Http::response(self::envelope([
            self::company(self::COMPANY_B, 'Zeta Widgets'),
            self::company(self::COMPANY_A, 'Acme Manufacturing', '12345'),
        ]), 200)]);

        $companies = $this->service()->companies();

        $this->assertSame([
            ['id' => self::COMPANY_A, 'name' => 'Acme Manufacturing'],
            ['id' => self::COMPANY_B, 'name' => 'Zeta Widgets'],
        ], $companies, 'sorted by name, {id,name} only — managementSystemCompanyId is never surfaced');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), self::BASE.'/api/v1/companies?')
            && self::query($r) === ['take' => '200', 'skip' => '0']
            && $r->hasHeader('Authorization', 'Bearer synthetic-only-key')
            && $r->hasHeader('X-Acknowledgment', 'i-understand-this-is-beta-and-may-change')
            && $r->hasHeader('Accept', 'application/json')
            && $r->body() === '');
    }

    /** @return array<string, array{mixed}> */
    public static function driftedEnvelopes(): array
    {
        return [
            'bare array (pre-envelope shape)' => [[self::company(self::COMPANY_A, 'Acme')]],
            'missing totalCount' => [['items' => []]],
            'missing items' => [['totalCount' => 0]],
            'items not a list' => [['items' => ['a' => 1], 'totalCount' => 1]],
            'totalCount a string' => [['items' => [], 'totalCount' => '0']],
            'row not an object' => [['items' => ['x'], 'totalCount' => 1]],
            'not json' => ['<html>'],
        ];
    }

    #[DataProvider('driftedEnvelopes')]
    public function test_envelope_drift_screams_instead_of_reading_as_zero_rows(mixed $body): void
    {
        Http::fake([self::BASE.'/*' => Http::response($body, 200)]);
        try {
            $this->service()->companies();
            $this->fail('drifted envelope must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertContains($e->reason, ['envelope_drift', 'row_drift']);
            $this->assertStringNotContainsString('synthetic-only-key', $e->getMessage());
        }
    }

    /** @return array<string, array{int}> */
    public static function nonOkStatuses(): array
    {
        return ['401' => [401], '403' => [403], '429' => [429], '500' => [500], '302' => [302]];
    }

    #[DataProvider('nonOkStatuses')]
    public function test_non_200_status_is_a_failed_read_not_an_empty_one(int $status): void
    {
        Http::fake([self::BASE.'/*' => Http::response(self::envelope([]), $status, ['Location' => 'https://untrusted.invalid'])]);
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('http_'.$status);
        $this->service()->computersForCompany(self::COMPANY_A);
    }

    public function test_transport_failure_never_chains_or_quotes_the_key(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 Bearer synthetic-only-key'));
        try {
            $this->service()->companies();
            $this->fail('transport failure must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('transport', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-only-key', $e->getMessage());
        }
    }

    public function test_missing_key_is_a_configuration_failure_without_a_request(): void
    {
        Setting::setEncrypted('autoelevate_api_key', '');
        Http::fake();
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('configuration');
        try {
            $this->service()->companies();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_paging_walks_past_the_200_cap_to_total_count(): void
    {
        // 450 computers: the vendor caps take at 200, so three pages (200, 200, 50).
        $all = [];
        for ($i = 1; $i <= 450; $i++) {
            $all[] = self::computer(['id' => self::uuid($i), 'machineName' => sprintf('WS-%04d', $i)]);
        }
        $seen = [];
        Http::fake(function (Request $r) use ($all, &$seen) {
            $q = self::query($r);
            $seen[] = $q;
            $take = min((int) $q['take'], 200);   // server-side cap, as measured

            return Http::response(self::envelope(array_slice($all, (int) $q['skip'], $take), 450), 200);
        });

        $rows = $this->service()->computersForCompany(self::COMPANY_A);

        $this->assertCount(450, $rows, 'every row past the first page must land');
        $this->assertSame(450, count(array_unique(array_column($rows, 'id'))));
        $this->assertSame('WS-0001', $rows[0]['machine_name']);
        $this->assertSame('WS-0450', $rows[449]['machine_name']);
        Http::assertSentCount(3);
        $this->assertSame(['0', '200', '400'], array_column($seen, 'skip'));
        foreach ($seen as $q) {
            $this->assertSame('200', $q['take'], 'never ask for more than the documented cap');
            $this->assertSame(self::COMPANY_A, $q['companyId'], 'every page stays scoped to the company');
        }
    }

    public function test_paging_stops_exactly_at_total_count_on_a_full_last_page(): void
    {
        $all = [];
        for ($i = 1; $i <= 400; $i++) {
            $all[] = self::computer(['id' => self::uuid($i)]);
        }
        Http::fake(function (Request $r) use ($all) {
            $q = self::query($r);

            return Http::response(self::envelope(array_slice($all, (int) $q['skip'], 200), 400), 200);
        });
        $this->assertCount(400, $this->service()->computersForCompany(self::COMPANY_A));
        // 400 = 2 × 200: no third request to discover the documented `totalCount: 0` past the end.
        Http::assertSentCount(2);
    }

    public function test_short_page_before_total_count_is_a_failed_read_not_a_truncated_list(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(self::envelope([self::computer()], 350), 200)]);
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('paging_incomplete');
        $this->service()->computersForCompany(self::COMPANY_A);
    }

    /**
     * #2124. An over-cap tenant is knowable from the FIRST page's `totalCount`, so it must
     * cost one request, not fifty against a 100/hour bucket — and it must not wear the
     * `paging_bound` label, which means something else (see the next test).
     */
    public function test_a_tenant_larger_than_the_walk_is_named_from_the_first_page(): void
    {
        $page = [];
        for ($i = 1; $i <= 200; $i++) {
            $page[] = self::computer(['id' => self::uuid($i)]);
        }
        $overCap = AutoElevateReadService::MAX_PAGES * AutoElevateClient::MAX_TAKE + 1;   // 10,001
        Http::fake([self::BASE.'/*' => Http::response(self::envelope($page, $overCap), 200)]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('an over-cap tenant must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_over_cap', $e->reason);
        }
        Http::assertSentCount(1);
    }

    /**
     * The at-cap boundary walked to COMPLETION, not merely asserted to skip the over-cap
     * branch: exactly MAX_PAGES × MAX_TAKE rows must be collectible, which means the 50th
     * request returns the last full page, `$skip` reaches `$total` inside the final iteration
     * and reconciliation passes on 10,000 unique rows. Without this, moving the loop bound or
     * flipping the over-cap comparison to `>=` would silently start failing a tenant the
     * design deliberately admits, and the negative test below would not notice.
     *
     * Deliberately uses bare row arrays rather than the full computer fixture: this asserts
     * the PAGING boundary, and 10,000 normalized rows would make it a slow normalization test.
     */
    public function test_a_tenant_exactly_at_the_cap_walks_to_completion(): void
    {
        $atCap = AutoElevateReadService::MAX_PAGES * AutoElevateClient::MAX_TAKE;   // 10,000
        $requests = 0;
        Http::fake(function (Request $r) use ($atCap, &$requests) {
            $requests++;
            $skip = (int) self::query($r)['skip'];
            $items = [];
            for ($i = $skip + 1; $i <= min($skip + AutoElevateClient::MAX_TAKE, $atCap); $i++) {
                $items[] = self::company(self::uuid($i), sprintf('Co %05d', $i));
            }

            return Http::response(self::envelope($items, $atCap), 200);
        });

        $rows = $this->service()->companies();

        $this->assertCount($atCap, $rows, 'a tenant exactly at the cap must be collectible');
        $this->assertSame($atCap, count(array_unique(array_column($rows, 'id'))));
        $this->assertSame(AutoElevateReadService::MAX_PAGES, $requests, 'exactly 50 full pages, no 51st request');
    }

    /** Exactly at the cap is collectible, so it is NOT over-cap: the walk proceeds and fails on its merits. */
    public function test_a_tenant_exactly_at_the_cap_is_not_over_cap(): void
    {
        $page = [];
        for ($i = 1; $i <= 200; $i++) {
            $page[] = self::computer(['id' => self::uuid($i)]);
        }
        $atCap = AutoElevateReadService::MAX_PAGES * AutoElevateClient::MAX_TAKE;   // 10,000
        Http::fake([self::BASE.'/*' => Http::sequence()
            ->push(self::envelope($page, $atCap), 200)
            ->push(self::envelope([self::computer(['id' => self::uuid(9001)])], $atCap), 200)]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_incomplete', $e->reason, 'at the cap the walk is attempted, not refused');
        }
        Http::assertSentCount(2);
    }

    /**
     * `paging_bound` survives #2124 and is still reachable: `totalCount` can GROW after the
     * first page, so a walk that began inside the cap can still run out of pages. The
     * first-page check cannot see that; the page bound can.
     */
    public function test_runaway_paging_is_bounded_when_the_total_grows_mid_walk(): void
    {
        $under = AutoElevateReadService::MAX_PAGES * AutoElevateClient::MAX_TAKE - 1000;
        $call = 0;
        Http::fake(function (Request $r) use (&$call, $under) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $page = [];
            for ($i = 1; $i <= 200; $i++) {
                $page[] = self::computer(['id' => self::uuid((int) $q['skip'] + $i)]);
            }

            return Http::response(self::envelope($page, $call++ === 0 ? $under : PHP_INT_MAX), 200);
        });

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('unbounded walk must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_bound', $e->reason);
        }
        Http::assertSentCount(AutoElevateReadService::MAX_PAGES);
    }

    /**
     * #2115, and the distinction a careless de-dup gets wrong. The vendor repeats one row
     * across a page boundary (an unstable sort does exactly this). The rows must be
     * de-duplicated by id, and `skip` must keep advancing by rows RECEIVED — the vendor's
     * own cursor — not by the unique rows kept. Advancing by unique rows kept re-requests
     * ground already walked, which is visible here as a skip of 399 instead of 400.
     */
    public function test_repeated_rows_are_deduplicated_while_skip_follows_the_vendor_cursor(): void
    {
        // 451 served entries for 450 distinct machines: entry 201 repeats machine 200.
        $served = [];
        for ($i = 1; $i <= 200; $i++) {
            $served[] = self::computer(['id' => self::uuid($i), 'machineName' => sprintf('WS-%04d', $i)]);
        }
        $served[] = self::computer(['id' => self::uuid(200), 'machineName' => 'WS-0200']);   // the repeat
        for ($i = 201; $i <= 450; $i++) {
            $served[] = self::computer(['id' => self::uuid($i), 'machineName' => sprintf('WS-%04d', $i)]);
        }
        $this->assertCount(451, $served);

        $skips = [];
        Http::fake(function (Request $r) use ($served, &$skips) {
            $q = self::query($r);
            $skips[] = (int) $q['skip'];

            return Http::response(self::envelope(array_slice($served, (int) $q['skip'], 200), 450), 200);
        });

        $rows = $this->service()->computersForCompany(self::COMPANY_A);

        $this->assertCount(450, $rows, 'the repeat is dropped, every distinct machine is kept');
        $this->assertSame(450, count(array_unique(array_column($rows, 'id'))));
        $this->assertSame('WS-0450', $rows[449]['machine_name']);
        // The cursor is the vendor's: 0, 200, 400. Advancing by unique rows KEPT would ask
        // for skip=399 on the third request and re-walk a row it already holds.
        $this->assertSame([0, 200, 400], $skips);
        Http::assertSentCount(3);
    }

    /**
     * #2115, the other half. When repetition means rows were DROPPED, the unique count no
     * longer accounts for the vendor's `totalCount` — a silently short list. De-duplicating
     * without reconciling would hand the panel 399 of 400 machines and look healthy.
     */
    public function test_a_short_unique_count_against_total_count_is_a_failed_read(): void
    {
        $served = [];
        for ($i = 1; $i <= 200; $i++) {
            $served[] = self::computer(['id' => self::uuid($i)]);
        }
        $served[] = self::computer(['id' => self::uuid(200)]);   // repeat; machine 400 is never served
        for ($i = 201; $i <= 399; $i++) {
            $served[] = self::computer(['id' => self::uuid($i)]);
        }
        Http::fake(fn (Request $r) => Http::response(
            self::envelope(array_slice($served, (int) self::query($r)['skip'], 200), 400), 200
        ));

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('a short unique count must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_count_mismatch', $e->reason);
        }
    }

    /** More rows than the vendor admits to holding is drift in the other direction. */
    public function test_more_unique_rows_than_total_count_is_also_a_failed_read(): void
    {
        $page = [];
        for ($i = 1; $i <= 5; $i++) {
            $page[] = self::computer(['id' => self::uuid($i)]);
        }
        Http::fake([self::BASE.'/*' => Http::response(self::envelope($page, 3), 200)]);

        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('an over-long page must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_count_mismatch', $e->reason);
        }
    }

    public function test_computer_rows_are_normalized_from_the_documented_shape(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response(self::envelope([
            self::computer(['id' => self::uuid(2), 'machineName' => 'srv-dc-01', 'elevationMode' => 'live',
                'operatingSystem' => ['name' => 'Windows Server 2022', 'version' => null]]),
            self::computer(['id' => self::uuid(1), 'machineName' => 'LT-SALES-07', 'elevationMode' => 'technicianBypass']),
        ]), 200)]);

        $rows = $this->service()->computersForCompany(self::COMPANY_A);

        $this->assertSame(['LT-SALES-07', 'srv-dc-01'], array_column($rows, 'machine_name'), 'case-insensitive sort by machine name');
        $this->assertSame(self::uuid(1), $rows[0]['id']);
        $this->assertSame('Windows 11 Pro', $rows[0]['os_name']);
        $this->assertSame('10.0.26100', $rows[0]['os_version']);
        $this->assertSame('technicianBypass', $rows[0]['elevation_mode']);
        $this->assertTrue($rows[0]['elevation_mode_known']);
        $this->assertSame('Windows Server 2022', $rows[1]['os_name']);
        $this->assertNull($rows[1]['os_version']);
        $this->assertSame('live', $rows[1]['elevation_mode']);
        $this->assertSame('2024-05-28T12:40:00+00:00', $rows[0]['last_checked_in_at']->toIso8601String());
    }

    public function test_epoch_milliseconds_are_not_read_as_seconds_or_iso(): void
    {
        $at = AutoElevateReadService::fromEpochMs(self::EXAMPLE_MS);
        $this->assertSame('2024-05-28T12:40:00.000000Z', $at->toIso8601ZuluString('microsecond'));
        $this->assertSame(0, $at->utcOffset(), 'UTC instant');
        // Pacific rendering, as the panel shows it (PDT in May).
        $this->assertSame('May 28, 2024 5:40 AM PDT', $at->setTimezone('America/Los_Angeles')->format('M j, Y g:i A T'));
        // Sub-second precision survives (1716900000123 → .123).
        $this->assertSame('2024-05-28T12:40:00.123000Z', AutoElevateReadService::fromEpochMs(self::EXAMPLE_MS + 123)->toIso8601ZuluString('microsecond'));
        // Null = never reported in.
        $this->assertNull(AutoElevateReadService::fromEpochMs(null));
        // Read as seconds the same integer would be year ~56,000: guard the unit explicitly.
        $this->assertSame(2024, $at->year);
    }

    /** @return array<string, array{mixed}> */
    public static function driftedTimestamps(): array
    {
        return [
            'ISO string' => ['2024-05-28T13:20:00Z'],
            'numeric string' => ['1716900000000'],
            'float' => [1716900000000.0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('driftedTimestamps')]
    public function test_non_integer_timestamp_is_drift(mixed $value): void
    {
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('timestamp_drift');
        AutoElevateReadService::fromEpochMs($value);
    }

    public function test_null_operating_system_and_null_elevation_mode_and_null_check_in_are_genuine_no_values(): void
    {
        $row = $this->service()->normalizeComputer(self::computer([
            'operatingSystem' => null, 'elevationMode' => null, 'lastCheckedInAt' => null, 'machineName' => null,
        ]), self::COMPANY_A);
        $this->assertNull($row['os_name']);
        $this->assertNull($row['os_version']);
        $this->assertNull($row['elevation_mode']);
        $this->assertTrue($row['elevation_mode_known']);
        $this->assertNull($row['last_checked_in_at']);
        $this->assertNull($row['machine_name']);
    }

    public function test_unrecognised_elevation_mode_is_surfaced_and_flagged_not_hidden(): void
    {
        $row = $this->service()->normalizeComputer(self::computer(['elevationMode' => 'someFutureMode']), self::COMPANY_A);
        $this->assertSame('someFutureMode', $row['elevation_mode']);
        $this->assertFalse($row['elevation_mode_known']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function driftedComputerRows(): array
    {
        return [
            'operatingSystem as a string' => [['operatingSystem' => 'Windows 11 Pro']],
            'operatingSystem missing version' => [['operatingSystem' => ['name' => 'Windows 11 Pro']]],
            'operatingSystem as a list' => [['operatingSystem' => ['Windows 11 Pro', '10.0']]],
            'elevationMode as int' => [['elevationMode' => 1]],
            'machineName as int' => [['machineName' => 7]],
            'id not a uuid' => [['id' => '42']],
            'companyId from another company' => [['companyId' => self::COMPANY_B]],
        ];
    }

    #[DataProvider('driftedComputerRows')]
    public function test_row_drift_fails_the_whole_read(array $override): void
    {
        Http::fake([self::BASE.'/*' => Http::response(self::envelope([self::computer(), self::computer($override + ['id' => self::uuid(9)])]), 200)]);
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('row_drift');
        $this->service()->computersForCompany(self::COMPANY_A);
    }

    public function test_missing_required_key_is_drift_not_null(): void
    {
        $row = self::computer();
        unset($row['lastCheckedInAt']);
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('row_drift');
        $this->service()->normalizeComputer($row, self::COMPANY_A);
    }

    public function test_invalid_company_id_never_reaches_the_vendor(): void
    {
        Http::fake();
        try {
            $this->service()->computersForCompany('not-a-uuid');
            $this->fail('must refuse');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('invalid_company_id', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_normalize_name_strips_to_lowercase_alphanumerics(): void
    {
        $this->assertSame('acmemanufacturingllc', AutoElevateReadService::normalizeName('  Acme Manufacturing, LLC. '));
        $this->assertSame('', AutoElevateReadService::normalizeName(' - '));
    }

    // --- #2111: the timestamp plausibility window ---------------------------------

    /**
     * The unit slip that will actually happen: the vendor's own example value in SECONDS.
     * Carbon accepts it without complaint and renders 1970-01-20 — a date plausible enough
     * to sit unnoticed in a "last check-in" column, which is why magnitude alone is not the
     * guard. Measured 2026-09-18 on Carbon 3.11.1.
     */
    public function test_a_seconds_unit_timestamp_is_refused_instead_of_rendering_as_1970(): void
    {
        $seconds = intdiv(self::EXAMPLE_MS, 1000);   // 1716900000
        $this->assertSame(
            '1970-01-20T20:55:00+00:00',
            \Carbon\CarbonImmutable::createFromTimestampMsUTC($seconds)->toIso8601String(),
            'control: Carbon renders the seconds value silently, so only our window catches it'
        );

        try {
            AutoElevateReadService::fromEpochMs($seconds);
            $this->fail('a seconds-unit timestamp must be refused');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('timestamp_implausible', $e->reason);
        }
    }

    /**
     * The other end. Carbon throws for NO integer magnitude — PHP_INT_MAX renders as year
     * 292278994 — so an absurd future value is a rendered absurdity, never a 500.
     */
    public function test_an_absurd_future_timestamp_is_refused_and_carbon_would_not_have_thrown(): void
    {
        $this->assertSame(
            '292278994-08-17T07:12:56+00:00',
            \Carbon\CarbonImmutable::createFromTimestampMsUTC(PHP_INT_MAX)->toIso8601String(),
            'control: Carbon accepts PHP_INT_MAX — the defect is the rendered date, not an exception'
        );

        foreach ([PHP_INT_MAX, 99999999999999999] as $absurd) {
            try {
                AutoElevateReadService::fromEpochMs($absurd);
                $this->fail("must refuse {$absurd}");
            } catch (AutoElevateReadException $e) {
                $this->assertSame('timestamp_implausible', $e->reason);
            }
        }
    }

    public function test_the_plausibility_window_admits_its_own_edges_and_refuses_just_outside(): void
    {
        $floor = AutoElevateReadService::PLAUSIBLE_FLOOR_MS;
        $this->assertSame(946684800000, $floor, '2000-01-01T00:00:00Z in epoch ms');
        $this->assertSame('2000-01-01T00:00:00+00:00', AutoElevateReadService::fromEpochMs($floor)->toIso8601String());

        try {
            AutoElevateReadService::fromEpochMs($floor - 1);
            $this->fail('one millisecond below the floor must be refused');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('timestamp_implausible', $e->reason);
        }

        // Ceiling: now + 1 year, so clock skew at either end is absorbed but nonsense is not.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-18T00:00:00Z'));
        $this->assertSame(2027, AutoElevateReadService::fromEpochMs(
            \Carbon\CarbonImmutable::parse('2027-09-17T00:00:00Z')->getTimestampMs()
        )->year);
        try {
            AutoElevateReadService::fromEpochMs(\Carbon\CarbonImmutable::parse('2027-09-19T00:00:00Z')->getTimestampMs());
            $this->fail('beyond now + 1 year must be refused');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('timestamp_implausible', $e->reason);
        }
        $this->travelBack();
    }

    // --- #2126: the scope proof is not opt-in -------------------------------------

    /**
     * The defect was the DEFAULT, not the check: `?string $expectedCompanyId = null` let any
     * caller — and three tests did — exercise the public method with the scope proof disabled.
     * Assert the signature itself, because that is what the caller can opt out of.
     */
    /**
     * The null-hint contract asserted directly on the table, because the panel test can only
     * observe the ABSENCE of some particular sentence — which a label-restating hint would
     * satisfy. Every reason the service can raise must either carry a sentence that adds
     * information or carry none at all.
     */
    public function test_only_reasons_that_add_information_carry_a_hint(): void
    {
        // Each hint must carry the SPECIFIC operator fact the label cannot: what was refused
        // and why nothing was listed. Asserting the fact is the only assertion that can fail
        // — an earlier version compared the prose against the snake_case label itself, which no
        // English sentence would ever contain, so a pure restatement passed it.
        $mustSay = [
            // the derived threshold, and that nothing was listed rather than a partial list
            'paging_over_cap' => ['over 10,000', 'partial list would look complete'],
            // BOTH directions, because reconciled() raises this for both
            'paging_count_mismatch' => ['missing from what it sent', 'does not admit to holding'],
            // that a date was withheld, not merely that it was odd
            'timestamp_implausible' => ['no machine was shown', 'wrong date'],
        ];
        foreach ($mustSay as $explained => $facts) {
            $hint = AutoElevateReadException::hintFor($explained);
            $this->assertIsString($hint);
            foreach ($facts as $fact) {
                $this->assertStringContainsString($fact, $hint, "{$explained} must tell the operator: {$fact}");
            }
            // A restatement of the label in prose adds nothing: reject the label's own words.
            $words = array_filter(explode('_', $explained), fn ($w) => strlen($w) > 3);
            $this->assertNotSame(
                $words,
                array_values(array_filter($words, fn ($w) => stripos($hint, $w) !== false)),
                "{$explained}: a hint built only from the label's own words is a restatement"
            );
        }

        // Self-evident or already-explained labels get no hint at all — not a restatement.
        foreach (['paging_incomplete', 'paging_bound', 'row_drift', 'envelope_drift', 'timestamp_drift',
            'configuration', 'transport', 'invalid_company_id', 'http_429'] as $bare) {
            $this->assertNull(AutoElevateReadException::hintFor($bare), "{$bare} must not gain noise");
        }

        // A null reason must not crash the lookup: the panel treats $reason as nullable, and
        // a degraded read that 500s has stopped screaming and started crashing (C-56).
        $this->assertNull(AutoElevateReadException::hintFor(null));

        // operatorHint() is the instance door onto the same table and must not drift from it.
        $this->assertSame(
            AutoElevateReadException::hintFor('paging_over_cap'),
            (new AutoElevateReadException('paging_over_cap'))->operatorHint()
        );
        $this->assertNull((new AutoElevateReadException('paging_incomplete'))->operatorHint());
    }

    public function test_the_company_scope_argument_cannot_be_omitted_or_nulled(): void
    {
        $param = (new \ReflectionMethod(AutoElevateReadService::class, 'normalizeComputer'))->getParameters()[1];

        $this->assertSame('expectedCompanyId', $param->getName());
        $this->assertFalse($param->isOptional(), 'the scope proof must not be skippable');
        $this->assertFalse($param->allowsNull(), 'null must not disable the scope proof');
        $this->assertSame('string', (string) $param->getType());
    }

    /**
     * The behavioural half of the same fix. An earlier version of this test asserted only
     * that an argument-less call raises ArgumentCountError — which is PHP's arity check
     * firing before the method body runs, and would pass for ANY two-parameter method whether
     * or not the scope proof existed. That is a control that restates a declaration instead
     * of executing code, so it is replaced here.
     *
     * This one drives the SCOPE PROOF ITSELF over every value the old signature permitted:
     * the removed default (null) and the empty string a nullable parameter invites. Each must
     * refuse a foreign row with row_drift rather than normalize it, and a matching row must
     * still pass — so the test fails if the check is dropped AND if it is made unconditional.
     */
    public function test_no_company_argument_can_disable_the_scope_proof(): void
    {
        $foreign = self::computer(['companyId' => self::COMPANY_B]);

        foreach ([null, ''] as $weak) {
            try {
                $row = (new \ReflectionMethod(AutoElevateReadService::class, 'normalizeComputer'))
                    ->invokeArgs($this->service(), [$foreign, $weak]);
                $this->fail('a foreign row must not normalize under '.var_export($weak, true)
                    .'; it returned '.json_encode($row));
            } catch (AutoElevateReadException $e) {
                $this->assertSame('row_drift', $e->reason);
            } catch (\TypeError $e) {
                // null is now refused by the signature itself, before the body runs.
                $this->assertStringContainsString('normalizeComputer', $e->getMessage());
            }
        }

        // The same proof must still ADMIT the row that genuinely belongs to the company,
        // so this cannot be satisfied by a check that refuses everything.
        $this->assertSame(
            self::uuid(1),
            $this->service()->normalizeComputer(self::computer(['id' => self::uuid(1)]), self::COMPANY_A)['id']
        );
    }

    public function test_a_foreign_row_is_drift_even_when_normalize_is_called_directly(): void
    {
        $this->expectException(AutoElevateReadException::class);
        $this->expectExceptionMessage('row_drift');
        $this->service()->normalizeComputer(self::computer(['companyId' => self::COMPANY_B]), self::COMPANY_A);
    }

    /**
     * The page window is OURS, not the caller's. PHP's `+` keeps the left operand's keys, so
     * a caller passing take/skip could have driven the request while every bound in the walk
     * (over-cap threshold, short-page test) went on reasoning about MAX_TAKE — a healthy
     * tenant would then be reported as a paging-inconsistent vendor. Refused outright.
     */
    public function test_a_caller_cannot_override_the_page_window(): void
    {
        Http::fake();
        foreach ([['take' => 50], ['skip' => 400], ['take' => 50, 'skip' => 400]] as $override) {
            try {
                (new \ReflectionMethod(AutoElevateReadService::class, 'allItems'))
                    ->invokeArgs($this->service(), ['/api/v1/computers', $override]);
                $this->fail('a caller-supplied page window must be refused: '.json_encode($override));
            } catch (AutoElevateReadException $e) {
                $this->assertSame('paging_contract', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    /** The other half of the same contract: the walk still sends OUR window, merged after the query. */
    public function test_the_walk_sends_its_own_page_window_with_the_callers_filter(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(self::envelope([self::computer()]), 200)]);
        $this->service()->computersForCompany(self::COMPANY_A);
        Http::assertSent(fn (Request $r) => self::query($r) === [
            'take' => '200', 'skip' => '0', 'companyId' => self::COMPANY_A,
        ]);
    }
}
