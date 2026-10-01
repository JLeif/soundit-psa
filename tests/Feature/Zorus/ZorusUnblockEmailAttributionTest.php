<?php

namespace Tests\Feature\Zorus;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Support\ZorusConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Card 6abe578e, Z-S2 — Zorus unblock-request intake fails closed on an ambiguous
 * hostname. Zorus is left UNCONFIGURED (no api_key, asserted in setUp), so strategy 1
 * (ZorusEmailParser::resolveClient) returns null BEFORE it constructs the Guzzle-based
 * ZorusClient, and strategy 2 — the hostname fallback across all Zorus-mapped
 * clients — runs. No vendor call is made. Synthetic names only.
 *
 * The within-client asset pin is observed on a persisted column: the asset link is
 * what resolves person_id through shared contracts, so "no asset link" reads as
 * person_id null while the single-asset control resolves the contract person. The
 * ticket the email auto-creates is pinned too: an ambiguous hostname attaches no asset
 * (neither the Zorus hostname link nor the intake matcher picks one).
 */
class ZorusUnblockEmailAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::where('key', 'like', 'zorus%')->delete();
        $this->assertFalse(ZorusConfig::isConfigured(), 'strategy 1 must not run: Zorus stays unconfigured');
    }

    /** A person reachable ONLY through a contract the given asset is attached to. */
    private function contractPerson(Client $client, Asset $asset): Person
    {
        $contract = Contract::create(['client_id' => $client->id, 'name' => 'Alpha MSA', 'type' => 'managed', 'start_date' => '2026-01-01']);
        $asset->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);
        $person = Person::create(['halo_id' => 9001, 'client_id' => $client->id, 'first_name' => 'Test', 'last_name' => 'Contact']);
        $person->contracts()->attach($contract->id, ['assignment_source' => 'manual', 'assigned_at' => now()]);

        return $person;
    }

    private function unblockEmail(string $company = 'Alpha Co', string $hostname = 'Test-MBP'): Email
    {
        return Email::create([
            'from_address' => 'no-reply@zorustech.com',
            'subject' => "{$company} Requests to Unblock Domain",
            'body_text' => "{$company} on {$hostname} requests to unblock https://example.test/x\n\nEnd user reason: No reason provided",
            'received_at' => now(),
        ]);
    }

    private function resolve(Email $email): Email
    {
        return app(EmailService::class)->resolveSender($email)->fresh();
    }

    private function ticketFor(Email $email): Ticket
    {
        Bus::fake(); // async triage on create is captured, not run
        User::factory()->create();

        return app(EmailService::class)->autoCreateTicketFromEmail($email)->fresh();
    }

    public function test_two_matching_assets_inside_one_client_attach_no_asset_to_the_created_ticket(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);
        Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'TEST-MBP']);

        $ticket = $this->ticketFor($this->resolve($this->unblockEmail()));

        $this->assertSame($alpha->id, $ticket->client_id, 'the client attribution stands');
        $this->assertSame(0, $ticket->assets()->count(), 'ambiguous hostname: no asset may be attached to the ticket');
    }

    public function test_one_matching_asset_inside_the_client_is_attached_to_the_created_ticket(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        $a1 = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);

        $ticket = $this->ticketFor($this->resolve($this->unblockEmail()));

        $this->assertSame([$a1->id], $ticket->assets()->pluck('assets.id')->all(), 'single match unchanged: the device is attached');
    }

    public function test_a_hostname_shared_by_two_zorus_clients_leaves_the_email_unattributed(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co', 'zorus_customer_id' => 'zc-bravo']);
        Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);
        Asset::factory()->create(['client_id' => $bravo->id, 'hostname' => 'test-mbp']);

        Log::spy();
        $email = $this->resolve($this->unblockEmail());

        $this->assertNull($email->client_id, 'two clients share the hostname: nothing may be attributed');
        $this->assertNull($email->person_id);
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return str_contains($message, 'more than one client')
                && ($context['matching_client_count'] ?? null) === 2
                && ! array_key_exists('hostname', $context)
                && ! array_key_exists('company_name', $context);
        })->once();
    }

    public function test_a_hostname_matching_one_zorus_client_is_attributed_unchanged(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        // An unmapped client's namesake device takes no part (strategy 2 is Zorus-mapped only).
        $unmapped = Client::factory()->create(['name' => 'Bravo Co', 'zorus_customer_id' => null]);
        Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);
        Asset::factory()->create(['client_id' => $unmapped->id, 'hostname' => 'Test-MBP']);

        $email = $this->resolve($this->unblockEmail());

        $this->assertSame($alpha->id, $email->client_id);
    }

    public function test_two_matching_assets_inside_one_client_keep_the_client_but_link_no_asset(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        // The lower-id row carries the contract, so a ->first() pick would resolve the person.
        $a1 = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);
        Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'TEST-MBP']);
        $this->contractPerson($alpha, $a1);

        $email = $this->resolve($this->unblockEmail());

        $this->assertSame($alpha->id, $email->client_id, 'the client attribution stands');
        $this->assertNull($email->person_id, 'two matching assets: no asset link, so no contract-derived person');
    }

    public function test_one_matching_asset_inside_the_client_still_links_and_resolves_the_contract_person(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co', 'zorus_customer_id' => 'zc-alpha']);
        $a1 = Asset::factory()->create(['client_id' => $alpha->id, 'hostname' => 'Test-MBP']);
        $person = $this->contractPerson($alpha, $a1);

        $email = $this->resolve($this->unblockEmail());

        $this->assertSame($alpha->id, $email->client_id);
        $this->assertSame($person->id, $email->person_id, 'single match unchanged: the asset links and its contract resolves the person');
    }
}
