<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Setting;
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

    public function test_runaway_paging_is_bounded(): void
    {
        // A vendor that always claims more rows than it returns cannot spin the walk forever.
        $page = [];
        for ($i = 1; $i <= 200; $i++) {
            $page[] = self::computer(['id' => self::uuid($i)]);
        }
        Http::fake([self::BASE.'/*' => Http::response(self::envelope($page, PHP_INT_MAX), 200)]);
        try {
            $this->service()->computersForCompany(self::COMPANY_A);
            $this->fail('unbounded walk must throw');
        } catch (AutoElevateReadException $e) {
            $this->assertSame('paging_bound', $e->reason);
        }
        Http::assertSentCount(AutoElevateReadService::MAX_PAGES);
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
        ]));
        $this->assertNull($row['os_name']);
        $this->assertNull($row['os_version']);
        $this->assertNull($row['elevation_mode']);
        $this->assertTrue($row['elevation_mode_known']);
        $this->assertNull($row['last_checked_in_at']);
        $this->assertNull($row['machine_name']);
    }

    public function test_unrecognised_elevation_mode_is_surfaced_and_flagged_not_hidden(): void
    {
        $row = $this->service()->normalizeComputer(self::computer(['elevationMode' => 'someFutureMode']));
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
        $this->service()->normalizeComputer($row);
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
}
