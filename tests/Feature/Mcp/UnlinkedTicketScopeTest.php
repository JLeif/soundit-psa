<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UnlinkedTicketScopeTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, ?bool $flag = null): string
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: 'synthetic-triage');
        if ($flag !== null) {
            McpToken::where('label', 'synthetic-triage')->update(['allow_unlinked_tickets' => $flag]);
        }

        return $token;
    }

    private function ticket(): Ticket
    {
        Setting::setValue('triage_system_user_id', (string) User::factory()->create()->id);

        return Ticket::factory()->create(['client_id' => null, 'status' => TicketStatus::PendingClient, 'closed_at' => null]);
    }

    private function callTool(string $token, string $name, array $args): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args],
        ])->assertOk();
    }

    private function closeArgs(Ticket $ticket): array
    {
        return ['ticket_id' => $ticket->id, 'reason' => 'Synthetic control', 'resolution_summary' => 'Synthetic resolved issue'];
    }

    private function assertUnlinkedAudit(string $tool): void
    {
        $audit = McpAuditLog::where('tool_name', $tool)->latest('id')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertArrayHasKey('ticket_scope', $audit->arguments);
        $this->assertSame('unlinked', $audit->arguments['ticket_scope']);
        $this->assertArrayHasKey('client_id', $audit->arguments);
        $this->assertNull($audit->arguments['client_id']);
    }

    public function test_new_token_close_succeeds_and_audits_unlinked_scope(): void
    {
        $ticket = $this->ticket();
        $r = $this->callTool($this->token(['close_ticket']), 'close_ticket', $this->closeArgs($ticket));
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertUnlinkedAudit('close_ticket');
    }

    #[TestWith([null])]
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_move_requires_confirmation_then_succeeds_with_original_unlinked_audit(?bool $legacy): void
    {
        $ticket = $this->ticket();
        $client = Client::factory()->create(['name' => 'Synthetic Destination']);
        $token = $this->token(['move_ticket_to_client'], $legacy);
        $args = ['ticket_id' => $ticket->id, 'new_client_id' => $client->id, 'reason' => 'Synthetic linking'];
        $this->callTool($token, 'move_ticket_to_client', $args)->assertJsonPath('result.isError', true);
        $this->assertNull($ticket->fresh()->client_id);
        $r = $this->callTool($token, 'move_ticket_to_client', $args + ['confirm_client_name' => $client->name]);
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $this->assertSame($client->id, $ticket->fresh()->client_id);
        $this->assertUnlinkedAudit('move_ticket_to_client');
    }

    public function test_legacy_flag_values_do_not_affect_granted_close(): void
    {
        foreach ([false, true] as $flag) {
            $ticket = $this->ticket();
            $r = $this->callTool($this->token(['close_ticket'], $flag), 'close_ticket', $this->closeArgs($ticket));
            $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
            $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
            $this->assertUnlinkedAudit('close_ticket');
        }
    }

    public function test_flag_does_not_grant_a_verb(): void
    {
        $ticket = $this->ticket();
        $this->callTool($this->token(['move_ticket_to_client'], true), 'close_ticket', $this->closeArgs($ticket))->assertJsonPath('result.isError', true);
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
    }

    public function test_contact_and_other_ticket_writes_refuse_with_linking_instruction(): void
    {
        $ticket = $this->ticket();
        foreach (['set_ticket_contact', 'update_ticket', 'set_ticket_status', 'assign_ticket', 'assign_asset', 'unassign_asset'] as $tool) {
            $r = $this->callTool($this->token([$tool]), $tool, ['ticket_id' => $ticket->id]);
            $r->assertJsonPath('result.isError', true);
            $this->assertSame("ticket {$ticket->id} is not linked to a client; link it with move_ticket_to_client (confirm_client_name required)", $r->json('result.content.0.text'));
        }
        $this->assertNull($ticket->fresh()->client_id);
    }

    public function test_missing_ticket_keeps_existing_message(): void
    {
        $this->callTool($this->token(['close_ticket']), 'close_ticket', ['ticket_id' => 999999])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'ticket_id is required and must resolve to an existing ticket.');
        $audit = McpAuditLog::where('tool_name', 'close_ticket')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('ticket_scope', $audit->arguments, 'Missing is not an existing unlinked scope');
    }

    public function test_linked_ticket_rejects_supplied_wrong_client_scope(): void
    {
        $ticket = Ticket::factory()->create();
        $other = Client::factory()->create();
        $r = $this->callTool($this->token(['close_ticket']), 'close_ticket', $this->closeArgs($ticket) + ['client_id' => $other->id]);
        $r->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('client_id must be omitted', $r->json('result.content.0.text'));
    }

    public function test_staged_grant_cannot_execute_immediately_without_extra_flag(): void
    {
        $ticket = $this->ticket();
        $token = $this->token(['close_ticket:staged']);
        // Existing mode semantics downgrade rather than refuse an immediate request.
        $r = $this->callTool($token, 'close_ticket', $this->closeArgs($ticket) + ['staged' => false]);
        $this->assertStringContainsString('Immediate execution is not granted', $r->json('result.content.0.text'));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::firstOrFail()->state);
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
    }

    public function test_executor_rechecks_explicit_scope_and_never_treats_null_as_zero(): void
    {
        $ticket = $this->ticket();
        $executor = app(\App\Services\Mcp\StaffPsaActionToolExecutor::class);
        $args = $this->closeArgs($ticket);
        $this->assertArrayHasKey('error', $executor->execute('close_ticket', $args, 0, 'synthetic'));
        $this->assertArrayHasKey('error', $executor->execute('update_ticket', $args, \App\Support\UnlinkedTicketScope::Unlinked, 'synthetic'));
        $client = Client::factory()->create();
        $ticket->update(['client_id' => $client->id]);
        $this->assertArrayHasKey('error', $executor->execute('close_ticket', $args, \App\Support\UnlinkedTicketScope::Unlinked, 'synthetic'));
        $other = Client::factory()->create();
        $this->assertArrayHasKey('error', $executor->execute('close_ticket', $args, $other->id, 'synthetic'));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
    }

    #[TestWith([null])]
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_staged_close_needs_no_extra_flag_and_stays_held(?bool $legacy): void
    {
        $ticket = $this->ticket();
        $r = $this->callTool($this->token(['close_ticket:staged'], $legacy), 'stage_close_ticket', $this->closeArgs($ticket));
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));
        $run = TechnicianRun::firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertNull($run->client_id);
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
        $this->assertUnlinkedAudit('stage_close_ticket');
    }
}
