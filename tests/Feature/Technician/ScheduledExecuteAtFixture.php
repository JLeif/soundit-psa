<?php

namespace Tests\Feature\Technician;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\Scheduled\ScheduledClock;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * The shared scheduled-execution fixture: a frozen clock, a Tactical vendor whose every
 * Guzzle request terminates in the handler (no live socket, no vendor), and one
 * client/asset/ticket triple wired the way the adapters demand.
 *
 * $wire is the count of requests that actually reached the transport. It is the only
 * honest proof that a scheduled call did not execute now, so every test that claims
 * "nothing ran" asserts on it rather than on a status string.
 *
 * Extracted from ScheduledExecuteAtTest so the immediate-lane suite can share the exact
 * same fixture without inheriting (and therefore re-running) its cases.
 */
trait ScheduledExecuteAtFixture
{
    use RefreshDatabase;

    protected const AT = '2026-09-16T03:30:00+00:00';

    protected CarbonImmutable $time;

    protected User $user;

    protected Client $client;

    protected Asset $asset;

    protected Ticket $ticket;

    protected TechnicianRun $run;

    protected array $wire = [];

    protected int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->time = CarbonImmutable::parse('2026-09-16 00:00:00', 'UTC');
        $clock = Mockery::mock(ScheduledClock::class);
        $clock->shouldReceive('now')->andReturnUsing(fn () => $this->time);
        $clock->shouldReceive('healthy')->andReturn(true);
        $this->app->instance(ScheduledClock::class, $clock);
        Setting::setValue('tactical_enabled', '1');
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-key');
        $agent = ['agent_id' => 'fixture-agent', 'site' => 17, 'hostname' => 'fixture-device', 'status' => 'online'];
        $clients = [['id' => 4, 'name' => 'Fixture Client', 'sites' => [['id' => 17, 'name' => 'Main Site', 'client' => 4]]]];
        $http = new HttpClient(['base_uri' => 'https://tactical.example.test/', 'handler' => HandlerStack::create(function ($request) use ($agent, $clients) {
            if ($request->getMethod() === 'GET') {
                $this->reads++;
                $body = str_starts_with($request->getUri()->getPath(), '/clients/') ? $clients : $agent;

                return Create::promiseFor(new Response(200, [], json_encode($body)));
            }
            $this->wire[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true)];

            // Tactical's exact maintenance reply; the settle path classifies anything else as uncertain.
            return Create::promiseFor(new Response(200, [], json_encode('The agent was updated successfully')));
        })]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
        $this->user = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $this->client = Client::factory()->create(['tactical_site_id' => 'Fixture Client|Main Site']);
        $this->asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'fixture-device']);
        TacticalAsset::create(['asset_id' => $this->asset->id, 'agent_id' => 'fixture-agent', 'hostname' => 'fixture-device', 'status' => 'online']);
        $this->ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        $this->ticket->assets()->attach($this->asset);
    }

    protected function bearer(?array $tools, string $label = 'synthetic-execute-at'): string
    {
        return \App\Support\McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    protected function mcp(string $bearer, string $tool, array $arguments): array
    {
        $reply = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ])->assertOk();
        $text = (string) $reply->json('result.content.0.text');
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['error' => $text, 'raw' => true];
    }

    protected function surface(string $bearer): array
    {
        $reply = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])->assertOk();
        $out = [];
        foreach ((array) $reply->json('result.tools') as $tool) {
            $out[$tool['name']] = $tool;
        }

        return $out;
    }

    protected function maintenanceArgs(array $extra = []): array
    {
        return array_merge(['client_id' => $this->client->id, 'asset_id' => $this->asset->id, 'ticket_id' => $this->ticket->id,
            'enabled' => true, 'reason' => 'Synthetic control', 'staged' => true], $extra);
    }

    protected function stageWithExecuteAt(array $grant = ['tactical_set_maintenance:staged'], array $extra = []): TechnicianRun
    {
        $result = $this->mcp($this->bearer($grant), 'tactical_set_maintenance', $this->maintenanceArgs(array_merge(['execute_at' => self::AT], $extra)));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->run = TechnicianRun::findOrFail($result['run_id']);

        return $this->run;
    }
}
