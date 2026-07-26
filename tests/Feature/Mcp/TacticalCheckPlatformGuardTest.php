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
 * psa-0pb9m — the create-check platform guard (R2: FAIL CLOSED, evidence not
 * assertion).
 *
 * Root-cause-class prevention: tactical_create_check could attach a script
 * check whose script cannot run on the target agent's platform (e.g. a
 * PowerShell/Windows-only script on a Mac). Tactical runs it anyway and it
 * fails on 100% of executions forever — manufacturing exactly the
 * "one check on every Mac, fails on all of them" defect. The guard fails
 * CLOSED before any upstream call: an agent whose platform is unknown is
 * refused (remedy: sync devices), a provably incompatible agent create is
 * refused outright, script metadata without a usable platform signal is
 * refused (absence is not compatibility), and a platform-bound check on a
 * POLICY is allowed only on SERVER-DERIVED MEMBERSHIP PROOF — the policy's
 * current member agents enumerated live from Tactical, every one on a
 * compatible platform. The R1 acknowledge_platform_risk boolean is GONE
 * (psa-0pb9m R2 HIGH): a caller-assertable claim was not evidence, and an AI
 * caller could simply retry with it set. The same invariant is enforced again
 * at the shared TacticalClient::createCheck boundary
 * (TacticalCheckPlatformGuard) so no caller path can bypass it — covered by
 * the client-boundary tests at the bottom of this file.
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

    public function test_unknown_agent_platform_is_refused_before_any_upstream_create(): void
    {
        $this->configureTactical();
        $this->configureAiActor();
        // No plat, unrecognizable os — FAIL CLOSED (revise): an unknown
        // platform is precisely the state the original wrong-platform
        // always-failing check shipped in. The remedy is a device sync.
        $fixture = $this->macFixture(plat: null, os: null);
        $this->seedLocalScript();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool($this->token(), [
            'client_id' => $fixture['client']->id,
            'reason' => 'Platform unknown — proceed.',
            'hostname' => 'MAC-01',
            'confirm_hostname' => 'MAC-01',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('unknown', $text);
        $this->assertStringContainsString('tactical:sync-devices', $text);

        $rejected = TechnicianActionLog::query()
            ->where('action_type', 'tactical_create_check')
            ->where('result_status', 'rejected')
            ->exists();
        $this->assertTrue($rejected, 'unknown-platform refusal must be audited');
    }

    public function test_policy_target_with_a_mac_member_is_refused_pre_write_naming_the_member(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        // SERVER-DERIVED membership proof (R2): the policy's related payload +
        // the fleet list are read live; the Mac member is discovered here, not
        // asserted away by a caller boolean.
        $tactical->shouldReceive('getAutomationPolicyRelated')->once()->with(7)->andReturn([
            'pk' => 7, 'name' => 'Workstations',
            'agents' => [
                ['id' => 1, 'hostname' => 'PC-01', 'agent_id' => 'agent-pc1', 'client' => 'Acme', 'site' => 'Main'],
                ['id' => 2, 'hostname' => 'MAC-01', 'agent_id' => 'agent-mac', 'client' => 'Acme', 'site' => 'Main'],
            ],
            'workstation_clients' => [], 'server_clients' => [],
            'workstation_sites' => [], 'server_sites' => [],
            'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
        ]);
        $tactical->shouldReceive('getAgents')->once()->andReturn([
            ['agent_id' => 'agent-pc1', 'hostname' => 'PC-01', 'plat' => 'windows', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
        ]);
        // The whole point: NO write happens — a mixed-membership policy is
        // refused on evidence, with no caller-assertable override.
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        // The refusal is an informed affordance: it names the incompatible
        // member and says there is no override.
        $this->assertStringContainsString('MAC-01', $text);
        $this->assertStringContainsString('no override', $text);
        $this->assertStringNotContainsString('acknowledge_platform_risk', $text, 'the caller-assertable escape hatch is gone');
    }

    public function test_policy_target_with_all_windows_membership_proven_creates_with_the_proof_note(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldReceive('getAutomationPolicyRelated')->with(7)->andReturn([
            'pk' => 7, 'name' => 'Workstations',
            'agents' => [
                ['id' => 1, 'hostname' => 'PC-01', 'agent_id' => 'agent-pc1', 'client' => 'Acme', 'site' => 'Main'],
            ],
            'workstation_clients' => [], 'server_clients' => [],
            'workstation_sites' => [], 'server_sites' => [],
            'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
        ]);
        $tactical->shouldReceive('getAgents')->andReturn([
            ['agent_id' => 'agent-pc1', 'hostname' => 'PC-01', 'plat' => 'windows', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
        ]);
        $tactical->shouldReceive('createCheck')->once()->andReturn('Script Check was added!');
        $tactical->shouldReceive('getPolicyChecks')->once()->with(7)->andReturn([
            ['id' => 212, 'check_type' => 'script', 'script' => 102],
        ]);
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector; policy is Windows-only.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('platform_note', $payload);
        $this->assertStringContainsStringIgnoringCase('membership proof', $payload['platform_note']);
        $this->assertStringContainsStringIgnoringCase('added to the policy later', $payload['platform_note']);
    }

    public function test_policy_target_is_refused_when_membership_cannot_be_read(): void
    {
        $this->configureTactical();
        $this->configureAiActor();

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getPolicies')->once()->andReturn([['id' => 7, 'name' => 'Workstations']]);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)
            ->andReturn($this->upstreamScripts('powershell', ['windows']));
        $tactical->shouldReceive('getAutomationPolicyRelated')->once()->with(7)
            ->andThrow(new \App\Services\Tactical\TacticalClientException('boom'));
        // Unverifiable membership is UNKNOWN, and unknown is never compatible.
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $this->seedLocalScript();

        $response = $this->callTool($this->token(), [
            'reason' => 'Policy-wide detector.',
            'policy_id' => 7,
            'confirm_policy_name' => 'Workstations',
            'script_name' => 'Fleet Health Detector',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('could not be read', (string) $response->json('result.content.0.text'));
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
        $this->assertArrayNotHasKey('platform_note', $payload);
    }

    // ── Client-boundary enforcement (TacticalCheckPlatformGuard) ─────────────
    // The MCP pre-checks above are defence in depth; the MANDATORY gate lives
    // where every check creation converges: TacticalClient::createCheck. These
    // tests drive a REAL client over a mock transport with an EMPTY response
    // queue — if the guard let the call through, Guzzle's MockHandler would
    // throw "queue is empty", so a passing refusal proves NOTHING was sent.

    private function realClient(array $responses = []): TacticalClient
    {
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));

        return new TacticalClient(new \GuzzleHttp\Client([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'headers' => ['X-API-KEY' => 'k', 'Content-Type' => 'application/json'],
        ]));
    }

    public function test_client_boundary_refuses_wrong_platform_agent_create_with_no_http_sent(): void
    {
        $this->macFixture(); // darwin agent 'agent-mac'
        $this->seedLocalScript(); // powershell, tactical_script_id 102

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/powershell/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Wrong platform',
        ]);
    }

    public function test_client_boundary_refuses_unknown_agent_platform_with_no_http_sent(): void
    {
        $this->macFixture(plat: null, os: null);
        $this->seedLocalScript();

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/tactical:sync-devices/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Unknown platform',
        ]);
    }

    public function test_client_boundary_refuses_script_missing_from_catalog_and_claim(): void
    {
        $this->macFixture();
        // Script 999 is in neither the local catalog nor a caller claim —
        // attaching it blind is refused (fail closed), remedy named.

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/tactical:sync-scripts/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 999,
            'name' => 'Uncatalogued script',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_membership_includes_an_incompatible_agent(): void
    {
        $this->seedLocalScript(); // powershell → cannot run on darwin/linux

        // The guard reads membership over the SAME client: exactly two queued
        // read responses (related, then the fleet list). Refusal must consume
        // only those — a POST would hit an empty mock queue and blow up with a
        // different exception, so the expected TacticalClientException proves
        // no write was sent.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Workstations',
                'agents' => [['id' => 2, 'hostname' => 'MAC-01', 'agent_id' => 'agent-mac', 'client' => 'Acme', 'site' => 'Main']],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-mac', 'hostname' => 'MAC-01', 'plat' => 'darwin', 'monitoring_type' => 'workstation', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/MAC-01/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Policy check',
        ]);
    }

    public function test_client_boundary_refuses_non_script_policy_check_without_all_windows_proof(): void
    {
        // The R2 security drive: policy=7/check_type=ping previously bypassed
        // the guard entirely and returned HTTP_SENT. Non-script checks are
        // Windows-only (vendor constraint), so a policy with a linux member
        // refuses before any write.
        $client = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'pk' => 7, 'name' => 'Mixed',
                'agents' => [['id' => 3, 'hostname' => 'LNX-01', 'agent_id' => 'agent-lnx', 'client' => 'Acme', 'site' => 'Main']],
                'workstation_clients' => [], 'server_clients' => [],
                'workstation_sites' => [], 'server_sites' => [],
                'is_default_server_policy' => false, 'is_default_workstation_policy' => false,
            ])),
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                ['agent_id' => 'agent-lnx', 'hostname' => 'LNX-01', 'plat' => 'linux', 'monitoring_type' => 'server', 'client_name' => 'Acme', 'site_name' => 'Main'],
            ])),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/LNX-01/');

        $client->createCheck([
            'policy' => 7,
            'check_type' => 'ping',
            'name' => 'Ping check',
        ]);
    }

    public function test_client_boundary_refuses_an_empty_script_meta_claim_with_no_http_sent(): void
    {
        // The R2 security drive: scriptMeta=[] previously resolved to
        // shell=null/supported_platforms=null, an empty blocked set, and
        // HTTP_SENT. Absence of metadata is not compatibility.
        $this->macFixture(); // darwin agent

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/neither a shell nor any/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 555,
            'name' => 'Empty meta claim',
        ], scriptMeta: []);
    }

    public function test_client_boundary_refuses_a_catalog_row_without_platform_signal(): void
    {
        $this->macFixture();
        // The schema requires a shell string, so the no-signal shape a sync
        // can actually produce is an EMPTY one — same refusal semantics.
        TacticalScript::create([
            'tactical_script_id' => 103,
            'name' => 'Signal-less script',
            'shell' => '',
            'supported_platforms' => null,
            'synced_at' => now(),
        ]);

        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/neither/');

        $this->realClient()->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 103,
            'name' => 'Uncheckable catalog row',
        ]);
    }

    public function test_client_boundary_refuses_policy_create_when_membership_read_fails(): void
    {
        $this->seedLocalScript();

        // The related read fails (empty mock queue → transport error). The
        // guard must convert that into a refusal, never proceed unproven.
        $this->expectException(\App\Services\Tactical\TacticalClientException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        $this->realClient()->createCheck([
            'policy' => 7,
            'check_type' => 'script',
            'script' => 102,
            'name' => 'Policy check',
        ]);
    }

    public function test_client_boundary_allows_compatible_create_and_accepts_caller_script_meta(): void
    {
        $this->macFixture(); // darwin agent

        // The script is NOT in the local catalog, but the caller supplies the
        // vendor-sourced meta claim (the provisioner's exact situation right
        // after creating its script upstream) — darwin-compatible, so the
        // create goes through and the queued response is consumed.
        $result = $this->realClient([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode('Script Check was added!')),
        ])->createCheck([
            'agent' => 'agent-mac',
            'check_type' => 'script',
            'script' => 555,
            'name' => 'Shipped macOS check',
        ], scriptMeta: ['shell' => 'shell', 'supported_platforms' => ['darwin']]);

        $this->assertSame('Script Check was added!', $result);
    }
}
