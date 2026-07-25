<?php

namespace Tests\Feature\Mcp;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Triage\TriageToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * create_ticket MCP: agents (Chet) set a ticket's ITIL taxonomy node
 * (tickets.category_id) AT creation, mirroring the psa-alzsw/psa-begf3.1 human
 * create form (so-0ftg, psa-begf3.2 — the create counterpart to psa-bk13g's
 * update_ticket).
 *
 * The write routes through TicketService::createTicket -> TicketObserver, which
 * (psa-begf3 foundation) stamps tickets.category_source and logs the assignment
 * like any other category write. The MCP surface has no authenticated web-user,
 * so a Chet create stamps source=System — which triage treats as
 * human-owned/protected, i.e. authoritative: the auto-triage that fires on
 * creation will NOT clobber it (proven below). System is honestly distinct from
 * the human form's Staff, and the actor is also recorded in mcp_audit_logs.
 */
class CreateTicketCategoryMcpTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function configureAiActor(): User
    {
        // create_ticket resolves TechnicianConfig::requiredAiActorUserId().
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
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

    /** Minimum valid create_ticket argument set; merge overrides in. */
    private function args(Client $client, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'subject' => 'Laptop will not boot',
            'description' => 'Reported by the user this morning.',
            'reason' => 'New symptom with no existing ticket.',
        ], $overrides);
    }

    private function assertNotError(TestResponse $resp): void
    {
        $resp->assertOk();
        $this->assertFalse((bool) $resp->json('result.isError'), (string) $resp->json('result.content.0.text'));
    }

    public function test_create_ticket_sets_the_taxonomy_category_and_stamps_system_source(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $node = TicketCategory::create(['name' => 'Boot failure']);
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => $node->id]));

        $this->assertNotError($resp);
        $ticket = Ticket::latest('id')->first();
        $this->assertSame($node->id, $ticket->category_id);
        // No auth web-user on the MCP surface -> System (triage-protected).
        $this->assertSame(TicketCategoryChangeSource::System, $ticket->category_source);
        // Audited via the shared change-log seam (previous null — a fresh ticket).
        $this->assertDatabaseHas('ticket_category_change_logs', [
            'ticket_id' => $ticket->id,
            'previous_category_id' => null,
            'new_category_id' => $node->id,
            'source' => 'system',
        ]);
    }

    public function test_create_ticket_without_a_category_is_uncategorized(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client));

        $this->assertNotError($resp);
        $ticket = Ticket::latest('id')->first();
        $this->assertNull($ticket->category_id);
        $this->assertNull($ticket->category_source);
    }

    public function test_create_ticket_rejects_an_inactive_category(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false]);
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => $retired->id]));

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('result.isError'));
        $this->assertSame(0, Ticket::count()); // no ticket on a rejected payload
    }

    public function test_create_ticket_rejects_a_nonexistent_category(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => 999999]));

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('result.isError'));
        $this->assertSame(0, Ticket::count());
    }

    /**
     * A rejected category_id must not hand the agent the bare validator line —
     * it returns recovery copy naming (1) the active-node requirement, (2) how to
     * enumerate valid ids (list_ticket_categories without include_inactive), and
     * (3) that omitting it / null creates the ticket uncategorized. Mirrors the
     * update_ticket UX must-fix (psa-bk13g), reworded for the create surface.
     */
    private function assertActionableCategoryRecoveryCopy(TestResponse $resp): void
    {
        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('result.isError'));

        $text = (string) $resp->json('result.content.0.text');
        $this->assertStringContainsStringIgnoringCase('must reference an active', $text);
        $this->assertStringContainsString('list_ticket_categories', $text);
        $this->assertStringContainsString('include_inactive', $text);
        // Names THIS surface to retry, and the uncategorized escape hatch.
        $this->assertStringContainsString('create_ticket', $text);
        $this->assertStringContainsStringIgnoringCase('uncategorized', $text);
    }

    public function test_rejected_inactive_category_returns_actionable_recovery_copy(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false]);
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => $retired->id]));

        $this->assertActionableCategoryRecoveryCopy($resp);
        $this->assertSame(0, Ticket::count());
    }

    public function test_rejected_nonexistent_category_returns_actionable_recovery_copy(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket']);

        $resp = $this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => 999999]));

        $this->assertActionableCategoryRecoveryCopy($resp);
        $this->assertSame(0, Ticket::count());
    }

    public function test_the_published_schema_advertises_category_id(): void
    {
        $token = $this->token(['create_ticket']);

        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];

        $create = collect($tools)->firstWhere('name', 'create_ticket');
        $this->assertNotNull($create, 'create_ticket tool not published');
        $this->assertArrayHasKey('category_id', $create['inputSchema']['properties'] ?? []);
    }

    /**
     * The authoritative claim (bead psa-begf3.2): a category set by Chet AT
     * creation is System-owned, so the auto-triage that fires on creation KEEPS
     * it — it is never remapped to whatever the classifier would have chosen.
     * Proven end to end: create via MCP with category_id, then run the same
     * triage taxonomy mapping against a DIFFERENT classification pair.
     */
    public function test_a_system_owned_create_time_category_survives_a_triage_mapping(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();

        $security = TicketCategory::create(['name' => 'Security & EDR']);
        $phishing = TicketCategory::create(['name' => 'Phishing/BEC', 'parent_id' => $security->id]);
        $malware = TicketCategory::create(['name' => 'Malware/ransomware', 'parent_id' => $security->id]);
        config(['tickets.taxonomy_map' => [
            'Security' => ['Malware' => ['Security & EDR', 'Malware/ransomware']],
        ]]);

        // Chet creates the ticket already sitting on the phishing node.
        $token = $this->token(['create_ticket']);
        $this->assertNotError($this->callTool($token, 'create_ticket', $this->args($client, ['category_id' => $phishing->id])));
        $ticket = Ticket::latest('id')->first();
        $this->assertSame(TicketCategoryChangeSource::System, $ticket->category_source);

        // Triage would map "Security/Malware" -> the malware node, but the
        // System-owned node is human-owned in the precedence, so it is kept.
        $result = (new TriageToolExecutor($ticket))->execute('set_ticket_category', [
            'category' => 'Security',
            'subcategory' => 'Malware',
        ]);

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $ticket->refresh();
        $this->assertSame($phishing->id, $ticket->category_id); // NOT remapped to malware
        $this->assertNotSame($malware->id, $ticket->category_id);
    }
}
