<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B4: the `controld_onboard_client` staged verb + the client-page button, the caller
 * for the dark B1–B3 services. Every vendor exchange is a Guzzle MockHandler on the
 * injected ControlDOnboardingStaged; Http::preventStrayRequests() guards the rest.
 */
class ControlDOnboardClientTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    /**
     * B3 refuses an ambient transaction (vendor calls must run at transaction level 0),
     * so this class cannot run inside RefreshDatabase's per-test transaction. Same
     * construction as ControlDOnboardingStagedTest: no wrapping transaction, and the
     * in-memory database is re-migrated for the next test.
     */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function configure(bool $toggle = true, bool $defaults = true): void
    {
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $defaults ? $value : '');
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, $toggle ? '1' : '0');
    }

    private function aiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools = ['controld_onboard_client:staged']): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(array $client = []): array
    {
        $client = Client::factory()->create(array_merge(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.invalid'], $client));
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return compact('client', 'ticket');
    }

    private function orgRow(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    /** Bind a ControlDOnboardingStaged whose vendor answers from $responses, recording history. */
    private function vendor(array $responses): void
    {
        $this->history = [];
        $handler = new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    /** @return array<int, Response> */
    private function codeResponses(): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['max'] = 2;
        $row['ts_exp'] = now()->getTimestamp() + 7 * 86400;

        return [
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => 'testprofile01']]]),
            $this->ok(['provision' => $row]),
            $this->ok(['provisions' => [$row]]),
        ];
    }

    private function stage(array $fixture, ?string $token = null): TechnicianRun
    {
        $response = $this->callTool($token ?? $this->token(), 'controld_onboard_client', [
            'client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'new client', 'staged' => true,
        ]);
        $result = $this->decoded($response);
        $this->assertTrue($result['success'] ?? false, json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function approve(TechnicianRun $run, User $approver): TestResponse
    {
        return $this->actingAs($approver)->post(route('cockpit.approve', $run));
    }

    // ── surface & gating ────────────────────────────────────────────────────────

    public function test_verb_is_registry_backed_held_only_and_explicit_grant_only(): void
    {
        $this->assertContains('controld_onboard_client', McpToolRegistry::allToolNames());
        $this->assertNotContains('controld_stage_onboard_client', McpToolRegistry::allToolNames());
        $this->assertSame('controld_onboard_client', McpToolModes::canonicalForAlias('controld_stage_onboard_client'));
        $this->assertTrue(McpToolModes::isHeldOnly('controld_onboard_client'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('controld_onboard_client'));
        $this->assertSame('controld', McpToolRegistry::integrationForToolName('controld_onboard_client'));
        [$name, $mode] = McpToolModes::parseGrantEntry('controld_onboard_client:immediate');
        $this->assertNull($mode, 'the :immediate grant must be rejected, not silently staged');

        // Legacy full-surface token never inherits it.
        $this->configure();
        $legacy = McpConfig::rotateStaffToken(allowedTools: null, label: 'legacy');
        $fixture = $this->fixture();
        $response = $this->callTool($legacy, 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]);
        $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_verb_is_published_only_when_toggle_on_and_defaults_complete(): void
    {
        $token = $this->token();
        $this->configure(toggle: false);
        $this->assertNotContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
        $this->configure(toggle: true, defaults: false);
        $this->assertNotContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
        $this->configure();
        $this->assertContains('controld_onboard_client', array_column($this->listTools($token), 'name'));
    }

    /** RED CONTROL: toggle off → verb inert (no run, no vendor call), even with the grant. */
    public function test_verb_is_inert_with_the_toggle_off(): void
    {
        $this->configure(toggle: false);
        $fixture = $this->fixture();
        $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]);
        // Unpublished tools are refused at the grant gate before dispatch.
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed', (string) $response->json('result.content.0.text'));
        // And the executor itself refuses if reached directly.
        $direct = app(StaffControlDOnboardingToolExecutor::class)->execute('controld_stage_onboard_client', ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'], $fixture['client']->id, 'test');
        $this->assertStringContainsString('not enabled', $direct['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /** RED CONTROL: verb executes without staging → refused whatever mode; nothing created. */
    public function test_immediate_call_is_refused_and_nothing_is_created(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $this->vendor([]);
        $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'reason' => 'now', 'staged' => false]);
        $result = $this->decoded($response);
        // Staged-only grant downgrades to staged: that is the #1277 contract, and the
        // staged path then needs a ticket. Either way there is no immediate lane.
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertNull($fixture['client']->fresh()->controld_org_id);
        $this->assertCount(0, $this->history);

        // The canonical name reached directly (no alias, no downgrade) is refused by the executor.
        $executor = app(StaffControlDOnboardingToolExecutor::class);
        $direct = $executor->execute('controld_onboard_client', ['reason' => 'now'], $fixture['client']->id, 'test');
        $this->assertStringContainsString('held-only', $direct['error']);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'controld_onboard_client', 'result_status' => 'rejected', 'client_id' => $fixture['client']->id]);
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    public function test_caller_supplied_pin_prefix_icon_or_pk_is_refused_by_name(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        foreach (['pin' => '1234', 'name_prefix' => 'ACME-', 'icon' => 'router', 'org_pk' => 'x', 'profile_id' => 'p'] as $key => $value) {
            $response = $this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true, $key => $value]);
            $error = (string) $this->decoded($response)['error'];
            $this->assertStringContainsString("refused: {$key}", $error);
            $this->assertStringNotContainsString('1234', $error);
        }
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%1234%')->count());
    }

    // ── step 1: organization ────────────────────────────────────────────────────

    public function test_unmapped_client_stages_the_organization_step_and_second_admin_approval_binds_the_mapping(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('organization', $run->proposed_meta['redacted_params']['step']);
        $this->assertStringContainsString('step 1 of 2', $run->proposed_content);
        $this->assertStringContainsString('Synthetic Organization', $run->proposed_content);
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'staging makes no intent and no vendor call');

        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->orgRow()]])]);
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $this->approve($run, $approver);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['organization', 'bound', $approver->id], [$intent->operation, $intent->state, (int) $intent->actor_id]);
        $this->assertCount(2, $this->history);
        $this->assertSame('/organizations/suborg', $this->history[0]['request']->getUri()->getPath());
        parse_str((string) $this->history[0]['request']->getBody(), $sent);
        $this->assertSame(['name' => 'Synthetic Organization', 'contact_email' => 'synthetic@example.invalid', 'twofa_req' => '1', 'stats_endpoint' => 'synthetic-region'], $sent);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'controld_stage_onboard_client', 'result_status' => 'executed', 'run_id' => $run->id, 'approver_user_id' => $approver->id]);
    }

    /** RED CONTROL: a second `organization` intent for an already-mapped client is never staged. */
    public function test_mapped_client_never_stages_a_second_organization_step(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'existingOrg01']);
        $run = $this->stage($fixture);
        $this->assertSame('code', $run->proposed_meta['redacted_params']['step'], 'a mapped client proposes the code step, never organization');

        // Even a proposal that WAS staged as organization refuses at approval once mapped.
        $unmapped = $this->fixture();
        $orgRun = $this->stage($unmapped);
        $this->assertSame('organization', $orgRun->proposed_meta['redacted_params']['step']);
        $unmapped['client']->forceFill(['controld_org_id' => 'racedOrg01'])->save();
        $this->vendor([]);
        $response = $this->approve($orgRun, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $orgRun->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::where('operation', 'organization')->count());
        $this->assertCount(0, $this->history);
        $this->assertSame('racedOrg01', $unmapped['client']->fresh()->controld_org_id);
    }

    // ── step 2: code ────────────────────────────────────────────────────────────

    public function test_mapped_client_stages_the_code_step_and_approval_stores_the_code_encrypted_without_leaking_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['controld_org_id' => 'testorg001']);
        $run = $this->stage($fixture);
        $this->assertSame('code', $run->proposed_meta['redacted_params']['step']);
        $this->assertStringContainsString('step 2 of 2', $run->proposed_content);
        $this->assertStringContainsString('No deactivation PIN and no hostname prefix', $run->proposed_content);

        $this->vendor($this->codeResponses());
        $approver = User::factory()->admin()->create(['is_active' => true]);
        $response = $this->approve($run, $approver);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $client = $fixture['client']->fresh();
        $this->assertSame('0123456789abcdef0123456789abcdef', $client->controld_provisioning_code);
        $this->assertNull($client->controld_deactivation_pin);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['code', 'bound', 'fixture001'], [$intent->operation, $intent->state, $intent->vendor_pk]);
        $this->assertCount(4, $this->history);
        $sent = json_decode((string) $this->history[2]['request']->getBody(), true);
        $this->assertSame('desktop-windows', $sent['icon']);
        $this->assertArrayNotHasKey('deactivation_pin', $sent);
        $this->assertArrayNotHasKey('name_prefix', $sent);
        $this->assertSame('testprofile01', $sent['profile_id']);

        // RED CONTROL: the code (and any PIN) never appears in an audit row, the run, or the session flash.
        $code = '0123456789abcdef0123456789abcdef';
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', "%{$code}%")->count());
        $this->assertStringNotContainsString($code, json_encode(TechnicianRun::findOrFail($run->id)->toArray()));
        $this->assertStringNotContainsString($code, json_encode(session()->all()));
        $this->assertStringNotContainsString($code, json_encode($intent->toArray()));
    }

    /** RED CONTROL: approver == stager → refused; nothing created. */
    public function test_the_stager_cannot_approve_their_own_proposal(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client'])
            ->assertRedirect()->assertSessionHas('success');
        $run = TechnicianRun::sole();
        $this->assertSame($admin->id, $run->proposed_meta['staged_by_user_id']);

        $this->vendor([]);
        $this->approve($run, $admin);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'blocked']);
        $this->assertStringContainsString('approver is the stager', TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->value('summary'));

        // A different active Admin may approve it.
        $this->vendor([$this->ok(['organization' => $this->orgRow()]), $this->ok(['sub_organizations' => [$this->orgRow()]])]);
        $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('syntheticOrg01', $fixture['client']->fresh()->controld_org_id);
    }

    /** RED CONTROL: non-admin stages (button) or approves → refused; nothing created. */
    public function test_non_admin_cannot_stage_from_the_button_or_approve(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->actingAs($tech)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, TechnicianRun::count());

        $run = $this->stage($fixture);
        $this->vendor([]);
        $this->approve($run, $tech);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);

        $inactive = User::factory()->admin()->create(['is_active' => false]);
        $this->approve($run, $inactive);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /** RED CONTROL: the button is not rendered with the toggle off (nor for a non-admin). */
    public function test_button_is_rendered_only_for_an_admin_with_the_toggle_on(): void
    {
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $tech = User::factory()->tech()->create(['is_active' => true]);

        $this->configure(toggle: false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false)->assertDontSee(route('clients.controld.onboard', $fixture['client']));
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])->assertNotFound();

        $this->configure(toggle: true, defaults: false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false);

        $this->configure();
        $this->actingAs($tech)->get(route('clients.show', $fixture['client']))->assertOk()->assertDontSee('id="controld-onboarding"', false);
        $this->actingAs($admin)->get(route('clients.show', $fixture['client']))->assertOk()
            ->assertSee('id="controld-onboarding"', false)->assertSee('Stage onboarding step 1 for approval')->assertSee(route('clients.controld.onboard', $fixture['client']));
    }

    public function test_button_stages_the_same_proposal_as_the_verb_and_is_idempotent(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client'])->assertRedirect();
        $run = TechnicianRun::sole();
        $this->assertSame('controld_stage_onboard_client', $run->action_type);
        $this->assertSame('organization', $run->proposed_meta['redacted_params']['step']);

        // The verb finds the same live proposal rather than a second one.
        $again = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'again', 'staged' => true]));
        $this->assertTrue($again['idempotent']);
        $this->assertSame($run->id, $again['run_id']);
        $this->assertSame(1, TechnicianRun::count());

        // Another client's ticket is refused.
        $other = $this->fixture();
        $this->actingAs($admin)->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $other['ticket']->id, 'reason' => 'x'])->assertSessionHasErrors('ticket_id');
    }

    public function test_vendor_readonly_rejection_is_terminal_and_names_the_fix_without_creating_anything(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->vendor([new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $response = $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertNull($fixture['client']->fresh()->controld_org_id);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['rejected', 40301, null], [$intent->state, $intent->reason_code, $intent->active_client_id]);
        $this->assertStringContainsString('Read token', (string) session('error'));
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $run->id, 'result_status' => 'error']);
        // A fresh proposal is possible after the key is fixed.
        $this->assertNotNull($this->stage($fixture));
    }

    public function test_uncertain_outcome_is_terminal_never_rearmed_and_keeps_the_lock(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->vendor([new Response(503, [], 'upstream')]);
        $response = $this->approve($run, User::factory()->admin()->create(['is_active' => true]));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['uncertain', $fixture['client']->id], [$intent->state, (int) $intent->active_client_id]);
        $this->assertStringContainsString('HARD FAULT', (string) session('error'));
        $this->assertStringContainsString('Do NOT re-approve', (string) session('error'));
        // A second proposal for this client is refused while the intent holds the lock.
        $refused = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'retry', 'staged' => true]));
        $this->assertStringContainsString('already owns this client', $refused['error']);
    }

    public function test_client_without_a_contact_email_is_refused_at_staging_with_no_vendor_call(): void
    {
        $this->configure();
        $this->aiActor();
        $fixture = $this->fixture(['email' => null]);
        $result = $this->decoded($this->callTool($this->token(), 'controld_onboard_client', ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'x', 'staged' => true]));
        $this->assertStringContainsString('contact email', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_toggle_cannot_be_turned_on_while_defaults_are_incomplete(): void
    {
        $this->configure(toggle: false, defaults: false);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding', 'enabled' => '1'])->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(ControlDConfig::isOnboardingEnabled());
        $this->configure(toggle: false);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding', 'enabled' => '1'])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(ControlDConfig::isOnboardingEnabled());
        $this->actingAs($admin)->get(route('settings.integrations'))->assertOk()->assertSee('Onboarding enabled')->assertSee('id="controld_onboarding_enabled"', false);
        $this->actingAs($admin)->post(route('settings.integrations.toggle'), ['integration' => 'controld_onboarding'])->assertRedirect();
        $this->assertFalse(ControlDConfig::isOnboardingEnabled());
        $this->assertFalse(ControlDConfig::isOnboardingEnabled(), 'default is off');
    }
}
