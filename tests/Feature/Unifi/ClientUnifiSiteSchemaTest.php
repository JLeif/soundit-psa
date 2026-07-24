<?php

namespace Tests\Feature\Unifi;

use App\Models\Client;
use App\Models\ClientUnifiSite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-jpygj Increment 1 — the additive schema layer for UniFi client->site
 * one-to-MANY. Proves a client can hold several sites, a site still maps to at
 * most one client, and the legacy per-client columns backfill without loss.
 */
class ClientUnifiSiteSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_map_to_multiple_unifi_sites(): void
    {
        // The whole point of psa-jpygj: a client with 2 locations = 2 UniFi sites.
        $client = Client::factory()->create();
        $client->unifiSites()->create(['unifi_site_id' => 'SITE_A', 'unifi_host_id' => 'HOST_1']);
        $client->unifiSites()->create(['unifi_site_id' => 'SITE_B', 'unifi_host_id' => 'HOST_2']);

        $this->assertCount(2, $client->refresh()->unifiSites);
        $this->assertEqualsCanonicalizing(
            ['SITE_A', 'SITE_B'],
            $client->unifiSites->pluck('unifi_site_id')->all(),
        );
        $this->assertTrue(ClientUnifiSite::first()->client->is($client));
    }

    public function test_a_unifi_site_maps_to_at_most_one_client(): void
    {
        // Site -> client stays <=1: the UNIQUE on unifi_site_id is preserved on the pivot.
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $a->unifiSites()->create(['unifi_site_id' => 'SITE_SHARED', 'unifi_host_id' => 'HOST_1']);

        $this->expectException(QueryException::class);
        $b->unifiSites()->create(['unifi_site_id' => 'SITE_SHARED', 'unifi_host_id' => 'HOST_2']);
    }

    public function test_backfill_copies_legacy_client_columns_and_is_idempotent(): void
    {
        // Additive transition: existing clients.unifi_site_id/unifi_host_id must carry
        // into the pivot without loss, and a re-run must never duplicate a site.
        $client = Client::factory()->create();
        DB::table('clients')->where('id', $client->id)->update([
            'unifi_site_id' => 'LEGACY_SITE',
            'unifi_host_id' => 'LEGACY_HOST',
        ]);

        $this->assertSame(1, ClientUnifiSite::backfillFromLegacyColumns());
        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $client->id,
            'unifi_site_id' => 'LEGACY_SITE',
            'unifi_host_id' => 'LEGACY_HOST',
        ]);

        // Idempotent: a second pass copies nothing and creates no duplicate row.
        $this->assertSame(0, ClientUnifiSite::backfillFromLegacyColumns());
        $this->assertSame(1, ClientUnifiSite::where('unifi_site_id', 'LEGACY_SITE')->count());
    }
}
