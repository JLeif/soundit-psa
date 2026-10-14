<?php

namespace Tests\Feature\Prepay;

use App\Models\ContactSubmission;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContactIntake\StaffWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC16ViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_held_badges_name_verification_until_ticket_is_verified(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $user = User::factory()->admin()->create(['is_active' => true]);
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        for ($i = 0; $i < 2; $i++) {
            $contract = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C16 view '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
        }
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'author_id' => $user->id, 'note_type' => 'note',
            'body' => 'Synthetic held time', 'is_private' => true, 'is_billable' => true,
            'time_minutes' => 60, 'noted_at' => now(), 'contract_held_at' => now(), 'contract_id' => $contract->id,
        ]));
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'ticket_id' => $ticket->id, 'call_uuid' => 'synthetic-c16-view',
            'direction' => 'inbound', 'from_number' => '+15555550142', 'status' => 'completed',
            'is_billable' => true, 'duration' => 3600, 'started_at' => now(), 'contract_held_at' => now(),
        ]));
        $formNote = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic form', 'note_type' => 'note',
            'contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'noted_at' => now(),
        ]);
        $row = ContactSubmission::forceCreate([
            'integration_id' => 'website', 'submission_id' => 'synthetic-c16-view',
            'payload_hash' => hash('sha256', 'synthetic-c16-view'), 'identity_hash' => hash('sha256', 'synthetic-c16-view'),
            'receipt' => 'synthetic-c16-view', 'payload' => [], 'state' => 'processed',
            'ticket_id' => $ticket->id, 'ticket_note_id' => $formNote->id,
        ]);
        $this->assertHeldBadges($ticket, $user, ['Needs contract', 'Awaiting verification']);

        $single = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $single->client->forceFill(['default_contract_id' => null])->saveQuietly();
        Contract::create([
            'client_id' => $single->client_id, 'name' => 'Synthetic single C16',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $singleNote = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $single->id, 'author_id' => $user->id, 'note_type' => 'note',
            'body' => 'Synthetic single held time', 'is_private' => true, 'is_billable' => true,
            'time_minutes' => 60, 'noted_at' => now(), 'contract_held_at' => now(),
        ]));
        $this->assertNull($singleNote->contract_id);
        $this->assertSame(1, Contract::where('client_id', $single->client_id)->active()->count());
        $this->assertHeldBadges($single, $user, ['Awaiting verification']);
        app(StaffWorkflow::class)->act($row->id, $user, 'verify', 'Synthetic verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertNull($note->fresh()->contract_held_at);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertHeldBadges($ticket->fresh(), $user, ['Needs contract']);
    }

    private function assertHeldBadges(Ticket $ticket, User $user, array $expected): void
    {
        $html = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $badges = (new \DOMXPath($dom))->query('//span[contains(@class, "badge") and starts-with(@title, "Not debited yet:")]');
        $this->assertCount(count($expected), $badges);
        $actual = [];
        foreach ($badges as $badge) {
            $actual[] = trim($badge->textContent);
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }
}
