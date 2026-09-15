<?php

namespace Tests\Feature\Mcp;

use App\Enums\EmailDirection;
use App\Models\Client;
use App\Models\Email;
use App\Models\EmailResolutionProposal;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Email\EmailResolutionService;
use App\Services\EmailService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function email(string $sender = 'sender@example.test'): Email
    {
        return Email::create(['direction' => EmailDirection::Inbound, 'from_address' => $sender,
            'subject' => 'Fixture', 'body_text' => 'DO NOT LOG MESSAGE BODY', 'received_at' => now()]);
    }

    private function callResolve(?array $grant, array $arguments, string $name = 'resolve_email_item')
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $grant);

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    private function stage(Email $email, Client $client): array
    {
        $response = $this->callResolve(['resolve_email_item:staged'], [
            'email_id' => $email->id, 'client_id' => $client->id, 'reason' => 'Known sender', 'staged' => true,
        ]);
        $response->assertOk();
        $result = json_decode($response->json('result.content.0.text') ?? '{}', true);
        $this->assertTrue($result['staged'] ?? false, $response->getContent());

        return $result;
    }

    public function test_stages_exact_backlog_then_approves_once_and_creates_only_one_ticket(): void
    {
        $client = Client::factory()->create();
        $first = $this->email();
        $second = $this->email();
        $other = $this->email('other@example.test');
        $result = $this->stage($first, $client);
        $this->assertSame(2, $result['sender_wide_count']);
        $this->assertSame([$first->id, $second->id], $result['email_ids']);
        $this->assertNull($first->fresh()->client_id);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, \App\Models\TechnicianRun::count());
        $proposal = EmailResolutionProposal::findOrFail($result['proposal_id']);
        $this->assertStringNotContainsString('sender@example.test', $proposal->getRawOriginal('payload'));
        $this->assertStringNotContainsString('DO NOT LOG MESSAGE BODY', json_encode(McpAuditLog::all()->toArray()));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get('/cockpit')->assertOk()->assertSee('2 unresolved email(s)')->assertSee('sender@example.test')->assertSee('Target client #'.$client->id);
        $this->actingAs($admin)->postJson(route('email-resolutions.approve', $proposal->id))->assertOk()->assertJsonPath('sender_wide_count', 2);
        $this->assertSame($client->id, $first->fresh()->client_id);
        $this->assertSame($client->id, $second->fresh()->client_id);
        $this->assertNull($other->fresh()->client_id);
        $this->actingAs($admin)->postJson(route('email-resolutions.approve', $proposal->id))->assertStatus(409);
        $this->assertSame('done', $proposal->fresh()->state);
        $service = app(EmailService::class);
        $stale = $first->fresh();
        $one = $service->autoCreateTicketFromEmail($stale);
        $two = $service->autoCreateTicketFromEmail($stale);
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_changed_backlog_refuses_and_requires_a_new_proposal(): void
    {
        $client = Client::factory()->create();
        $first = $this->email();
        $result = $this->stage($first, $client);
        $extra = $this->email();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(EmailResolutionService::class);
        $refused = $service->approve($result['proposal_id'], $admin);
        $this->assertStringContainsString('backlog changed', $refused['error']);
        $this->assertSame('stale', EmailResolutionProposal::find($result['proposal_id'])->state);
        $this->assertNull($first->fresh()->client_id);
        $this->assertNull($extra->fresh()->client_id);
        $new = $this->stage($first, $client);
        $this->assertNotSame($result['proposal_id'], $new['proposal_id']);
        $this->assertSame(2, $new['sender_wide_count']);
        $this->assertTrue($service->approve($new['proposal_id'], $admin)['success']);
    }

    public function test_legacy_ungranted_immediate_malformed_and_extra_arguments_fail_closed(): void
    {
        $client = Client::factory()->create();
        $email = $this->email();
        $args = ['email_id' => $email->id, 'client_id' => $client->id, 'reason' => 'Fixture', 'staged' => true];
        foreach ([null, ['list_clients']] as $grant) {
            $response = $this->callResolve($grant, $args);
            $this->assertTrue($response->json('error') !== null || (bool) $response->json('result.isError'), $response->getContent());
        }
        foreach ([false, 'true', 1, null] as $staged) {
            $this->assertNotNull($this->callResolve(['resolve_email_item:immediate'], array_replace($args, ['staged' => $staged]))->json('error'));
        }
        foreach ([null, 0, -1, '1', [], true] as $clientId) {
            $this->assertNotNull($this->callResolve(['resolve_email_item:staged'], array_replace($args, ['client_id' => $clientId]))->json('error'));
        }
        $r = $this->callResolve(['resolve_email_item:staged'], $args + ['person_id' => 3]);
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertSame(0, EmailResolutionProposal::count());
        $this->assertNull($email->fresh()->client_id);
    }

    public function test_kill_switch_deny_and_removed_cohort_member_refuse(): void
    {
        $client = Client::factory()->create();
        $email = $this->email();
        $second = $this->email();
        $result = $this->stage($email, $client);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(EmailResolutionService::class);
        Setting::setValue('technician_kill_switch', '1');
        $this->assertArrayHasKey('error', $service->approve($result['proposal_id'], $admin));
        $this->assertArrayHasKey('error', $service->stage(['email_id' => $email->id, 'reason' => 'Fixture'], $client->id, 'fixture'));
        $this->assertNull($email->fresh()->client_id);
        Setting::setValue('technician_kill_switch', '0');
        $second->update(['client_id' => $client->id]);
        $this->assertArrayHasKey('error', $service->approve($result['proposal_id'], $admin));
        $this->assertNull($email->fresh()->client_id);
        $fresh = $this->stage($email, $client);
        $this->assertTrue($service->deny($fresh['proposal_id'], $admin)['success']);
        $this->assertArrayHasKey('error', $service->approve($fresh['proposal_id'], $admin));
        $this->assertNull($email->fresh()->client_id);
    }

    public function test_approval_permissions_and_tamper_refuse_without_resolution(): void
    {
        $client = Client::factory()->create();
        $email = $this->email();
        $result = $this->stage($email, $client);
        $service = app(EmailResolutionService::class);
        foreach ([['role' => 'billing', 'is_active' => true], ['role' => 'admin', 'is_active' => false]] as $attrs) {
            $user = User::factory()->create($attrs);
            $this->actingAs($user)->postJson(route('email-resolutions.approve', $result['proposal_id']))->assertForbidden();
            $this->assertArrayHasKey('error', $service->approve($result['proposal_id'], $user));
        }
        $proposal = EmailResolutionProposal::findOrFail($result['proposal_id']);
        $payload = $proposal->payload;
        $payload['client_id'] = Client::factory()->create()->id;
        $proposal->update(['payload' => $payload]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->assertArrayHasKey('error', $service->approve($proposal->id, $admin));
        $this->assertNull($email->fresh()->client_id);
    }
}
