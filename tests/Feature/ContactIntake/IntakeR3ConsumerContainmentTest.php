<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Jobs\RunTriagePipeline;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Ai\AiClient;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** r3 layer-1 rework: triage, AI ticket readers and intake routing never see a held form ticket. */
class IntakeR3ConsumerContainmentTest extends TestCase
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

    /** diff:5 — the triage job never hands a held ticket to the pipeline; once verified it does. */
    public function test_triage_job_does_not_run_the_pipeline_on_a_held_ticket(): void
    {
        $ticket = $this->formTicket();

        $pipeline = $this->createMock(TriagePipeline::class);
        $pipeline->expects($this->never())->method('run');
        (new RunTriagePipeline($ticket->id, 'triage'))->handle($pipeline);

        // Positive control: the same job runs the pipeline once staff verify the ticket.
        $this->verify($ticket);
        $pipeline = $this->createMock(TriagePipeline::class);
        $pipeline->expects($this->once())->method('run');
        (new RunTriagePipeline($ticket->id, 'triage'))->handle($pipeline);
    }

    /** diff:5 — a direct pipeline run on a held ticket runs no stage. */
    public function test_triage_pipeline_runs_no_stage_on_a_held_ticket(): void
    {
        $run = app(TriagePipeline::class)->run($this->formTicket(), 'review');

        $this->assertSame('failed', $run->status);
        $this->assertSame([], $run->stages_completed);
        $this->assertSame('pre_check', $run->errors[0]['stage']);
        $this->assertStringContainsString('Unverified contact intake', $run->errors[0]['message']);
    }

    /** diff:5 — the scheduled review sweep never selects a held ticket; once verified it does. */
    public function test_review_sweep_skips_a_held_ticket(): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_review', '1');
        Setting::setEncrypted('ai_api_key', 'test-key');
        $ticket = $this->formTicket(['subject' => 'Synthetic held sweep ticket']);

        $this->artisan('triage:review-open', ['--dry-run' => true])
            ->doesntExpectOutputToContain('Synthetic held sweep ticket')
            ->assertSuccessful();

        // Positive control: the verified ticket is reviewed.
        $this->verify($ticket);
        $this->artisan('triage:review-open', ['--dry-run' => true])
            ->expectsOutputToContain('Synthetic held sweep ticket')
            ->assertSuccessful();
    }

    /** diff:6 — the assistant / staff-MCP ticket readers neither list nor read a held ticket. */
    public function test_assistant_ticket_readers_do_not_serve_a_held_ticket(): void
    {
        $subject = 'Synthetic held reader ticket';
        $ticket = $this->formTicket(['subject' => $subject]);
        $staff = new AssistantToolExecutor;
        $scoped = new AssistantToolExecutor(null, $ticket->client_id);

        $this->assertSame(['error' => 'Ticket not found'], $staff->execute('get_ticket_detail', ['ticket_id' => $ticket->id]));
        $this->assertStringNotContainsString($subject, json_encode($staff->execute('search_all_tickets', [])));
        $this->assertStringNotContainsString($subject, json_encode($staff->execute('list_open_tickets', [])));
        $this->assertStringNotContainsString($subject, json_encode($scoped->execute('search_tickets', [])));
        $this->assertArrayHasKey('error', $scoped->execute('get_ticket_notes', ['ticket_id' => $ticket->id]));

        // Positive control: once verified, every reader serves it.
        $this->verify($ticket);
        $this->assertSame($subject, $staff->execute('get_ticket_detail', ['ticket_id' => $ticket->id])['subject']);
        $this->assertStringContainsString($subject, json_encode($staff->execute('search_all_tickets', [])));
        $this->assertStringContainsString($subject, json_encode($staff->execute('list_open_tickets', [])));
        $this->assertStringContainsString($subject, json_encode($scoped->execute('search_tickets', [])));
        $this->assertArrayNotHasKey('error', $scoped->execute('get_ticket_notes', ['ticket_id' => $ticket->id]));
    }

    /** diff:6 — a held note on an ordinary ticket is not served to the assistant until verified. */
    public function test_assistant_note_readers_withhold_a_held_note(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $note = new TicketNote(['ticket_id' => $ticket->id, 'body' => 'Synthetic held note body', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'ai_authored' => false, 'is_private' => true, 'noted_at' => now()]);
        $note->forceFill(['contact_intake_origin' => true])->save();
        $notes = fn () => json_encode((new AssistantToolExecutor(null, $ticket->client_id))->execute('get_ticket_notes', ['ticket_id' => $ticket->id]));
        $detail = fn () => json_encode((new AssistantToolExecutor)->execute('get_ticket_detail', ['ticket_id' => $ticket->id]));

        $this->assertStringNotContainsString('Synthetic held note body', $notes());
        $this->assertStringNotContainsString('Synthetic held note body', $detail());

        // Positive control: the verified note is served like any other.
        $note->forceFill(['contact_intake_verified_at' => now()])->save();
        $this->assertStringContainsString('Synthetic held note body', $notes());
        $this->assertStringContainsString('Synthetic held note body', $detail());
    }

    /** context:1 — intake routing never offers a held ticket to the model as a candidate. */
    public function test_intake_router_offers_no_held_candidate(): void
    {
        $ticket = $this->formTicket();
        $email = new Email;
        $email->client_id = $ticket->client_id;
        $email->subject = 'Synthetic follow-up';
        $email->body_text = 'Any news on this?';

        // One model call in total: none while held, one for the positive control.
        $this->mock(AiClient::class)->shouldReceive('completeJson')->once()->andReturn([
            'decision' => 'attach', 'ticket_id' => $ticket->id, 'confidence' => 0.9, 'reason' => 'Same issue',
        ]);

        $this->assertFalse(app(IntakeRouter::class)->route($email)->isAttach());

        // Positive control: once verified, the ticket is a candidate again.
        $this->verify($ticket);
        $decision = app(IntakeRouter::class)->route($email);
        $this->assertTrue($decision->isAttach());
        $this->assertSame($ticket->id, $decision->ticketId);
    }

    /** c1:v2:1 — the assistant's propose_close does not find a held ticket and records nothing. */
    public function test_assistant_propose_close_does_not_act_on_a_held_ticket(): void
    {
        User::factory()->create();
        $ticket = $this->formTicket();
        $propose = fn () => (new AssistantToolExecutor)->execute('propose_close',
            ['ticket_id' => $ticket->id, 'reason' => 'Synthetic close reason', 'confidence' => 0.9]);

        $this->assertSame(['error' => 'Ticket not found'], $propose());
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());

        // Positive control: once verified, the close is proposed and held for approval.
        $this->verify($ticket);
        $this->assertTrue($propose()['success'] ?? false);
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')
            ->where('state', TechnicianRunState::AwaitingApproval->value)->count());
    }

    /** c1:v2:1 — ProposeCloseTool itself records nothing on a held ticket, on either entry point. */
    public function test_propose_close_tool_records_nothing_on_a_held_ticket(): void
    {
        User::factory()->create();
        $ticket = $this->formTicket();
        $held = "Left ticket #{$ticket->id} (an unverified web-form intake awaits staff verification; no close proposed).";
        $input = ['reason' => 'Synthetic close reason', 'confidence' => 0.9];

        $this->assertSame($held, app(ProposeCloseTool::class)->executeHeld($ticket, $input));
        $this->assertSame($held, app(ProposeCloseTool::class)->execute($ticket, $input));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());

        // Positive control: the verified ticket gets a held proposal.
        $ticket = $this->verify($ticket);
        $this->assertStringContainsString('held for approval', app(ProposeCloseTool::class)->executeHeld($ticket, $input));
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count());
    }

    /** c1:v2:2 — get_ticket_calls refuses a held ticket like get_ticket_detail, serving none of its calls. */
    public function test_assistant_ticket_calls_do_not_serve_a_held_ticket(): void
    {
        $ticket = $this->formTicket();
        PhoneCall::create(['call_uuid' => 'intake-held-call', 'ticket_id' => $ticket->id, 'from_number' => '+15095550101',
            'to_number' => '+15095550202', 'status' => CallStatus::Completed, 'started_at' => now()->subHour(),
            'call_summary' => 'HELD-CALL-SUMMARY', 'cleaned_transcript' => 'HELD-CALL-TRANSCRIPT']);
        $staff = fn () => (new AssistantToolExecutor)->execute('get_ticket_calls', ['ticket_id' => $ticket->id]);
        $scoped = fn () => (new AssistantToolExecutor(null, $ticket->client_id))->execute('get_ticket_calls', ['ticket_id' => $ticket->id]);

        foreach ([$staff(), $scoped()] as $result) {
            $this->assertSame(['error' => 'Ticket not found'], $result);
        }

        // Positive control: once verified, both readers serve its calls.
        $this->verify($ticket);
        foreach ([$staff(), $scoped()] as $result) {
            $this->assertSame($ticket->display_id, $result['display_id'] ?? null);
            $this->assertStringContainsString('HELD-CALL-TRANSCRIPT', json_encode($result));
        }
    }
}
