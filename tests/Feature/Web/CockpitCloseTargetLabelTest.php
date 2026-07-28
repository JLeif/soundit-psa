<?php

namespace Tests\Feature\Web;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-d9ayt: a staged close_ticket run carries its requested terminal target in
 * proposed_meta.close_status. The cockpit closure card must NAME that target — a
 * resolve is not a close — so the operator approves the transition they see, not a
 * generic "Close" for every held terminal proposal. Legacy propose_close runs
 * (no marker) keep the "Proposed close" / "Close" wording unchanged.
 */
class CockpitCloseTargetLabelTest extends TestCase
{
    use RefreshDatabase;

    private function closeRun(?string $closeStatus): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Widget will not start',
        ]);

        $meta = ['confidence' => null];
        if ($closeStatus !== null) {
            $meta['close_status'] = $closeStatus;
        }

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => str_repeat('d', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Summary of the outcome.',
            'proposed_meta' => $meta,
        ]);
    }

    public function test_resolve_target_card_says_resolve_on_badge_and_button(): void
    {
        $this->closeRun(TicketStatus::Resolved->value);

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Proposed resolve')
            ->assertSee('Resolve</button>', false);
    }

    public function test_close_target_card_says_close_not_resolve(): void
    {
        $this->closeRun(TicketStatus::Closed->value);

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Proposed close')
            ->assertSee('Close</button>', false)
            ->assertDontSee('Proposed resolve');
    }

    public function test_legacy_close_run_without_a_marker_says_close(): void
    {
        $this->closeRun(null); // legacy propose_close: no close_status marker

        $this->actingAs(User::factory()->create())->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Proposed close')
            ->assertDontSee('Proposed resolve');
    }
}
