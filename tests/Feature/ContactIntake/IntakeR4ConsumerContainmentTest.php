<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Jobs\ResolveCallerFromPeople;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\EmailService;
use App\Services\PhoneCallService;
use App\Services\TicketService;
use App\Services\Triage\ConversationReviewer;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** r4 layer-1 rework: vendor dedup, review, triage tools, call auto-link, keyword backfill and asset reads skip held intake. */
class IntakeR4ConsumerContainmentTest extends TestCase
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

    private function heldReply(Ticket $ticket, string $body): TicketNote
    {
        $note = new TicketNote(['ticket_id' => $ticket->id, 'body' => $body, 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'ai_authored' => false, 'is_private' => true, 'noted_at' => now()]);
        $note->forceFill(['contact_intake_origin' => true])->save();

        return $note;
    }

    private function vendorEmail(int $clientId, string $subject): Email
    {
        $email = Email::create(['direction' => EmailDirection::Inbound, 'from_address' => 'alerts@emailsecurity.app',
            'subject' => $subject, 'body_text' => 'Synthetic delivery request', 'received_at' => now()]);
        $email->forceFill(['client_id' => $clientId])->save();

        return $email->fresh();
    }

    /** contract-s2:1 — a vendor burst is never deduplicated onto a held ticket; once verified it is. */
    public function test_vendor_dedup_does_not_link_to_a_held_ticket(): void
    {
        $subject = 'Email delivery request: synthetic held burst';
        $held = $this->formTicket(['subject' => $subject]);

        $created = app(EmailService::class)->autoCreateTicketFromEmail($this->vendorEmail($held->client_id, $subject));
        $this->assertNotSame($held->id, $created->id);
        $created->delete();

        // Positive control: the verified ticket absorbs the burst.
        $this->verify($held);
        $linked = app(EmailService::class)->autoCreateTicketFromEmail($this->vendorEmail($held->client_id, $subject));
        $this->assertSame($held->id, $linked->id);
    }

    /** contract-s4:1 — review waits on an unverified client reply instead of assessing blind; once verified it runs. */
    public function test_review_waits_on_a_contained_client_reply(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $note = $this->heldReply($ticket, 'Synthetic held reply');
        $this->mock(AiClient::class)->shouldReceive('completeJson')->once()
            ->andReturn(['assessment' => 'active', 'confidence' => 'high', 'confidence_score' => 90, 'reasoning' => 'Synthetic']);
        $review = fn () => ConversationReviewer::review($ticket->fresh(), app(AiClient::class), app(TicketService::class), true);

        $this->assertSame(['skipped' => true, 'reason' => 'contained_reply_pending'], $review());

        // Positive control: the verified reply is reviewed like any other.
        $note->forceFill(['contact_intake_verified_at' => now()])->save();
        $this->assertSame('active', $review()['assessment'] ?? null);
    }

    /** context:1 — the triage/agent PSA tools neither list nor read a held ticket; once verified they do. */
    public function test_triage_tools_do_not_serve_a_held_ticket(): void
    {
        $held = $this->formTicket(['subject' => 'Zebrafinch held ticket']);
        $tools = new TriageToolExecutor(Ticket::factory()->create(['client_id' => $held->client_id, 'status' => TicketStatus::InProgress]));

        $this->assertStringNotContainsString('Zebrafinch', json_encode($tools->execute('search_tickets', ['query' => 'Zebrafinch'])));
        $this->assertStringNotContainsString('Zebrafinch', json_encode($tools->execute('list_client_tickets', [])));
        $this->assertArrayHasKey('error', $tools->execute('get_ticket_notes', ['ticket_id' => $held->id]));

        // Positive control: once verified, every tool serves it.
        $this->verify($held);
        $this->assertStringContainsString('Zebrafinch', json_encode($tools->execute('search_tickets', ['query' => 'Zebrafinch'])));
        $this->assertStringContainsString('Zebrafinch', json_encode($tools->execute('list_client_tickets', [])));
        $this->assertArrayNotHasKey('error', $tools->execute('get_ticket_notes', ['ticket_id' => $held->id]));
    }

    /** context:1 — get_ticket_notes withholds a held note on an ordinary ticket until verified. */
    public function test_triage_notes_tool_withholds_a_held_note(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $note = $this->heldReply($ticket, 'Synthetic held triage note');
        $notes = fn () => json_encode((new TriageToolExecutor($ticket))->execute('get_ticket_notes', ['ticket_id' => $ticket->id]));

        $this->assertStringNotContainsString('Synthetic held triage note', $notes());

        // Positive control: the verified note is served.
        $note->forceFill(['contact_intake_verified_at' => now()])->save();
        $this->assertStringContainsString('Synthetic held triage note', $notes());
    }

    /** context:2 — a completed call is never auto-linked to a held ticket; once verified it is. */
    public function test_call_auto_link_skips_a_held_ticket(): void
    {
        User::factory()->create();
        Setting::setValue('call_autolink_enabled', '1');
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Ada', 'last_name' => 'Caller',
            'phone' => '+15555550142', 'is_active' => true]);
        $held = $this->formTicket(['client_id' => $client->id, 'contact_id' => $person->id]);
        $call = PhoneCall::create(['call_uuid' => uniqid('autolink_', true), 'direction' => CallDirection::Inbound,
            'from_number' => '+15555550142', 'status' => CallStatus::Completed, 'started_at' => now()]);

        (new ResolveCallerFromPeople($call->id))->handle(app(PhoneCallService::class));
        $call = $call->fresh();
        $this->assertSame($person->id, $call->person_id);
        $this->assertNull($call->ticket_id);

        // Positive control: once verified, the sole open ticket is linked.
        $this->verify($held);
        $this->assertSame($held->id, app(PhoneCallService::class)->autoLinkToSoleOpenTicket($call));
        $this->assertSame($held->id, $call->fresh()->ticket_id);
    }

    /** context:4 — the keyword backfill never sends a held ticket to the model; once verified it does. */
    public function test_keyword_backfill_skips_a_held_ticket(): void
    {
        Setting::setEncrypted('ai_api_key', 'test-key');
        $held = $this->formTicket(['subject' => 'Synthetic held backfill ticket']);
        $this->mock(AiClient::class)->shouldReceive('completeJson')->once()->andReturn(['keywords' => ['printer', 'offline']]);

        $this->artisan('tickets:backfill-keywords', ['--dry-run' => true])
            ->expectsOutputToContain('No tickets to process.')
            ->assertSuccessful();

        // Positive control: the verified ticket is backfilled.
        $this->verify($held);
        $this->artisan('tickets:backfill-keywords', ['--dry-run' => true])
            ->expectsOutputToContain("T-{$held->id}: printer, offline")
            ->assertSuccessful();
    }

    /** context:5 — get_asset neither counts nor lists a held linked ticket; once verified it does. */
    public function test_asset_detail_does_not_serve_a_held_ticket(): void
    {
        $held = $this->formTicket(['subject' => 'Synthetic held asset ticket']);
        $asset = Asset::factory()->create(['client_id' => $held->client_id, 'hostname' => 'HELD-ASSET', 'name' => 'HELD-ASSET', 'is_active' => true]);
        $held->assets()->attach($asset->id, ['is_primary' => true]);
        $read = fn () => (new AssistantToolExecutor(clientId: $held->client_id))->execute('get_asset', ['asset_id' => $asset->id, 'expand' => ['tickets']]);

        $result = $read();
        $this->assertSame(0, $result['related']['tickets_count']);
        $this->assertSame([], $result['related']['recent_tickets']);
        $this->assertSame([], $result['expanded']['tickets']);

        // Positive control: once verified, the asset serves its ticket.
        $this->verify($held);
        $result = $read();
        $this->assertSame(1, $result['related']['tickets_count']);
        $this->assertSame($held->id, $result['related']['recent_tickets'][0]['id']);
        $this->assertSame($held->id, $result['expanded']['tickets'][0]['id']);
    }
}
