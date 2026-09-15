<?php

namespace Tests\Feature\Mcp;

use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Email;
use App\Models\McpAuditLog;
use App\Models\PhoneCall;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Mcp\TicketTimeline;
use App\Services\Mcp\TicketTimelineTool;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->for(Client::factory())->create();
    }

    private function note(Ticket $ticket, string $at, string $body = 'Synthetic note'): TicketNote
    {
        return TicketNote::create(['ticket_id' => $ticket->id, 'noted_at' => $at, 'body' => $body, 'note_type' => 'note']);
    }

    private function action(Ticket $ticket, string $at, string $state = 'awaiting_approval'): TechnicianActionLog
    {
        return TechnicianActionLog::forceCreate(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'synthetic_timeline_tool', 'actor_label' => 'Synthetic actor', 'tier' => 'approve',
            'result_status' => $state, 'content_hash' => str_repeat('a', 64), 'summary' => 'RAW-SECRET-PAYLOAD',
            'correlation_id' => (string) Str::uuid(), 'created_at' => $at]);
    }

    private function email(Ticket $ticket, string $at, string $direction = 'inbound'): Email
    {
        return Email::create(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'graph_id' => (string) Str::uuid(), 'direction' => $direction, 'from_address' => 'sender@example.test',
            'subject' => 'Synthetic correspondence', 'received_at' => $at, 'body_preview' => 'Synthetic preview']);
    }

    private function callTool(string $token, string $name, array $args): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ])->assertOk()->json();
    }

    private function decoded(array $response): array
    {
        $this->assertFalse($response['result']['isError'], json_encode($response));

        return json_decode($response['result']['content'][0]['text'], true);
    }

    public function test_all_kinds_and_colliding_ids_page_without_loss_in_both_directions(): void
    {
        $ticket = $this->ticket();
        $at = '2026-01-01 10:00:00';
        $this->note($ticket, $at, 'Latency <500ms before <b>the</b> change, >2s after');
        $action = $this->action($ticket, $at);
        McpAuditLog::forceCreate(['id' => $action->id, 'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'server_name' => 'staff', 'method' => 'tools/call', 'tool_name' => 'synthetic_read', 'actor_label' => 'Synthetic reader',
            'status' => 'success', 'activity_kind' => 'read', 'duration_ms' => 1, 'created_at' => $at]);
        $this->email($ticket, $at);
        $this->email($ticket, $at, 'outbound');
        PhoneCall::forceCreate(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'call_uuid' => (string) Str::uuid(),
            'from_number' => '5550100', 'direction' => 'inbound', 'started_at' => $at]);
        AssistantConversation::forceCreate(['context_type' => 'ticket', 'context_id' => $ticket->id,
            'user_id' => User::factory()->create()->id, 'title' => 'Synthetic chat', 'created_at' => $at]);
        $timeline = app(TicketTimeline::class);
        $all = $timeline->page($ticket);
        $this->assertCount(7, $all['items']);
        $this->assertContains('tool_call:'.$action->id, array_column($all['items'], 'id'));
        $this->assertContains('tool_action:'.$action->id, array_column($all['items'], 'id'));
        $this->assertEqualsCanonicalizing(TicketTimeline::TYPES, array_unique(array_column($all['items'], 'kind')));
        $this->assertSame('Latency <500ms before the change, >2s after',
            collect($all['items'])->firstWhere('kind', 'note')['summary'], 'A bare < must not truncate the summary');
        $this->assertEqualsCanonicalizing(['inbound', 'outbound'], array_column(array_filter($all['items'], fn ($e) => $e['kind'] === 'email'), 'direction'));
        $seen = [];
        $input = ['limit' => 1];
        do {
            $page = $timeline->page($ticket, $input);
            $this->assertSame([], array_intersect($seen, array_column($page['items'], 'id')), 'Cursor must make progress without repeating IDs');
            array_push($seen, ...array_column($page['items'], 'id'));
            $input['before'] = $page['next_cursor'];
        } while ($page['has_more']);
        $this->assertSame(array_column($all['items'], 'id'), $seen);
        $seen = [];
        $input = ['limit' => 1, 'after' => $page['after']];
        do {
            $page = $timeline->page($ticket, $input);
            $this->assertSame([], array_intersect($seen, array_column($page['items'], 'id')), 'Cursor must make progress without repeating IDs');
            array_push($seen, ...array_column($page['items'], 'id'));
            $input['after'] = $page['next_cursor'];
        } while ($page['has_more']);
        $this->assertSame(array_reverse(array_slice(array_column($all['items'], 'id'), 0, -1)), $seen);
        $this->assertStringNotContainsString('RAW-SECRET-PAYLOAD', json_encode($all));
    }

    public function test_cursor_stable_across_insert_anchor_delete_filter_and_scope_fenced(): void
    {
        $ticket = $this->ticket();
        $old = $this->note($ticket, '2026-01-01 09:00:00');
        $anchor = $this->note($ticket, '2026-01-01 10:00:00');
        $timeline = app(TicketTimeline::class);
        $first = $timeline->page($ticket, ['limit' => 1, 'types' => ['note']]);
        $this->note($ticket, '2026-01-01 11:00:00');
        $anchor->delete();
        $next = $timeline->page($ticket, ['limit' => 1, 'types' => ['note'], 'before' => $first['before']]);
        $this->assertSame(['note:'.$old->id], array_column($next['items'], 'id'));
        $this->assertFalse($next['has_more']);
        $this->assertNull($first['after'], 'The newest page must not offer a newer page');
        $this->assertNull($next['before'], 'The oldest page must not offer an older page');
        $this->assertNotNull($next['after']);
        $tool = app(TicketTimelineTool::class);
        foreach ([['types' => ['tool'], 'before' => $first['before']], ['before' => 'bad'],
            ['before' => $first['before'], 'after' => $first['before']], ['limit' => 51], ['limit' => '2'], ['types' => []], ['types' => ['bogus']]] as $bad) {
            $this->assertArrayHasKey('error', $tool->execute($bad + ['ticket_id' => $ticket->id], $ticket->client_id));
        }
        $other = $this->ticket();
        $this->assertArrayHasKey('error', $tool->execute(['ticket_id' => $other->id, 'types' => ['note'], 'before' => $first['before']], $other->client_id));
        $this->assertArrayHasKey('error', $tool->execute(['ticket_id' => $ticket->id], $other->client_id));
    }

    public function test_page_interleaves_tool_between_notes_and_removes_separate_panel(): void
    {
        $ticket = $this->ticket();
        $this->note($ticket, '2026-01-01 11:00:00', 'Newest synthetic note marker');
        $this->action($ticket, '2026-01-01 10:00:00');
        $this->note($ticket, '2026-01-01 09:00:00', 'Oldest synthetic note marker');
        $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('Tool activity')->assertSee('Timeline filters')
            ->assertSeeInOrder(['Newest synthetic note marker', 'synthetic_timeline_tool', 'Oldest synthetic note marker'])
            ->assertSee('Awaiting approval; not executed.')->assertDontSee('RAW-SECRET-PAYLOAD')
            // One page holds every entry, so neither navigation link may be offered.
            ->assertDontSee('>Newer<', false)->assertDontSee('>Older<', false);
        $this->get(route('tickets.show', ['ticket' => $ticket, 'types' => ['tool']]))->assertOk()
            ->assertSee('synthetic_timeline_tool')->assertDontSee('Newest synthetic note marker');
    }

    public function test_ai_chat_projection_never_renders_raw_tool_messages(): void
    {
        $ticket = $this->ticket();
        $user = User::factory()->create();
        $chat = AssistantConversation::create(['context_type' => 'ticket', 'context_id' => $ticket->id,
            'user_id' => $user->id, 'title' => 'Synthetic conversation']);
        $chat->messages()->create(['role' => 'tool', 'content' => 'RAW-TOOL-MESSAGE-MARKER']);
        $chat->messages()->create(['role' => 'assistant', 'content' => 'Safe synthetic assistant text']);
        $page = app(TicketTimeline::class)->page($ticket, ['types' => ['ai_chat']]);
        $this->assertStringNotContainsString('RAW-TOOL-MESSAGE-MARKER', json_encode($page));
        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('RAW-TOOL-MESSAGE-MARKER')->assertSee('Safe synthetic assistant text');
    }

    public function test_unlinked_ticket_keeps_records_whose_own_client_resolved(): void
    {
        // Intake ticket still without a client; the call's caller resolved later.
        $ticket = Ticket::factory()->create(['client_id' => null]);
        $resolved = Client::factory()->create();
        PhoneCall::forceCreate(['ticket_id' => $ticket->id, 'client_id' => $resolved->id, 'call_uuid' => (string) Str::uuid(),
            'from_number' => '5550188', 'direction' => 'inbound', 'started_at' => '2026-01-01 10:00:00']);
        $this->email($ticket, '2026-01-01 10:05:00')->update(['client_id' => $resolved->id]);
        $page = app(TicketTimeline::class)->page($ticket, ['types' => ['call', 'email']]);
        $this->assertEqualsCanonicalizing(['call', 'email'], array_column($page['items'], 'kind'),
            'A resolved-client call/email on an unlinked ticket must stay on the timeline');
    }

    public function test_grant_discovery_self_noise_scope_and_state_legend(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        foreach (['awaiting_approval', 'executed', 'executed_with_fault', 'failed', 'held'] as $i => $state) {
            $this->action($ticket, '2026-01-01 10:00:0'.$i, $state);
        }
        $this->action($other, '2026-01-01 12:00:00');
        // Linked to the ticket before caller/client resolution: still the ticket's call.
        PhoneCall::forceCreate(['ticket_id' => $ticket->id, 'client_id' => null, 'call_uuid' => (string) Str::uuid(),
            'from_number' => '5550199', 'direction' => 'inbound', 'started_at' => '2026-01-01 11:00:00']);
        $foreign = $this->email($ticket, '2026-01-01 13:00:00');
        $foreign->update(['client_id' => $other->client_id]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_timeline']);
        $args = ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id];
        for ($i = 0; $i < 2; $i++) {
            $page = $this->decoded($this->callTool($token, 'get_ticket_timeline', $args));
            $this->assertCount(6, $page['items']);
            $this->assertContains('call', array_column($page['items'], 'kind'), 'A NULL-client call linked to the ticket stays on the timeline');
            $this->assertEqualsCanonicalizing(['proposed', 'executed', 'executed_with_fault', 'failed', 'pending'], array_column($page['items'], 'state'));
            $this->assertStringContainsString('not executed', $page['states']);
            $this->assertStringContainsString('do not re-run', $page['states']);
            $this->assertStringNotContainsString('RAW-SECRET-PAYLOAD', json_encode($page));
            $this->assertArrayNotHasKey('model', $page['items'][0]);
        }
        $this->assertSame(2, McpAuditLog::where('tool_name', 'get_ticket_timeline')->where('ticket_id', $ticket->id)->count());
        $this->assertTrue($this->callTool($token, 'get_ticket_timeline', ['ticket_id' => $ticket->id, 'client_id' => $other->client_id])['result']['isError']);
        $noGrant = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_notes']);
        $this->assertTrue($this->callTool($noGrant, 'get_ticket_timeline', $args)['result']['isError']);
        $this->assertContains('get_ticket_timeline', array_column(\App\Support\McpToolRegistry::psaReadTools(), 'name'));
    }

    public function test_notes_keep_legacy_list_and_opt_in_cursor_envelope_reaches_old_history(): void
    {
        $ticket = $this->ticket();
        for ($i = 0; $i < 23; $i++) {
            $this->note($ticket, sprintf('2026-01-01 10:00:%02d', $i), 'Synthetic note '.$i);
        }
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_notes']);
        $args = ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id];
        $legacy = $this->decoded($this->callTool($token, 'get_ticket_notes', $args));
        $this->assertTrue(array_is_list($legacy));
        $this->assertCount(20, $legacy);
        $first = $this->decoded($this->callTool($token, 'get_ticket_notes', $args + ['paginate' => true]));
        $this->assertSame($legacy, $first['notes']);
        $this->assertTrue($first['has_more']);
        $this->note($ticket, '2026-01-01 11:00:00', 'Inserted note');
        $second = $this->decoded($this->callTool($token, 'get_ticket_notes', $args + ['before' => $first['next_cursor']]));
        $this->assertSame(['Synthetic note 0', 'Synthetic note 1', 'Synthetic note 2'], array_column($second['notes'], 'body'));
        $this->assertFalse($second['has_more']);
        $this->assertStringContainsString('oldest first', $second['order']);
        $this->assertStringNotContainsString('Entries always newest first', $second['pagination']);
        $this->assertTrue($this->callTool($token, 'get_ticket_notes', $args + ['before' => 'bad'])['result']['isError']);
    }

    public function test_email_cursor_recovers_older_correspondence_with_filters_and_scope(): void
    {
        $ticket = $this->ticket();
        for ($i = 0; $i < 53; $i++) {
            $this->email($ticket, sprintf('2026-01-01 10:00:%02d', $i));
        }
        $other = $this->ticket();
        $this->email($other, '2026-01-01 12:00:00');
        $token = McpConfig::rotateStaffToken(allowedTools: ['list_email_items']);
        $args = ['client_id' => $ticket->client_id, 'limit' => 50, 'direction' => 'inbound'];
        $first = $this->decoded($this->callTool($token, 'list_email_items', $args));
        $this->assertSame(50, $first['count']);
        $this->assertTrue($first['has_more']);
        $this->email($ticket, '2026-01-01 11:00:00');
        $second = $this->decoded($this->callTool($token, 'list_email_items', $args + ['before' => $first['next_cursor']]));
        $this->assertSame(3, $second['count']);
        $this->assertFalse($second['has_more']);
        $this->assertSame([], array_intersect(array_column($first['email_items'], 'id'), array_column($second['email_items'], 'id')));
        $this->assertTrue($this->callTool($token, 'list_email_items', array_replace($args, ['client_id' => $other->client_id, 'before' => $first['before']]))['result']['isError']);
        $this->assertTrue($this->callTool($token, 'list_email_items', array_replace($args, ['direction' => 'outbound', 'before' => $first['before']]))['result']['isError']);
    }
}
