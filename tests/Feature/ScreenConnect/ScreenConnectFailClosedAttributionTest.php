<?php

namespace Tests\Feature\ScreenConnect;

use App\Jobs\ProcessScreenConnectWebhook;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use App\Models\ScreenConnectWebhook;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card 6abe578e — one fail-closed attribution rule for the ScreenConnect ingest.
 *
 *  W1: a session already linked to client B's asset, whose webhook company resolves to
 *      client A (A != B), attaches NOTHING: no ScreenConnectEvent row, no asset field
 *      written, and a "Skipped:" result so the job marks the webhook skipped.
 *  W3: a company name carried by two clients (case-insensitive) resolves to no client.
 *
 * Every refusal is pinned against a sentinel (pre-set asset fields that the attach path
 * would overwrite), and every test also proves the single-match path is unchanged.
 * Synthetic names only.
 */
class ScreenConnectFailClosedAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = '11111111-2222-3333-4444-555555555555';

    /** Native {*:json} shape: the company lives in Session.CustomProperty1. */
    private function nativePayload(string $eventType, ?string $company, array $extraSession = []): array
    {
        return [
            'Session' => array_merge([
                'SessionID' => self::SESSION,
                'Name' => 'Test-MBP',
                'SessionType' => 'Access',
                'CustomProperty1' => $company,
                'GuestMachineName' => 'Test-MBP',
                'GuestClientVersion' => '24.1.0.1',
                'GuestNetworkAddress' => '198.51.100.7',
                'GuestLoggedOnUserName' => 'intruder',
            ], $extraSession),
            'Event' => ['EventType' => $eventType, 'Time' => '2026-10-01T12:00:00Z', 'Data' => 'whoami', 'Host' => 'tech'],
            'Connection' => ['ParticipantName' => 'tech', 'NetworkAddress' => '203.0.113.9'],
        ];
    }

    private function linkedAsset(Client $client): Asset
    {
        return Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'Test-MBP',
            'screenconnect_session_id' => self::SESSION,
            'screenconnect_online' => false,
            'screenconnect_client_version' => 'sentinel-version',
            'ip_address' => '192.0.2.1',
            'last_user' => 'sentinel-user',
            'screenconnect_synced_at' => null,
        ]);
    }

    private function runThroughJob(array $payload): ScreenConnectWebhook
    {
        $webhook = ScreenConnectWebhook::create([
            'event_type' => $payload['Event']['EventType'] ?? 'unknown',
            'session_id' => self::SESSION,
            'payload' => $payload,
        ]);

        (new ProcessScreenConnectWebhook($webhook->id))->handle(app(ScreenConnectSyncService::class));

        return $webhook->fresh();
    }

    // ── W1 ──

    public function test_w1_a_company_naming_another_client_attaches_no_activity_event_and_is_skipped(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $asset = $this->linkedAsset($bravo);

        $webhook = $this->runThroughJob($this->nativePayload('RanCommand', 'Alpha Co'));

        $this->assertSame(0, ScreenConnectEvent::count(), 'a contradicted session must not attach an activity event');
        $this->assertSame('skipped', $webhook->status);
        $this->assertStringStartsWith('Skipped:', (string) $webhook->error);
        // Ids only: no company string or hostname in the reason.
        $this->assertStringNotContainsString('Alpha Co', (string) $webhook->error);
        $this->assertStringNotContainsString('Test-MBP', (string) $webhook->error);
        $this->assertStringContainsString('#'.$asset->id, (string) $webhook->error);
        $this->assertStringContainsString('#'.$alpha->id, (string) $webhook->error);

        $fresh = $asset->fresh();
        $this->assertNull($fresh->screenconnect_synced_at, 'no asset field may be written');
        $this->assertSame($bravo->id, $fresh->client_id);
    }

    public function test_w1_a_contradicted_device_event_writes_no_asset_field(): void
    {
        Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $asset = $this->linkedAsset($bravo);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'ALPHA CO'));

        $this->assertSame('skipped', $webhook->status);
        $fresh = $asset->fresh();
        $this->assertFalse((bool) $fresh->screenconnect_online);
        $this->assertSame('sentinel-version', $fresh->screenconnect_client_version);
        $this->assertSame('192.0.2.1', $fresh->ip_address);
        $this->assertSame('sentinel-user', $fresh->last_user);
        $this->assertNull($fresh->screenconnect_synced_at);
    }

    public function test_w1_the_same_client_company_still_attaches_unchanged(): void
    {
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        Client::factory()->create(['name' => 'Alpha Co']);
        $asset = $this->linkedAsset($bravo);

        $webhook = $this->runThroughJob($this->nativePayload('RanCommand', 'bravo co'));
        $this->assertSame('processed', $webhook->status);
        $this->assertSame(1, ScreenConnectEvent::where('asset_id', $asset->id)->count());

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Bravo Co'));
        $this->assertSame('processed', $webhook->status);
        $fresh = $asset->fresh();
        $this->assertTrue((bool) $fresh->screenconnect_online);
        $this->assertSame('24.1.0.1', $fresh->screenconnect_client_version);
        $this->assertNotNull($fresh->screenconnect_synced_at);
    }

    public function test_w1_a_missing_or_unresolved_company_keeps_the_session_link_authoritative(): void
    {
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $asset = $this->linkedAsset($bravo);

        $this->assertSame('processed', $this->runThroughJob($this->nativePayload('RanCommand', null))->status);
        $this->assertSame('processed', $this->runThroughJob($this->nativePayload('RanCommand', 'Nobody Co'))->status);
        $this->assertSame(2, ScreenConnectEvent::where('asset_id', $asset->id)->count());
    }

    // ── W3 ──

    public function test_w3_a_company_name_shared_by_two_clients_resolves_to_no_client(): void
    {
        $first = Client::factory()->create(['name' => 'Alpha Co']);
        Client::factory()->create(['name' => 'ALPHA CO']);

        $this->assertNull(ScreenConnectSyncService::resolveCompanyClient('alpha co'));

        // Single match is unchanged.
        $first->forceFill(['name' => 'Alpha Co Unique'])->save();
        $this->assertNull(ScreenConnectSyncService::resolveCompanyClient('Nobody Co'));
        $this->assertSame($first->id, ScreenConnectSyncService::resolveCompanyClient('alpha co unique')?->id);
    }

    public function test_w3_an_ambiguous_company_links_no_hostname_match(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        Client::factory()->create(['name' => 'alpha co']);
        $asset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP', 'screenconnect_session_id' => null]);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co'));

        $this->assertSame('skipped', $webhook->status, 'an ambiguous company must not resolve, so no hostname link is made');
        $this->assertNull($asset->fresh()->screenconnect_session_id);
    }

    public function test_w3_a_soft_deleted_namesake_takes_no_part_and_an_inactive_one_does(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $gone = Client::factory()->create(['name' => 'Alpha Co']);
        $gone->delete();

        $this->assertSame($alpha->id, ScreenConnectSyncService::resolveCompanyClient('Alpha Co')?->id);

        Client::factory()->create(['name' => 'Alpha Co', 'is_active' => false]);
        $this->assertNull(ScreenConnectSyncService::resolveCompanyClient('Alpha Co'));
    }

    public function test_w3_a_single_company_match_still_links_by_hostname(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $asset = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP', 'screenconnect_session_id' => null]);

        $webhook = $this->runThroughJob($this->nativePayload('Connected', 'Alpha Co'));

        $this->assertSame('processed', $webhook->status);
        $this->assertSame(self::SESSION, $asset->fresh()->screenconnect_session_id);
    }
}
