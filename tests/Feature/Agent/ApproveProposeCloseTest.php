<?php

namespace Tests\Feature\Agent;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\SendPortalNotification;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Operator-approval path for propose_close runs (Task 8).
 *
 * Covers:
 *  1. Silent-close with teeth: portal contact present → result 'closed', ticket Closed,
 *     run Done, executed audit row, NO SendPortalNotification dispatched.
 *  2. Double-approve is single-use (run-state CAS latch).
 *  3. Gate-declined releases claim → run retryable, NOT stuck in Executing (CO-3).
 *  4. Route-level: no body required for propose_close path (CO-2).
 *  5. Deny: ticket stays open, run → Denied, no executed audit row (CO-10).
 */
class ApproveProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns [actor User, open Ticket (InProgress), held propose_close TechnicianRun].
     * The ticket's contact has portal_enabled = $portalEnabled.
     *
     * Note: Ticket::factory() defaults to Closed (CO-6) — override to InProgress.
     */
    private function heldCloseRun(bool $portalEnabled = false): array
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
            'portal_enabled' => $portalEnabled,
        ]);

        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
        ]);

        $reason = 'No reply in 60 days.';
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':'.$reason),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $reason,
            'confidence' => 0.85,
            'tokens_used' => 0,
        ]);

        return [$actor, $ticket, $run];
    }

    // ── 1. Silent-close with teeth ────────────────────────────────────────────

    /**
     * Approving a propose_close run closes the ticket to Closed silently.
     * Even with a portal-enabled contact present, SendPortalNotification is NOT
     * dispatched — closing to Closed is deliberately silent (CO-18).
     */
    public function test_approve_close_closes_silently_with_portal_contact_present(): void
    {
        Queue::fake();

        [$actor, $ticket, $run] = $this->heldCloseRun(portalEnabled: true);

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('closed', $result->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
        // Portal contact is present and enabled — assert no notification fires.
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    // ── 2. Double-approve is single-use ──────────────────────────────────────

    public function test_double_approve_returns_already_handled(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        $service = app(TechnicianApprovalService::class);

        $first = $service->approveClose($run, $actor->id);
        $second = $service->approveClose($run->fresh(), $actor->id);

        $this->assertSame('closed', $first->status);
        $this->assertSame('already_handled', $second->status);
    }

    // ── 3. Gate-declined releases claim (CO-3) ────────────────────────────────

    /**
     * When the gate declines (kill-switch engaged), the claim is released so the
     * operator can retry. The run must be back at AwaitingApproval, NOT stuck at
     * Executing. Disengaging and re-approving must succeed.
     */
    public function test_gate_declined_releases_claim_and_run_is_retryable(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        Setting::setValue('technician_kill_switch', '1');

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state); // NOT stuck in Executing
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);        // ticket untouched

        // Disengage — the operator can approve again.
        Setting::setValue('technician_kill_switch', '0');

        $retry = app(TechnicianApprovalService::class)->approveClose($run->fresh(), $actor->id);
        $this->assertSame('closed', $retry->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    // ── 4. Route-level: no body required for propose_close (CO-2) ────────────

    /**
     * POSTing to cockpit.approve with NO body for a propose_close run must succeed
     * (redirect + success flash, ticket Closed). The body-required validation must
     * only apply to the reply arm, not the close arm.
     */
    public function test_route_approve_close_redirects_success_without_body(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run)) // NO body
            ->assertRedirect();

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
    }

    // ── 5. Deny (CO-10) ──────────────────────────────────────────────────────

    public function test_deny_leaves_ticket_open_and_run_denied(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        app(TechnicianApprovalService::class)->deny($run);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }

    // ── 6a. close_ticket-originated staged runs honor the requested TARGET + resolution ──

    /**
     * Build a held propose_close run that ORIGINATED from close_ticket/stageClose:
     * it carries proposed_meta.close_status (the requested terminal target) and a
     * meaningful resolution_summary in proposed_content. Returns [actor, ticket, run].
     */
    private function heldCloseRunWithTarget(TicketStatus $target, string $summary): array
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();
        $run->update([
            'proposed_content' => $summary,
            'proposed_meta' => [
                'confidence' => null,
                'close_status' => $target->value,
                'drafted_by' => 'mcp-staff:chet',
            ],
        ]);

        return [$actor, $ticket, $run];
    }

    /**
     * The core defect all three lenses flagged: a staged close_ticket(status=resolved)
     * must RESOLVE on approval, not close — staging changes governance, not action
     * semantics. The resolution_summary is written as BOTH the ticket resolution AND
     * the status-change note body (no silent close), and the result carries 'resolved'.
     */
    public function test_approve_staged_resolve_applies_resolved_and_writes_resolution_summary(): void
    {
        $summary = 'Reinstalled the printer driver; client confirmed working.';
        [$actor, $ticket, $run] = $this->heldCloseRunWithTarget(TicketStatus::Resolved, $summary);

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('resolved', $result->status, 'a resolved target must not report as closed');
        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Resolved, $fresh->status, 'staged resolve must RESOLVE, never close');
        $this->assertSame($summary, $fresh->resolution, 'resolution_summary is written as the ticket resolution');
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
            'status_to' => TicketStatus::Resolved->value,
            'body' => $summary,
        ]);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    /**
     * A staged close_ticket(status=closed) writes resolution_summary as BOTH the ticket
     * resolution and the closing note — the summary must never be dropped for the generic
     * operator-approved note the way the pre-fix path did.
     */
    public function test_approve_staged_close_writes_resolution_summary_as_note_and_resolution(): void
    {
        $summary = 'Closed after the client confirmed the fix held for a week.';
        [$actor, $ticket, $run] = $this->heldCloseRunWithTarget(TicketStatus::Closed, $summary);

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('closed', $result->status);
        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Closed, $fresh->status);
        $this->assertSame($summary, $fresh->resolution, 'resolution_summary is written as the ticket resolution');
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
            'status_to' => TicketStatus::Closed->value,
            'body' => $summary,
        ]);
    }

    /**
     * Backward compat: a LEGACY propose_close run (ProposeCloseTool) carries NO
     * close_status in meta. It must still close with the generic operator-approved note
     * and leave the ticket resolution untouched — target/resolution honoring is
     * close_ticket-only, never inferred from a legacy run's reason text.
     */
    public function test_approve_legacy_propose_close_keeps_generic_note_and_no_resolution(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun(); // proposed_content = reason; no close_status meta

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('closed', $result->status);
        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Closed, $fresh->status);
        $this->assertNull($fresh->resolution, 'a legacy close must not write a resolution from the reason text');
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
            'status_to' => TicketStatus::Closed->value,
            'body' => TechnicianApprovalService::OPERATOR_APPROVED_CLOSE_NOTE,
        ]);
    }

    /**
     * If the ticket is ALREADY at the requested terminal state when a staged resolve is
     * approved (the →Closed auto-withdraw does not fire for →Resolved, so the held run
     * survives), approval degrades to already_handled — it does not re-resolve or throw, and
     * the run reaches Done rather than stranding in Executing. This exercises the approveClose
     * "already at target / stale proposal" skip branch directly.
     */
    public function test_approve_staged_resolve_on_an_already_resolved_ticket_is_graceful(): void
    {
        $summary = 'Resolve that arrived after the ticket was already resolved.';
        [$actor, $ticket, $run] = $this->heldCloseRunWithTarget(TicketStatus::Resolved, $summary);
        $ticket->update(['status' => TicketStatus::Resolved]);

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('already_handled', $result->status);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status, 'a moot resolve must not re-touch the ticket');
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'the run must reach Done, never strand in Executing');
    }

    /**
     * Route-level proof that the cockpit approve endpoint resolves (not closes) a staged
     * resolve and writes the resolution — the controller must honor the run's target, not
     * flash "Ticket closed." and close it.
     */
    public function test_route_approve_staged_resolve_resolves_the_ticket(): void
    {
        $summary = 'Resolved via staged approval.';
        [$actor, $ticket, $run] = $this->heldCloseRunWithTarget(TicketStatus::Resolved, $summary);

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run))
            ->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Resolved, $fresh->status, 'route approval of a staged resolve must resolve, not close');
        $this->assertSame($summary, $fresh->resolution);
    }

    // ── 6. Fail-closed: unknown action type aborts (does NOT fall into the send path) ──

    public function test_route_approve_unknown_action_type_fails_closed(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();
        // An action type the dispatch doesn't recognize must abort, not route to a send.
        $run->update(['action_type' => 'frobnicate']);

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run))
            ->assertStatus(422);

        // Nothing executed; the run is untouched (still AwaitingApproval) and ticket open.
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }
}
