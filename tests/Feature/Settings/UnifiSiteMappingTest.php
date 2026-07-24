<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\ClientUnifiSite;
use App\Models\Setting;
use App\Models\User;
use App\Services\Unifi\UnifiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UniFi Site Mapping page (psa-g5l80) — Settings → Integrations → UniFi → Site Mapping.
 *
 * The invariant this page owns: a mapping is the PAIR unifi_site_id + unifi_host_id,
 * stored in the client_unifi_sites pivot (psa-jpygj — a client may map to MANY sites),
 * and the console (host) id is resolved server-side from the vendor's own /v1/sites
 * listing at save time — never from the submitted form. The downstream device-
 * attribution guards in UnifiReadOnlyToolset trust that pair. A site still maps to at
 * most one client (the UNIQUE on the pivot's unifi_site_id).
 *
 * Envelope and row shapes below mirror the vendor's committed example payload
 * (tests/Fixtures/unifi/list_sites.json): {data, httpStatusCode, traceId, nextToken},
 * rows carrying siteId / hostId / meta{desc,name} / statistics.
 */
class UnifiSiteMappingTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_A = '661de833b6b2463f0c20b319';

    private const SITE_B = '772ef944c7c3574g1d31c420';

    private const HOST_A = '900A6F00301100000000074A6BA90000000007A3387E0000000063EC9853:123456789';

    private const HOST_B = '811B7E11402200000000085B7CB10000000008B4498F0000000074FD9964:987654321';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Setting::setEncrypted('unifi_api_key', 'test-key');
    }

    /** @param array<int, Response> $queue */
    private function bindClientReturning(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://api.ui.com/', 'handler' => $stack]);

        $this->app->instance(UnifiClient::class, new UnifiClient(['api_key' => 'test-key'], $http));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sitesPage(array $rows, ?string $nextToken = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => $rows,
            'httpStatusCode' => 200,
            'traceId' => 'trace-1',
            'nextToken' => $nextToken,
        ]));
    }

    private function siteRow(string $siteId, string $hostId, string $desc, string $name = 'default'): array
    {
        return [
            'siteId' => $siteId,
            'hostId' => $hostId,
            'meta' => ['desc' => $desc, 'name' => $name, 'timezone' => 'America/Vancouver'],
            'statistics' => [
                'counts' => ['totalDevice' => 12, 'offlineDevice' => 1],
                'ispInfo' => ['name' => 'Comcast', 'organization' => 'Comcast Cable'],
                'percentages' => ['wanUptime' => 99],
                'internetIssues' => [],
            ],
            'permission' => 'admin',
            'isOwner' => true,
        ];
    }

    public function test_the_page_redirects_to_integrations_when_unifi_is_not_configured(): void
    {
        Setting::where('key', 'unifi_api_key')->delete();

        $this->actingAs($this->user)
            ->get(route('settings.unifi-sites.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error');
    }

    public function test_the_page_lists_sites_across_pages_and_surfaces_both_ids(): void
    {
        $mapped = Client::factory()->create(['name' => 'Acme Co']);
        ClientUnifiSite::create(['client_id' => $mapped->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A]);

        // Two pages: the page must walk the cursor, not render page 1 only.
        $this->bindClientReturning([
            $this->sitesPage([$this->siteRow(self::SITE_A, self::HOST_A, 'HQ')], 'cursor-2'),
            $this->sitesPage([$this->siteRow(self::SITE_B, self::HOST_B, 'Branch Office', 'branch')]),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.unifi-sites.index'))
            ->assertOk();

        // Both ids for both sites — unifi_site_id is the telemetry grain,
        // unifi_host_id the console unifi_list_devices needs (the bead's requirement).
        $response->assertSee(self::SITE_A);
        $response->assertSee(self::HOST_A);
        $response->assertSee(self::SITE_B);
        $response->assertSee(self::HOST_B);
        $response->assertSee('HQ');
        $response->assertSee('Branch Office');

        // The mapped client is preselected; the other site reads unmapped.
        $response->assertSee('data-selected="'.$mapped->id.'"', false);
    }

    public function test_saving_writes_the_site_and_console_pair_resolved_server_side(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);

        $this->bindClientReturning([
            $this->sitesPage([$this->siteRow(self::SITE_A, self::HOST_A, 'HQ')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [self::SITE_A => (string) $client->id],
                // A tampered form must not be able to choose the console: this field
                // is not part of the contract and must be ignored.
                'hosts' => [self::SITE_A => 'attacker-chosen-console'],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'))
            ->assertSessionHas('success');

        // The console id comes from the vendor listing, never the tampered form field.
        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $client->id,
            'unifi_site_id' => self::SITE_A,
            'unifi_host_id' => self::HOST_A,
        ]);
        $this->assertDatabaseMissing('client_unifi_sites', ['unifi_host_id' => 'attacker-chosen-console']);
    }

    public function test_saving_clears_mappings_deselected_in_the_form(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        ClientUnifiSite::create(['client_id' => $client->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A]);

        $this->bindClientReturning([
            $this->sitesPage([$this->siteRow(self::SITE_A, self::HOST_A, 'HQ')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [self::SITE_A => ''],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'));

        $this->assertDatabaseMissing('client_unifi_sites', ['unifi_site_id' => self::SITE_A]);
    }

    public function test_saving_maps_one_client_to_two_sites(): void
    {
        // psa-jpygj: the one-to-one refusal is gone. A client with two locations maps to
        // BOTH — the per-site rows already express it; the pivot stores both pairs.
        $client = Client::factory()->create(['name' => 'Smart-Service']);

        $this->bindClientReturning([
            $this->sitesPage([
                $this->siteRow(self::SITE_A, self::HOST_A, 'HQ'),
                $this->siteRow(self::SITE_B, self::HOST_B, 'Branch Office', 'branch'),
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [
                    self::SITE_A => (string) $client->id,
                    self::SITE_B => (string) $client->id,
                ],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $client->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A,
        ]);
        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $client->id, 'unifi_site_id' => self::SITE_B, 'unifi_host_id' => self::HOST_B,
        ]);
        $this->assertSame(2, $client->unifiSites()->count());
    }

    public function test_saving_still_refuses_to_map_two_clients_to_one_site(): void
    {
        // The other direction of the invariant holds: a SITE maps to at most one client
        // (the UNIQUE on the pivot). The form is keyed by site, so this can only arise
        // if an existing mapping collides — a fresh save re-asserts the site's row.
        $a = Client::factory()->create(['name' => 'Acme Co']);
        $b = Client::factory()->create(['name' => 'Beta LLC']);
        ClientUnifiSite::create(['client_id' => $a->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A]);

        $this->bindClientReturning([
            $this->sitesPage([$this->siteRow(self::SITE_A, self::HOST_A, 'HQ')]),
        ]);

        // Re-mapping SITE_A to Beta must move it, not duplicate it (site->client stays 1).
        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [self::SITE_A => (string) $b->id],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'));

        $this->assertSame(1, ClientUnifiSite::where('unifi_site_id', self::SITE_A)->count());
        $this->assertSame($b->id, ClientUnifiSite::where('unifi_site_id', self::SITE_A)->value('client_id'));
    }

    public function test_saving_skips_sites_the_account_can_no_longer_see_and_says_so(): void
    {
        $kept = Client::factory()->create(['name' => 'Acme Co']);
        $ghosted = Client::factory()->create(['name' => 'Beta LLC']);

        $this->bindClientReturning([
            $this->sitesPage([$this->siteRow(self::SITE_A, self::HOST_A, 'HQ')]),
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [
                    self::SITE_A => (string) $kept->id,
                    'ghost-site-id' => (string) $ghosted->id,
                ],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'ghost-site-id'));

        $this->assertDatabaseHas('client_unifi_sites', ['client_id' => $kept->id, 'unifi_site_id' => self::SITE_A]);
        $this->assertSame(0, $ghosted->unifiSites()->count(), 'an unverifiable site must not be written');
    }

    public function test_a_failed_site_listing_aborts_the_save_without_wiping_mappings(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        ClientUnifiSite::create(['client_id' => $client->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A]);

        $this->bindClientReturning([new Response(500, [], '{"httpStatusCode":500}')]);

        $this->actingAs($this->user)
            ->post(route('settings.unifi-sites.update'), [
                'mappings' => [self::SITE_A => ''],
            ])
            ->assertRedirect(route('settings.unifi-sites.index'))
            ->assertSessionHas('error');

        // The listing failed BEFORE the DB was touched, so the mapping must survive.
        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $client->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A,
        ]);
    }

    public function test_auto_match_pairs_sites_to_clients_by_display_name_and_writes_both_ids(): void
    {
        // Case-insensitive on the site's display label (meta.desc).
        $hq = Client::factory()->create(['name' => 'hq']);
        $taken = Client::factory()->create(['name' => 'Gamma Corp']);
        ClientUnifiSite::create(['client_id' => $taken->id, 'unifi_site_id' => self::SITE_B, 'unifi_host_id' => self::HOST_B]);
        $unmatched = Client::factory()->create(['name' => 'Branch Office']);

        $this->bindClientReturning([
            $this->sitesPage([
                $this->siteRow(self::SITE_A, self::HOST_A, 'HQ'),
                // SITE_B is already mapped (to Gamma Corp) — auto-match must not
                // steal it for the identically-named client.
                $this->siteRow(self::SITE_B, self::HOST_B, 'Branch Office', 'branch'),
            ]),
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.unifi-sites.auto-match'))
            ->assertRedirect(route('settings.unifi-sites.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1'));

        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $hq->id, 'unifi_site_id' => self::SITE_A, 'unifi_host_id' => self::HOST_A,
        ]);

        // Existing mappings are never overwritten by auto-match.
        $this->assertDatabaseHas('client_unifi_sites', [
            'client_id' => $taken->id, 'unifi_site_id' => self::SITE_B,
        ]);
        $this->assertSame(0, $unmatched->unifiSites()->count(), 'an already-mapped site must not be re-assigned');
    }

    public function test_the_page_fails_loud_when_the_cursor_outlives_the_page_cap(): void
    {
        // 21 pages, every one with a live cursor: the walk stops at the safe cap and
        // must SCREAM, not render the 20 pages it happened to fetch as the whole
        // account (a missing site here reads as "that site is gone").
        $pages = [];
        for ($i = 1; $i <= 21; $i++) {
            $pages[] = $this->sitesPage([$this->siteRow("site-{$i}", "host-{$i}", "Site {$i}")], "cursor-{$i}");
        }
        $this->bindClientReturning($pages);

        $this->actingAs($this->user)
            ->get(route('settings.unifi-sites.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'incomplete'));
    }
}
