<?php

namespace Tests\Feature\Web;

use App\Enums\CallStatus;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn.7 GAP 1: the calls/show "Or link to an existing ticket" picker lists
 * up to 20 of the client's recent tickets so a tech can attach the call to the
 * right one. Each picker row must show the ticket's ITIL category via the shared
 * <x-ticket-category-badge> (leaf in the row, full path on hover) so similarly-
 * titled tickets are disambiguated — the epic goal "everywhere that lists a
 * ticket". Null-safe; retired nodes preserved. Subjects deliberately avoid the
 * category words so the assertions prove the badge rendered, not the subject.
 */
class CallTicketPickerCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    private function retiredNode(): TicketCategory
    {
        return TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
    }

    /** An unlinked (no ticket_id) call for $client, so calls/show renders the picker. */
    private function unlinkedCallFor(Client $client): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15555550100',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ]);
        $call->client_id = $client->id;
        $call->save();

        return $call;
    }

    public function test_call_ticket_picker_row_shows_category(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Machine acting strange',
            'category_id' => $this->tree()->id,
        ]);
        $call = $this->unlinkedCallFor($client);

        $resp = $this->actingAs($this->staff())->get(route('calls.show', $call))->assertOk();
        $resp->assertSee('Fake-AV popup');
        $resp->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false);
    }

    public function test_call_ticket_picker_is_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'category_id' => $this->retiredNode()->id,
        ]);
        $call = $this->unlinkedCallFor($client);

        $this->actingAs($this->staff())->get(route('calls.show', $call))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }
}
