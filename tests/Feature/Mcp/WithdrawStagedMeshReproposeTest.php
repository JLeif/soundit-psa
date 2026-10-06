<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\WithdrawStagedActionTool;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Card XUiMXNEH, "afterwards": once a drafter withdraws a Mesh allow-rule proposal,
 * mesh_add_allow_rule for the same sender must accept a fresh one. Its pending
 * guards (liveAwaitingRun / awaitingRunForSender) read awaiting_approval only, so a
 * Withdrawn run must not block the corrected proposal, whatever lifetime it asks.
 *
 * Nothing is stamped by hand: the run is staged through the real MCP Mesh path
 * with a token, which records proposed_meta.drafted_by_token, and withdrawn over
 * MCP by that same token. So these tests also prove the writer.
 */
class WithdrawStagedMeshReproposeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('mesh_api_key', 'k');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $write = Mockery::mock(MeshWriteClient::class);
        $write->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $write->shouldNotReceive('createAllowRule');
        $this->app->instance(MeshWriteClient::class, $write);
    }

    private ?string $token = null;

    private function token(): string
    {
        return $this->token ??= McpConfig::rotateStaffToken(allowedTools: ['mesh_add_allow_rule:staged', 'withdraw_staged_action'], label: 'opsbot');
    }

    private function stage(array $fixture, array $overrides = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'mesh_add_allow_rule', 'arguments' => array_merge([
                    'client_id' => $fixture['client']->id,
                    'ticket_id' => $fixture['ticket']->id,
                    'sender' => 'billing@vendor.example',
                    'confirm_domain' => 'vendor.example',
                    'reason' => 'Vendor invoices are being quarantined.',
                ], $overrides)],
            ]);
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Example Co', 'mesh_customer_id' => '11111111-2222-3333-4444-555555555555']);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);

        return compact('client', 'ticket');
    }

    private function stagedRun(array $fixture, array $overrides = []): TechnicianRun
    {
        $response = $this->stage($fixture, $overrides);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $runId = json_decode((string) $response->json('result.content.0.text'), true)['run_id'];
        $run = TechnicianRun::findOrFail($runId);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    private function withdrawAsDrafter(TechnicianRun $run): void
    {
        $this->assertSame('opsbot', $run->proposed_meta['drafted_by_token'] ?? null, 'the Mesh staging path records the drafting token');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => WithdrawStagedActionTool::NAME, 'arguments' => ['run_id' => $run->id, 'reason' => 'Wrong lifetime; restaging.']],
            ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::Withdrawn, $run->fresh()->state);
    }

    public function test_a_corrected_lifetime_is_accepted_after_the_drafter_withdraws(): void
    {
        $fixture = $this->fixture();
        $first = $this->stagedRun($fixture, ['expires_at' => 'never']);

        // Control: while the first is awaiting, the different-lifetime proposal is refused.
        $blocked = $this->stage($fixture, ['expires_at' => now()->addDays(7)->toIso8601String()]);
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('already awaiting approval', (string) $blocked->json('result.content.0.text'));

        $this->withdrawAsDrafter($first);

        $second = $this->stagedRun($fixture, ['expires_at' => now()->addDays(7)->toIso8601String()]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(TechnicianRunState::Withdrawn, $first->fresh()->state);
    }

    public function test_an_identical_proposal_is_staged_fresh_after_the_drafter_withdraws(): void
    {
        $fixture = $this->fixture();
        $first = $this->stagedRun($fixture);

        $this->withdrawAsDrafter($first);

        // Not answered "Already staged; awaiting approval." against the withdrawn run.
        $second = $this->stagedRun($fixture);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, TechnicianRun::where('action_type', 'mesh_stage_add_allow_rule')->count());
    }
}
