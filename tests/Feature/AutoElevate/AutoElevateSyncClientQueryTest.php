<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * sync() must select every mapped, operational client on the PRODUCTION engine, not just on the
 * test engine.
 *
 * The defect this guards (first manual sync on production, 2026-09-25): sync() filtered
 * `autoelevate_company_id != ''`. The column is Laravel `->uuid()`, which on MariaDB >= 10.7
 * is the native `uuid` type. There `uuid_col != ''` is false for every row, so the run selected
 * 0 of 57 mapped clients, read nothing, and still exited SUCCESS in about a second.
 *
 * This suite runs on SQLite, where the column is plain text and the same clause is harmless, so
 * NO behavioural test here can fail on the old code. That is why the first test inspects the SQL
 * sync() actually executes: a `!=`/`<>` comparison between any uuid-typed column and '' is the
 * defect's signature, whatever dialect renders it. The uuid columns are read from the migrations
 * rather than hard-coded, so a new ->uuid() column is covered without editing this file.
 *
 * The real proof is on MariaDB: the production run after deploy.
 */
class AutoElevateSyncClientQueryTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    /**
     * Every column a migration declares with ->uuid(...) / ->foreignUuid(...).
     *
     * @return list<string>
     */
    private static function uuidColumns(): array
    {
        $cols = [];
        foreach (glob(base_path('database/migrations/*.php')) as $file) {
            if (preg_match_all("/->(?:foreignUuid|uuid)\\(\\s*'([A-Za-z0-9_]+)'/", (string) file_get_contents($file), $m)) {
                array_push($cols, ...$m[1]);
            }
        }

        return array_values(array_unique($cols));
    }

    /** @param array<string, list<array<string, mixed>>> $byCompany */
    private function fakeVendor(array $byCompany): void
    {
        Http::fake(function (Request $request) use ($byCompany) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $company = strtolower((string) ($q['companyId'] ?? ''));

            return array_key_exists($company, $byCompany)
                ? Http::response(self::envelope($byCompany[$company]), 200)
                : Http::response('', 500);
        });
    }

    /** A `!=` / `<>` comparison of a (possibly table-qualified, quoted) uuid column with ''. */
    private static function emptyStringComparisonsOnUuidColumns(string $rawSql): array
    {
        $hits = [];
        foreach (self::uuidColumns() as $col) {
            // Identifier boundaries: `id` must not match the tail of `autoelevate_company_id`.
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($col, '/')."[`\"\\]]?\\s*(?:!=|<>)\\s*''/i";
            if (preg_match($pattern, $rawSql)) {
                $hits[] = $col;
            }
        }

        return $hits;
    }

    public function test_sync_never_compares_a_uuid_column_with_empty_string(): void
    {
        $this->assertContains('autoelevate_company_id', self::uuidColumns(), 'uuid column discovery found nothing: the guard below would pass vacuously.');
        $this->assertContains('autoelevate_computer_id', self::uuidColumns());

        // Positive control: the detector must flag the exact clause that broke production.
        $bad = Client::query()->whereNotNull('autoelevate_company_id')->where('autoelevate_company_id', '!=', '')->toRawSql();
        $this->assertSame(['autoelevate_company_id'], self::emptyStringComparisonsOnUuidColumns($bad),
            'Detector failed its positive control; it cannot be trusted to find the defect.');

        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'WS-ONE']);
        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(1), 'machineName' => 'WS-ONE'])]]);

        $executed = [];
        DB::listen(function (QueryExecuted $q) use (&$executed) {
            $executed[] = $q->toRawSql();
        });

        app(AutoElevateAssetSyncService::class)->sync();

        $clientSelects = array_filter($executed, fn (string $sql) => preg_match('/^select .* from [`"]?clients[`"]?/i', $sql)
            && str_contains($sql, 'autoelevate_company_id'));
        $this->assertNotEmpty($clientSelects, 'Did not observe the client selection query: the SQL guard below would pass vacuously.');

        foreach ($executed as $sql) {
            $this->assertSame([], self::emptyStringComparisonsOnUuidColumns($sql),
                "sync() compares a uuid column with '' — on MariaDB's native uuid type that matches no row. Query: ".$sql);
        }
    }

    public function test_every_mapped_operational_client_is_read_and_unmapped_ones_are_not(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        Client::factory()->create(['autoelevate_company_id' => null]);
        Asset::factory()->create(['client_id' => $a->id, 'hostname' => 'WS-A']);
        Asset::factory()->create(['client_id' => $b->id, 'hostname' => 'WS-B']);
        $this->fakeVendor([
            self::COMPANY_A => [self::computer(['id' => self::uuid(1), 'machineName' => 'WS-A', 'companyId' => self::COMPANY_A])],
            self::COMPANY_B => [self::computer(['id' => self::uuid(2), 'machineName' => 'WS-B', 'companyId' => self::COMPANY_B])],
        ]);

        $report = app(AutoElevateAssetSyncService::class)->sync();

        $asked = [];
        Http::assertSent(function (Request $r) use (&$asked) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $asked[] = strtolower((string) ($q['companyId'] ?? ''));

            return true;
        });
        $this->assertEqualsCanonicalizing([self::COMPANY_A, self::COMPANY_B], array_values(array_unique($asked)));
        $this->assertSame(2, $report->linked);
    }
}
