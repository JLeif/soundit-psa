<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * withdraw_staged_action over the staff MCP endpoint (card XUiMXNEH): published
 * in the sensitive psa_action group, callable only with an explicit grant (never
 * by the legacy full-surface token), scoped by the drafting token rather than a
 * client (client_id refused), undeclared arguments refused, and audited through
 * the same McpAuditLog path as every other staff MCP write.
 */
class WithdrawStagedActionMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    /** @return array<int, string> */
    private function listed(string $token): array
    {
        return array_column($this->rpc($token, 'tools/list')->json('result.tools') ?? [], 'name');
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    /** Stage a public note over MCP with $token; the run records that token's bare label. */
    private function stagedNote(string $token): TechnicianRun
    {
        $client = Client::factory()->create(['name' => 'Example Co']);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Printer offline at example.test']);
        $res = $this->callTool($token, 'stage_public_note', [
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'reason' => 'Client asked for an update.',
            'body' => 'We replaced the toner.',
        ]);
        $runId = json_decode($this->text($res), true)['run_id'] ?? null;
        $this->assertNotNull($runId, (string) $res->getContent());
        $run = TechnicianRun::findOrFail($runId);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    private function grantedToken(string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['stage_public_note', 'withdraw_staged_action'], label: $label);
    }

    public function test_it_sits_in_the_sensitive_psa_action_group(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertContains('withdraw_staged_action', array_column($groups['psa_action']['tools'], 'name'));
        $this->assertTrue($groups['psa_action']['sensitive']);
    }

    public function test_an_explicitly_granted_token_withdraws_its_own_run_and_the_call_is_audited(): void
    {
        $token = $this->grantedToken();
        $run = $this->stagedNote($token);
        $this->assertContains('withdraw_staged_action', $this->listed($token));

        $res = $this->callTool($token, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'Wrong body.']);

        $this->assertFalse((bool) $res->json('result.isError'), (string) $res->getContent());
        $this->assertTrue(json_decode($this->text($res), true)['success'] ?? false);
        $run->refresh();
        $this->assertSame(TechnicianRunState::Withdrawn, $run->state);
        $this->assertSame('drafter', $run->proposed_meta['withdrawn_by']);
        $this->assertSame('chet', $run->proposed_meta['withdrawn_by_token']);

        $audit = McpAuditLog::where('tool_name', 'withdraw_staged_action')->sole();
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-staff:chet', $audit->actor_label);
    }

    public function test_a_token_without_the_grant_is_refused_and_the_run_is_unchanged(): void
    {
        $drafter = McpConfig::rotateStaffToken(allowedTools: ['stage_public_note'], label: 'chet');
        $run = $this->stagedNote($drafter);
        $this->assertNotContains('withdraw_staged_action', $this->listed($drafter));

        $res = $this->callTool($drafter, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'x']);

        $this->assertTrue((bool) $res->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed for this token: withdraw_staged_action', $this->text($res));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame('error', McpAuditLog::where('tool_name', 'withdraw_staged_action')->sole()->status);
    }

    public function test_the_legacy_full_surface_token_is_refused(): void
    {
        $run = $this->stagedNote($this->grantedToken());
        $legacy = McpConfig::rotateStaffToken();
        $this->assertNotContains('withdraw_staged_action', $this->listed($legacy));

        $res = $this->callTool($legacy, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'x']);

        $this->assertTrue((bool) $res->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed for this token: withdraw_staged_action', $this->text($res));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_a_client_id_is_refused_not_required(): void
    {
        $token = $this->grantedToken();
        $run = $this->stagedNote($token);

        $res = $this->callTool($token, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'x', 'client_id' => $run->client_id]);

        $this->assertTrue((bool) $res->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted for withdraw_staged_action', $this->text($res));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_an_unknown_argument_is_refused_by_name(): void
    {
        $token = $this->grantedToken();
        $run = $this->stagedNote($token);

        $res = $this->callTool($token, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'x', 'force' => true]);

        $this->assertTrue((bool) $res->json('result.isError'), (string) $res->getContent());
        $this->assertStringContainsString('Unsupported argument(s): force', $this->text($res));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_another_granted_token_gets_the_not_found_text(): void
    {
        $run = $this->stagedNote($this->grantedToken('chet'));
        $other = $this->grantedToken('opsbot');

        $res = $this->callTool($other, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'x']);

        $this->assertTrue((bool) $res->json('result.isError'));
        $this->assertStringContainsString("No staged action #{$run->id} drafted by this token was found", $this->text($res));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }
}
