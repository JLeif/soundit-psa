<?php

namespace Tests\Feature\Technician;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Technician\Scheduled\ApprovalEnvelope;
use App\Services\Technician\Scheduled\MailboxDispatch;
use App\Services\Technician\Scheduled\MailboxEvidence;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledClock;
use App\Services\Technician\Scheduled\ScheduledOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ScheduledMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected CarbonImmutable $time;

    protected User $user;

    protected Client $client;

    protected Person $owner;

    protected Person $other;

    protected Ticket $ticket;

    protected TechnicianRun $run;

    protected array $wire = [];

    protected array $users;

    protected array $tenants;

    protected mixed $result = ['Results' => 'unknown'];

    protected int $status = 200;

    protected bool $transportFail = false;

    protected bool $healthy = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduled_approvals.enabled' => true]);
        $this->time = CarbonImmutable::parse('2026-09-16 00:00:00', 'UTC');
        $clock = Mockery::mock(ScheduledClock::class);
        $clock->shouldReceive('now')->andReturnUsing(fn () => $this->time);
        $clock->shouldReceive('healthy')->andReturnUsing(fn () => $this->healthy);
        $this->app->instance(ScheduledClock::class, $clock);
        foreach (['cipp_enabled' => '1', 'cipp_api_url' => 'https://cipp.example.test', 'cipp_tenant_id' => 'tenant-1', 'cipp_client_id' => 'write-client'] as $key => $value) {
            Setting::setValue($key, $value);
        }
        Setting::setEncrypted('cipp_client_secret', 'synthetic-secret');
        $this->app->instance(CippRestWriteClient::class, new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1', 'client_id' => 'write-client', 'client_secret' => 'synthetic-secret',
        ], Cache::store(), fn () => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            if (str_contains($r->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]);
            }
            if (str_contains($r->url(), '/api/ListTenants')) {
                return Http::response($this->tenants);
            }
            if (str_contains($r->url(), '/api/ListUsers')) {
                $this->assertSame('synthetic.onmicrosoft.com', $r['tenantFilter']);

                return Http::response($this->users);
            }
            $this->wire[] = ['url' => $r->url(), 'body' => $r->data()];
            if ($this->transportFail) {
                throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
            }

            return Http::response($this->result, $this->status);
        });
        $this->user = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $this->client = Client::factory()->create(['cipp_tenant_domain' => 'synthetic.onmicrosoft.com']);
        $this->ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        $this->owner = $this->person('11111111-1111-4111-8111-111111111111', 'owner@synthetic.test');
        $this->other = $this->person('22222222-2222-4222-8222-222222222222', 'other@synthetic.test');
        $this->tenants = [['customerId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'defaultDomainName' => 'synthetic.onmicrosoft.com']];
        $this->users = array_map(fn ($p) => ['id' => $p->cipp_user_id, 'userPrincipalName' => $p->cipp_upn, 'accountEnabled' => true, 'userType' => 'Member'], [$this->owner, $this->other]);
    }

    private function person(string $id, string $upn): Person
    {
        return Person::create(['client_id' => $this->client->id, 'person_type' => PersonType::User, 'first_name' => 'Synthetic',
            'last_name' => 'Fixture', 'email' => $upn, 'cipp_user_id' => $id, 'cipp_upn' => $upn, 'is_active' => true]);
    }

    protected function proposal(string $action, array $params): TechnicianRun
    {
        $this->run = TechnicianRun::create(['ticket_id' => $this->ticket->id, 'client_id' => $this->client->id,
            'action_type' => $action, 'content_hash' => hash('sha256', json_encode([$action, $params])), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Synthetic mailbox proposal', 'proposed_meta' => [
                'scheduled_provenance' => ['version' => 1, 'kind' => 'native_human', 'user_id' => $this->user->id],
                'encrypted_payload' => Crypt::encryptString(json_encode(['direct_tool' => str_replace('cipp_stage_', 'cipp_', $action),
                    'client_id' => $this->client->id, 'ticket_id' => $this->ticket->id, 'person_id' => $this->owner->id, 'params' => $params])),
            ]]);

        return $this->run;
    }

    protected function admit(array $human = []): int
    {
        return app(ScheduledAdmission::class)->admit($this->run->id, $this->user->id, $this->run->content_hash, null,
            '2026-09-16 01:00:00', '2026-09-16 02:00:00', 'UTC', $human, app(MailboxEvidence::class));
    }

    public function test_real_evidence_to_single_forwarding_transport_and_private_result_note(): void
    {
        $this->proposal('cipp_stage_set_mailbox_forwarding', ['mode' => 'external', 'keep_copy' => true, 'external_domain' => 'example.test']);
        $id = $this->admit(['external_smtp' => 'approved@example.test']);
        $this->result = ['Results' => ['Successfully set forwarding for owner@synthetic.test to External Address approved@example.test with keeping a copy set to True']];
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame(['tenantFilter' => 'synthetic.onmicrosoft.com', 'userID' => 'owner@synthetic.test', 'ForwardInternal' => null,
            'ForwardExternal' => 'approved@example.test', 'forwardOption' => 'ExternalAddress', 'KeepCopy' => 'true'], $this->wire[0]['body']);
        $this->assertSame('completed', DB::table('scheduled_authorizations')->value('state'));
        foreach (DB::table('scheduled_note_outbox')->pluck('id') as $note) {
            $this->assertTrue(app(ScheduledOutbox::class)->deliver($note));
        }
        $this->assertDatabaseCount('ticket_notes', 2);
        foreach (DB::table('ticket_notes')->get() as $note) {
            $this->assertTrue((bool) $note->is_private);
            $this->assertStringNotContainsString('approved@example.test', $note->body);
        }
    }

    public static function effects(): array
    {
        return [
            'delegate success' => [['Results' => ['Granted other@synthetic.test FullAccess to owner@synthetic.test with automapping False']], 200, false, 'completed'],
            'delegate 200 failure' => [['Results' => ['Failed to Add FullAccess for other@synthetic.test on owner@synthetic.test: denied']], 200, false, 'failed'],
            'unknown' => [['Results' => ['Everything is fine']], 200, false, 'uncertain'],
            'empty' => [['Results' => []], 200, false, 'uncertain'],
            'wrong identity' => [['Results' => ['Granted foreign@synthetic.test FullAccess to owner@synthetic.test with automapping False']], 200, false, 'uncertain'],
            'mixed' => [['Results' => ['Granted other@synthetic.test FullAccess to owner@synthetic.test with automapping False', 'Failed to Add FullAccess for other@synthetic.test on owner@synthetic.test: denied']], 200, false, 'uncertain'],
            'redirect' => [['Results' => ['Granted other@synthetic.test FullAccess to owner@synthetic.test with automapping False']], 302, false, 'uncertain'],
            'error' => [['Results' => 'upstream internal error'], 500, false, 'uncertain'],
            'timeout' => [null, 200, true, 'uncertain'],
            'malformed' => ['not JSON', 200, false, 'uncertain'],
            'oversized' => [['Results' => str_repeat('x', 17000)], 200, false, 'uncertain'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('effects')]
    public function test_results_body_classification_is_terminal_and_never_retries(mixed $body, int $status, bool $timeout, string $expected): void
    {
        $this->proposal('cipp_stage_set_mailbox_delegate', ['delegate_person_id' => $this->other->id, 'permission' => 'full_access', 'operation' => 'grant', 'auto_map' => false]);
        $id = $this->admit();
        $this->result = $body;
        $this->status = $status;
        $this->transportFail = $timeout;
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        $this->assertSame($expected, DB::table('scheduled_authorizations')->value('state'));
        $this->time = $this->time->addMinutes(10);
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame([['value' => 'other@synthetic.test', 'label' => 'other@synthetic.test']], $this->wire[0]['body']['AddFullAccessNoAutoMap']);
        $this->assertSame([], $this->wire[0]['body']['AddFullAccess']);
        $this->assertDatabaseCount('scheduled_target_fences', $expected === 'uncertain' ? 1 : 0);
    }

    public static function identityChanges(): array
    {
        return array_map(fn ($x) => [$x], ['owner_id', 'owner_upn', 'tenant_id', 'local_mapping', 'foreign_ticket', 'approver', 'duplicate_tenant', 'integration', 'delegate_id', 'delegate_disabled']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identityChanges')]
    public function test_fire_time_tuple_changes_block_without_a_send(string $change): void
    {
        $this->proposal('cipp_stage_set_mailbox_delegate', ['delegate_person_id' => $this->other->id, 'permission' => 'send_as', 'operation' => 'grant']);
        $id = $this->admit();
        match ($change) {
            'owner_id' => $this->users[0]['id'] = '33333333-3333-4333-8333-333333333333',
            'owner_upn' => $this->users[0]['userPrincipalName'] = 'renamed@synthetic.test',
            'tenant_id' => $this->tenants[0]['customerId'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'local_mapping' => $this->owner->update(['cipp_user_id' => '33333333-3333-4333-8333-333333333333']),
            'foreign_ticket' => $this->ticket->update(['client_id' => Client::factory()->create()->id]),
            'approver' => $this->user->update(['is_active' => false]),
            'duplicate_tenant' => Client::factory()->create(['cipp_tenant_domain' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']),
            'integration' => Setting::setValue('cipp_api_url', 'https://other.example.test'),
            'delegate_id' => $this->users[1]['id'] = '33333333-3333-4333-8333-333333333333',
            'delegate_disabled' => $this->users[1]['accountEnabled'] = false,
        };
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->value('state'));
        $this->assertCount(0, $this->wire);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/ListUsers'));
    }

    public function test_out_of_office_uses_exact_encrypted_bodies_not_lengths(): void
    {
        $this->proposal('cipp_stage_set_mailbox_out_of_office', ['state' => 'Enabled', 'internal_message_length' => 5, 'external_message_length' => 5]);
        $id = $this->admit(['internal_message' => 'hello', 'external_message' => 'world']);
        $row = DB::table('scheduled_authorizations')->find($id);
        $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $this->assertSame('hello', $sealed['human_inputs']['internal_message']);
        $this->assertStringNotContainsString('hello', json_encode($row));
        $this->time = $this->time->setTime(1, 0);
        $this->result = ['Results' => 'Set Out-of-office for owner@synthetic.test to Enabled.'];
        app(MailboxDispatch::class)->run($id);
        $this->assertSame('hello', $this->wire[0]['body']['InternalMessage']);
        $this->assertSame('world', $this->wire[0]['body']['ExternalMessage']);
        $this->assertSame('completed', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_flag_kill_clock_and_cancellation_keep_transport_dark(): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        config(['scheduled_approvals.enabled' => false]);
        app(MailboxDispatch::class)->run($id);
        config(['scheduled_approvals.enabled' => true]);
        $this->healthy = false;
        app(MailboxDispatch::class)->run($id);
        $this->healthy = true;
        $this->assertTrue(app(\App\Services\Technician\Scheduled\ScheduledCoordinator::class)->cancel($id, $this->user->id));
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(0, $this->wire);
        $this->assertSame('cancelled', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_schedule_form_post_cancel_and_results_render_with_no_live_send(): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $this->actingAs($this->user)->get(route('cockpit.schedule', $this->run))->assertOk()->assertSee('Approve schedule');
        $this->post(route('cockpit.schedule.store', $this->run), ['content_hash' => $this->run->content_hash,
            'start' => '2026-09-16T01:00', 'end' => '2026-09-16T02:00', 'timezone' => 'UTC', 'confirm' => '1'])
            ->assertRedirect(route('cockpit.index'))->assertSessionHas('success');
        $this->assertDatabaseCount('scheduled_authorizations', 1);
        $this->get(route('cockpit.index'))->assertOk()->assertSee('Scheduled approvals')->assertSee('Cancel schedule');
        config(['scheduled_approvals.enabled' => false]);
        $this->post(route('cockpit.schedule.cancel', $this->run))->assertRedirect()->assertSessionHas('success');
        $this->get(route('cockpit.index'))->assertOk()->assertSee('Cancelled');
        $this->assertCount(0, $this->wire);
    }

    public function test_unsupported_deferral_never_becomes_immediate_and_roles_refuse(): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $this->actingAs($this->user)->post(route('cockpit.approve', $this->run), ['schedule' => 'yes'])->assertStatus(422);
        foreach (['billing', 'contractor'] as $role) {
            $this->user->update(['role' => $role]);
            $this->actingAs($this->user->fresh())->get(route('cockpit.schedule', $this->run))->assertForbidden();
        }
        $this->assertCount(0, $this->wire);
        $this->assertDatabaseCount('scheduled_authorizations', 0);
    }

    public function test_invalid_form_never_flashes_sensitive_mailbox_inputs(): void
    {
        $this->proposal('cipp_stage_set_mailbox_out_of_office', ['state' => 'Enabled']);
        $this->actingAs($this->user)->post(route('cockpit.schedule.store', $this->run), [
            'content_hash' => 'invalid', 'internal_message' => 'private synthetic body', 'external_smtp' => 'private@example.test',
        ])->assertRedirect()->assertSessionHas('error')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertCount(0, $this->wire);
    }

    public function test_mcp_boundary_records_real_token_lineage_and_revocation_blocks_dispatch(): void
    {
        $bearer = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['cipp_convert_mailbox:staged'], label: 'synthetic-scheduler');
        $reply = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cipp_convert_mailbox',
                'arguments' => ['client_id' => $this->client->id, 'person_id' => $this->owner->id, 'ticket_id' => $this->ticket->id,
                    'mailbox_type' => 'Shared', 'confirm_upn' => $this->owner->cipp_upn, 'reason' => 'Synthetic control', 'staged' => true]],
        ])->assertOk();
        $result = json_decode($reply->json('result.content.0.text'), true);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->run = TechnicianRun::findOrFail($result['run_id']);
        $token = McpToken::where('label', 'synthetic-scheduler')->sole();
        $this->assertSame($token->id, $this->run->proposed_meta['scheduled_provenance']['token_id']);
        $id = app(ScheduledAdmission::class)->admit($this->run->id, $this->user->id, $this->run->content_hash, $token->id,
            '2026-09-16 01:00:00', '2026-09-16 02:00:00', 'UTC', [], app(MailboxEvidence::class));
        $token->update(['tools' => []]);
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->value('state'));
        $this->assertCount(0, $this->wire);
    }

    public function test_scheduled_tombstone_cannot_be_revived_by_restaging(): void
    {
        $args = ['person_id' => $this->owner->id, 'ticket_id' => $this->ticket->id, 'mailbox_type' => 'Shared', 'confirm_upn' => $this->owner->cipp_upn, 'reason' => 'Synthetic control'];
        $executor = app(StaffCippWriteToolExecutor::class);
        $result = $executor->execute('cipp_stage_convert_mailbox', $args, $this->client->id, 'synthetic');
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);
        $run->update(['state' => TechnicianRunState::Scheduled]);
        // Advance beyond cooldown without violating the append-only audit table.
        $this->travel(11)->minutes();
        $again = $executor->execute('cipp_stage_convert_mailbox', $args, $this->client->id, 'synthetic');
        $this->assertStringContainsString('scheduled authorization', $again['error']);
        $this->assertSame(TechnicianRunState::Scheduled, $run->fresh()->state);
        $this->assertCount(0, $this->wire);
    }

    public function test_gal_and_conversion_exact_wires(): void
    {
        foreach ([['cipp_stage_set_mailbox_gal_visibility', ['hidden' => true], 'ExecHideFromGAL', 'HideFromGAL', true,
            'Successfully hidden owner@synthetic.test from GAL.'],
            ['cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared'], 'ExecConvertMailbox', 'MailboxType', 'Shared',
                'Successfully converted owner@synthetic.test to a Shared mailbox']] as [$action, $params, $endpoint, $field, $value, $result]) {
            $this->time = $this->time->setTime(0, 0);
            $this->proposal($action, $params);
            $id = $this->admit();
            $this->time = $this->time->setTime(1, 0);
            $this->result = ['Results' => $result];
            app(MailboxDispatch::class)->run($id);
            $wire = end($this->wire);
            $this->assertStringEndsWith('/api/'.$endpoint, $wire['url']);
            $this->assertSame($value, $wire['body'][$field]);
            $this->assertSame('owner@synthetic.test', $wire['body']['ID']);
            $this->assertSame('completed', DB::table('scheduled_authorizations')->where('id', $id)->value('state'));
        }
        $this->assertCount(2, $this->wire);
    }

    public function test_uncertain_result_is_rendered_even_when_disabled_and_cannot_cancel(): void
    {
        $this->proposal('cipp_stage_convert_mailbox', ['mailbox_type' => 'Shared']);
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        config(['scheduled_approvals.enabled' => false]);
        $this->actingAs($this->user)->get(route('cockpit.index'))->assertOk()->assertSee('Effect unknown. Never retry automatically.')->assertDontSee('Cancel schedule');
        $this->post(route('cockpit.schedule.cancel', $this->run))->assertSessionHas('error');
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertCount(1, $this->wire);
    }
}
