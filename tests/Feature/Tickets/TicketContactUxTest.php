<?php

namespace Tests\Feature\Tickets;

use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketContactUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_selected_contact_and_client_details_are_persistent_selectable_text(): void
    {
        $client = Client::factory()->create(['phone' => '+12025550101', 'phone_display' => null, 'email' => 'office@example.test']);
        $person = Person::create([
            'client_id' => $client->id,
            'first_name' => 'Fixture', 'last_name' => 'Contact',
            'phone' => '+12025550102', 'phone_display' => null,
            'mobile' => '+12025550103', 'mobile_display' => null, 'email' => 'person@example.test',
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);
        $response = $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['client' => [$client->phone, $client->email], 'person' => [$person->phone_display, $person->mobile_display, $person->email]] as $kind => $values) {
            $nodes = $xpath->query('//*[@data-ticket-contact="'.$kind.'"]');
            $this->assertCount(1, $nodes);
            foreach ($values as $value) {
                $this->assertStringContainsString($value, $nodes->item(0)->textContent);
            }
            $this->assertCount(count($values), $xpath->query('.//span[@class="user-select-text"]', $nodes->item(0)));
            $this->assertCount(0, $xpath->query('.//*[@data-bs-toggle or @hidden]', $nodes->item(0)));
        }
        $response->assertSee('href="'.route('people.show', $person).'"', false);
        // Positive control: existing change-contact form remains available.
        $this->assertCount(1, $xpath->query('//select[@name="contact_id"]/option[@selected and @value="'.$person->id.'"]'));
        $response->assertSee('action="'.route('tickets.move', $ticket).'"', false);
    }

    public function test_contact_fields_are_escaped_and_empty_fields_are_explicit(): void
    {
        $person = Person::create(['first_name' => '<script>bad()</script>', 'last_name' => 'Fixture', 'email' => '<img src=x onerror=bad()>', 'phone' => null, 'mobile' => null]);
        $html = view('tickets._contact-details', ['entity' => $person, 'person' => true])->render();
        $this->assertStringContainsString(e($person->email), $html);
        $this->assertStringContainsString(e($person->full_name), $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $person->email = null;
        $html = view('tickets._contact-details', ['entity' => $person, 'person' => true])->render();
        $this->assertStringContainsString('No phone or email recorded.', $html);
        $this->assertStringNotContainsString('user-select-text', $html);
        $this->assertStringNotContainsString('data-ticket-contact', view('tickets._contact-details', ['entity' => null, 'person' => true])->render());
    }

    public function test_unlinked_ticket_renders_without_contact_details_and_notes_remain_keyboard_reachable(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => null, 'contact_id' => null]);
        $response = $this->actingAs(User::factory()->create())->get(route('tickets.show', $ticket))->assertOk();
        $response->assertDontSee('data-ticket-contact=', false);
        $response->assertSee('ticket-notes-scroll" tabindex="0" role="region" aria-labelledby="ticketNotesHeading"', false);
        $response->assertSee('id="ticketNotesHeading"', false);
        $response->assertSee('id="toggleSystemNotes"', false);
    }
}
