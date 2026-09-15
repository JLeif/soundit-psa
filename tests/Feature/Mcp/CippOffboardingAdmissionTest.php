<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\Offboarding\OffboardingAdmission;
use App\Services\Cipp\Offboarding\OffboardingScope;
use App\Support\CippMcpToolPolicy;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class CippOffboardingAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private array $input;

    private CippRestWriteClient $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'synthetic-tenant');
        Setting::setValue('cipp_client_id', 'synthetic-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-secret');
        Setting::setValue('cipp_offboarding_installation_id', '11111111-1111-4111-8111-111111111111');
        $client = Client::factory()->create(['cipp_tenant_domain' => 'example.test']);
        $person = Person::create(['client_id' => $client->id, 'person_type' => PersonType::User, 'first_name' => 'Synthetic', 'last_name' => 'Leaver', 'is_active' => false, 'cipp_user_id' => '22222222-2222-4222-8222-222222222222', 'cipp_upn' => 'leaver@example.test']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->input = ['client_id' => $client->id, 'person_id' => $person->id, 'ticket_id' => $ticket->id, 'confirm_upn' => 'leaver@example.test', 'reason' => 'Synthetic offboarding', 'staged' => true, 'actions' => ['revoke_sessions']];
        $this->vendor = Mockery::mock(CippRestWriteClient::class);
        $this->vendor->shouldReceive('submitOffboardingOnce')->never();
        $this->app->instance(CippRestWriteClient::class, $this->vendor);
    }

    private function reads(): void
    {
        $this->vendor->shouldReceive('offboardingRead')->with('tenants')->andReturn([['customerId' => '33333333-3333-4333-8333-333333333333', 'defaultDomainName' => 'example.test', 'domains' => ['example.test']]]);
        $this->vendor->shouldReceive('offboardingRead')->with('users', ['tenantFilter' => 'example.test'])->andReturn([['id' => '22222222-2222-4222-8222-222222222222', 'userPrincipalName' => 'leaver@example.test', 'userType' => 'Member', 'accountEnabled' => false]]);
    }

    private function token(?array $grants = ['cipp_offboard_user:staged']): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $grants, label: 'synthetic-offboarding');
    }

    private function invokeTool(string $token, array $input, string $name = 'cipp_offboard_user'): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $input],
        ])->json();
    }

    private function staged(): TechnicianRun
    {
        $this->reads();
        $result = $this->invokeTool($this->token(), $this->input);
        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $this->assertSame(1, TechnicianRun::where('action_type', 'cipp_stage_offboard_user')->count(), json_encode($result));

        return TechnicianRun::where('action_type', 'cipp_stage_offboard_user')->firstOrFail();
    }

    public function test_stage_sealed_preview_zero_posts_and_coalesces(): void
    {
        $this->reads();
        $token = $this->token();
        $this->invokeTool($token, $this->input);
        $this->invokeTool($token, $this->input);
        $this->assertSame(1, TechnicianRun::where('action_type', 'cipp_stage_offboard_user')->count());
        $run = TechnicianRun::firstOrFail();
        $this->assertStringContainsString('revoke_sessions', $run->proposed_content);
        $this->assertStringContainsString('All licenses retained', $run->proposed_content);
        $this->assertStringNotContainsString('example.test', $run->proposed_meta['encrypted_payload']);
        $sealed = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);
        $this->assertSame(['revoke_sessions'], $sealed['input']['actions']);
        $this->assertSame('awaiting_approval', $run->state->value);
    }

    public function test_legacy_ungranted_and_immediate_calls_cannot_stage(): void
    {
        foreach ([null, ['get_ticket_detail']] as $grants) {
            $this->invokeTool($this->token($grants), $this->input);
        }
        foreach (['cipp_offboard_user', 'cipp_stage_offboard_user'] as $name) {
            $this->invokeTool($this->token(['cipp_offboard_user:immediate']), array_replace($this->input, ['staged' => false]), $name);
        }
        $this->assertSame(0, TechnicianRun::where('action_type', 'cipp_stage_offboard_user')->count());
    }

    public function test_missing_seal_or_changed_actions_declines(): void
    {
        $run = $this->staged();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $scope = Mockery::mock(OffboardingScope::class)->makePartial();
        $scope->shouldReceive('approver')->with($user->id)->andReturnNull();
        $scope->shouldReceive('token')->never();
        $this->app->instance(OffboardingScope::class, $scope);
        foreach ([[], ['revision' => '2', 'plan_hash' => $run->content_hash, 'actions' => ['revoke_sessions']], ['revision' => '1', 'plan_hash' => $run->content_hash, 'actions' => ['disable_sign_in']]] as $approval) {
            $result = app(OffboardingAdmission::class)->approve($run, $user->id, $approval);
            $this->assertSame('gate_declined', $result->status);
        }
        $this->assertDatabaseCount('cipp_offboarding_operations', 0);
    }

    public function test_changed_rendered_preview_or_metadata_declines_before_scope_reads(): void
    {
        $original = $this->staged();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $scope = Mockery::mock(OffboardingScope::class)->makePartial();
        $scope->shouldReceive('approver')->with($user->id)->andReturnNull();
        $scope->shouldReceive('token')->never();
        $this->app->instance(OffboardingScope::class, $scope);
        foreach (['content', 'actions', 'revision', 'plan_hash'] as $field) {
            $run = clone $original;
            if ($field === 'content') {
                $run->proposed_content = 'Different displayed target';
            } else {
                $meta = $run->proposed_meta;
                $meta[$field] = $field === 'actions' ? ['disable_sign_in'] : 'changed';
                $run->proposed_meta = $meta;
            }
            $result = app(OffboardingAdmission::class)->approve($run, $user->id, ['revision' => '1', 'plan_hash' => $run->content_hash, 'actions' => ['revoke_sessions']]);
            $this->assertSame('gate_declined', $result->status);
        }
        $this->assertDatabaseCount('cipp_offboarding_operations', 0);
    }

    public function test_revoked_grant_declines_at_approval(): void
    {
        $run = $this->staged();
        McpToken::query()->update(['revoked_at' => now()]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $result = app(OffboardingAdmission::class)->approve($run, $user->id, ['revision' => '1', 'plan_hash' => $run->content_hash, 'actions' => ['revoke_sessions']]);
        $this->assertSame('gate_declined', $result->status);
        $this->assertDatabaseCount('cipp_offboarding_operations', 0);
    }

    public function test_unknown_sequential_runtime_blocks_before_scheduler_or_intent(): void
    {
        $run = $this->staged();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $result = app(OffboardingAdmission::class)->approve($run, $user->id, ['revision' => '1', 'plan_hash' => $run->content_hash, 'actions' => ['revoke_sessions']]);
        $this->assertSame('gate_declined', $result->status);
        $this->assertDatabaseCount('cipp_offboarding_operations', 0);
    }

    public function test_scheduler_checks_visible_and_hidden_and_refuses_hidden_active_task(): void
    {
        $run = $this->staged();
        $snapshot = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);
        Setting::setValue('cipp_offboarding_sequential_evidence', json_encode([
            'integration' => $snapshot['namespace'][1], 'sequential' => true, 'version' => 'synthetic-only',
            'checked_at' => now()->subMinute()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String(),
        ]));
        $query = ['tenantFilter' => 'example.test', 'Name' => 'Offboarding: leaver@example.test', 'Type' => 'Invoke-CIPPOffboardingJob'];
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', [...$query, 'ShowHidden' => 'false'])->once()->andReturn([]);
        $this->vendor->shouldReceive('offboardingRead')->with('scheduled', [...$query, 'ShowHidden' => 'true'])->once()->andReturn([['TaskState' => 'Running']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scheduler task blocks admission');
        app(OffboardingScope::class)->dependenciesAndScheduler($snapshot);
    }

    public function test_dynamic_relay_cannot_shadow_wizard(): void
    {
        $this->assertFalse(CippMcpToolPolicy::permitsDynamicTool('cipp_offboard_user', 'different_endpoint'));
        $this->assertFalse(CippMcpToolPolicy::permitsDynamicTool('cipp_any_alias', 'ExecOffboardUser'));
    }
}
