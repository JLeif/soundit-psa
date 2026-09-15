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
        $ids = array_column($all['items'], 'id');
        $this->assertSame(array_slice($ids, 2, 2), array_column($service->page($ticket, 2, 2)['items'], 'id'));
        $this->assertSame('pending', collect($all['items'])->firstWhere('tool', 'bad-link')['state']);
    }
}
