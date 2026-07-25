<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Per-ticket ITIL taxonomy category on the ticket CREATE form (so-0ftg slice,
 * psa-begf3.1 — the create-path mirror of the psa-alzsw edit-form picker). A
 * human can pick the SOP-carrying taxonomy node when opening a ticket, distinct
 * from the legacy free-text category/subcategory. The write reuses
 * tickets.store: category_id is fillable, TicketObserver stamps
 * category_source=staff and logs the assignment (psa-begf3 foundation), so a
 * human-set-at-create node is human-owned and triage will not clobber it.
 */
class TicketCreateCategoryUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // store() fires triage/technician dispatches — not under test here.
        Bus::fake();
    }

    /** A 3-tier branch: Hardware / Laptop / Boot failure. Returns the leaf. */
    private function leaf(): TicketCategory
    {
        $hardware = TicketCategory::create(['name' => 'Hardware']);
        $laptop = TicketCategory::create(['name' => 'Laptop', 'parent_id' => $hardware->id]);

        return TicketCategory::create(['name' => 'Boot failure', 'parent_id' => $laptop->id]);
    }

    /** Minimum valid create payload; merge overrides in. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Laptop will not boot',
            'client_id' => Client::factory()->create()->id,
            'type' => 'incident',
            'priority' => 'p3',
        ], $overrides);
    }

    // ── DISPLAY ──

    public function test_the_create_form_offers_the_taxonomy_picker_with_active_nodes(): void
    {
        $this->leaf();
        TicketCategory::create(['name' => 'Retired Node', 'is_active' => false]);

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.create'))
            ->assertOk();

        $resp->assertSee('name="category_id"', false);         // the picker exists
        $resp->assertSee('Hardware / Laptop / Boot failure');   // active leaf offered as a full path
        $resp->assertDontSee('Retired Node');                   // inactive node not offered
    }

    // ── ASSIGN ──

    public function test_creating_a_ticket_with_a_category_sets_it_and_records_staff(): void
    {
        $node = $this->leaf();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('tickets.store'), $this->payload(['category_id' => $node->id]))
            ->assertRedirect();

        $ticket = Ticket::latest('id')->first();
        $this->assertSame($node->id, $ticket->category_id);
        // Observer-stamped ownership: a human create is Staff, triage-protected.
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);
        $this->assertDatabaseHas('ticket_category_change_logs', [
            'ticket_id' => $ticket->id,
            'previous_category_id' => null,
            'new_category_id' => $node->id,
            'source' => 'staff',
            'changed_by' => $user->id,
        ]);
    }

    public function test_creating_a_ticket_without_a_category_leaves_it_uncategorized(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('tickets.store'), $this->payload())
            ->assertRedirect();

        $ticket = Ticket::latest('id')->first();
        $this->assertNull($ticket->category_id);
        $this->assertNull($ticket->category_source);
    }

    public function test_a_nonexistent_category_id_is_rejected_at_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('tickets.store'), $this->payload(['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, Ticket::count());
    }

    public function test_an_inactive_category_id_is_rejected_at_create(): void
    {
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false]);

        $this->actingAs(User::factory()->create())
            ->post(route('tickets.store'), $this->payload(['category_id' => $retired->id]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, Ticket::count());
    }
}
