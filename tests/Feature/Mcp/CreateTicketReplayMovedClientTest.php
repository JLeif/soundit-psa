<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateTicketReplayMovedClientTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['name' => 'Synthetic Actor']);
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
        $this->client = Client::forceCreate(['id' => 81001, 'name' => 'Synthetic Replay X']);
    }

    private function contract(Client $client, int $id, string $name): Contract
    {
        return Contract::forceCreate([
            'id' => $id, 'client_id' => $client->id, 'name' => $name,
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
        ]);
    }

    private function replay(): array
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['create_ticket'], label: 'synthetic-replay');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_ticket', 'arguments' => [
                'client_id' => $this->client->id, 'subject' => 'Synthetic replay subject',
                'description' => 'Synthetic replay description', 'reason' => 'Synthetic replay reason',
            ]],
        ]);
        $response->assertOk();
        $wire = $response->json();
        $result = json_decode($wire['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

        return [$result, $wire];
    }

    private function containsNeedle(array $response, string $needle): bool
    {
        return str_contains(json_encode($response, JSON_THROW_ON_ERROR), $needle);
    }

    public function test_moved_replay_with_destination_default_is_refused(): void
    {
        $this->assertMovedReplay(true);
    }

    public function test_moved_replay_with_destination_candidates_is_refused(): void
    {
        $this->assertMovedReplay(false);
    }

    private function assertMovedReplay(bool $default): void
    {
        [$first] = $this->replay();
        $this->assertTrue($first['success']);
        $ticket = Ticket::findOrFail($first['ticket_id']);
        $other = Client::forceCreate(['id' => $ticket->id, 'name' => 'Synthetic Replay Y']);
        $a = $this->contract($other, 92001, 'Synthetic Destination Alpha');
        $b = $this->contract($other, 92002, 'Synthetic Destination Beta');
        if ($default) {
            $other->update(['default_contract_id' => $a->id]);
        }
        app(TicketService::class)->moveToClient($ticket, $other->id, null, $this->actor->id);
        $this->assertSame($other->id, $ticket->fresh()->client_id);
        $this->assertNull($ticket->fresh()->contract_id);
        $this->assertSame($this->client->id, TechnicianActionLog::sole()->client_id);

        [$result, $wire] = $this->replay();
        foreach ([$a, $b] as $contract) {
            $this->assertFalse($this->containsNeedle($wire, (string) $contract->id));
            $this->assertFalse($this->containsNeedle($wire, $contract->name));
        }
        $this->assertTrue($wire['result']['isError']);
        $this->assertSame([
            'error_code' => 'ticket_moved',
            'error' => 'The previously created ticket is no longer under the requested client.',
        ], $result);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TechnicianActionLog::count());
    }

    public function test_same_client_replay_reports_the_ticket_contract(): void
    {
        $contract = $this->contract($this->client, 93001, 'Synthetic Original Contract');
        [$first] = $this->replay();
        [$result, $wire] = $this->replay();
        $this->assertFalse($wire['result']['isError']);
        $this->assertTrue($result['idempotent']);
        $this->assertSame($first['ticket_id'], $result['ticket_id']);
        $this->assertSame(['id' => $contract->id, 'name' => $contract->name, 'rule' => 'ticket'], $result['contract']);
        $this->assertSame('ticket', $result['contract_rule']);
        $this->assertArrayNotHasKey('candidate_contracts', $result);
        $this->assertFalse($this->containsNeedle($wire, 'no longer under the requested client'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_needle_scan_detects_destination_name_in_nested_response(): void
    {
        $this->assertTrue($this->containsNeedle([
            'result' => ['content' => [['text' => json_encode(['name' => 'Synthetic Destination Alpha'], JSON_THROW_ON_ERROR)]]],
        ], 'Synthetic Destination Alpha'));
    }
}
