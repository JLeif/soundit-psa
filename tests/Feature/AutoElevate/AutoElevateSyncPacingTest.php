<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateAssetSyncReport;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * #3420 pacing between clients and #3414 the run-level stop, driven end to end through sync()
 * and the artisan command. The vendor is faked per company (Http::fake + preventStrayRequests,
 * no live call) and every wait goes through a faked Sleep, so the waits requested are asserted
 * exactly and nothing really sleeps.
 *
 * The skipped-client cases give each skipped company an EMPTY computer list at the fake vendor.
 * A sync that wrongly read a skipped client would therefore take a successful empty read and
 * CLEAR its links, so "links unchanged" is a behavioural check, not a restatement of the setup.
 */
class AutoElevateSyncPacingTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    private const SYNCED_AT = '2026-09-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    /** Company uuid for client slot $n (1-based), valid for the read service's uuid check. */
    private static function company(int $n): string
    {
        return self::uuid(0xC000 + $n);
    }

    /**
     * $plan[slot] is 'ok' (one computer named HOST-<slot>), 'empty' (zero computers) or an HTTP
     * status every request for that company gets. Returns the mapped clients in sync order.
     *
     * @param  array<int, string|int>  $plan
     * @return list<Client>
     */
    private function world(array $plan): array
    {
        $clients = [];
        foreach (array_keys($plan) as $slot) {
            $clients[$slot] = Client::factory()->create(['autoelevate_company_id' => self::company($slot)]);
        }
        Http::fake(function (Request $request) use ($plan) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            foreach ($plan as $slot => $answer) {
                if (strtolower((string) ($q['companyId'] ?? '')) !== self::company($slot)) {
                    continue;
                }
                if ($answer === 'ok') {
                    return Http::response(self::envelope([self::computer([
                        'id' => self::uuid(0xA000 + $slot), 'machineName' => "HOST-{$slot}",
                        'companyId' => self::company($slot), 'elevationMode' => 'live',
                    ])]), 200);
                }

                return $answer === 'empty' ? Http::response(self::envelope([]), 200) : Http::response('', $answer);
            }

            return Http::response('', 404);
        });

        return array_values($clients);
    }

    /** An asset already linked by an earlier run, with a recorded ok outcome for its client. */
    private function linkedAsset(Client $client, int $slot): Asset
    {
        $asset = Asset::factory()->create([
            'client_id' => $client->id, 'hostname' => "HOST-{$slot}",
            'autoelevate_computer_id' => self::uuid(0xB000 + $slot),
            'autoelevate_elevation_mode' => 'audit',
            'autoelevate_synced_at' => self::SYNCED_AT,
        ]);
        AutoElevateAssetSyncService::recordClientOutcome($client->id, self::company($slot), true, null,
            [$asset->id => AutoElevateAssetSyncService::normalizeHostname($asset->hostname)]);

        return $asset;
    }

    /** Company slots the fake vendor was asked about, one entry per request, in order. */
    private function requestedSlots(): array
    {
        return Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $q);

            return (int) hexdec(substr((string) $q['companyId'], 0, 8)) - 0xC000;
        })->values()->all();
    }

    private function sync(): AutoElevateAssetSyncReport
    {
        return app(AutoElevateAssetSyncService::class)->sync();
    }

    /** Asserts every AutoElevate column and the recorded outcome are exactly as linkedAsset() left them. */
    private function assertUntouched(Asset $asset, Client $client, int $slot, array $outcomeBefore): void
    {
        $fresh = $asset->fresh();
        $this->assertSame(self::uuid(0xB000 + $slot), $fresh->autoelevate_computer_id,
            "client slot {$slot} was not read, so its asset link must be unchanged (not cleared)");
        $this->assertSame('audit', $fresh->autoelevate_elevation_mode);
        $this->assertSame(self::SYNCED_AT, $fresh->autoelevate_synced_at->format('Y-m-d H:i:s'),
            "client slot {$slot} was not read, so synced_at must not move");
        $this->assertSame($outcomeBefore, AutoElevateAssetSyncService::lastClientOutcome($client->id),
            "client slot {$slot}'s recorded outcome must be the earlier run's, untouched");
    }

    // --- #3420: pacing between clients -------------------------------------------------------

    /**
     * POSITIVE CONTROL for the whole file: every client reads fine, everything links, the run
     * does not stop, and the command exits SUCCESS. The waits sit BETWEEN client reads (recorded
     * in one timeline), one fewer than the clients, none before the first.
     */
    public function test_a_healthy_run_waits_between_clients_links_everything_and_succeeds(): void
    {
        $clients = $this->world([1 => 'ok', 2 => 'ok', 3 => 'ok']);
        $assets = [];
        foreach ($clients as $i => $client) {
            $assets[] = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'HOST-'.($i + 1)]);
        }
        $timeline = [];
        Sleep::whenFakingSleep(function ($duration) use (&$timeline) {
            $timeline[] = 'wait '.$duration->totalSeconds;
        });
        Http::globalRequestMiddleware(function ($request) use (&$timeline) {
            $timeline[] = 'read';

            return $request;
        });

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('3 linked')
            ->doesntExpectOutputToContain('Stopped early')
            ->assertSuccessful();

        $this->assertSame(['read', 'wait 4', 'read', 'wait 4', 'read'], $timeline);
        $this->assertSame(4, AutoElevateAssetSyncService::CLIENT_SPACING_SECONDS);
        foreach ($assets as $i => $asset) {
            $this->assertSame(self::uuid(0xA000 + $i + 1), $asset->fresh()->autoelevate_computer_id);
        }
    }

    public function test_a_single_client_run_does_not_wait(): void
    {
        $this->world([1 => 'ok']);

        $report = $this->sync();

        $this->assertFalse($report->hasFailures());
        Sleep::assertNeverSlept();
    }

    // --- #3414: stop after consecutive http_429 ----------------------------------------------

    public function test_three_clients_in_a_row_rate_limited_stops_the_run_and_skips_the_rest(): void
    {
        $clients = $this->world([1 => 'ok', 2 => 429, 3 => 429, 4 => 429, 5 => 'empty', 6 => 'empty']);
        $skippedA = $this->linkedAsset($clients[4], 5);
        $skippedB = $this->linkedAsset($clients[5], 6);
        $outcomeA = AutoElevateAssetSyncService::lastClientOutcome($clients[4]->id);
        $outcomeB = AutoElevateAssetSyncService::lastClientOutcome($clients[5]->id);

        $report = $this->sync();

        // Clients 5 and 6 were never asked about: 1 read, then 4 attempts each for 2, 3, 4.
        $this->assertSame([1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4], $this->requestedSlots());
        $this->assertSame(AutoElevateAssetSyncReport::STOP_CONSECUTIVE_429, $report->stoppedEarly);
        $this->assertSame([$clients[4]->id, $clients[5]->id], $report->skippedClients);
        $this->assertSame(['http_429', 'http_429', 'http_429'], array_values($report->failedClients));
        $this->assertTrue($report->hasFailures());
        $this->assertSame(0, $report->cleared, 'no link may be cleared for a client that was not read');
        $this->assertUntouched($skippedA, $clients[4], 5, $outcomeA);
        $this->assertUntouched($skippedB, $clients[5], 6, $outcomeB);
        // Pacing before each client read; each 429 client spends its own 5/10/20 backoff.
        Sleep::assertSequence(array_map(fn ($s) => Sleep::for($s)->seconds(),
            [4, 5, 10, 20, 4, 5, 10, 20, 4, 5, 10, 20]));
    }

    public function test_the_command_says_it_stopped_early_names_the_skipped_clients_and_fails(): void
    {
        $clients = $this->world([1 => 429, 2 => 429, 3 => 429, 4 => 'empty']);
        $skipped = $this->linkedAsset($clients[3], 4);
        $outcome = AutoElevateAssetSyncService::lastClientOutcome($clients[3]->id);

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('Stopped early: 3 clients in a row were rate-limited (http_429)')
            ->expectsOutputToContain('1 client(s) were not read and their asset links were left unchanged: #'.$clients[3]->id)
            ->expectsOutputToContain('1 client(s) skipped')
            ->assertFailed();

        $this->assertUntouched($skipped, $clients[3], 4, $outcome);
    }

    /** The streak is CONSECUTIVE: a successful read or a non-429 failure resets it. */
    public function test_rate_limits_that_are_not_consecutive_do_not_stop_the_run(): void
    {
        $clients = $this->world([1 => 429, 2 => 429, 3 => 'ok', 4 => 429, 5 => 429, 6 => 503, 7 => 429, 8 => 429, 9 => 'empty']);
        $last = $this->linkedAsset($clients[8], 9);

        $report = $this->sync();

        $this->assertNull($report->stoppedEarly);
        $this->assertSame([], $report->skippedClients);
        $this->assertContains(9, $this->requestedSlots(), 'the last client must still be read');
        $this->assertNull($last->fresh()->autoelevate_computer_id,
            'client 9 WAS read (empty list), so its link is released exactly as before this change');
    }

    /** A streak that ends with the last client is a failed run, not an early stop: nothing was skipped. */
    public function test_three_rate_limited_clients_at_the_end_are_failures_not_a_stop(): void
    {
        $this->world([1 => 'ok', 2 => 429, 3 => 429, 4 => 429]);

        $report = $this->sync();

        $this->assertNull($report->stoppedEarly);
        $this->assertSame([], $report->skippedClients);
        $this->assertCount(3, $report->failedClients);
        $this->assertTrue($report->hasFailures());
    }

    // --- #3414: run deadline --------------------------------------------------------------------

    public function test_the_run_deadline_stops_reading_and_skips_the_rest_with_links_unchanged(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $clients = $this->world([1 => 'ok', 2 => 'ok', 3 => 'empty', 4 => 'empty']);
        $skippedA = $this->linkedAsset($clients[2], 3);
        $skippedB = $this->linkedAsset($clients[3], 4);
        $outcomeA = AutoElevateAssetSyncService::lastClientOutcome($clients[2]->id);
        $outcomeB = AutoElevateAssetSyncService::lastClientOutcome($clients[3]->id);
        // Each read takes 1,000 s of clock: after two reads the 1,800 s deadline has passed.
        Http::globalRequestMiddleware(function ($request) {
            Carbon::setTestNow(now()->addSeconds(1000));

            return $request;
        });

        $report = $this->sync();

        $this->assertSame([1, 2], $this->requestedSlots(), 'clients 3 and 4 must not be read after the deadline');
        $this->assertSame(1800, AutoElevateAssetSyncService::MAX_RUN_SECONDS);
        $this->assertSame(AutoElevateAssetSyncReport::STOP_DEADLINE, $report->stoppedEarly);
        $this->assertSame([$clients[2]->id, $clients[3]->id], $report->skippedClients);
        $this->assertTrue($report->hasFailures());
        $this->assertSame(0, $report->cleared);
        $this->assertUntouched($skippedA, $clients[2], 3, $outcomeA);
        $this->assertUntouched($skippedB, $clients[3], 4, $outcomeB);
        Carbon::setTestNow();
    }

    /** Every read that WAS made succeeded, and the command must still fail: clients were skipped. */
    public function test_the_command_fails_on_a_deadline_stop_even_when_every_read_succeeded(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->world([1 => 'ok', 2 => 'ok', 3 => 'ok']);
        Http::globalRequestMiddleware(function ($request) {
            Carbon::setTestNow(now()->addSeconds(2000));

            return $request;
        });

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('0 client read(s) failed, 2 client(s) skipped')
            ->expectsOutputToContain('Stopped early: the run deadline of 1800 s was reached')
            ->assertFailed();
        Carbon::setTestNow();
    }
}
