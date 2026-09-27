<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Jobs\ResolveCallerFromPeople;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card 0JJon0z4: deterministic call auto-link and the "resolved client, no
 * ticket" calls-list view.
 *
 * Every auto-link case runs through ResolveCallerFromPeople, the job that
 * first gives a call both client_id and person_id; the answered/unanswered
 * cases also run through PhoneCallService's answer and hangup handlers. The
 * switch is turned on by its literal setting key. Neither depends on a class
 * this change adds.
 */
class CallAutoLinkTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '+15555550142';

    private Client $client;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        // linkCallToTicketWithNote() authors its note as TriageConfig::systemUserId().
        // With triage_system_user_id unset that is the lowest-id user; with no
        // user at all it is null and the note is skipped.
        User::factory()->create();

        $this->client = Client::factory()->create();
        $this->person = Person::create([
            'client_id' => $this->client->id,
            'first_name' => 'Ada',
            'last_name' => 'Caller',
            'phone' => self::NUMBER,
            'is_active' => true,
        ]);
    }

    private function enable(): void
    {
        Setting::setValue('call_autolink_enabled', '1');
    }

    private function newCall(CallDirection $direction = CallDirection::Inbound, array $attrs = []): PhoneCall
    {
        return PhoneCall::create(array_merge([
            'call_uuid' => uniqid('autolink_', true),
            'direction' => $direction,
            'from_number' => self::NUMBER,
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ], $attrs));
    }

    private function ticket(TicketStatus $status, array $attrs = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'contact_id' => $this->person->id,
            'status' => $status->value,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ], $attrs));
    }

    private function resolve(PhoneCall $call): PhoneCall
    {
        (new ResolveCallerFromPeople($call->id))->handle(app(PhoneCallService::class));

        return $call->fresh();
    }

    private function autoLinkNotes(Ticket $ticket)
    {
        return TicketNote::where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::PhoneCall)
            ->where('body', 'like', '%auto-linked%')
            ->get();
    }

    // ── Auto-link: the one case that links ──

    public function test_single_open_ticket_links_an_inbound_call_and_writes_the_note(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::InProgress);
        $call = $this->newCall();

        $call = $this->resolve($call);

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertSame($open->id, $call->ticket_id);
        $notes = $this->autoLinkNotes($open);
        $this->assertCount(1, $notes);
        $this->assertTrue((bool) $notes->first()->is_private);
        $this->assertStringContainsString('#'.$call->id, $notes->first()->body);
        $this->assertStringContainsString('only open ticket', $notes->first()->body);
    }

    public function test_single_open_ticket_links_an_outbound_call(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::PendingClient);
        $call = $this->newCall(CallDirection::Outbound);

        $this->assertSame($open->id, $this->resolve($call)->ticket_id);
        $this->assertCount(1, $this->autoLinkNotes($open));
    }

    public function test_open_tickets_of_another_contact_or_client_are_not_counted(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::New);
        $colleague = Person::create([
            'client_id' => $this->client->id, 'first_name' => 'Bo', 'last_name' => 'Other', 'is_active' => true,
        ]);
        $this->ticket(TicketStatus::New, ['contact_id' => $colleague->id]);
        $this->ticket(TicketStatus::New, ['client_id' => Client::factory()->create()->id]);

        $this->assertSame($open->id, $this->resolve($this->newCall())->ticket_id);
    }

    public function test_a_resolved_ticket_beside_the_open_one_does_not_make_it_ambiguous(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::PendingThirdParty);
        $this->ticket(TicketStatus::Resolved);

        $this->assertSame($open->id, $this->resolve($this->newCall())->ticket_id);
    }

    // ── Auto-link: every case that must leave the call unlinked ──

    public function test_no_open_ticket_leaves_the_call_unlinked(): void
    {
        $this->enable();
        // Open tickets exist, but none is this contact's at this client: one is a
        // colleague's, one names this contact under another client.
        $colleague = Person::create([
            'client_id' => $this->client->id, 'first_name' => 'Bo', 'last_name' => 'Other', 'is_active' => true,
        ]);
        $this->ticket(TicketStatus::New, ['contact_id' => $colleague->id]);
        $this->ticket(TicketStatus::New, ['client_id' => Client::factory()->create()->id]);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
    }

    public function test_two_open_tickets_leave_the_call_unlinked(): void
    {
        $this->enable();
        $first = $this->ticket(TicketStatus::New);
        $second = $this->ticket(TicketStatus::InProgress);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($first));
        $this->assertCount(0, $this->autoLinkNotes($second));
    }

    public function test_a_resolved_ticket_with_null_closed_at_leaves_the_call_unlinked(): void
    {
        $this->enable();
        $resolved = $this->ticket(TicketStatus::Resolved, ['closed_at' => null]);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($resolved));
    }

    public function test_a_closed_ticket_leaves_the_call_unlinked(): void
    {
        $this->enable();
        $closed = $this->ticket(TicketStatus::Closed);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($closed));
    }

    public function test_a_followed_up_call_stays_unlinked(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::InProgress);
        $call = $this->newCall();
        // followed_up_at is not fillable; set it the way markFollowedUp() does.
        $call->followed_up_at = now();
        $call->save();

        $call = $this->resolve($call);

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($open));
    }

    public function test_setting_explicitly_off_leaves_the_call_unlinked(): void
    {
        Setting::setValue('call_autolink_enabled', '0');
        $open = $this->ticket(TicketStatus::InProgress);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($open));
    }

    public function test_absent_setting_row_leaves_the_call_unlinked(): void
    {
        $this->assertFalse(Setting::where('key', 'call_autolink_enabled')->exists());
        $open = $this->ticket(TicketStatus::InProgress);

        $call = $this->resolve($this->newCall());

        $this->assertSame($this->person->id, $call->person_id);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($open));
    }

    public function test_intake_settings_do_not_turn_the_auto_link_on(): void
    {
        Setting::setValue('intake_call_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.80');
        $this->ticket(TicketStatus::InProgress);

        $this->assertNull($this->resolve($this->newCall())->ticket_id);
    }

    // ── Auto-link: only an answered call is linked ──

    public function test_a_ringing_call_is_linked_only_once_it_ends_answered(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::InProgress);
        $call = $this->newCall(attrs: ['status' => CallStatus::Ringing]);

        $this->assertNull($this->resolve($call)->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($open));

        $service = app(PhoneCallService::class);
        $service->handleCallAnswered($call->call_uuid, []);
        $service->handleCallEnded($call->call_uuid, ['Duration' => '30']);
        // A redelivered hangup must not link or note a second time.
        $service->handleCallEnded($call->call_uuid, ['Duration' => '30']);

        $call = $call->fresh();
        $this->assertSame(CallStatus::Completed, $call->status);
        $this->assertSame($open->id, $call->ticket_id);
        $this->assertCount(1, $this->autoLinkNotes($open));
    }

    public function test_an_unanswered_call_is_linked_neither_at_ring_nor_at_end(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::InProgress);

        foreach ([CallDirection::Inbound, CallDirection::Outbound] as $direction) {
            $call = $this->newCall($direction, ['status' => CallStatus::Ringing]);

            $this->assertNull($this->resolve($call)->ticket_id);

            app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '40']);

            $call = $call->fresh();
            $this->assertSame(CallStatus::Missed, $call->status);
            $this->assertNull($call->ticket_id);
        }

        $this->assertCount(0, $this->autoLinkNotes($open));
    }

    public function test_a_voicemail_call_stays_unlinked_at_resolve_and_at_end(): void
    {
        $this->enable();
        $open = $this->ticket(TicketStatus::InProgress);
        $call = $this->newCall(attrs: ['status' => CallStatus::Voicemail]);

        $this->assertNull($this->resolve($call)->ticket_id);

        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '40']);

        $call = $call->fresh();
        $this->assertSame(CallStatus::Voicemail, $call->status);
        $this->assertNull($call->ticket_id);
        $this->assertCount(0, $this->autoLinkNotes($open));
    }

    // ── The calls-list view ──

    private function rowLink(PhoneCall $call): string
    {
        return '<a href="'.route('calls.show', $call).'" class="btn btn-sm btn-outline-secondary"';
    }

    public function test_resolved_no_ticket_view_lists_only_resolved_unlinked_calls(): void
    {
        $wanted = $this->newCall();
        $wanted->client_id = $this->client->id;
        $wanted->person_id = $this->person->id;
        $wanted->save();

        $linked = $this->newCall();
        $linked->client_id = $this->client->id;
        $linked->person_id = $this->person->id;
        $linked->ticket_id = $this->ticket(TicketStatus::New)->id;
        $linked->save();

        $clientOnly = $this->newCall();
        $clientOnly->client_id = $this->client->id;
        $clientOnly->save();

        $unresolved = $this->newCall();

        $response = $this->actingAs(User::first())->get('/calls?status=resolved-no-ticket');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString($this->rowLink($wanted), $html);
        $this->assertStringNotContainsString($this->rowLink($linked), $html);
        $this->assertStringNotContainsString($this->rowLink($clientOnly), $html);
        $this->assertStringNotContainsString($this->rowLink($unresolved), $html);
        $this->assertStringContainsString('value="resolved-no-ticket" selected', $html);
    }

    public function test_resolved_no_ticket_view_needs_no_setting_and_writes_nothing(): void
    {
        $call = $this->newCall();
        $call->client_id = $this->client->id;
        $call->person_id = $this->person->id;
        $call->save();
        $this->ticket(TicketStatus::InProgress);

        $response = $this->actingAs(User::first())->get('/calls?status=resolved-no-ticket');

        $response->assertOk();
        $this->assertStringContainsString($this->rowLink($call), $response->getContent());
        $this->assertNull($call->fresh()->ticket_id);
    }
}
