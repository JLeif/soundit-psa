<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Services\PhoneCallService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Diagnostic controls for the existing contract, not acceptance tests for a resolver. */
class PhoneCallIdentityDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_relink_preserves_unresolved_identity_and_readback_reports_it(): void
    {
        $ticket = Ticket::factory()->create();
        $person = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Synthetic', 'last_name' => 'Contact', 'email' => 'contact@example.test', 'is_active' => true]);
        $ticket->update(['contact_id' => $person->id]);
        $call = PhoneCall::create([
            'call_uuid' => 'synthetic-already-linked-identity',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'status' => CallStatus::Completed,
            'started_at' => now(),
            'ticket_id' => $ticket->id,
            'is_billable' => false,
        ]);
        $this->assertNull($call->fresh()->client_id);
        $this->assertNull($call->fresh()->person_id);

        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);
        $this->assertNull($call->fresh()->client_id);
        $this->assertNull($call->fresh()->person_id);

        $token = McpConfig::rotateStaffToken(allowedTools: ['get_phone_call'], label: 'synthetic-diagnosis');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_phone_call', 'arguments' => ['phone_call_id' => $call->id]],
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'));
        $result = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame($ticket->id, $result['phone_call']['ticket_id']);
        $this->assertArrayHasKey('client_id', $result['phone_call']);
        $this->assertArrayHasKey('person_id', $result['phone_call']);
        $this->assertNull($result['phone_call']['client_id']);
        $this->assertNull($result['phone_call']['person_id']);
    }

    public function test_ticket_link_schema_does_not_accept_explicit_identity(): void
    {
        $schema = McpToolRegistry::linkCallToTicketTool()['input_schema'];
        $this->assertArrayNotHasKey('client_id', $schema['properties']);
        $this->assertArrayNotHasKey('person_id', $schema['properties']);
        $this->assertArrayNotHasKey('contact_id', $schema['properties']);
    }
}
