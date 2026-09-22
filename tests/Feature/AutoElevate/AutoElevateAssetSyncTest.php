<?php

namespace Tests\Feature\AutoElevate;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stage 3a sync: vendor computers → PSA assets, by hostname WITHIN the mapped client only.
 * Fixtures are the vendor's documented Computer shape (AutoElevateFixtures); every vendor call
 * is Http::fake'd and stray requests are refused — no test here can reach AutoElevate.
 */
class AutoElevateAssetSyncTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    /**
     * Fake /api/v1/computers per companyId, answering only for the company asked about —
     * exactly as the vendor scopes by `companyId`. An unknown company gets a 500 so a wrong
     * query surfaces as a read failure rather than an empty list.
     *
     * @param  array<string, list<array<string, mixed>>>  $byCompany
     */
    private function fakeVendor(array $byCompany): void
    {
        Http::fake(function (Request $request) use ($byCompany) {
            if (! str_starts_with($request->url(), self::BASE.'/api/v1/computers')) {
                return Http::response('', 404);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $company = strtolower((string) ($q['companyId'] ?? ''));
            if (! array_key_exists($company, $byCompany)) {
                return Http::response('', 500);
            }

            return Http::response(self::envelope($byCompany[$company]), 200);
        });
    }

    private function sync()
    {
        return app(AutoElevateAssetSyncService::class)->sync();
    }

    private function mappedClient(string $companyId): Client
    {
        return Client::factory()->create(['autoelevate_company_id' => $companyId]);
    }

    // --- THE red check: within-client scoping ---------------------------------------------

    /**
     * Two clients each own an asset named SHARED-PC. Only client A is mapped to a company that
     * reports a SHARED-PC. Client B's SHARED-PC must stay unlinked: hostname alone is ambiguous
     * across clients, and linking it would put A's elevation posture on B's machine.
     *
     * Both clients are MAPPED (B to a company reporting nothing), so a matcher that drops the
     * client scope has a live, operational, mapped foreign asset to wrongly pick — the red is
     * not an artefact of B being skipped for being unmapped.
     */
    public function test_same_hostname_in_two_clients_does_not_cross_link(): void
    {
        $clientB = $this->mappedClient(self::COMPANY_B);
        $clientA = $this->mappedClient(self::COMPANY_A);
        // B's asset is created FIRST so an unscoped "first by hostname" query finds it first.
        $assetB = Asset::factory()->create(['client_id' => $clientB->id, 'hostname' => 'SHARED-PC']);
        $assetA = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'SHARED-PC']);

        $computerId = self::uuid(1);
        $this->fakeVendor([
            self::COMPANY_A => [self::computer(['id' => $computerId, 'machineName' => 'SHARED-PC', 'companyId' => self::COMPANY_A])],
            self::COMPANY_B => [],
        ]);

        // Record every link WRITE, not just the end state: a later clean-up step could undo a
        // cross-link before the assertions run and hide it (that happened during the red check).
        $writes = [];
        Asset::saved(function (Asset $a) use (&$writes) {
            if ($a->wasChanged('autoelevate_computer_id') && $a->autoelevate_computer_id !== null) {
                $writes[] = [$a->id, $a->client_id];
            }
        });

        $report = $this->sync();

        $this->assertSame([[$assetA->id, $clientA->id]], $writes,
            'Exactly one link write, to client A\'s asset. Any write to client B\'s asset is a cross-client link, even if later undone.');
        $this->assertNull($assetB->fresh()->autoelevate_computer_id,
            "Client B's SHARED-PC was linked to a computer read under client A's company — cross-client link.");
        $this->assertSame($computerId, $assetA->fresh()->autoelevate_computer_id,
            "Client A's SHARED-PC must be linked to A's computer.");
        $this->assertSame(1, $report->linked);
        $this->assertSame(0, $report->unmatched);
    }

    /** The mirror case: both companies report SHARED-PC; each links to its own client's asset. */
    public function test_same_hostname_in_two_mapped_clients_links_each_to_its_own(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $clientB = $this->mappedClient(self::COMPANY_B);
        $assetA = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'SHARED-PC']);
        $assetB = Asset::factory()->create(['client_id' => $clientB->id, 'hostname' => 'shared-pc']);

        $this->fakeVendor([
            self::COMPANY_A => [self::computer(['id' => self::uuid(10), 'machineName' => 'SHARED-PC', 'companyId' => self::COMPANY_A])],
            self::COMPANY_B => [self::computer(['id' => self::uuid(20), 'machineName' => 'SHARED-PC', 'companyId' => self::COMPANY_B, 'elevationMode' => 'live'])],
        ]);

        $this->sync();

        $this->assertSame(self::uuid(10), $assetA->fresh()->autoelevate_computer_id);
        $this->assertSame('audit', $assetA->fresh()->autoelevate_elevation_mode);
        $this->assertSame(self::uuid(20), $assetB->fresh()->autoelevate_computer_id);
        $this->assertSame('live', $assetB->fresh()->autoelevate_elevation_mode);
    }

    /** An UNMAPPED client's asset is never a candidate, even when it is the only SHARED-PC left. */
    public function test_unmapped_clients_asset_is_never_linked(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $unmapped = Client::factory()->create();
        $foreign = Asset::factory()->create(['client_id' => $unmapped->id, 'hostname' => 'ONLY-THERE']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(3), 'machineName' => 'ONLY-THERE'])]]);

        $report = $this->sync();

        $this->assertNull($foreign->fresh()->autoelevate_computer_id);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(['ONLY-THERE'], $report->perClient[$clientA->id]['unmatched']);
    }

    // --- unmatched are reported, never created --------------------------------------------

    public function test_unmatched_computers_are_reported_per_client_and_never_created(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $clientB = $this->mappedClient(self::COMPANY_B);
        Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'WS-FINANCE-04']);
        $assetsBefore = Asset::withTrashed()->count();

        $this->fakeVendor([
            self::COMPANY_A => [
                self::computer(['id' => self::uuid(1), 'machineName' => 'WS-FINANCE-04']),
                self::computer(['id' => self::uuid(2), 'machineName' => 'LT-GHOST-01']),
                self::computer(['id' => self::uuid(3), 'machineName' => null]),
            ],
            self::COMPANY_B => [
                self::computer(['id' => self::uuid(4), 'machineName' => 'SRV-NOWHERE', 'companyId' => self::COMPANY_B]),
            ],
        ]);

        $report = $this->sync();

        $this->assertSame($assetsBefore, Asset::withTrashed()->count(), 'An unmatched computer must never create an asset.');
        $this->assertSame(1, $report->linked);
        $this->assertSame(3, $report->unmatched);
        $this->assertSame(['(no machine name)', 'LT-GHOST-01'], $report->perClient[$clientA->id]['unmatched']);
        $this->assertSame(1, $report->perClient[$clientA->id]['linked']);
        $this->assertSame(['SRV-NOWHERE'], $report->perClient[$clientB->id]['unmatched']);
        $this->assertSame(0, $report->perClient[$clientB->id]['linked']);
        $this->assertStringContainsString('3 unmatched', $report->summary());
    }

    public function test_two_live_assets_with_one_hostname_in_one_client_are_ambiguous_not_guessed(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $one = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'DUP-PC']);
        $two = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'dup-pc.corp.local']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(5), 'machineName' => 'DUP-PC'])]]);

        $report = $this->sync();

        $this->assertNull($one->fresh()->autoelevate_computer_id);
        $this->assertNull($two->fresh()->autoelevate_computer_id);
        $this->assertSame(1, $report->ambiguous);
        $this->assertSame(['DUP-PC'], $report->perClient[$clientA->id]['ambiguous']);
    }

    // --- elevation mode is the vendor's, verbatim ------------------------------------------

    public function test_null_elevation_mode_stays_null_never_audit(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $asset = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'WS-NULLMODE']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(6), 'machineName' => 'WS-NULLMODE', 'elevationMode' => null])]]);

        $this->sync();

        $asset->refresh();
        $this->assertSame(self::uuid(6), $asset->autoelevate_computer_id);
        $this->assertNull($asset->autoelevate_elevation_mode, 'A null vendor mode must stay unknown, not be defaulted.');
        $this->assertNull($asset->autoelevate_agent_version, 'The vendor schema has no agent version; nothing may be invented.');
        $this->assertSame(self::EXAMPLE_MS, $asset->autoelevate_last_checked_in_at->getTimestampMs());
        $this->assertNotNull($asset->autoelevate_synced_at);
    }

    public function test_unrecognised_elevation_mode_is_stored_verbatim(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $asset = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'WS-ODD']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(7), 'machineName' => 'WS-ODD', 'elevationMode' => 'quarantine'])]]);

        $this->sync();

        $this->assertSame('quarantine', $asset->fresh()->autoelevate_elevation_mode);
    }

    // --- soft deletes and the non-unique index --------------------------------------------

    public function test_computer_id_index_is_plain_not_unique(): void
    {
        $index = collect(Schema::getIndexes('assets'))->first(fn ($i) => $i['columns'] === ['autoelevate_computer_id']);

        $this->assertNotNull($index, 'autoelevate_computer_id must be indexed.');
        $this->assertFalse($index['unique'], 'A unique index lets a soft-deleted asset block re-linking.');
    }

    /**
     * The asset was replaced (old row soft-deleted, still carrying the computer id). The live
     * replacement with the same hostname must link to the same computer, and the trashed row
     * is neither matched nor able to block the write.
     */
    public function test_soft_deleted_asset_does_not_block_relinking_its_computer(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $computerId = self::uuid(8);
        $old = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'WS-REBUILT', 'autoelevate_computer_id' => $computerId]);
        $old->delete();
        $new = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'WS-REBUILT']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => $computerId, 'machineName' => 'WS-REBUILT'])]]);

        $report = $this->sync();

        $this->assertSame($computerId, $new->fresh()->autoelevate_computer_id);
        $this->assertSame(1, $report->linked);
        $this->assertSame(0, $report->ambiguous);
        $this->assertSame(2, Asset::withTrashed()->where('autoelevate_computer_id', $computerId)->count(),
            'The trashed row keeps its history; only the non-unique index makes this state writable.');
    }

    // --- re-sync, release, failure ---------------------------------------------------------

    public function test_existing_link_survives_a_hostname_change_and_vanished_computer_is_released(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $renamed = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'OLD-NAME', 'autoelevate_computer_id' => self::uuid(9)]);
        $gone = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'RETIRED', 'autoelevate_computer_id' => self::uuid(11), 'autoelevate_elevation_mode' => 'live']);

        $this->fakeVendor([self::COMPANY_A => [self::computer(['id' => self::uuid(9), 'machineName' => 'NEW-NAME'])]]);

        $report = $this->sync();

        $this->assertSame(self::uuid(9), $renamed->fresh()->autoelevate_computer_id);
        $this->assertNull($gone->fresh()->autoelevate_computer_id);
        $this->assertNull($gone->fresh()->autoelevate_elevation_mode);
        $this->assertSame(1, $report->cleared);
    }

    public function test_failed_read_leaves_links_untouched_and_is_reported(): void
    {
        $clientA = $this->mappedClient(self::COMPANY_A);
        $linked = Asset::factory()->create(['client_id' => $clientA->id, 'hostname' => 'KEEP-ME', 'autoelevate_computer_id' => self::uuid(12), 'autoelevate_elevation_mode' => 'audit']);
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response('', 503)]);

        $report = $this->sync();

        $this->assertSame(self::uuid(12), $linked->fresh()->autoelevate_computer_id);
        $this->assertSame('audit', $linked->fresh()->autoelevate_elevation_mode);
        $this->assertSame(['http_503'], array_values($report->failedClients));
        $this->assertSame(0, $report->cleared);
        $this->assertSame(false, AutoElevateAssetSyncService::lastClientOutcome($clientA->id)['ok']);
    }

    public function test_unmapping_a_client_releases_its_links(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'WAS-MAPPED', 'autoelevate_computer_id' => self::uuid(13)]);
        Http::fake();

        $this->sync();

        $this->assertNull($asset->fresh()->autoelevate_computer_id);
        Http::assertNothingSent();
    }

    public function test_hostname_normalizer(): void
    {
        $this->assertSame('ws-01', AutoElevateAssetSyncService::normalizeHostname('  WS-01.corp.local '));
        $this->assertSame('ws-01', AutoElevateAssetSyncService::normalizeHostname('ws-01'));
        $this->assertNotSame('ws-01', AutoElevateAssetSyncService::normalizeHostname('ws-011'));
        $this->assertNull(AutoElevateAssetSyncService::normalizeHostname(''));
        $this->assertNull(AutoElevateAssetSyncService::normalizeHostname('   '));
        $this->assertNull(AutoElevateAssetSyncService::normalizeHostname(null));
    }

    public function test_command_reports_and_fails_on_a_read_failure(): void
    {
        $this->mappedClient(self::COMPANY_A);
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response('', 503)]);

        $this->artisan('autoelevate:sync-assets')
            ->expectsOutputToContain('1 client read(s) failed')
            ->assertFailed();
    }
}
