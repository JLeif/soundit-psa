<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card XUiMXNEH, Jeeves's ruling 2: every Mesh staging site records the caller's
 * BARE token label as proposed_meta.drafted_by_token beside the prefixed
 * drafted_by, threaded from the staff MCP controller. Each site is staged here
 * through the real MCP endpoint and then withdrawn over MCP by the same token, so
 * the writer (not a hand-stamped fixture) is what the withdraw matches.
 */
class WithdrawStagedMeshTokenLabelTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('mesh_api_key', 'k');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function token(string $verb, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: [$verb.':staged', 'withdraw_staged_action'], label: $label);
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Example Co', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);

        return compact('client', 'ticket');
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldNotReceive('createAllowRule');
        $this->app->instance(MeshWriteClient::class, $write);

        return $write;
    }

    /** An existing PSA-tracked rule the remove/edit verbs resolve through the scoped read. */
    private function existingRule(array $fixture): void
    {
        MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-xyz',
            'expires_at' => now()->addDays(30)->startOfMinute(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);
        $this->mockWrite()->shouldReceive('findRuleById')->andReturn([
            'id' => 'rule-xyz',
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'ab' => MeshWriteClient::ALLOW_RULE,
            'created_by' => 'owner@soundit.example',
            'date_expiry' => now()->addDays(120)->startOfMinute()->toDateString(),
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> verb => [verb, staged action_type] */
    public static function sites(): array
    {
        return [
            'add' => ['mesh_add_allow_rule', 'mesh_stage_add_allow_rule'],
            'remove' => ['mesh_remove_allow_rule', 'mesh_stage_remove_allow_rule'],
            'edit' => ['mesh_edit_allow_rule', 'mesh_stage_edit_allow_rule'],
        ];
    }

    private function stage(string $verb, string $token, array $fixture): TechnicianRun
    {
        $base = [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Vendor invoices are being quarantined.',
        ];
        $arguments = match ($verb) {
            'mesh_add_allow_rule' => $base + ['sender' => 'billing@vendor.example', 'confirm_domain' => 'vendor.example'],
            'mesh_remove_allow_rule' => $base + ['rule_id' => 'rule-xyz', 'confirm_sender' => 'billing@vendor.example'],
            'mesh_edit_allow_rule' => $base + ['rule_id' => 'rule-xyz', 'confirm_sender' => 'billing@vendor.example',
                'expires_at' => now()->addDays(60)->startOfMinute()->toIso8601String()],
        };
        $res = $this->callTool($token, $verb, $arguments);
        $this->assertFalse((bool) $res->json('result.isError'), (string) $res->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail(json_decode((string) $res->json('result.content.0.text'), true)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    #[DataProvider('sites')]
    public function test_each_mesh_staging_site_records_the_bare_token_label(string $verb, string $actionType): void
    {
        $fixture = $this->fixture();
        $verb === 'mesh_add_allow_rule' ? $this->mockWrite() : $this->existingRule($fixture);

        $run = $this->stage($verb, $this->token($verb), $fixture);

        $this->assertSame($actionType, $run->action_type);
        $this->assertSame('opsbot', $run->proposed_meta['drafted_by_token'] ?? null);
        $this->assertSame('mcp-staff:opsbot', $run->proposed_meta['drafted_by']);
    }

    #[DataProvider('sites')]
    public function test_the_same_token_withdraws_a_mesh_run_another_token_gets_not_found(string $verb, string $actionType): void
    {
        $fixture = $this->fixture();
        $verb === 'mesh_add_allow_rule' ? $this->mockWrite() : $this->existingRule($fixture);
        $drafter = $this->token($verb);
        $run = $this->stage($verb, $drafter, $fixture);

        $other = $this->callTool($this->token($verb, 'otherbot'), 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'Not mine.']);
        $this->assertTrue((bool) $other->json('result.isError'));
        $this->assertStringContainsString("No staged action #{$run->id} drafted by this token was found", (string) $other->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        $mine = $this->callTool($drafter, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'Wrong lifetime; restaging.']);
        $this->assertFalse((bool) $mine->json('result.isError'), (string) $mine->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::Withdrawn, $run->fresh()->state);
    }

    public function test_mcp_stage_add_then_withdraw_then_repropose_succeeds(): void
    {
        $fixture = $this->fixture();
        $this->mockWrite();
        $token = $this->token('mesh_add_allow_rule');
        $first = $this->stage('mesh_add_allow_rule', $token, $fixture);

        $res = $this->callTool($token, 'withdraw_staged_action', ['run_id' => $first->id, 'reason' => 'Wrong lifetime; restaging.']);
        $this->assertFalse((bool) $res->json('result.isError'), (string) $res->json('result.content.0.text'));

        $second = $this->stage('mesh_add_allow_rule', $token, $fixture);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(TechnicianRunState::Withdrawn, $first->fresh()->state);
    }
}
