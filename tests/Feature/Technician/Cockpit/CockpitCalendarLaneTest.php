<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-lulgh blocker 1: staged calendar writes must be VISIBLE and APPROVABLE in
 * the cockpit. Both operator-facing classifiers (CockpitQuery::isEndpointOrAccount
 * Action and the blade's $isAccountAction) recognised only tactical_/cipp_ prefixes,
 * so calendar_stage_* runs sat in AwaitingApproval with a label and a badge but
 * NO card and NO Approve — the away-mode approval loop was silently broken for the
 * whole calendar family.
 *
 * The PR's own StaffCalendarWriteWiringTest POSTed cockpit.approve directly and so
 * never rendered the cockpit; this test closes that gap by GETting the rendered
 * page and asserting the card AND its Approve form are actually there.
 */
class CockpitCalendarLaneTest extends TestCase
{
    use RefreshDatabase;

    private function stagedCalendarCancel(): TechnicianRun
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Reschedule onsite']);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'calendar_stage_cancel_event',
            'content_hash' => hash('sha256', 'cal-cancel'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Cancel: Onsite visit Thursday 2pm',
            'proposed_meta' => ['drafted_by' => 'mcp-staff:chet'],
        ]);
    }

    public function test_a_staged_calendar_write_renders_an_approval_card_in_the_actions_lane(): void
    {
        $run = $this->stagedCalendarCancel();

        $this->actingAs(User::factory()->create())
            ->get(route('cockpit.index'))
            ->assertOk()
            // The card itself: subject + the curated badge label (rendered ONLY inside
            // the $actionDrafts loop, so its presence proves the run reached the lane).
            ->assertSee('Reschedule onsite')
            ->assertSee('Calendar event cancel')
            ->assertSee('Cancel: Onsite visit Thursday 2pm')
            // And it is APPROVABLE, not merely visible: the Approve form targets this run.
            ->assertSee(route('cockpit.approve', $run));
    }

    public function test_the_actions_count_includes_a_staged_calendar_write(): void
    {
        $this->stagedCalendarCancel();

        // CockpitQuery drives the counts the "all clear" empty state keys on; a
        // calendar write must lift actions off zero so all-clear cannot render over it.
        $counts = app(\App\Services\Technician\Cockpit\CockpitQuery::class)->counts();

        $this->assertGreaterThanOrEqual(1, $counts['actions'],
            'a staged calendar write must count in the cockpit actions lane');
    }
}
