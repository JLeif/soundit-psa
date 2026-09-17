<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\Scheduled\ExecuteAt;
use App\Services\Technician\Scheduled\ScheduledClock;
use App\Services\Technician\Scheduled\TacticalDispatch;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Points 1, 2, 4 and 5 of the ruled design: `execute_at` is an optional tool parameter on
 * the 13 capabilities with a scheduled adapter; a staged proposal carrying it is admitted
 * into scheduled_authorizations by the ordinary cockpit Approve with the derived window
 * [execute_at, execute_at + 60 min]; every other tool refuses it by name; the removed
 * global toggle has no effect anywhere. Tactical fixture mirrors ScheduledTacticalTest:
 * every Guzzle request terminates in the handler, no live socket or vendor.
 */
class ScheduledExecuteAtTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-09-16T03:30:00+00:00';

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

    private function bearer(?array $tools, string $label = 'synthetic-execute-at'): string
    {
        return \App\Support\McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function mcp(string $bearer, string $tool, array $arguments): array
    {
        $reply = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ])->assertOk();
        $text = (string) $reply->json('result.content.0.text');
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['error' => $text, 'raw' => true];
    }

    private function surface(string $bearer): array
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

    private function maintenanceArgs(array $extra = []): array
    {
        return array_merge(['client_id' => $this->client->id, 'asset_id' => $this->asset->id, 'ticket_id' => $this->ticket->id,
            'enabled' => true, 'reason' => 'Synthetic control', 'staged' => true], $extra);
    }

    // ── point 1: the parameter, its bounds and its advertisement ─────────────

    public function test_execute_at_is_advertised_only_for_the_thirteen_adapter_capabilities(): void
    {
        // Sensitive action tools are never on the legacy full-surface token: grant the 13
        // adapter capabilities plus the two excluded Tactical tools explicitly. The CIPP
        // integration is configured here so its surface is live alongside Tactical's.
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'write-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-secret');
        $grants = array_merge(ExecuteAt::CANONICAL_TOOLS, ['tactical_run_script', 'tactical_install_approved_patches', 'tactical_open_remote_control']);
        $tools = $this->surface($this->bearer(array_map(fn ($t) => $t.':immediate', $grants), 'synthetic-wide'));
        $this->assertCount(16, array_intersect_key($tools, array_flip($grants)), implode(',', array_keys($tools)));
        $expected = ['cipp_set_mailbox_forwarding', 'cipp_set_mailbox_out_of_office', 'cipp_set_mailbox_delegate',
            'cipp_set_mailbox_gal_visibility', 'cipp_convert_mailbox', 'tactical_run_command', 'tactical_reboot_device',
            'tactical_shutdown_device', 'tactical_recover_mesh', 'tactical_set_maintenance', 'tactical_start_service',
            'tactical_stop_service', 'tactical_restart_service'];
        $this->assertEqualsCanonicalizing($expected, ExecuteAt::CANONICAL_TOOLS);
        $advertised = [];
        foreach ($tools as $name => $tool) {
            if (isset($tool['inputSchema']['properties']['execute_at'])) {
                $advertised[] = $name;
            }
        }
        $this->assertEqualsCanonicalizing($expected, $advertised, 'execute_at advertised on: '.implode(',', $advertised));
        $this->assertArrayNotHasKey('execute_at', $tools['tactical_run_script']['inputSchema']['properties'] ?? []);
        $this->assertArrayNotHasKey('execute_at', $tools['tactical_install_approved_patches']['inputSchema']['properties'] ?? []);
        $this->assertStringContainsString('ISO-8601', $tools['tactical_set_maintenance']['inputSchema']['properties']['execute_at']['description']);
    }

    public function test_staged_only_grant_still_advertises_execute_at(): void
    {
        $tools = $this->surface($this->bearer(['tactical_set_maintenance:staged', 'tactical_run_script:staged']));
        $this->assertArrayHasKey('execute_at', $tools['tactical_set_maintenance']['inputSchema']['properties']);
        $this->assertArrayNotHasKey('execute_at', $tools['tactical_run_script']['inputSchema']['properties'] ?? []);
    }

    public static function unsupportedTools(): array
    {
        return [
            ['tactical_run_script', 'unsupported_scheduling_type:tactical_stage_script'],
            ['tactical_install_approved_patches', 'unsupported_scheduling_type:tactical_stage_install_approved_patches'],
            ['tactical_open_remote_control', 'unsupported_scheduling_type:tactical_stage_open_remote_control'],
            ['add_ticket_note', 'unsupported_scheduling_type:add_ticket_note'],
            ['send_email', 'unsupported_scheduling_type:stage_email'],
        ];
    }

    #[DataProvider('unsupportedTools')]
    public function test_execute_at_on_an_unsupported_tool_is_refused_by_name_and_nothing_runs(string $tool, string $reason): void
    {
        $bearer = $this->bearer([$tool.':staged', $tool]);
        $result = $this->mcp($bearer, $tool, $this->maintenanceArgs(['execute_at' => self::AT, 'script_id' => 1, 'body' => 'x', 'subject' => 'x', 'type' => 'control', 'confirm_install' => 'yes', 'confirm_hostname' => 'fixture-device']));
        $this->assertSame($reason, $result['error'] ?? null, json_encode($result));
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, $this->reads);
        $this->assertCount(0, $this->wire);
        $this->assertDatabaseCount('scheduled_authorizations', 0);
    }

    public static function badTimes(): array
    {
        return [
            'no offset' => ['2026-09-16T03:30:00', 'execute_at_invalid'],
            'garbage' => ['tomorrow at noon', 'execute_at_invalid'],
            'not a string' => [1758000000, 'execute_at_invalid'],
            'past' => ['2026-09-15T23:59:00+00:00', 'execute_at_not_in_future'],
            'now' => ['2026-09-16T00:00:00+00:00', 'execute_at_not_in_future'],
            'beyond seven days' => ['2026-09-23T00:00:01+00:00', 'execute_at_too_far_ahead'],
            'empty' => ['', 'execute_at_invalid'],
        ];
    }

    #[DataProvider('badTimes')]
    public function test_execute_at_bounds_are_enforced_at_the_call(mixed $value, string $reason): void
    {
        $bearer = $this->bearer(['tactical_set_maintenance:staged']);
        $result = $this->mcp($bearer, 'tactical_set_maintenance', $this->maintenanceArgs(['execute_at' => $value]));
        $this->assertSame($reason, $result['error'] ?? null, json_encode($result));
        $this->assertSame(0, TechnicianRun::count());
        $this->assertCount(0, $this->wire);
    }

    public function test_execute_at_exactly_seven_days_ahead_and_with_a_non_utc_offset_is_accepted(): void
    {
        $bearer = $this->bearer(['tactical_set_maintenance:staged']);
        $result = $this->mcp($bearer, 'tactical_set_maintenance', $this->maintenanceArgs(['execute_at' => '2026-09-22T17:00:00-07:00']));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame('2026-09-23T00:00:00+00:00', $run->proposed_meta['scheduled_provenance']['execute_at']);
        $this->assertSame('-07:00', $run->proposed_meta['scheduled_provenance']['execute_at_offset']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('Runs at', $result['message']);
    }

    // ── point 2: staged + ordinary Approve → scheduled row, no immediate send ──

    private function stageWithExecuteAt(array $grant = ['tactical_set_maintenance:staged'], array $extra = []): TechnicianRun
    {
        $result = $this->mcp($this->bearer($grant), 'tactical_set_maintenance', $this->maintenanceArgs(array_merge(['execute_at' => self::AT], $extra)));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->run = TechnicianRun::findOrFail($result['run_id']);

        return $this->run;
    }

    public function test_staged_execute_at_proposal_shows_runs_at_and_approve_admits_with_derived_window(): void
    {
        $run = $this->stageWithExecuteAt();
        $token = McpToken::where('label', 'synthetic-execute-at')->sole();
        $this->assertSame(self::AT, $run->proposed_meta['scheduled_provenance']['execute_at']);
        $this->assertSame($token->id, $run->proposed_meta['scheduled_provenance']['token_id']);
        $this->actingAs($this->user)->get(route('cockpit.index'))->assertOk()->assertSee('Runs at')->assertDontSee('Schedule approval instead');
        $this->post(route('cockpit.approve', $run))->assertRedirect(route('cockpit.index'))->assertSessionHas('success');
        $row = DB::table('scheduled_authorizations')->sole();
        $this->assertSame('waiting', $row->state);
        $this->assertSame('2026-09-16 03:30:00', $row->not_before);
        $this->assertSame('2026-09-16 04:30:00', $row->expires_at);
        $this->assertSame('UTC', $row->display_timezone);
        $this->assertSame((int) $this->user->id, (int) $row->approver_user_id);
        $this->assertSame($token->id, (int) $row->originating_mcp_token_id);
        $this->assertSame(TechnicianRunState::Scheduled, $run->fresh()->state);
        $this->assertCount(0, $this->wire, 'Approve executed now despite execute_at');
        // Fire time: the sweep dispatches inside the window and not before.
        app(TacticalDispatch::class)->run($row->id);
        $this->assertCount(0, $this->wire);
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($row->id);
        $this->assertCount(1, $this->wire);
    }

    public function test_approve_seals_the_ai_confirmation_inputs_as_human_inputs(): void
    {
        $bearer = $this->bearer(['tactical_reboot_device:staged']);
        $result = $this->mcp($bearer, 'tactical_reboot_device', $this->maintenanceArgs(['execute_at' => self::AT, 'confirm_hostname' => 'fixture-device']));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $row = DB::table('scheduled_authorizations')->sole();
        $sealed = \App\Services\Technician\Scheduled\ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $this->assertSame(['confirm_hostname' => 'fixture-device'], $sealed['human_inputs']);
        $this->assertCount(0, $this->wire);
    }

    public function test_approve_of_execute_at_proposal_with_wrong_confirmation_refuses_and_leaves_it_awaiting(): void
    {
        $bearer = $this->bearer(['tactical_reboot_device:staged']);
        $result = $this->mcp($bearer, 'tactical_reboot_device', $this->maintenanceArgs(['execute_at' => self::AT, 'confirm_hostname' => 'other-device']));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(0, $this->wire);
    }

    public function test_execute_at_already_past_at_approval_time_refuses_without_running_now(): void
    {
        $run = $this->stageWithExecuteAt();
        $this->time = $this->time->setTime(3, 31);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(0, $this->wire, 'stale execute_at fell through to immediate execution');
    }

    public function test_deny_of_execute_at_proposal_is_unchanged(): void
    {
        $run = $this->stageWithExecuteAt();
        $this->actingAs($this->user)->post(route('cockpit.deny', $run))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertCount(0, $this->wire);
    }

    public function test_immediate_grant_with_execute_at_is_staged_to_the_cockpit_in_pr1_and_approve_admits(): void
    {
        // PR2 owns the no-cockpit lane; until then execute_at on an :immediate grant is
        // staged with an explicit message, never run now and never silently dropped.
        $result = $this->mcp($this->bearer(['tactical_set_maintenance:immediate']), 'tactical_set_maintenance', $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false]));
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertStringContainsString('cockpit', $result['message']);
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertCount(0, $this->wire);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->value('state'));
        $this->assertCount(0, $this->wire);
    }

    public function test_same_proposal_with_a_different_execute_at_is_refused_by_name(): void
    {
        $this->stageWithExecuteAt();
        $again = $this->mcp($this->bearer(['tactical_set_maintenance:staged']), 'tactical_set_maintenance', $this->maintenanceArgs(['execute_at' => '2026-09-16T05:00:00+00:00']));
        $this->assertSame('execute_at_conflicts_with_pending_proposal', $again['error'] ?? null, json_encode($again));
        $this->assertSame(1, TechnicianRun::count());
        $this->assertSame(self::AT, TechnicianRun::sole()->proposed_meta['scheduled_provenance']['execute_at']);
    }

    public function test_lineage_accepts_the_immediate_grant_for_a_human_approved_row(): void
    {
        $run = $this->stageWithExecuteAt(['tactical_set_maintenance:immediate'], ['staged' => false]);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $id = DB::table('scheduled_authorizations')->sole()->id;
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame('completed', DB::table('scheduled_authorizations')->value('state'));
    }

    // ── point 2: the schedule form is gone; cancel and the results table stay ──

    public function test_schedule_form_routes_are_gone_and_cancel_still_works(): void
    {
        $run = $this->stageWithExecuteAt();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('cockpit.schedule'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('cockpit.schedule.store'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('cockpit.schedule.cancel'));
        $this->assertFileDoesNotExist(resource_path('views/cockpit/schedule.blade.php'));
        $this->actingAs($this->user)->get('/cockpit/runs/'.$run->id.'/schedule')->assertNotFound();
        $this->post('/cockpit/runs/'.$run->id.'/schedule', ['content_hash' => $run->content_hash])->assertNotFound();
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $this->get(route('cockpit.index'))->assertOk()->assertSee('Scheduled approvals')->assertSee('Cancel schedule');
        $this->post(route('cockpit.schedule.cancel', $run))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('cancelled', DB::table('scheduled_authorizations')->value('state'));
        $this->get(route('cockpit.index'))->assertOk()->assertSee('Cancelled');
        $this->assertCount(0, $this->wire);
    }

    // ── point 4: the removed key has no effect anywhere ────────────────────

    public function test_removed_setting_key_present_as_off_changes_nothing(): void
    {
        Setting::setValue('scheduled_approvals_enabled', '0');
        $this->assertFalse(method_exists(\App\Support\TechnicianConfig::class, 'scheduledApprovalsEnabled'));
        $run = $this->stageWithExecuteAt();
        $this->actingAs($this->user)->get(route('cockpit.index'))->assertOk()->assertSee('Runs at');
        $this->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $id = DB::table('scheduled_authorizations')->sole()->id;
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'technician:scheduled-sweep')));
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->filtersPass($this->app), 'scheduler still gated on the removed key');
        $this->time = $this->time->setTime(3, 30);
        $sweep = app(\App\Services\Technician\Scheduled\ScheduledSweep::class)->run();
        $this->assertSame(0, $sweep['errors']);
        $this->assertCount(1, $this->wire, 'a gate still reads the removed key');
        $this->assertSame('completed', DB::table('scheduled_authorizations')->where('id', $id)->value('state'));
        $this->assertSame('0', Setting::getValue('scheduled_approvals_enabled'), 'the stale row is left alone, not rewritten');
    }

    public function test_kill_switch_still_stops_admission_and_dispatch(): void
    {
        $run = $this->stageWithExecuteAt();
        Setting::setValue('technician_kill_switch', '1');
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        Setting::setValue('technician_kill_switch', '0');
        $this->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $id = DB::table('scheduled_authorizations')->sole()->id;
        Setting::setValue('technician_kill_switch', '1');
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_settings_page_and_technician_form_no_longer_carry_the_checkbox(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('settings.integrations'))->assertOk()
            ->assertDontSee('scheduled_approvals_enabled')->assertDontSee('Enable scheduled (deferred) execution');
        $this->post(route('settings.integrations.technician.update'), ['scheduled_approvals_enabled' => '1'])->assertRedirect();
        $this->assertNull(Setting::getValue('scheduled_approvals_enabled'));
    }

    public function test_preflight_reports_clock_and_inventory_only(): void
    {
        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('technician:scheduled-preflight'));
        $result = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(['clock', 'inventory', 'total', 'reconciliation_required'], array_keys($result));
    }

    public function test_no_source_reads_the_removed_key(): void
    {
        $hits = [];
        foreach (['app', 'routes', 'resources/views', 'config', 'database'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
                    && preg_match('/scheduled_approvals_enabled|scheduledApprovalsEnabled|SCHEDULED_APPROVALS_ENABLED/', (string) file_get_contents($file->getPathname()))) {
                    $hits[] = substr($file->getPathname(), strlen(base_path()) + 1);
                }
            }
        }
        $this->assertSame([], $hits);
    }
}
