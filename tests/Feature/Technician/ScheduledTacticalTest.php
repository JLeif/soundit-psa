<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\Scheduled\ActionRegistry;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledClock;
use App\Services\Technician\Scheduled\TacticalDispatch;
use App\Services\Technician\Scheduled\TacticalEvidence;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduledTacticalTest extends TestCase
{
    use RefreshDatabase;

    protected CarbonImmutable $time;

    protected User $user;

    protected Client $client;

    protected Asset $asset;

    protected Ticket $ticket;

    protected TechnicianRun $run;

    protected array $wire = [];

    protected array $agent = ['agent_id' => 'fixture-agent', 'site' => 17, 'hostname' => 'fixture-device', 'status' => 'online'];

    protected array $clients = [['id' => 4, 'name' => 'Fixture Client', 'sites' => [['id' => 17, 'name' => 'Main Site', 'client' => 4]]]];

    protected array $services = [['name' => 'Spooler', 'display_name' => 'Print Spooler']];

    protected mixed $response = 'ok';

    protected int $status = 200;

    protected bool $fail = false;

    protected int $reads = 0;

    protected ?\Closure $atReceipt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->time = CarbonImmutable::parse('2026-09-16 00:00:00', 'UTC');
        $clock = Mockery::mock(ScheduledClock::class);
        $clock->shouldReceive('now')->andReturnUsing(fn () => $this->time);
        $clock->shouldReceive('healthy')->andReturn(true);
        $this->app->instance(ScheduledClock::class, $clock);
        Setting::setValue('tactical_enabled', '1');
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-key');
        // All Guzzle requests terminate in this handler: no live socket or vendor.
        $http = new HttpClient(['base_uri' => 'https://tactical.example.test/', 'handler' => HandlerStack::create(function ($request, $options) {
            if ($request->getMethod() === 'GET') {
                $this->reads++;
                $path = $request->getUri()->getPath();
                $body = match (true) {
                    str_starts_with($path, '/services/') => $this->services,
                    str_starts_with($path, '/clients/') => $this->clients,
                    default => $this->agent,
                };

                return Create::promiseFor(new Response(200, [], json_encode($body)));
            }
            $this->wire[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true)];
            if ($this->fail) {
                throw new \RuntimeException('synthetic transport timeout');
            }

            if ($this->atReceipt !== null) {
                ($this->atReceipt)();
            }

            return Create::promiseFor(new Response($this->status, ['Location' => 'https://not-followed.example.test'], json_encode($this->response)));
        })]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
        $this->user = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $this->client = Client::factory()->create(['tactical_site_id' => 'Fixture Client|Main Site']);
        $this->asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'fixture-device']);
        TacticalAsset::create(['asset_id' => $this->asset->id, 'agent_id' => 'fixture-agent', 'hostname' => 'fixture-device', 'status' => 'online']);
        $this->ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        $this->ticket->assets()->attach($this->asset);
    }

    protected function proposal(string $type, array $params): void
    {
        $this->run = TechnicianRun::create(['ticket_id' => $this->ticket->id, 'client_id' => $this->client->id,
            'action_type' => $type, 'content_hash' => hash('sha256', json_encode([$type, $params])), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Synthetic device proposal', 'proposed_meta' => [
                'scheduled_provenance' => ['version' => 1, 'kind' => 'native_human', 'user_id' => $this->user->id],
                'encrypted_payload' => Crypt::encryptString(json_encode(['direct_tool' => ActionRegistry::directTool($type),
                    'asset_id' => $this->asset->id, 'client_id' => $this->client->id, 'ticket_id' => $this->ticket->id, 'params' => $params])),
            ]]);
    }

    protected function admit(array $human = []): int
    {
        return app(ScheduledAdmission::class)->admit($this->run->id, $this->user->id, $this->run->content_hash, null,
            '2026-09-16 01:00:00', '2026-09-16 02:00:00', 'UTC', $human, app(TacticalEvidence::class));
    }

    /** Removed-key guard: a stale toggle row of any value neither gates nor enables evidence. */
    #[\PHPUnit\Framework\Attributes\DataProviderExternal(ScheduledApprovalTest::class, 'staleToggleValues')]
    public function test_tactical_evidence_ignores_the_removed_toggle_and_honours_the_kill_switch(?string $value): void
    {
        $this->proposal('tactical_stage_reboot', []);
        Setting::where('key', 'scheduled_approvals_enabled')->delete();
        if ($value !== null) {
            Setting::setValue('scheduled_approvals_enabled', $value);
        }
        config(['scheduled_approvals.enabled' => false]);
        $binding = app(TacticalEvidence::class)->approve($this->run, $this->user, ['confirm_hostname' => 'fixture-device']);
        $this->assertNotEmpty($binding['target']);
        Setting::setValue('technician_kill_switch', '1');
        $this->expectException(\App\Services\Technician\Scheduled\ScheduledUnavailable::class);
        $this->expectExceptionMessage('kill_switch');
        app(TacticalEvidence::class)->approve($this->run, $this->user, ['confirm_hostname' => 'fixture-device']);
    }

    public function test_real_mcp_lineage_web_admission_and_revocation(): void
    {
        $bearer = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['tactical_set_maintenance:staged'], label: 'synthetic-tactical');
        $reply = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'tactical_set_maintenance',
                'arguments' => ['client_id' => $this->client->id, 'asset_id' => $this->asset->id, 'ticket_id' => $this->ticket->id,
                    'enabled' => true, 'reason' => 'Synthetic control', 'staged' => true, 'execute_at' => '2026-09-16T01:00:00+00:00']],
        ])->assertOk();
        $result = json_decode($reply->json('result.content.0.text'), true);
        $this->assertTrue($result['success'] ?? false, $reply->getContent());
        $this->run = TechnicianRun::findOrFail($result['run_id']);
        $token = \App\Models\McpToken::where('label', 'synthetic-tactical')->sole();
        $this->assertSame($token->id, $this->run->proposed_meta['scheduled_provenance']['token_id']);
        $this->assertSame('2026-09-16T01:00:00+00:00', $this->run->proposed_meta['scheduled_provenance']['execute_at']);
        $this->assertFalse($this->run->proposed_meta['scheduled_argument_refusal'] ?? false);
        // The ordinary cockpit Approve admits it (window derived from execute_at); no form.
        $this->actingAs($this->user)->post(route('cockpit.approve', $this->run))->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $row = DB::table('scheduled_authorizations')->sole();
        $id = $row->id;
        $this->assertSame(['2026-09-16 01:00:00', '2026-09-16 02:00:00'], [$row->local_start, $row->local_end]);
        $this->assertStringStartsWith('2026-09-16 01:00:00', (string) $row->not_before);
        $this->assertStringStartsWith('2026-09-16 02:00:00', (string) $row->expires_at);
        $token->update(['tools' => []]);
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->value('state'));
        $this->assertCount(0, $this->wire);
    }

    public function test_immediate_boolean_coercion_does_not_grant_scheduled_authority(): void
    {
        $args = ['asset_id' => $this->asset->id, 'ticket_id' => $this->ticket->id, 'enabled' => 'false', 'reason' => 'Synthetic control'];
        $result = app(\App\Services\Mcp\StaffTacticalActionToolExecutor::class)->execute('tactical_stage_maintenance', $args, $this->client->id, 'synthetic', 17);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertTrue($this->run->proposed_meta['scheduled_argument_refusal']);
        try {
            app(TacticalEvidence::class)->approve($this->run, $this->user, []);
            $this->fail('Coerced boolean authorized');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('unsupported_scheduling_arguments', $e->getMessage());
        }
        $this->assertCount(0, $this->wire);
    }

    public static function refusals(): array
    {
        return [
            ['tactical_stage_script', 'unsupported_scheduling_type:tactical_stage_script', true],
            ['tactical_stage_install_approved_patches', 'unsupported_scheduling_type:tactical_stage_install_approved_patches', true],
            ['tactical_stage_unknown', 'scheduling_type_not_registered', false],
        ];
    }

    #[DataProvider('refusals')]
    public function test_distinct_visible_admission_refusals(string $type, string $reason, bool $present): void
    {
        $this->proposal($type, []);
        $this->assertSame($present, ActionRegistry::directTool($type) !== null);
        $this->assertFalse(ActionRegistry::adapterAvailable($type));
        try {
            $this->admit();
            $this->fail('Unsupported admission succeeded');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
        $this->assertSame(0, $this->reads);
        $this->assertCount(0, $this->wire);
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        // A proposal of this type that somehow carries execute_at refuses at Approve by the
        // same name and is NOT executed now.
        $this->run->update(['proposed_meta' => array_merge($this->run->proposed_meta, ['scheduled_provenance' => ['version' => 1, 'kind' => 'native_human', 'user_id' => $this->user->id, 'execute_at' => '2026-09-16T01:00:00+00:00', 'execute_at_offset' => '+00:00']])]);
        $this->actingAs($this->user)->post(route('cockpit.approve', $this->run))->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, $reason));
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertCount(0, $this->wire);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->run->fresh()->state);
    }

    public static function invalidArguments(): array
    {
        return [
            ['command', ['cmd' => 'whoami', 'shell' => 'custom', 'timeout' => 30]],
            ['command', ['cmd' => 'whoami', 'shell' => 'shell', 'timeout' => 30, 'run_as_user' => true]],
            ['command', ['cmd' => 'whoami', 'shell' => 'shell', 'timeout' => '30']],
            ['maintenance', ['enabled' => 'false']],
            ['maintenance', ['enabled' => 1]],
            ['recover_mesh', ['mode' => 'tacagent']],
            ['reboot', ['delay' => 600]],
            ['start_service', ['service_name' => 'Print Spooler']],
            ['stop_service', ['service_name' => 'spooler']],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_argument_policy_refuses_without_write(string $type, array $params): void
    {
        $this->proposal('tactical_stage_'.$type, $params);
        $refused = false;
        try {
            $this->admit(in_array($type, ['command', 'reboot'], true) ? ['confirm_hostname' => 'fixture-device'] : ($type === 'stop_service' ? ['confirm_hostname' => 'fixture-device', 'confirm_service_name' => 'spooler'] : []));
        } catch (\InvalidArgumentException|\App\Services\Tactical\Actions\InvalidActionParams $e) {
            $refused = true;
        }
        $this->assertTrue($refused, 'Bad argument reached scheduling');
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertCount(0, $this->wire);
    }

    public static function changes(): array
    {
        return [['agent'], ['site'], ['hostname'], ['service'], ['boolean'], ['token'], ['link'], ['kill']];
    }

    #[DataProvider('changes')]
    public function test_fire_time_mutations_refuse(string $change): void
    {
        $this->proposal('tactical_stage_start_service', ['service_name' => 'Spooler']);
        $id = $this->admit();
        match ($change) {
            'agent' => $this->agent['agent_id'] = 'replacement',
            'site' => $this->agent['site'] = 18,
            'hostname' => $this->agent['hostname'] = 'replacement',
            'service' => $this->services = [['name' => 'replacement', 'display_name' => 'Spooler']],
            'boolean' => $this->agent['site'] = '17',
            'token' => $this->user->update(['is_active' => false]),
            'link' => $this->ticket->assets()->detach(),
            'kill' => \App\Models\Setting::setValue('technician_kill_switch', '1'),
        };
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->assertSame($change === 'kill' ? 'waiting' : 'blocked', DB::table('scheduled_authorizations')->value('state'));
    }

    public static function uncertainReplies(): array
    {
        return [[200, ['unexpected' => true], false], [302, 'ok', false], [500, 'ok', false], [200, 'ok', true]];
    }

    #[DataProvider('uncertainReplies')]
    public function test_uncertain_transport_never_retries(int $status, mixed $reply, bool $fail): void
    {
        $this->proposal('tactical_stage_reboot', []);
        $id = $this->admit(['confirm_hostname' => 'fixture-device']);
        $this->status = $status;
        $this->response = $reply;
        $this->fail = $fail;
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertDatabaseCount('scheduled_target_fences', 1);
        $this->assertDatabaseCount('tactical_action_logs', 1);
    }

    public function test_reconnect_cannot_execute_scheduled_row_and_confirmations_remain_required(): void
    {
        $this->proposal('tactical_stage_reboot', []);
        try {
            $this->admit(['confirm_hostname' => 'wrong']);
            $this->fail('Wrong confirmation accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('confirmation_mismatch', $e->getMessage());
        }
        $id = $this->admit(['confirm_hostname' => 'fixture-device']);
        app(\App\Services\Mcp\StaffTacticalActionToolExecutor::class)->runQueuedOnReconnect($this->run->fresh());
        $this->assertCount(0, $this->wire);
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->find($id)->state);
        // Scheduled is a tombstone for the immediate lane too.
        $this->actingAs($this->user)->post(route('cockpit.approve', $this->run))->assertRedirect()->assertSessionHas('error');
        $this->assertCount(0, $this->wire);
        $this->assertDatabaseCount('scheduled_authorizations', 1);
    }

    public function test_provably_unsent_bus_refusal_settles_failed_not_uncertain(): void
    {
        $this->proposal('tactical_stage_reboot', []);
        $id = $this->admit(['confirm_hostname' => 'fixture-device']);
        // The bus decides denied/rejected/blocked before execute(): nothing was sent.
        $this->app->instance(\App\Services\Tactical\TacticalActionService::class, new class(app(TacticalClient::class)) extends \App\Services\Tactical\TacticalActionService
        {
            public function dispatch(\App\Services\Tactical\Actions\TacticalAction $action, Asset $target, ?User $actor, array $params,
                ?string $confirmToken = null, ?string $actorLabel = null, ?int $ticketId = null): \App\Services\Tactical\Actions\TacticalActionResult
            {
                return \App\Services\Tactical\Actions\TacticalActionResult::denied('Synthetic pre-send refusal');
            }
        });
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->assertSame('failed', DB::table('scheduled_authorizations')->value('state'));
        // Nothing was sent, so neither the row nor the operator note may claim a receipt.
        $this->assertSame('no_vendor_request', DB::table('scheduled_authorizations')->value('reason'));
        $this->assertSame('no_vendor_request', DB::table('scheduled_note_outbox')->orderByDesc('id')->value('reason'));
        $this->assertDatabaseCount('scheduled_target_fences', 0);
    }

    public function test_quiesce_inside_bus_prevents_actual_tactical_io(): void
    {
        $this->proposal('tactical_stage_reboot', []);
        $id = $this->admit(['confirm_hostname' => 'fixture-device']);
        $this->app->instance(\App\Services\Tactical\TacticalActionService::class, new class(app(TacticalClient::class)) extends \App\Services\Tactical\TacticalActionService
        {
            public function dispatch(\App\Services\Tactical\Actions\TacticalAction $action, Asset $target, ?User $actor, array $params,
                ?string $confirmToken = null, ?string $actorLabel = null, ?int $ticketId = null): \App\Services\Tactical\Actions\TacticalActionResult
            {
                app(\App\Services\Technician\Scheduled\ScheduledQuiescence::class)->begin();

                return parent::dispatch($action, $target, $actor, $params, $confirmToken, $actorLabel, $ticketId);
            }
        });
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->assertSame('abandoned_no_send', DB::table('scheduled_authorizations')->value('state'));
        $this->assertDatabaseCount('scheduled_late_receipts', 0);
    }

    public function test_late_tactical_receipt_is_evidence_not_success_or_replay(): void
    {
        $this->proposal('tactical_stage_reboot', []);
        $id = $this->admit(['confirm_hostname' => 'fixture-device']);
        $this->time = $this->time->setTime(1, 0);
        $this->atReceipt = function () use ($id) {
            $this->time = $this->time->addSeconds(640);
            app(\App\Services\Technician\Scheduled\ScheduledCoordinator::class)->recover($id);
        };
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertDatabaseHas('scheduled_late_receipts', ['authorization_id' => $id, 'vendor' => 'tactical', 'outcome' => 'completed']);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'error', 'message' => 'scheduled_late_receipt']);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertDatabaseCount('scheduled_late_receipts', 1);
    }

    public static function actions(): array
    {
        $host = ['confirm_hostname' => 'fixture-device'];
        $service = [...$host, 'confirm_service_name' => 'Spooler'];

        return [
            ['command', ['cmd' => 'whoami', 'shell' => 'powershell', 'timeout' => 30], $host, 'POST', '/agents/fixture-agent/cmd/', ['cmd' => 'whoami', 'shell' => 'powershell', 'timeout' => 30, 'custom_shell' => null, 'run_as_user' => false, 'env_vars' => []], 'fixture output', 'submitted'],
            ['reboot', [], $host, 'POST', '/agents/fixture-agent/reboot/', [], 'ok', 'completed'],
            ['shutdown', [], $host, 'POST', '/agents/fixture-agent/shutdown/', [], 'ok', 'completed'],
            ['recover_mesh', ['mode' => 'mesh'], [], 'POST', '/agents/fixture-agent/recover/', ['mode' => 'mesh'], 'Successfully completed recovery', 'completed'],
            ['maintenance', ['enabled' => false], [], 'PUT', '/agents/fixture-agent/', ['maintenance_mode' => false], 'The agent was updated successfully', 'completed'],
            ['start_service', ['service_name' => 'Spooler'], [], 'POST', '/services/fixture-agent/Spooler/', ['sv_action' => 'start'], 'The service was started successfully', 'completed'],
            ['stop_service', ['service_name' => 'Spooler'], $service, 'POST', '/services/fixture-agent/Spooler/', ['sv_action' => 'stop'], 'The service was stopped successfully', 'completed'],
            ['restart_service', ['service_name' => 'Spooler'], $service, 'POST', '/services/fixture-agent/Spooler/', ['sv_action' => 'restart'], 'The service was restarted successfully', 'completed'],
        ];
    }

    #[DataProvider('actions')]
    public function test_exact_eight_wire_effects_no_early_or_repeat_send(string $type, array $params, array $human, string $method, string $path, array $body, string $reply, string $outcome): void
    {
        $this->proposal('tactical_stage_'.$type, $params);
        $id = $this->admit($human);
        $this->assertGreaterThan(0, $this->reads);
        $this->assertSame(TechnicianRunState::Scheduled, $this->run->fresh()->state);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->response = $reply;
        $this->time = $this->time->setTime(1, 0);
        app(TacticalDispatch::class)->run($id);
        app(TacticalDispatch::class)->run($id);
        $this->assertSame([compact('method', 'path', 'body')], $this->wire);
        $this->assertSame($outcome, DB::table('scheduled_authorizations')->value('state'));
        // An observed send releases the target: only uncertain outcomes keep the fence.
        $this->assertDatabaseCount('scheduled_target_fences', 0);
        $this->assertDatabaseCount('tactical_action_logs', 1);
    }
}
