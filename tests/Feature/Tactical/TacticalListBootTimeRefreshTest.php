<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TacticalListBootTimeRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 12:00:00');
        Client::factory()->create(['tactical_site_id' => 'Example|Lab', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $id, array $overrides = []): array
    {
        // GET agents/ AgentTableSerializer.Meta.fields, captured verbatim at
        // 632a37a4 in tests/Fixtures/tactical/upstream_producers.json. Synthetic
        // values only; boot_time is the vendor's psutil epoch-seconds float.
        return array_replace([
            'agent_id' => $id, 'hostname' => $id,
            'client_name' => 'Example', 'site_name' => 'Lab',
            'status' => 'online', 'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'last_seen' => '2026-09-20 11:00:00',
            'boot_time' => 1789898400.726743,
        ], $overrides);
    }

    private function service(array $rows): TacticalDeviceSyncService
    {
        // A one-response MockHandler throws on a detail fan-out or any extra HTTP
        // call, and cannot reach the network.
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode($rows, JSON_THROW_ON_ERROR)),
            ])),
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function linked(string $id, ?string $boot, array $extra = []): Asset
    {
        $asset = Asset::factory()->create(array_replace([
            'hostname' => $id, 'last_boot_at' => $boot,
        ], $extra));
        TacticalAsset::create([
            'agent_id' => $id, 'hostname' => $id, 'asset_id' => $asset->id,
            'status' => 'online', 'synced_at' => now()->subDay(),
        ]);

        return $asset;
    }

    public function test_scheduled_list_refreshes_existing_and_newly_linked_assets_without_detail_calls(): void
    {
        $asset = $this->linked('EXISTING', '2026-06-24 01:00:00');
        $result = $this->service([$this->row('EXISTING'), $this->row('NEW')])->syncDevices();
        $this->assertSame(0, $result->errors);
        $this->assertSame('2026-09-20 10:00:00', $asset->fresh()->last_boot_at->toDateTimeString());
        $new = Asset::where('hostname', 'NEW')->firstOrFail();
        $this->assertSame('2026-09-20 10:00:00', $new->last_boot_at->toDateTimeString());
    }

    #[DataProvider('arbitration')]
    public function test_strictly_newer_arbitration_including_other_rmms(?string $stored, array $other, string $expected): void
    {
        $asset = $this->linked('ONE', $stored, $other);
        $this->service([$this->row('ONE')])->syncDevices();
        $this->assertSame($expected, $asset->fresh()->last_boot_at->toDateTimeString());
    }

    public static function arbitration(): array
    {
        return [
            'empty' => [null, [], '2026-09-20 10:00:00'],
            'older observation' => ['2026-09-20 11:00:00', [], '2026-09-20 11:00:00'],
            'ninja newer observation' => ['2026-09-20 09:00:00', ['ninja_id' => 123], '2026-09-20 10:00:00'],
            'level newer observation' => ['2026-09-20 09:59:59', ['level_id' => 'synthetic'], '2026-09-20 10:00:00'],
            'ninja older observation' => ['2026-09-20 11:00:00', ['ninja_id' => 123], '2026-09-20 11:00:00'],
            'level older observation' => ['2026-09-20 11:00:00', ['level_id' => 'synthetic'], '2026-09-20 11:00:00'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_no_usable_observation_preserves_empty_and_populated_columns(array $override, bool $missing = false): void
    {
        $empty = $this->linked('EMPTY', null);
        $populated = $this->linked('POPULATED', '2026-06-24 01:00:00');
        $rows = [$this->row('EMPTY', $override), $this->row('POPULATED', $override)];
        if ($missing) {
            foreach ($rows as &$row) {
                unset($row['boot_time']);
            }
            unset($row);
        }
        $result = $this->service($rows)->syncDevices();
        $this->assertSame(0, $result->errors);
        $this->assertNull($empty->fresh()->last_boot_at);
        $this->assertSame('2026-06-24 01:00:00', $populated->fresh()->last_boot_at->toDateTimeString());
    }

    public static function refusals(): array
    {
        return [
            'missing' => [[], true], 'null' => [['boot_time' => null]],
            'future' => [['boot_time' => 1789905601]],
            'array' => [['boot_time' => ['bad']]],
            'boolean' => [['boot_time' => true]],
            'blank' => [['boot_time' => ' ']],
            'relative' => [['boot_time' => 'now']],
            'below floor' => [['boot_time' => 999999999]],
            'nonfinite numeric string' => [['boot_time' => '1e999']],
        ];
    }

    public function test_equal_fractional_observation_does_not_issue_a_boot_write(): void
    {
        $this->linked('ONE', '2026-09-20 10:00:00');
        DB::enableQueryLog();
        try {
            $this->service([$this->row('ONE')])->syncDevices();
            $writes = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with(strtolower($q['query']), 'update') && str_contains($q['query'], 'last_boot_at'));
            $this->assertCount(0, $writes, 'Other list columns may update; boot_time must not rewrite equal seconds.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_malformed_row_is_contained_and_later_row_still_refreshes(): void
    {
        $bad = $this->linked('BAD', null);
        $good = $this->linked('GOOD', null);
        $result = $this->service([
            $this->row('BAD', ['last_seen' => 'not-a-date']), $this->row('GOOD'),
        ])->syncDevices();
        $this->assertSame(1, $result->errors);
        $this->assertNull($bad->fresh()->last_boot_at);
        $this->assertSame('2026-09-20 10:00:00', $good->fresh()->last_boot_at->toDateTimeString());
    }

    public function test_corrupt_stored_boot_is_contained_and_later_row_still_refreshes(): void
    {
        $bad = $this->linked('BAD', null);
        DB::table('assets')->where('id', $bad->id)->update(['last_boot_at' => 'not-a-date']);
        $good = $this->linked('GOOD', null);
        $result = $this->service([$this->row('BAD'), $this->row('GOOD')])->syncDevices();
        $this->assertSame(0, $result->errors, 'The shared boot refresh is opportunistic.');
        $this->assertSame('not-a-date', DB::table('assets')->where('id', $bad->id)->value('last_boot_at'));
        $this->assertSame('2026-09-20 10:00:00', $good->fresh()->last_boot_at->toDateTimeString());
    }
}
