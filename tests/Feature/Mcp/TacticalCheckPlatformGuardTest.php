<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * psa-0pb9m — the create-check platform guard.
 *
 * Root-cause-class prevention: tactical_create_check could attach a script
 * check whose script cannot run on the target agent's platform (e.g. a
 * PowerShell/Windows-only script on a Mac). Tactical runs it anyway and it
 * fails on 100% of executions forever — manufacturing exactly the
 * "one check on every Mac, fails on all of them" defect. Agent-target creates
 * with a provably incompatible script are REJECTED before any upstream call;
 * policy-target creates with a Windows-bound script succeed but carry an
 * explicit platform_warning (policies can cover mixed fleets). Unknown
 * platform makes no claim and does not block.
 */
class TacticalCheckPlatformGuardTest extends TestCase
{
    use RefreshDatabase;

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    private function configureAiActor(): void
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function token(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['tactical_create_check'], label: 'opsbot');
    }

    private function callTool(string $token, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'tactical_create_check', 'arguments' => $arguments],
            ]);
    }

    /** @return array{client: Client} */
    private function macFixture(?string $plat = 'darwin', ?string $os = 'Darwin 23.6.0 arm64'): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);
        Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'MAC-01',
            'name' => 'MAC-01',
        ]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac',
            'hostname' => 'MAC-01',
            'plat' => $plat,
            'os' => $os,
            'status' => 'online',
            'synced_at' => now(),
        ]);

        return compact('client');
    }

    /** @return array<int, array<string, mixed>> */
    private function upstreamScripts(string $shell, array $supportedPlatforms): array
    {
        return [
            [
                'id' => 102,
                'name' => 'Fleet Health Detector',
                'script_type' => 'userdefined',
                'shell' => $shell,
                'args' => [],
                'env_vars' => [],
                'supported_platforms' => $supportedPlatforms,
            ],
        ];
    }

    private function seedLocalScript(): void
    {
        TacticalScript::create([
            'tactical_script_id' => 102,
            'name' => 'Fleet Health Detector',
            'shell' => 'powershell',
            'synced_at' => now(),
        ]);
    }

    public function test_windows_only_script_on_a_mac_agent_is_rejected_before_any_upstream_create(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a health check to this Mac.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('darwin', $text);
        $this->assertStringContainsString('fail on every run', $text);

        $rejected = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->where('result_status', 'rejected')
            ->exists();
        $this->assertTrue($rejected, 'platform-guard rejection must be audited');
    }

    public function test_cmd_shell_script_on_a_mac_agent_is_rejected_without_metadata(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('cmd', []));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a health check to this Mac.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('cmd', (string) $response->json('result.content.0.text'));
    }

    public function test_darwin_supported_script_on_a_mac_agent_is_created(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        $fixture = $this->macFixture();
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('shell', ['darwin']));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac')->andReturn([
            ['id' => 310, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Add a genuine macOS health check.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(310, $payload['check_id']);
        $this->assertArrayNotHasKey('platform_warning', $payload);
    }

    public function test_unknown_agent_platform_makes_no_claim_and_does_not_block(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        // No plat, unrecognizable os — the guard must not guess.
        $fixture = $this->macFixture(plat: null, os: null);
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac')->andReturn([
            ['id' => 311, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Platform unknown — proceed.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
    }

    public function test_policy_target_with_windows_bound_script_succeeds_with_platform_warning(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 212, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('platform_warning', $payload);
        $this->assertStringContainsStringIgnoringCase('macos/linux', $payload['platform_warning']);
    }

    public function test_policy_target_with_cross_platform_script_has_no_warning(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('shell', []));
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 213, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('platform_warning', $payload);
    }
}
