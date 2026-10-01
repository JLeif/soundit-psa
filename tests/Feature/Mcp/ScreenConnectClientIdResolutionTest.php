<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use App\Models\Setting;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\ScreenConnect\ScreenConnectReadOnlyToolset;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card 6abdcac2, fourth integration: ScreenConnect reads take client_id and resolve
 * strictly within that client.
 *
 * ScreenConnect has no client-level vendor key (webhook-ingest only, no outbound API),
 * so the binding IS assets.client_id. Every case here is driven through the real
 * /api/mcp/staff boundary unless it names the executor or toolset: that boundary is
 * the only surface serving screenconnect_* reads (the Assistant and triage
 * executors do not route them).
 *
 * Synthetic data only: client names "Alpha Synthetic"/"Bravo Synthetic", hosts such
 * as Test-MBP and Bravo-WS, sessions with all-hex placeholder UUIDs.
 */
class ScreenConnectClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const ALPHA_SESSION = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const BRAVO_SESSION = 'bbbbbbbb-0000-0000-0000-000000000002';

    private const BRAVO_EVENT_TEXT = 'BRAVO-ONLY-EVENT-TEXT';

    private Client $alpha;

    private Client $bravo;

    private Asset $alphaAsset;

    private Asset $bravoAsset;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('screenconnect_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'test-secret');

        $this->alpha = Client::factory()->create(['name' => 'Alpha Synthetic']);
        $this->bravo = Client::factory()->create(['name' => 'Bravo Synthetic']);
        $this->alphaAsset = $this->linked($this->alpha, 'Test-MBP', self::ALPHA_SESSION);
        $this->bravoAsset = $this->linked($this->bravo, 'Bravo-WS', self::BRAVO_SESSION, ['name' => 'Bravo Front Desk']);

        ScreenConnectEvent::create([
            'asset_id' => $this->bravoAsset->id,
            'session_id' => self::BRAVO_SESSION,
            'event_type' => 'RanCommand',
            'event_time' => now()->subMinute(),
            'data' => self::BRAVO_EVENT_TEXT,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function linked(Client $client, string $hostname, ?string $session, array $extra = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => $hostname,
            'name' => $hostname.' PC',
            'screenconnect_session_id' => $session,
            'screenconnect_online' => $session !== null ? true : null,
            'screenconnect_last_seen_at' => $session !== null ? now()->subMinutes(5) : null,
            'screenconnect_synced_at' => $session !== null ? now()->subMinutes(5) : null,
        ], $extra));
    }

    private function token(): string
    {
        return McpConfig::rotateStaffToken(
            allowedTools: ['screenconnect_get_session_state', 'screenconnect_list_devices'],
            label: 'opsbot',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function mcp(string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function text(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode($this->text($response), true) ?? [];
    }

    /** $typed: what the caller itself sent, which an error may echo back; that is not a leak. */
    private function assertNoBravoLeak(string $body, string $because, ?string $typed = null): void
    {
        foreach ([self::BRAVO_SESSION, self::BRAVO_EVENT_TEXT, 'Bravo Synthetic', 'Bravo Front Desk', 'Bravo-WS'] as $needle) {
            if ($typed !== null && str_contains(mb_strtolower($typed), mb_strtolower($needle))) {
                continue;
            }
            $this->assertStringNotContainsString($needle, $body, $because.' (leaked: '.$needle.')');
        }
    }

    // ── the client resolves strictly ───────────────────────────────────────────

    public function test_the_clients_own_device_resolves_by_asset_id(): void
    {
        $result = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $this->alphaAsset->id,
        ]));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($this->alpha->id, $result['psa_client_id']);
        $this->assertSame($this->alphaAsset->id, $result['asset_id']);
        $this->assertSame(self::ALPHA_SESSION, $result['session_id']);
    }

    public function test_the_clients_own_device_resolves_by_hostname_and_by_asset_id_with_its_hostname(): void
    {
        $byHost = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'hostname' => 'test-mbp.lan',
        ]));
        $this->assertSame($this->alphaAsset->id, $byHost['asset_id'] ?? null);

        $both = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $this->alphaAsset->id,
            'hostname' => 'Test-MBP.lan',
        ]));
        $this->assertSame($this->alphaAsset->id, $both['asset_id'] ?? null, 'a hostname that names the asset (FQDN by its first label) is consistent');
    }

    // ── another client's hostname, asset or session fails closed ──────────────

    public function test_another_clients_hostname_fqdn_name_or_session_id_is_not_found_over_mcp(): void
    {
        foreach (['Bravo-WS', 'bravo-ws.lan', 'Bravo Front Desk', self::BRAVO_SESSION] as $typed) {
            $response = $this->mcp('screenconnect_get_session_state', [
                'client_id' => $this->alpha->id,
                'hostname' => $typed,
            ]);
            $body = $this->text($response);

            $this->assertTrue((bool) $response->json('result.isError'), "'{$typed}' (Bravo's) must not resolve under Alpha");
            $this->assertStringContainsString('was not found in Alpha Synthetic', $body);
            $this->assertNoBravoLeak($body, "'{$typed}' under Alpha", $typed);
        }
    }

    public function test_another_clients_asset_id_is_not_found_and_nothing_leaks(): void
    {
        $response = $this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $this->bravoAsset->id,
        ]);
        $body = $this->text($response);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString("Asset {$this->bravoAsset->id} was not found in Alpha Synthetic", $body);
        $this->assertNoBravoLeak($body, "Bravo's asset_id under Alpha");
    }

    public function test_another_clients_asset_id_with_this_clients_hostname_is_still_not_found(): void
    {
        $response = $this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $this->bravoAsset->id,
            'hostname' => 'Test-MBP',
        ]);
        $body = $this->text($response);

        $this->assertTrue((bool) $response->json('result.isError'), 'asset_id is primary: a valid own hostname must not rescue a foreign asset_id');
        $this->assertStringContainsString('was not found in Alpha Synthetic', $body);
        $this->assertNoBravoLeak($body, 'foreign asset_id plus own hostname');
    }

    public function test_an_asset_id_with_a_hostname_naming_another_device_is_refused(): void
    {
        $this->linked($this->alpha, 'Second-PC', 'aaaaaaaa-0000-0000-0000-000000000099');

        $result = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $this->alphaAsset->id,
            'hostname' => 'Second-PC',
        ]));

        $this->assertStringContainsString("is not asset {$this->alphaAsset->id}'s hostname or name", $result['error'] ?? '');
        $this->assertArrayNotHasKey('session_id', $result);
    }

    public function test_a_malformed_asset_id_is_refused_not_ignored(): void
    {
        foreach (['abc', 0, -3, '1.5'] as $bad) {
            $result = app(ScreenConnectReadOnlyToolset::class)->execute(
                'screenconnect_get_session_state',
                ['asset_id' => $bad, 'hostname' => 'Test-MBP'],
                $this->alpha->id,
            );
            $this->assertSame('asset_id must be a positive integer PSA asset ID.', $result['error'] ?? null, 'asset_id '.json_encode($bad));
        }
    }

    public function test_list_devices_never_lists_another_clients_device(): void
    {
        foreach (['', 'Bravo', 'bbbbbbbb', 'Front Desk'] as $query) {
            $args = ['client_id' => $this->alpha->id];
            if ($query !== '') {
                $args['query'] = $query;
            }
            $response = $this->mcp('screenconnect_list_devices', $args);
            $result = $this->decoded($response);

            $this->assertSame($this->alpha->id, $result['psa_client_id'] ?? null);
            $this->assertSame(1, $result['total_linked'], 'fleet totals are this client\'s only');
            $this->assertNotContains($this->bravoAsset->id, array_column($result['devices'], 'asset_id'));
            $this->assertNoBravoLeak($this->text($response), "list_devices query '{$query}'", $query);
        }
    }

    // ── ambiguity fails closed ─────────────────────────────────────────────────

    public function test_a_hostname_on_two_linked_devices_fails_closed_naming_only_this_clients_candidates(): void
    {
        $older = $this->linked($this->alpha, 'Dup-PC', 'aaaaaaaa-0000-0000-0000-000000000010', ['screenconnect_synced_at' => now()->subHour()]);
        $newer = $this->linked($this->alpha, 'Dup-PC', 'aaaaaaaa-0000-0000-0000-000000000011', ['screenconnect_synced_at' => now()->subMinute()]);
        $this->linked($this->bravo, 'Dup-PC', 'bbbbbbbb-0000-0000-0000-000000000012');

        foreach (['Dup-PC', 'dup-pc.lan'] as $typed) {
            $response = $this->mcp('screenconnect_get_session_state', [
                'client_id' => $this->alpha->id,
                'hostname' => $typed,
            ]);
            $result = $this->decoded($response);

            $this->assertTrue((bool) $response->json('result.isError'), "'{$typed}' is ambiguous and must not pick a device");
            $this->assertStringContainsString('more than one ScreenConnect-linked device', $result['error'] ?? '');
            $this->assertSame([$older->id, $newer->id], array_column($result['candidates'] ?? [], 'asset_id'));
            $this->assertArrayNotHasKey('session_id', $result);
            $this->assertStringNotContainsString('bbbbbbbb-0000-0000-0000-000000000012', $this->text($response));
        }

        // asset_id is the way out of the ambiguity.
        $chosen = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => $this->alpha->id,
            'asset_id' => $older->id,
        ]));
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000010', $chosen['session_id'] ?? null);
    }

    public function test_a_hostname_equal_to_another_linked_devices_name_is_ambiguous(): void
    {
        $this->linked($this->alpha, 'Name-Clash', 'aaaaaaaa-0000-0000-0000-000000000020');
        $this->linked($this->alpha, 'Other-Host', 'aaaaaaaa-0000-0000-0000-000000000021', ['name' => 'Name-Clash']);

        $result = app(ScreenConnectReadOnlyToolset::class)->execute(
            'screenconnect_get_session_state',
            ['hostname' => 'Name-Clash'],
            $this->alpha->id,
        );

        $this->assertStringContainsString('more than one ScreenConnect-linked device', $result['error'] ?? '');
        $this->assertCount(2, $result['candidates']);
    }

    public function test_one_linked_device_is_still_preferred_over_an_unlinked_duplicate(): void
    {
        $this->linked($this->alpha, 'Stale-PC', null);
        $live = $this->linked($this->alpha, 'Stale-PC', 'aaaaaaaa-0000-0000-0000-000000000030');

        $result = app(ScreenConnectReadOnlyToolset::class)->execute(
            'screenconnect_get_session_state',
            ['hostname' => 'Stale-PC'],
            $this->alpha->id,
        );

        $this->assertSame($live->id, $result['asset_id'] ?? null, 'an unlinked stale row is not a second candidate');
    }

    // ── a missing client_id fails closed on every layer ───────────────────────

    public function test_a_missing_or_malformed_client_id_is_refused_at_the_boundary_with_no_read(): void
    {
        foreach (['screenconnect_get_session_state', 'screenconnect_list_devices'] as $tool) {
            foreach ([null, 'abc', 0, -1] as $clientId) {
                $args = ['asset_id' => $this->bravoAsset->id];
                if ($tool === 'screenconnect_list_devices') {
                    $args = ['query' => 'Bravo'];
                }
                if ($clientId !== null) {
                    $args['client_id'] = $clientId;
                }

                $response = $this->mcp($tool, $args);
                $body = $this->text($response);

                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} with client_id ".json_encode($clientId));
                $this->assertStringContainsString("client_id is required for {$tool}", $body);
                $this->assertNoBravoLeak($body, "{$tool} without a usable client_id");
            }
        }
    }

    public function test_an_unknown_client_id_is_refused(): void
    {
        $result = $this->decoded($this->mcp('screenconnect_get_session_state', [
            'client_id' => 999999,
            'asset_id' => $this->bravoAsset->id,
        ]));

        $this->assertSame('PSA client 999999 was not found.', $result['error'] ?? null);
    }

    public function test_a_null_client_is_refused_by_the_executor_and_the_toolset_too(): void
    {
        foreach (['screenconnect_get_session_state', 'screenconnect_list_devices'] as $tool) {
            $this->assertTrue(ChetDataSurfaceTools::requiresClient($tool), "{$tool} must be client-scoped");

            $viaExecutor = app(ChetDataSurfaceToolExecutor::class)->execute($tool, ['asset_id' => $this->bravoAsset->id], null);
            $this->assertSame("client_id is required for {$tool}.", $viaExecutor['error'] ?? null);

            $viaToolset = app(ScreenConnectReadOnlyToolset::class)->execute($tool, ['asset_id' => $this->bravoAsset->id], null);
            $this->assertSame("client_id is required for {$tool}.", $viaToolset['error'] ?? null);
        }
    }

    public function test_neither_asset_id_nor_hostname_is_refused(): void
    {
        $result = $this->decoded($this->mcp('screenconnect_get_session_state', ['client_id' => $this->alpha->id]));

        $this->assertSame('asset_id or hostname is required', $result['error'] ?? null);
    }

    // ── descriptions say what the key is ─────────────────────────────────────

    public function test_the_published_descriptions_name_client_id_as_the_key_and_hostname_as_a_fallback(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $tools = collect($response->json('result.tools'))->keyBy('name');

        foreach (['screenconnect_get_session_state', 'screenconnect_list_devices'] as $tool) {
            $this->assertTrue($tools->has($tool), "{$tool} must be published");
            $this->assertStringContainsString(ScreenConnectReadOnlyToolset::DEVICE_KEY_NOTE, (string) $tools[$tool]['description']);
        }
        $this->assertStringContainsString('client_id is required and is the only client key', ScreenConnectReadOnlyToolset::DEVICE_KEY_NOTE);
        $this->assertStringContainsString('hostname is a fallback', ScreenConnectReadOnlyToolset::DEVICE_KEY_NOTE);

        $schema = $tools['screenconnect_get_session_state']['inputSchema'] ?? $tools['screenconnect_get_session_state']['input_schema'] ?? [];
        $this->assertArrayHasKey('asset_id', $schema['properties'] ?? [], 'asset_id must be a declared argument, or the boundary refuses it');
        $this->assertStringContainsString('FALLBACK', (string) ($schema['properties']['hostname']['description'] ?? ''));
        $this->assertNotContains('hostname', $schema['required'] ?? [], 'hostname is no longer required: asset_id alone is enough');
    }
}
