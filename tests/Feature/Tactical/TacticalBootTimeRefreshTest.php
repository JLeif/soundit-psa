<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * assets.last_boot_at for Tactical-synced devices.
 *
 * The defect: no Tactical path ever wrote last_boot_at, so the column kept
 * whatever the original import left and read as a months-old uptime for the
 * whole Tactical-only fleet — while AssetHealthService::patchFactor() scored it
 * as "up {N}d (patches may be pending)". The agent DETAIL payload carries
 * boot_time (epoch seconds), which this refresh reads.
 *
 * These cases pin the write AND its limits: it fills in and moves forward, it
 * never drags the column backwards over another integration (Ninja and Level
 * write the same column), and no observation is never a blanking.
 *
 * The type cases exist because the vendor's real type is psutil's FLOAT epoch and
 * a JSON round-trip can present it as a numeric STRING, while a near-empty string
 * must never be parsed (Carbon::parse(' ') returns NOW, which would fabricate a
 * boot time that always beats the never-backwards guard).
 */
class TacticalBootTimeRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixtures below are absolute instants; without a frozen clock the headline
        // case's "hours ago" would depend on the wall-clock hour the suite runs.
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function syncService(array $queue): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    private function linkedAsset(array $assetOverrides = []): Asset
    {
        $asset = Asset::factory()->create(array_merge(['hostname' => 'BOX-1'], $assetOverrides));

        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'offline',
            'synced_at' => now()->subDay(),
        ]);

        return $asset->refresh();
    }

    /** @param array<string, mixed> $overrides */
    private function agentDetail(array $overrides = []): string
    {
        return json_encode(array_merge([
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro',
            'last_seen' => '2026-09-17 01:00:00',
        ], $overrides));
    }

    /**
     * The headline case: a stale June value on a device that rebooted hours ago.
     * This is the exact production fingerprint — 175 Tactical-only assets sitting
     * on a 2026-06-24 ceiling while the agent reports current uptime.
     */
    public function test_detail_sync_moves_a_stale_boot_time_forward(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => Carbon::parse('2026-06-24 19:47:00')]);
        $bootedAt = Carbon::parse('2026-09-17 04:00:00');

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootedAt->timestamp])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertSame(
            $bootedAt->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'the observed boot time should replace the stale import value',
        );
    }

    /** An empty column is filled in — the 6 Tactical-only assets that never got a value. */
    public function test_detail_sync_fills_an_empty_boot_time(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);
        $bootedAt = Carbon::parse('2026-09-16 08:30:00');

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootedAt->timestamp])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $bootedAt->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * The adoption guard. linkOrCreateAsset ADOPTS assets that Ninja or Level may
     * also maintain, and both write last_boot_at. An older Tactical observation
     * must not drag a newer one backwards and resurrect the stale-uptime bug in
     * the other direction.
     */
    public function test_an_older_observation_never_drags_the_column_backwards(): void
    {
        $newer = Carbon::parse('2026-09-17 06:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $newer]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => Carbon::parse('2026-09-10 06:00:00')->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $newer->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'a stale Tactical read must not overwrite a newer value from another integration',
        );
    }

    /** No observation is not a blanking: a payload without boot_time leaves the column alone. */
    public function test_a_payload_without_boot_time_leaves_the_column_untouched(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail()),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /** A zero/unparseable boot_time is the same non-observation, not epoch 1970. */
    public function test_a_zero_boot_time_is_not_an_observation(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => 0])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            'a zero boot_time must not write 1970 and read as a 56-year uptime',
        );
    }

    /**
     * The same non-observation, but on an EMPTY column, where the
     * never-drag-backwards guard cannot mask it. This is the case that matters in
     * production: the Tactical-only assets whose last_boot_at is NULL. Treating an
     * absent boot_time as epoch 0 would stamp 1970 and read as a 56-year uptime —
     * a far worse number than the empty cell it replaced.
     */
    public function test_a_zero_boot_time_on_an_empty_column_writes_nothing(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => 0])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertNull(
            $asset->refresh()->last_boot_at,
            'a zero boot_time must leave the column empty, not stamp 1970',
        );
    }

    /** Likewise for a payload with no boot_time key at all and an empty column. */
    public function test_an_absent_boot_time_on_an_empty_column_writes_nothing(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail()),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertNull($asset->refresh()->last_boot_at);
    }

    /** A clock-skewed agent must not park the column ahead of every real observation. */
    public function test_a_future_boot_time_is_refused(): void
    {
        $existing = Carbon::parse('2026-09-01 12:00:00');
        $asset = $this->linkedAsset(['last_boot_at' => $existing]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => now()->addDays(3)->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            $existing->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * The point of the fix, stated as the operator sees it: the health factor stops
     * claiming patches may be pending once the real reboot time lands. This asserts
     * the downstream CONSEQUENCE, not the column.
     */
    public function test_a_refreshed_boot_time_clears_the_long_uptime_health_penalty(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => Carbon::parse('2026-06-24 19:47:00')]);

        $stale = (new \App\Services\AssetHealthService)->compute($asset->refresh())->factors;
        $stalePatch = collect($stale)->firstWhere('key', 'patch');
        $this->assertNotNull($stalePatch);
        $this->assertSame('warn', $stalePatch['status']);
        $this->assertStringContainsString('patches may be pending', $stalePatch['detail']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => now()->subHours(3)->timestamp,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $fresh = (new \App\Services\AssetHealthService)->compute($asset->refresh())->factors;
        $freshPatch = collect($fresh)->firstWhere('key', 'patch');
        $this->assertNotNull($freshPatch);
        $this->assertSame('ok', $freshPatch['status']);
        $this->assertSame(0, $freshPatch['points']);
    }

    /**
     * The vendor's real type. Tactical serialises psutil's boot_time, which is a
     * FLOAT epoch — is_int() is false for it, so a parser that only special-cases
     * int sends the true production value down the string path and drops it.
     */
    public function test_a_float_epoch_is_a_real_observation(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => '2026-06-24 19:47:00']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'boot_time' => 1789617600.7267435,
            ])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            Carbon::createFromTimestamp(1789617600.7267435)->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /** A JSON round-trip can present the same epoch as a numeric string. */
    public function test_a_numeric_string_epoch_is_a_real_observation(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => '2026-06-24 19:47:00']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => '1789617600'])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            Carbon::createFromTimestamp(1789617600)->toDateTimeString(),
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * Carbon::parse(' ') returns NOW. A whitespace boot_time must therefore be
     * refused outright — otherwise it fabricates an observation of this instant,
     * which is newer than anything stored and so always wins the forward guard.
     */
    public function test_a_whitespace_boot_time_never_fabricates_now(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => '2026-06-24 19:47:00']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => '  '])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            '2026-06-24 19:47:00',
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * The same fabrication, reached through the date-string branch: Carbon::parse()
     * resolves 'now'/'today'/'midnight'/'+0 seconds' to this instant exactly as it
     * does a blank string.
     *
     * Asserted on an EMPTY column on purpose — with a value already stored the
     * never-backwards guard refuses a backdated fabrication for the wrong reason,
     * while a fabricated NOW would still win it.
     *
     * @dataProvider fabricatingDateStrings
     */
    public function test_a_relative_date_string_never_fabricates_an_observation(mixed $bootTime): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootTime])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertNull(
            $asset->refresh()->last_boot_at,
            'a relative keyword is not an observation and must not stamp the current instant',
        );
    }

    /** @return array<string, array{mixed}> */
    public static function fabricatingDateStrings(): array
    {
        return [
            'now' => ['now'],
            'today' => ['today'],
            'midnight' => ['midnight'],
            'zero offset' => ['+0 seconds'],
            'unparseable word' => ['unknown'],

            // Regression for the laundering hole the r2 rework's own replacement
            // left open. An anchored YYYY-MM-DD prefix check accepts these, and the
            // plausibility floor cannot catch them because the floor tests the
            // RESOLVED instant: '1970-01-01 +56 years' resolves to 2026-01-01, which
            // is both past and above the floor, so it was WRITTEN. Relative
            // arithmetic behind a valid date prefix is still a fabrication.
            'date prefix with relative suffix' => ['1970-01-01 +56 years'],
            'date prefix with forward suffix' => ['2026-09-17 +1 year'],
            'date prefix with weekday suffix' => ['2026-09-17 next friday'],
        ];
    }

    /**
     * The accepted grammar is a closed enumeration, and every spelling in it must
     * actually round-trip. A strict parser that quietly refuses the vendor's real
     * format would turn this column off altogether while every negative control
     * still passed — the failure mode a refusal-only suite cannot see.
     *
     * @dataProvider acceptedDateStringSpellings
     */
    public function test_each_accepted_date_string_spelling_is_a_real_observation(
        string $bootTime,
        string $expected,
    ): void {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootTime])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertSame(
            $expected,
            $asset->refresh()->last_boot_at?->toDateTimeString(),
            "the vendor spelling {$bootTime} must be accepted as a real observation",
        );
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedDateStringSpellings(): array
    {
        return [
            'space separated' => ['2026-09-17 04:00:00', '2026-09-17 04:00:00'],
            'iso basic' => ['2026-09-17T04:00:00', '2026-09-17 04:00:00'],
            'iso zulu' => ['2026-09-17T04:00:00Z', '2026-09-17 04:00:00'],
            'iso offset' => ['2026-09-17T04:00:00+00:00', '2026-09-17 04:00:00'],
            'iso microseconds' => ['2026-09-17T04:00:00.123456+00:00', '2026-09-17 04:00:00'],

            // Date-only must reset the time to midnight, not adopt the current
            // instant. Without the '!' reset in the format this returns 12:00:00
            // (the frozen test clock) and fabricates the time half of a reading
            // that the vendor never sent.
            'date only resets to midnight' => ['2026-09-17', '2026-09-17 00:00:00'],
        ];
    }

    /**
     * The plausibility floor is a property of the parsed INSTANT, not of the numeric
     * input shape: a 1970 DATE STRING is the same garbled read as epoch 0, and on an
     * empty column there is no stored value to block it.
     *
     * @dataProvider implausibleDateStrings
     */
    public function test_an_implausible_date_string_is_refused_on_an_empty_column(mixed $bootTime): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootTime])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertNull(
            $asset->refresh()->last_boot_at,
            'a pre-floor date string must not be written as a decades-long uptime',
        );
    }

    /** @return array<string, array{mixed}> */
    public static function implausibleDateStrings(): array
    {
        return [
            'epoch start' => ['1970-01-01 00:00:01'],
            'pre-epoch' => ['1969-12-31 23:59:59'],
            'just below the floor' => ['2001-09-08 00:00:00'],
        ];
    }

    /** A genuine absolute date string is still a real observation. */
    public function test_an_absolute_date_string_is_a_real_observation(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => '2026-09-16 08:30:00'])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertSame(
            '2026-09-16 08:30:00',
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /**
     * A plausibility floor, not just a 0 sentinel: a small or negative epoch is a
     * garbled read, never a machine that has been up since 1970.
     *
     * @dataProvider implausibleEpochs
     */
    public function test_an_implausible_epoch_is_refused_on_an_empty_column(mixed $bootTime): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootTime])),
        ]);

        $service->syncDeviceDetail($asset);

        $this->assertNull($asset->refresh()->last_boot_at);
    }

    /** @return array<string, array{mixed}> */
    public static function implausibleEpochs(): array
    {
        return [
            'one' => [1],
            'negative' => [-1],
            'truncated' => [17581],
            'float zero' => [0.0],
            'numeric string zero' => ['0'],
        ];
    }

    /**
     * boot_time arrives straight from a decoded vendor payload, so a non-scalar is
     * reachable. It must be refused as "no observation" rather than raising a
     * TypeError at the parameter boundary — which would escape syncDeviceDetail's
     * try/catch entirely and 500 the refresh.
     *
     * @dataProvider nonScalarBootTimes
     */
    public function test_a_non_scalar_boot_time_does_not_break_the_sync(mixed $bootTime): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => '2026-06-24 19:47:00']);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => $bootTime])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertSame(
            '2026-06-24 19:47:00',
            $asset->refresh()->last_boot_at?->toDateTimeString(),
        );
    }

    /** @return array<string, array{mixed}> */
    public static function nonScalarBootTimes(): array
    {
        return [
            'array' => [['1789617600']],
            'bool' => [true],
            'nested object' => [['epoch' => 1789617600]],
        ];
    }

    /**
     * A bool on an EMPTY column. Without strict_types a bool coerces to int 1 at a
     * narrowly-typed boundary, and 1 is epoch 1970-01-01 — which on an empty column
     * has no stored value to block it and would be written as a 56-year uptime.
     * The never-backwards guard masks this whenever a value already exists, so an
     * empty column is the only place the hazard is visible.
     */
    public function test_a_bool_boot_time_on_an_empty_column_writes_nothing(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => true])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        $this->assertNull($asset->refresh()->last_boot_at);
    }

    /**
     * The queued-action sweep is unrelated work and must not be collateral damage
     * from the boot-time write: it is dispatched even when the asset row the write
     * targets is gone underneath us.
     */
    public function test_the_queued_action_sweep_survives_a_failed_boot_time_write(): void
    {
        Bus::fake();

        $asset = $this->linkedAsset(['last_boot_at' => null]);
        $agentId = TacticalAsset::where('asset_id', $asset->id)->value('agent_id');
        $ticket = Ticket::factory()->create();

        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::QueuedOffline,
            'queued_agent_id' => $agentId,
            'queued_dedup_key' => 'k',
            'queued_at' => now()->subMinutes(10),
            'expires_at' => now()->addDays(7),
        ]);

        // The asset row disappears underneath the write (a real race: a merge or a
        // delete between the detail read and the column refresh). The sweep is
        // unrelated work and must still be dispatched.
        Asset::where('id', $asset->id)->delete();

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail([
                'status' => 'online',
                'boot_time' => 1789617600,
            ])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue($result->ok);
        Bus::assertDispatched(SweepQueuedActionsForAgent::class);
    }

    /**
     * The boot-time write is OPPORTUNISTIC: the detail sync has already succeeded
     * when it runs. A database failure on it must not escape the service.
     *
     * This matters beyond tidiness. refreshTactical() has no try/catch and
     * syncDeviceDetail()'s catch takes TacticalClientException only, so an escaping
     * QueryException reaches a user-facing surface carrying the statement with its
     * bindings INTERPOLATED — measured on this Laravel version as
     * "SQL: update `assets` set `last_boot_at` = 2026-09-17 04:00:00 where `id` = 42".
     * That is the psa #359 leak class the rest of this class routes around via
     * safeFailure(); this write was the one path that bypassed it.
     *
     * Asserted through the PUBLIC result rather than on the log text: the verdict
     * is that the sync still succeeds and nothing propagates.
     */
    public function test_a_failing_boot_time_write_neither_fails_the_sync_nor_escapes(): void
    {
        $asset = $this->linkedAsset(['last_boot_at' => null]);

        // Force a genuine QueryException out of the column write by removing the
        // column the write targets, rather than by mocking the failure — a real
        // driver error, with real errorInfo for safeFailure() to read.
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('last_boot_at');
        });

        $service = $this->syncService([
            new Response(200, [], $this->agentDetail(['boot_time' => 1789617600])),
        ]);

        $result = $service->syncDeviceDetail($asset);

        $this->assertTrue(
            $result->ok,
            'an opportunistic column refresh must not turn a completed sync into a failure',
        );
    }
}
