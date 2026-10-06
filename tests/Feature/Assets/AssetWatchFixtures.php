<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetWatch;
use App\Models\Client;
use App\Models\SignalInboxEntry;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Shared synthetic fixtures for the asset watch suites (card K3VEcxtw).
 *
 * G-13: every name, host and address here is invented (example.test hosts,
 * RFC 5737 addresses, made-up agent ids); nothing comes from a real client.
 * The Tactical payloads carry only fields named in AgentTableSerializer /
 * AgentSerializer at the pinned upstream commit
 * (tests/Fixtures/tactical/upstream_producers.json): agent_id, client_name,
 * site_name, hostname, status, last_seen.
 */
trait AssetWatchFixtures
{
    /** @var array<string, string> label => plaintext token */
    private array $tokens = [];

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $tacticalRequests = [];

    private const OWNER_A = 'synthetic-agent-a';

    private const OWNER_B = 'synthetic-agent-b';

    private const WATCH_TOOLS = ['create_asset_watch', 'list_asset_watches', 'remove_asset_watch', 'poll_signals'];

    private function enableTactical(): void
    {
        \App\Models\Setting::setValue('tactical_api_url', 'https://rmm.example.test/');
        \App\Models\Setting::setEncrypted('tactical_api_key', 'synthetic-key');
        \App\Models\Setting::setValue('tactical_enabled', '1');
    }

    /** Mint one token per label ONCE (rotating a label invalidates its previous plaintext). */
    private function tokenFor(string $label, array $grants = self::WATCH_TOOLS): string
    {
        return $this->tokens[$label] ??= McpConfig::rotateStaffToken(allowedTools: $grants, label: $label);
    }

    private function mcpAs(string $label, string $method, array $params): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($label)])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
    }

    /** @return array<string, mixed> */
    private function callAs(string $label, string $tool, array $arguments): array
    {
        $r = $this->mcpAs($label, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        $r->assertOk();

        $text = (string) $r->json('result.content.0.text');
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['_raw' => $text, '_isError' => $r->json('result.isError')];
    }

    private function mappedClient(string $site = 'Synthetic Co|Main'): Client
    {
        return Client::factory()->create(['tactical_site_id' => $site, 'is_active' => true]);
    }

    /** A Tactical-only asset linked to agent $agentId. */
    private function tacticalAsset(Client $client, string $agentId = 'AGENT-SYN-1', ?bool $rmmOnline = null, array $assetAttrs = []): Asset
    {
        [$clientName, $siteName] = array_pad(explode('|', (string) $client->tactical_site_id, 2), 2, '');
        $asset = Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'WS-'.substr(md5($agentId), 0, 6),
            'rmm_online' => $rmmOnline,
            'ip_address' => '192.0.2.10',
        ], $assetAttrs));
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => $asset->hostname,
            'client_name' => $clientName,
            'site_name' => $siteName,
            'status' => $rmmOnline === true ? 'online' : 'offline',
        ]);

        return $asset->refresh();
    }

    private function bindTactical(array $responses): void
    {
        $this->tacticalRequests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->tacticalRequests));
        $http = new GuzzleClient(['base_uri' => 'https://rmm.example.test/api/', 'handler' => $stack, 'timeout' => 30]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function agentResponse(string $agentId, string $status, ?Carbon $lastSeen): Response
    {
        return new Response(200, [], json_encode(array_filter([
            'agent_id' => $agentId,
            'hostname' => 'WS-SYN',
            'status' => $status,
            'last_seen' => $lastSeen?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ], fn ($v) => $v !== null)));
    }

    /** @return array<int, array<string, mixed>> */
    private function listAgentsPayload(Client $client, string $agentId, string $status, ?Carbon $lastSeen, string $hostname): array
    {
        [$clientName, $siteName] = array_pad(explode('|', (string) $client->tactical_site_id, 2), 2, '');

        return [array_filter([
            'agent_id' => $agentId,
            'hostname' => $hostname,
            'client_name' => $clientName,
            'site_name' => $siteName,
            'status' => $status,
            'last_seen' => $lastSeen?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'local_ips' => '192.0.2.10',
        ], fn ($v) => $v !== null)];
    }

    /** Inbox rows delivered to $label's destinations. */
    private function inboxFor(string $label): \Illuminate\Support\Collection
    {
        return SignalInboxEntry::query()
            ->whereHas('destination', fn ($q) => $q->where('mcp_token_label', $label))
            ->get();
    }

    private function watchRow(int $id): AssetWatch
    {
        return AssetWatch::query()->findOrFail($id);
    }
}
