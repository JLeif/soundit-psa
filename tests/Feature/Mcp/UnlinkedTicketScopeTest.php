<?php

namespace Tests\Feature\Mcp;

use App\Models\Ticket;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlinkedTicketScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_authenticated_unlinked_ticket_is_misreported_as_missing(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => null]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic-triage');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'close_ticket', 'arguments' => ['ticket_id' => $ticket->id, 'reason' => 'Synthetic control']],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'ticket_id is required and must resolve to an existing ticket.');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'client_id' => null]);
    }
}
