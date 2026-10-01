<?php

namespace Tests\Feature\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\ScreenConnect\ScreenConnectReadOnlyToolset;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card tIP4JoIG part A: a session whose webhook machine name is "Test-MBP" never linked
 * to an asset stored with hostname "test-mbp.lan", because resolveAsset compared the
 * short name with LOWER(hostname) exactly. The fix adds a client-scoped, UNIQUE
 * first-label match (ScreenConnectAssetMatcher). Synthetic names only.
 */
class ScreenConnectFirstLabelLinkTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(string $sessionId, string $machine, ?string $company): string
    {
        return app(ScreenConnectSyncService::class)->processWebhook([
            'event_type' => 'Connected',
            'session_id' => $sessionId,
            'session_type' => 'Access',
            'company' => $company,
            'guest_machine_name' => $machine,
            'guest_client_version' => '23.1.4.8794',
        ]);
    }

    public function test_a_stored_fqdn_hostname_links_by_its_first_label(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'Test-MBP.lan', 'name' => 'Test MacBook']);

        $result = $this->webhook('sess-lan-01', 'Test-MBP', 'Gamma LLC');

        $this->assertStringContainsString("asset #{$asset->id}", $result);
        $this->assertSame('sess-lan-01', $asset->fresh()->screenconnect_session_id);
        $this->assertTrue($asset->fresh()->screenconnect_online);
    }

    public function test_a_fully_qualified_webhook_name_also_links_a_stored_fqdn_hostname(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'test-mbp.lan', 'name' => 'Test MacBook']);

        $this->webhook('sess-lan-02', 'TEST-MBP.local', 'gamma llc');

        $this->assertSame('sess-lan-02', $asset->fresh()->screenconnect_session_id);
    }

    public function test_an_exact_short_hostname_still_links_and_is_preferred_over_a_first_label_match(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        // The FQDN row is created FIRST (lower id) so an un-preferred match would pick it.
        $fqdn = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'ws-07.corp.example', 'name' => 'Old WS-07']);
        $exact = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'WS-07', 'name' => 'WS-07 desk']);

        $this->webhook('sess-exact-01', 'WS-07', 'Gamma LLC');

        $this->assertSame('sess-exact-01', $exact->fresh()->screenconnect_session_id);
        $this->assertNull($fqdn->fresh()->screenconnect_session_id);
    }

    public function test_another_clients_same_named_fqdn_asset_never_links(): void
    {
        $gamma = Client::factory()->create(['name' => 'Gamma LLC']);
        $delta = Client::factory()->create(['name' => 'Delta Co']);
        // Only Delta has a matching asset. Gamma's webhook must not reach it.
        $deltaAsset = Asset::factory()->create(['client_id' => $delta->id, 'hostname' => 'test-mbp.lan', 'name' => 'Test-MBP']);

        $result = $this->webhook('sess-cross-lan', 'Test-MBP', 'Gamma LLC');

        $this->assertStringContainsString('No matching asset', $result);
        $this->assertNull($deltaAsset->fresh()->screenconnect_session_id);
        $this->assertNotTrue($deltaAsset->fresh()->screenconnect_online);
        $this->assertSame(0, Asset::where('client_id', $gamma->id)->whereNotNull('screenconnect_session_id')->count());
    }

    public function test_an_exact_name_match_on_another_client_never_links(): void
    {
        // Pins the grouped OR: `client_id = ? AND hostname = ? OR name = ?` would let the
        // name branch match ANY client's asset.
        Client::factory()->create(['name' => 'Gamma LLC']);
        $delta = Client::factory()->create(['name' => 'Delta Co']);
        $deltaAsset = Asset::factory()->create(['client_id' => $delta->id, 'hostname' => 'unrelated-host', 'name' => 'Test-MBP']);

        $result = $this->webhook('sess-cross-name', 'Test-MBP', 'Gamma LLC');

        $this->assertStringContainsString('No matching asset', $result);
        $this->assertNull($deltaAsset->fresh()->screenconnect_session_id);
    }

    public function test_an_ambiguous_first_label_match_stays_unlinked(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $a = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'test-mbp.lan', 'name' => 'Mac one']);
        $b = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'test-mbp.corp.example', 'name' => 'Mac two']);

        $result = $this->webhook('sess-ambig-01', 'Test-MBP', 'Gamma LLC');

        $this->assertStringContainsString('No matching asset', $result);
        $this->assertNull($a->fresh()->screenconnect_session_id);
        $this->assertNull($b->fresh()->screenconnect_session_id);
    }

    public function test_like_metacharacters_in_the_webhook_name_do_not_widen_the_match(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'test-mbp.lan', 'name' => 'Test MacBook']);

        // '%' would match anything and '_' any one character if passed to LIKE raw.
        $this->webhook('sess-meta-01', '%', 'Gamma LLC');
        $this->webhook('sess-meta-02', 'test_mbp', 'Gamma LLC');
        $this->webhook('sess-meta-03', 'test-mb%', 'Gamma LLC');

        $this->assertNull($asset->fresh()->screenconnect_session_id);
    }

    public function test_a_hostname_only_prefixing_the_first_label_does_not_match(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'test-mbp2.lan', 'name' => 'Other Mac']);

        $this->webhook('sess-prefix-01', 'Test-MBP', 'Gamma LLC');

        $this->assertNull($asset->fresh()->screenconnect_session_id);
    }

    // ── read tool shares the rule ──────────────────────────────────────────────

    private function readTool(): ScreenConnectReadOnlyToolset
    {
        Setting::setValue('screenconnect_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'test-secret');

        return new ScreenConnectReadOnlyToolset(app(ChetDataSurfaceTextSanitizer::class));
    }

    public function test_the_read_tool_finds_a_stored_fqdn_hostname_by_its_short_name(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        $asset = Asset::factory()->create([
            'client_id' => $client->id, 'hostname' => 'test-mbp.lan', 'name' => 'Test MacBook',
            'screenconnect_session_id' => 'sess-read-01', 'screenconnect_synced_at' => now(),
        ]);

        $result = $this->readTool()->execute('screenconnect_get_session_state', ['hostname' => 'Test-MBP'], $client->id);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($asset->id, $result['asset_id']);
    }

    public function test_the_read_tool_refuses_an_ambiguous_first_label_match(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC']);
        foreach (['test-mbp.lan', 'test-mbp.corp.example'] as $i => $host) {
            Asset::factory()->create([
                'client_id' => $client->id, 'hostname' => $host, 'name' => "Mac {$i}",
                'screenconnect_session_id' => "sess-read-amb-{$i}", 'screenconnect_synced_at' => now(),
            ]);
        }

        $result = $this->readTool()->execute('screenconnect_get_session_state', ['hostname' => 'Test-MBP'], $client->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('was not found', $result['error']);
    }

    public function test_the_read_tool_never_finds_another_clients_fqdn_asset(): void
    {
        $gamma = Client::factory()->create(['name' => 'Gamma LLC']);
        $delta = Client::factory()->create(['name' => 'Delta Co']);
        Asset::factory()->create([
            'client_id' => $delta->id, 'hostname' => 'test-mbp.lan', 'name' => 'Test MacBook',
            'screenconnect_session_id' => 'sess-read-x', 'screenconnect_synced_at' => now(),
        ]);

        $result = $this->readTool()->execute('screenconnect_get_session_state', ['hostname' => 'Test-MBP'], $gamma->id);

        $this->assertArrayHasKey('error', $result);
    }
}
