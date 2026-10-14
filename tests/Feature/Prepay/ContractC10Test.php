<?php

namespace Tests\Feature\Prepay;

use App\Models\ContactSubmission;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContactIntake\StaffWorkflow;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC10Test extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_call_is_not_stamped_or_debited(): void
    {
        [$ticket, $contract, $call] = $this->linkedCall();
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertNull($call->fresh()->contract_id);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_call_logged_before_verification_is_released_at_verification(): void
    {
        [$ticket, $contract, $call] = $this->linkedCall();
        $time = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($time->fresh()->contract_held_at);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic form',
            'contact_intake_origin' => true, 'contact_intake_verified_at' => null,
            'noted_at' => now(),
        ]);
        $row = ContactSubmission::forceCreate([
            'integration_id' => 'website', 'submission_id' => 'synthetic-c10',
            'payload_hash' => hash('sha256', 'synthetic'), 'identity_hash' => hash('sha256', 'synthetic'),
            'receipt' => 'synthetic-c10', 'payload' => [], 'state' => 'processed',
            'ticket_id' => $ticket->id, 'ticket_note_id' => $note->id,
        ]);
        app(StaffWorkflow::class)->act($row->id, User::factory()->admin()->create(['is_active' => true]), 'verify', 'Synthetic verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertSame($contract->id, $ticket->fresh()->contract_id);
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count(), 'pre-verification call released');
        $this->assertSame(1, PrepayTransaction::where('ticket_note_id', $time->id)->count());
        $this->assertSame(2, PrepayTransaction::count());
        $this->assertSame($contract->id, PrepayTransaction::where('phone_call_id', $call->id)->sole()->contract_id);
        $this->assertSame($contract->id, PrepayTransaction::where('ticket_note_id', $time->id)->sole()->contract_id);
        foreach ([$call, $time] as $entry) {
            $this->assertSame($contract->id, $entry->fresh()->contract_id);
            $this->assertNull($entry->fresh()->contract_held_at);
        }
        $this->assertEquals(8, $contract->fresh()->prepay_balance);
    }

    public function test_intake_hold_tooltips_render_for_call_and_note(): void
    {
        [$ticket] = $this->linkedCall();
        TicketNote::forceCreate(['ticket_id' => $ticket->id, 'body' => 'Synthetic time', 'note_type' => 'note', 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now()]);
        $page = $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertOk();
        $page->assertSee("Not debited yet: this ticket's contract is not settled (the client has several active contracts and no default, or the ticket is waiting for verification).");
        $page->assertSee("Not debited yet: this ticket's contract is not settled (the client has several active contracts and no default, or the ticket is waiting for verification). Verify the ticket, or edit the note to choose its contract.");
    }

    public function test_never_verified_entries_stay_held(): void
    {
        [$ticket, , $call] = $this->linkedCall();
        $note = TicketNote::forceCreate(['ticket_id' => $ticket->id, 'body' => 'Synthetic time', 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now()]);
        $this->travel(2)->days();
        app(\App\Services\PrepayService::class)->releaseHeldDebits($ticket->client_id);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
    }

    public function test_picked_intake_contract_cannot_resolve_or_debit(): void
    {
        [$ticket, $contract, $call] = $this->linkedCall();
        $this->assertFalse(app(\App\Services\ContractResolver::class)->forEntry($ticket, $contract->id)->isResolved());
        $note = TicketNote::forceCreate(['ticket_id' => $ticket->id, 'contract_id' => $contract->id, 'body' => 'Synthetic picked time', 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now()]);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertNotNull($note->fresh()->contract_held_at);
    }

    public function test_plain_none_stays_unmarked(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create();
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(['call_uuid' => 'synthetic-none', 'from_number' => '+15555550142', 'direction' => 'inbound', 'ticket_id' => $ticket->id, 'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now()]));
        app(\App\Services\PrepayService::class)->debitFromPhoneCall($call->fresh());
        $note = TicketNote::forceCreate(['ticket_id' => $ticket->id, 'body' => 'Synthetic none', 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now()]);
        $this->assertNull($call->fresh()->contract_held_at);
        $this->assertNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
    }

    public function test_verified_ticket_resolves(): void
    {
        [$ticket, $contract] = $this->linkedCall();
        $ticket->forceFill(['contact_intake_verified_at' => now()])->saveQuietly();
        $this->assertSame($contract->id, app(\App\Services\ContractResolver::class)->forEntry($ticket)->contract?->id);
    }

    public function test_existing_contract_gets_sla_at_verification(): void
    {
        [$ticket, $contract] = $this->slaTicket();
        $ticket->updateQuietly(['contract_id' => $contract->id]);
        $this->verify($ticket);
        $this->assertEquals($ticket->opened_at->copy()->addHours(4), $ticket->fresh()->due_at);
        $this->assertEquals($ticket->opened_at->copy()->addHours(1), $ticket->fresh()->response_due_at);
        $this->assertSame(0, $this->verificationNotes($ticket)->count());
        $this->assertSame(0, \App\Models\ContractActivity::where('action', 'ticket_contract_assigned_at_verification')->count());
    }

    public function test_assigned_sla_runs_from_opened_at_and_is_already_breached(): void
    {
        [$ticket] = $this->slaTicket();
        $this->verify($ticket);
        $this->assertEquals($ticket->opened_at->copy()->addHours(4), $ticket->fresh()->due_at);
        $this->assertEquals($ticket->opened_at->copy()->addHours(1), $ticket->fresh()->response_due_at);
        $this->assertTrue($ticket->fresh()->due_at->isPast());
        $this->assertTrue($ticket->fresh()->response_due_at->isPast());
    }

    public function test_existing_either_deadline_prevents_sla_restamping(): void
    {
        foreach (['due_at', 'response_due_at'] as $field) {
            [$ticket, $contract] = $this->slaTicket();
            $ticket->updateQuietly(['contract_id' => $contract->id, $field => now()->addDay()]);
            $before = [$ticket->due_at, $ticket->response_due_at];
            $this->verify($ticket);
            $this->assertEquals($before, [$ticket->fresh()->due_at, $ticket->fresh()->response_due_at]);
        }
    }

    public function test_default_verification_note(): void
    {
        [$ticket, $contract] = $this->slaTicket();
        $this->verify($ticket);
        $this->assertVerificationNote($ticket, "Contract Synthetic C10 assigned at verification: it is the client's default contract.");
        $this->assertSame('client_default', \App\Models\ContractActivity::where('action', 'ticket_contract_assigned_at_verification')->sole()->changes['rule']);
    }

    public function test_only_active_verification_note(): void
    {
        [$ticket] = $this->slaTicket();
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $this->verify($ticket);
        $this->assertVerificationNote($ticket, "Contract Synthetic C10 assigned at verification: it is the client's only active contract.");
        $this->assertSame('only_active', \App\Models\ContractActivity::where('action', 'ticket_contract_assigned_at_verification')->sole()->changes['rule']);
    }

    public function test_ambiguous_verification_note(): void
    {
        [$ticket, $contract] = $this->slaTicket();
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $copy = $contract->replicate();
        $copy->name = 'Synthetic other';
        $copy->save();
        $this->verify($ticket);
        $this->assertVerificationNote($ticket, "No contract was assigned at verification: this client has several active contracts and no default. Choose this ticket's contract.");
        $this->assertNull($ticket->fresh()->contract_id);
        $this->assertSame(0, \App\Models\ContractActivity::where('action', 'ticket_contract_assigned_at_verification')->count());
    }

    public function test_none_writes_no_verification_note(): void
    {
        [$ticket, $contract] = $this->slaTicket();
        $contract->updateQuietly(['status' => 'expired']);
        $this->verify($ticket);
        $this->assertSame(0, $this->verificationNotes($ticket)->count());
    }

    private function verificationNotes(Ticket $ticket)
    {
        return $ticket->notes()->where('note_type', \App\Enums\NoteType::System)->where('body', 'like', '%at verification:%');
    }

    private function assertVerificationNote(Ticket $ticket, string $body): void
    {
        $this->assertSame(1, $this->verificationNotes($ticket)->count());
        $note = $this->verificationNotes($ticket)->sole();
        $this->assertSame($body, $note->body);
        $this->assertTrue($note->is_private);
        $this->assertSame(\App\Enums\NoteType::System, $note->note_type);
        $this->assertSame(\App\Enums\WhoType::System, $note->who_type);
        $this->assertNull($note->author_id);
        $this->assertSame('System', $note->author_name);
        $this->assertSame(\App\Helpers\MarkdownRenderer::render($body), $note->body_html);
    }

    private function slaTicket(): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'priority' => 'p3', 'opened_at' => now()->subHours(12), 'due_at' => null, 'response_due_at' => null]);
        $contract = Contract::create(['client_id' => $ticket->client_id, 'name' => 'Synthetic C10', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01', 'sla_terms' => ['resolution' => ['p3' => 4], 'response' => ['p3' => 1]]]);
        $ticket->client->forceFill(['default_contract_id' => $contract->id])->saveQuietly();

        return [$ticket, $contract];
    }

    private function verify(Ticket $ticket): void
    {
        $note = TicketNote::forceCreate(['ticket_id' => $ticket->id, 'body' => 'Synthetic form', 'contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'noted_at' => now()]);
        $key = 'synthetic-c10-'.$ticket->id;
        $row = ContactSubmission::forceCreate(['integration_id' => 'website', 'submission_id' => $key, 'payload_hash' => hash('sha256', $key), 'identity_hash' => hash('sha256', $key), 'receipt' => $key, 'payload' => [], 'state' => 'processed', 'ticket_id' => $ticket->id, 'ticket_note_id' => $note->id]);
        app(StaffWorkflow::class)->act($row->id, User::factory()->admin()->create(['is_active' => true]), 'verify', 'Synthetic verification');
    }

    private function linkedCall(): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $contract = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic C10',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket->client->forceFill(['default_contract_id' => $contract->id])->save();
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c10', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);

        return [$ticket, $contract, $call];
    }
}
