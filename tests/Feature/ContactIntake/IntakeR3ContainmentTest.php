<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\NotificationEventType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Jobs\SendTicketNotification;
use App\Models\Email;
use App\Models\Person;
use App\Models\SignalEvent;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\SendReplyTool;
use App\Services\Agent\SignificanceGate;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\Technician\DraftPipeline;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** r3 layer-1 controls: a held form ticket is contained as a whole until staff verify it. */
class IntakeR3ContainmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function formTicket(array $attrs = []): Ticket
    {
        $ticket = Ticket::factory()->create($attrs + ['status' => TicketStatus::InProgress]);
        $ticket->forceFill(['contact_intake_origin' => true])->save();

        return $ticket->fresh();
    }

    private function verify(Ticket $ticket): Ticket
    {
        $ticket->forceFill(['contact_intake_verified_at' => now()])->save();

        return $ticket->fresh();
    }

    /** diff:1 — an inbound-email client reply on a held ticket signals nothing; once verified it does. */
    public function test_email_reply_on_held_ticket_emits_no_client_replied_signal(): void
    {
        $ticket = $this->formTicket();
        $link = fn (Ticket $t, string $graphId) => app(EmailService::class)->linkEmailToTicket(Email::create([
            'graph_id' => $graphId, 'direction' => EmailDirection::Inbound, 'from_address' => 'client@example.test',
            'subject' => 'Synthetic reply', 'body_preview' => 'Any update?', 'body_text' => 'Any update?',
            'received_at' => now(), 'client_id' => $t->client_id,
        ]), $t);

        $link($ticket, 'graph-held-reply');
        $this->assertSame(0, SignalEvent::where('type_key', 'ticket.client_replied')->count());

        // Positive control: the same reply on the verified ticket signals.
        $link($this->verify($ticket), 'graph-verified-reply');
        $this->assertSame(1, SignalEvent::where('type_key', 'ticket.client_replied')->count());
    }

    /** contract-replacement:1 — neither a blind nor a supplied draft is recorded on a held ticket. */
    public function test_send_reply_records_nothing_on_a_held_ticket(): void
    {
        User::factory()->create();
        $this->mock(TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')->once()
            ->andReturn(new TechnicianDraft('Synthetic verified reply.', null, 0)));
        $ticket = $this->formTicket();
        $held = "Left ticket #{$ticket->id} (an unverified web-form intake awaits staff verification; no reply drafted).";

        $this->assertSame($held, app(SendReplyTool::class)->execute($ticket, ['reason' => 'Synthetic']));
        $this->assertSame($held, app(SendReplyTool::class)->executeHeld($ticket,
            ['reason' => 'Synthetic', 'body' => 'Synthetic supplied body.'], 'mcp-staff:synthetic'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());

        // Positive control: once verified, the intake draft slot is still free and is used.
        $ticket = $this->verify($ticket);
        $this->assertStringContainsString('held for approval', app(SendReplyTool::class)->execute($ticket, ['reason' => 'Synthetic']));
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }

    /** contract-replacement:2 — nothing of a held ticket reaches the significance model. */
    public function test_significance_gate_sends_nothing_of_a_held_ticket(): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('complete')->once()->andReturn(new AiResponse(text: 'YES', inputTokens: 5, outputTokens: 1));
        $ticket = $this->formTicket(['subject' => 'FORM_SUBJECT_MARKER']);

        $this->assertFalse((new SignificanceGate($ai))->assess($ticket));

        // Positive control: the verified ticket is assessed as usual.
        $this->assertTrue((new SignificanceGate($ai))->assess($this->verify($ticket)));
    }

    /** contract-replacement:3 — ticket notifications are withheld for a held ticket; ordinary tickets keep them. */
    public function test_ticket_notifications_are_withheld_for_a_held_ticket(): void
    {
        User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $held = $this->formTicket(['assignee_id' => $assignee->id]);
        $ordinary = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assignee_id' => $assignee->id]);
        $email = (new Email)->forceFill(['direction' => EmailDirection::Inbound, 'from_address' => 'client@example.test',
            'body_preview' => 'Any update?']);
        $note = new TicketNote(['body' => 'Any update?']);
        $person = new Person(['first_name' => 'Portal', 'last_name' => 'User']);
        $notifyAll = function (Ticket $ticket) use ($assignee, $actor, $email, $note, $person): void {
            $service = app(NotificationService::class);
            $service->notifyEmailAdded($ticket, $email);
            $service->notifyTicketAssigned($ticket, $assignee->id, $actor->id);
            $service->notifyPriorityChanged($ticket, TicketPriority::P3, TicketPriority::P2, $actor->id);
            $service->notifyStatusChanged($ticket, TicketStatus::New, TicketStatus::InProgress, $actor->id);
            $service->notifyPortalReply($ticket, $note, $person);
        };

        Bus::fake();
        $notifyAll($held);
        Bus::assertNotDispatched(SendTicketNotification::class);

        // Positive control: an ordinary ticket still notifies on every one of them.
        $notifyAll($ordinary);
        foreach ([NotificationEventType::TicketEmailAdded, NotificationEventType::TicketAssigned,
            NotificationEventType::TicketPriorityChanged, NotificationEventType::TicketStatusChanged,
            NotificationEventType::TicketPortalReply] as $event) {
            Bus::assertDispatched(SendTicketNotification::class, fn ($job) => str_contains(serialize($job), $event->value));
        }
    }

    /** contract-replacement:4 — an inbound email does not thread onto a held ticket by its [T-id]. */
    public function test_inbound_email_does_not_thread_onto_a_held_ticket(): void
    {
        $ticket = $this->formTicket();
        $match = new \ReflectionMethod(EmailService::class, 'matchToExistingTicket');
        $email = (new Email)->forceFill(['subject' => "Re: [T-{$ticket->id}] Synthetic"]);

        $this->assertNull($match->invoke(app(EmailService::class), $email));

        // Positive control: the verified ticket is matched by its [T-id] as usual.
        $this->verify($ticket);
        $this->assertSame($ticket->id, $match->invoke(app(EmailService::class), $email)?->id);
    }

    private function pipelineHasUnaddressedClientReply(Ticket $ticket): bool
    {
        $pipeline = (new \ReflectionClass(DraftPipeline::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(DraftPipeline::class, 'hasUnaddressedClientReply'))->invoke($pipeline, $ticket);
    }

    private function plainReply(Ticket $ticket): void
    {
        TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'plain', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'ai_authored' => false, 'is_private' => false, 'noted_at' => now()]);
    }

    /** Written now, stamped with a submission time that predates the proposal. */
    private function backdatedContainedReply(Ticket $ticket): TicketNote
    {
        $note = new TicketNote(['ticket_id' => $ticket->id, 'body' => 'x', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'ai_authored' => false, 'is_private' => true, 'noted_at' => now()->subHours(5)]);
        $note->forceFill(['contact_intake_origin' => true])->save();

        return $note;
    }

    private function proposeResolution(Ticket $ticket): void
    {
        TechnicianRun::create(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'action_type' => 'propose_resolution',
            'content_hash' => hash('sha256', 'synthetic-resolution'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Synthetic resolution.', 'proposed_meta' => [], 'confidence' => 0.9, 'tokens_used' => 0]);
    }

    /** diff:3 — the pipeline waits for verification instead of proposing past a contained reply. */
    public function test_draft_pipeline_waits_for_verification_of_a_contained_reply(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $this->plainReply($ticket);
        $this->travel(5)->minutes();
        $this->proposeResolution($ticket);
        $this->travel(4)->hours();
        $contained = $this->backdatedContainedReply($ticket);
        $this->travel(1)->hours();
        $this->plainReply($ticket);
        $this->travel(1)->minutes();

        $this->assertFalse($this->pipelineHasUnaddressedClientReply($ticket));

        // Positive control: once verified, the ticket is proposed against.
        $contained->forceFill(['contact_intake_verified_at' => now()])->save();
        $this->assertTrue($this->pipelineHasUnaddressedClientReply($ticket));
    }

    /** diff:3 — a backdated contained reply verified after our last proposal is still unaddressed. */
    public function test_draft_pipeline_treats_a_reply_verified_after_the_last_proposal_as_unaddressed(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $this->plainReply($ticket);
        $this->travel(5)->minutes();
        $this->proposeResolution($ticket);
        $this->assertFalse($this->pipelineHasUnaddressedClientReply($ticket));

        $this->travel(4)->hours();
        $contained = $this->backdatedContainedReply($ticket);
        $this->travel(1)->minutes();
        $contained->forceFill(['contact_intake_verified_at' => now()])->save();
        $this->travel(1)->minutes();

        $this->assertTrue($this->pipelineHasUnaddressedClientReply($ticket));
    }
}
