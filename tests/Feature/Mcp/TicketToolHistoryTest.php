<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\TicketToolActivity;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketToolHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function callTool(string $token, string $tool, array $args): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ])->assertOk();

        return $response->json();
    }

    private function decoded(array $response): array
    {
        return json_decode($response['result']['content'][0]['text'], true);
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->for(Client::factory())->create();
    }

    private function action(Ticket $ticket, string $state): TechnicianActionLog
    {
        return TechnicianActionLog::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'synthetic_tool', 'actor_label' => 'synthetic actor',
            'tier' => 'approve', 'result_status' => $state, 'content_hash' => str_repeat('a', 64),
            'summary' => 'SECRET-RAW-PAYLOAD', 'correlation_id' => (string) Str::uuid(),
        ]);
    }

    public function test_staged_call_links_exact_action_and_both_surfaces_show_proposed_without_payload(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = $this->ticket();
        $token = McpConfig::rotateStaffToken(allowedTools: ['stage_public_note', 'get_ticket_tool_history'], label: 'synthetic-bot');
        $response = $this->callTool($token, 'stage_public_note', [
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'reason' => 'Synthetic staging check', 'body' => 'SECRET-RAW-PAYLOAD',
        ]);
        $this->assertFalse($response['result']['isError'], json_encode($response));
        $action = TechnicianActionLog::where('ticket_id', $ticket->id)->firstOrFail();
        $audit = McpAuditLog::where('tool_name', 'stage_public_note')->firstOrFail();
        $this->assertSame($action->id, $audit->action_log_id);
        $this->assertSame($action->correlation_id, $audit->correlation_id);
        $this->assertSame($ticket->id, $audit->ticket_id);
        $this->assertSame('success', $audit->status);
        $this->assertStringNotContainsString('SECRET-RAW-PAYLOAD', $audit->result_summary);
        $page = $this->decoded($this->callTool($token, 'get_ticket_tool_history', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id]));
        $this->assertCount(1, $page['items']);
        $this->assertSame('proposed', $page['items'][0]['state']);
        $this->assertSame('stage_public_note', $page['items'][0]['tool']);
        $this->assertStringContainsString('synthetic-bot', $page['items'][0]['actor']);
        $this->assertStringNotContainsString('SECRET-RAW-PAYLOAD', json_encode($page));
        $this->actingAs($actor)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Tool activity')->assertSee('Awaiting approval; not executed.');
    }

    public function test_read_association_scope_absent_context_and_grant_denial(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_notes', 'get_ticket_tool_history'], label: 'reader');
        $this->callTool($token, 'get_ticket_notes', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id]);
        $audit = McpAuditLog::where('tool_name', 'get_ticket_notes')->latest('id')->firstOrFail();
        $this->assertSame($ticket->id, $audit->ticket_id);
        $this->assertNull($audit->action_log_id);
        $this->assertSame('read', app(TicketToolActivity::class)->page($ticket)['items'][0]['state']);
        $this->callTool($token, 'get_ticket_notes', ['ticket_id' => $ticket->id, 'client_id' => $other->client_id]);
        $this->assertNull(McpAuditLog::latest('id')->firstOrFail()->ticket_id);
        $this->callTool($token, 'get_ticket_notes', ['ticket_id' => $ticket->id]);
        $this->assertNull(McpAuditLog::latest('id')->firstOrFail()->ticket_id);
        $denied = $this->callTool($token, 'get_ticket_tool_history', ['ticket_id' => $ticket->id, 'client_id' => $other->client_id]);
        $this->assertTrue($denied['result']['isError']);
        $noGrant = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_notes']);
        $this->assertTrue($this->callTool($noGrant, 'get_ticket_tool_history', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id])['result']['isError']);
        $this->withHeaders(['Authorization' => 'Bearer invalid'])->postJson('/api/mcp/staff', [])->assertUnauthorized();
        $this->get(route('tickets.show', $ticket))->assertRedirect(route('login'));
    }

    public function test_action_ticket_wins_over_multiple_validations_and_cli_is_inert(): void
    {
        $first = $this->ticket();
        $second = $this->ticket();
        $this->assertNull(\App\Services\Mcp\TicketToolActivityContext::current());
        $cli = $this->action($first, 'executed');
        $this->assertTrue($cli->exists);
        $context = new \App\Services\Mcp\TicketToolActivityContext;
        request()->attributes->set(\App\Services\Mcp\TicketToolActivityContext::class, $context);
        try {
            $context->validated($first);
            $context->validated($second);
            $action = $this->action($first, 'executed');
            $context->validated($second);
            $this->assertSame($first->id, $context->ticketId);
            $this->assertSame($action->id, $context->actionLogId);
            $this->assertSame($action->correlation_id, $context->correlationId);
        } finally {
            request()->attributes->remove(\App\Services\Mcp\TicketToolActivityContext::class);
        }
    }

    public function test_self_reads_remain_audited_but_not_projected_and_absent_context_has_null_columns(): void
    {
        $ticket = $this->ticket();
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_tool_history', 'list_clients']);
        foreach ([1, 2] as $attempt) {
            $page = $this->decoded($this->callTool($token, 'get_ticket_tool_history', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id]));
            $this->assertSame([], $page['items']);
        }
        $this->assertSame(2, McpAuditLog::where('tool_name', 'get_ticket_tool_history')->where('ticket_id', $ticket->id)->count());
        $this->callTool($token, 'list_clients', []);
        $audit = McpAuditLog::latest('id')->firstOrFail();
        foreach (['ticket_id', 'client_id', 'action_log_id', 'correlation_id', 'activity_kind', 'result_summary'] as $column) {
            $this->assertNull($audit->$column, $column);
        }
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes('mcp_audit_logs');
        $this->assertTrue(collect($indexes)->contains(fn ($index) => $index['columns'] === ['action_log_id']));
        $this->assertSame('varchar', \Illuminate\Support\Facades\Schema::getColumnType('mcp_audit_logs', 'correlation_id'));
    }

    public function test_execution_failure_and_pending_are_not_call_success(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = $this->ticket();
        $token = McpConfig::rotateStaffToken(allowedTools: ['update_ticket', 'get_ticket_attachment']);
        $args = ['ticket_id' => $ticket->id, 'reason' => 'Synthetic change', 'subject' => 'Changed synthetic subject'];
        $response = $this->callTool($token, 'update_ticket', $args);
        $this->assertFalse($response['result']['isError'], json_encode($response));
        $audit = McpAuditLog::latest('id')->firstOrFail();
        $this->assertNotNull($audit->action_log_id);
        $this->assertSame('executed', app(TicketToolActivity::class)->page($ticket)['items'][0]['state']);
        $failure = $this->callTool($token, 'get_ticket_attachment', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'attachment_id' => 99999]);
        $this->assertTrue($failure['result']['isError']);
        $audit = McpAuditLog::latest('id')->firstOrFail();
        $this->assertSame($ticket->id, $audit->ticket_id);
        $this->assertSame('failure', $audit->activity_kind);
        $this->assertSame('failure', collect(app(TicketToolActivity::class)->page($ticket)['items'])->firstWhere('tool', 'get_ticket_attachment')['state']);
        // A returned write without a produced action row must not become executed.
        $context = new \App\Services\Mcp\TicketToolActivityContext;
        $context->finish(['success' => true, 'secret' => 'SECRET-RAW-PAYLOAD']);
        $this->assertSame('pending', $context->kind);
        $this->assertStringNotContainsString('SECRET-RAW-PAYLOAD', $context->summary);
        $this->assertNull(\App\Services\Mcp\TicketToolActivityContext::current());
    }

    public function test_history_discovery_limits_and_explicit_grant(): void
    {
        $ticket = $this->ticket();
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_tool_history']);
        $list = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ])->assertOk()->json('result.tools');
        $this->assertContains('get_ticket_tool_history', array_column($list, 'name'));
        foreach ([['limit' => 51], ['limit' => 0], ['offset' => -1], ['offset' => 10001], ['limit' => '2']] as $invalid) {
            $response = $this->callTool($token, 'get_ticket_tool_history', $invalid + ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id]);
            $this->assertTrue($response['result']['isError']);
        }
        $legacy = McpConfig::rotateStaffToken();
        $this->assertTrue($this->callTool($legacy, 'get_ticket_tool_history', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id])['result']['isError']);
        $this->assertTrue($this->callTool($token, 'get_ticket_tool_history', ['ticket_id' => $ticket->id])['result']['isError']);
    }

    public function test_projection_pagination_action_precedence_and_redaction(): void
    {
        $ticket = $this->ticket();
        foreach (['executed', 'awaiting_approval', 'held', 'error', 'executed_with_fault'] as $state) {
            $this->action($ticket, $state);
        }
        $foreign = $this->action($this->ticket(), 'executed');
        McpAuditLog::create(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'server_name' => 'staff', 'method' => 'tools/call', 'tool_name' => 'bad-link',
            'actor_label' => 'reader', 'status' => 'success', 'activity_kind' => 'pending', 'duration_ms' => 0,
            'action_log_id' => $foreign->id, 'correlation_id' => $foreign->correlation_id,
            'result_summary' => 'SECRET-RAW-PAYLOAD', 'arguments' => ['secret' => 'SECRET-RAW-PAYLOAD'],
            'error_message' => 'SECRET-RAW-PAYLOAD']);
        // A moved ticket's old-client call remains invisible, even with the same ticket id.
        McpAuditLog::create(['ticket_id' => $ticket->id, 'client_id' => $foreign->client_id,
            'server_name' => 'staff', 'method' => 'tools/call', 'tool_name' => 'old-client',
            'actor_label' => 'reader', 'status' => 'success', 'duration_ms' => 0]);
        $service = app(TicketToolActivity::class);
        $page = $service->page($ticket, 2);
        $this->assertCount(2, $page['items']);
        $this->assertTrue($page['has_more']);
        $this->assertSame(2, $page['next_offset']);
        $all = $service->page($ticket, 50);
        $this->assertCount(6, $all['items']);
        $this->assertFalse($all['has_more']);
        $this->assertNull($all['next_offset']);
        $this->assertStringNotContainsString('SECRET-RAW-PAYLOAD', json_encode($all));
        $states = array_column($all['items'], 'state');
        $this->assertContains('executed', $states);
        $this->assertContains('proposed', $states);
        $this->assertContains('failure', $states);
        $this->assertContains('pending', $states);
        // A faulted execution is never projected as a denial of execution.
        $this->assertContains('executed_with_fault', $states);
        $this->assertCount(1, array_filter($states, fn (string $state): bool => $state === 'failure'));
        $faulted = collect($all['items'])->firstWhere('state', 'executed_with_fault');
        $this->assertStringContainsString('executed with a fault', $faulted['summary']);
        $ids = array_column($all['items'], 'id');
        $this->assertSame(array_slice($ids, 2, 2), array_column($service->page($ticket, 2, 2)['items'], 'id'));
        $this->assertSame('pending', collect($all['items'])->firstWhere('tool', 'bad-link')['state']);
    }
}
