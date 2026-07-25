<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Create-time taxonomy attribution + audit (so-0ftg CREATE path, psa-begf3
 * foundation). The update path stamps tickets.category_source in
 * TicketObserver::updating() and logs the move in updated(); a category chosen
 * AT creation got NEITHER — no honest owner, no audit row. This foundation
 * mirrors both onto create (creating()/created()), so a human- or agent-set
 * category at creation is attributed (Staff / System) and recorded, and stays
 * triage-protected exactly like an update-path assignment.
 *
 * Only a real node is stamped/logged: a create with no category (the default)
 * carries no ownership and writes no row — a null there is the absence of a
 * choice, not a deliberate clear.
 */
class TicketCreateCategoryAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // created() dispatches triage/technician jobs — not under test here.
        Bus::fake();
    }

    private function node(): TicketCategory
    {
        return TicketCategory::create(['name' => 'Boot failure']);
    }

    public function test_a_web_create_with_a_category_stamps_staff_and_logs_the_assignment(): void
    {
        $node = $this->node();
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        // Stamped in the same INSERT (creating()), from auth context.
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);
        // Audited in created(): previous null (there was no prior node), new = the node.
        $this->assertDatabaseHas('ticket_category_change_logs', [
            'ticket_id' => $ticket->id,
            'previous_category_id' => null,
            'new_category_id' => $node->id,
            'source' => 'staff',
            'changed_by' => $user->id,
        ]);
    }

    public function test_a_no_auth_create_with_a_category_stamps_system(): void
    {
        $node = $this->node();
        // No actingAs — an unauthenticated code path (the MCP surface, imports).

        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        $this->assertSame(TicketCategoryChangeSource::System, $ticket->category_source);
        $this->assertDatabaseHas('ticket_category_change_logs', [
            'ticket_id' => $ticket->id,
            'previous_category_id' => null,
            'new_category_id' => $node->id,
            'source' => 'system',
            'changed_by' => null,
        ]);
    }

    public function test_a_create_without_a_category_stamps_no_owner_and_writes_no_log(): void
    {
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->assertNull($ticket->category_source);
        $this->assertSame(0, TicketCategoryChangeLog::count());
    }
}
