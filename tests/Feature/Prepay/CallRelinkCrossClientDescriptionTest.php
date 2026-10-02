<?php

namespace Tests\Feature\Prepay;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #4924: a debited call relinked to another client's ticket keeps its ledger row on
 * the first client's contract (card I3EvQKUV), and that row must not take the other
 * client's ticket subject, which the first client's portal renders. Synthetic data only (G-13).
 */
class CallRelinkCrossClientDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const P_SUBJECT = 'Synthetic P printer jam';

    private const Q_SUBJECT = 'Synthetic Q payroll server breach';

    private Client $clientP;

    private Contract $contractP;

    private Ticket $ticketP;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Setting::setValue('portal_enabled', '1');

        $this->clientP = Client::create(['name' => 'Synthetic Client P']);
        $this->contractP = $this->contract($this->clientP);
        $this->ticketP = Ticket::factory()->create([
            'client_id' => $this->clientP->id, 'contract_id' => $this->contractP->id, 'subject' => self::P_SUBJECT,
        ]);
    }

    private function contract(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay '.$client->id,
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
    }

    /** A billable one-hour call linked to P's ticket and debited to P's contract. */
    private function debitedCallOnP(): PhoneCall
    {
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $this->ticketP->id);

        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contractP->id, $row->contract_id);
        $this->assertStringContainsString(self::P_SUBJECT, $row->description);

        return $call->fresh();
    }

    private function portalPerson(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Portal', 'last_name' => 'User',
            'email' => 'portal-'.uniqid().'@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true,
        ]);
    }

    private function assertLedgerUnmoved(PhoneCall $call, float $hours): PrepayTransaction
    {
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contractP->id, $row->contract_id);
        $this->assertEquals(-$hours, (float) $row->hours);
        $this->assertEquals(10 - $hours, (float) $this->contractP->fresh()->prepay_balance);
        $this->assertEquals($hours, (float) $this->contractP->fresh()->prepay_used);

        return $row;
    }

    private function assertNoQText(PrepayTransaction $row, Ticket $q): void
    {
        $this->assertStringNotContainsString(self::Q_SUBJECT, $row->description);
        $this->assertStringNotContainsString("Ticket #{$q->id}:", $row->description);
        $this->assertStringContainsString(self::P_SUBJECT, $row->description);
    }

    public function test_cross_client_relink_keeps_description_and_p_portal_does_not_show_q_subject(): void
    {
        $call = $this->debitedCallOnP();
        $before = PrepayTransaction::where('phone_call_id', $call->id)->value('description');

        $clientQ = Client::create(['name' => 'Synthetic Client Q']);
        $contractQ = $this->contract($clientQ);
        $ticketQ = Ticket::factory()->create([
            'client_id' => $clientQ->id, 'contract_id' => $contractQ->id, 'subject' => self::Q_SUBJECT,
        ]);
        app(PhoneCallService::class)->linkCallToTicket($call, $ticketQ->id);
        $this->assertSame($ticketQ->id, $call->fresh()->ticket_id);

        $row = $this->assertLedgerUnmoved($call, 1.0);
        $this->assertSame($before, $row->description);
        $this->assertNoQText($row, $ticketQ);
        $this->assertEquals(10, (float) $contractQ->fresh()->prepay_balance);
        $this->assertSame(0, PrepayTransaction::where('contract_id', $contractQ->id)->count());

        $page = $this->actingAs($this->portalPerson($this->clientP), 'portal')
            ->get(route('portal.contracts.show', $this->contractP));
        $page->assertOk();
        $page->assertSee('Prepaid Time Activity');
        $page->assertSee(self::P_SUBJECT);
        $page->assertDontSee(self::Q_SUBJECT);
        $page->assertDontSee("Ticket #{$ticketQ->id}:", false);
    }

    public function test_cross_client_relink_with_new_duration_moves_hours_but_keeps_description(): void
    {
        $call = $this->debitedCallOnP();
        $before = PrepayTransaction::where('phone_call_id', $call->id)->value('description');

        $clientQ = Client::create(['name' => 'Synthetic Client Q']);
        $ticketQ = Ticket::factory()->create(['client_id' => $clientQ->id, 'subject' => self::Q_SUBJECT]);
        app(PhoneCallService::class)->linkCallToTicket($call, $ticketQ->id);
        $call = $call->fresh();
        $call->duration = 5400;
        $call->saveQuietly();
        app(PrepayService::class)->debitFromPhoneCall($call);

        $row = $this->assertLedgerUnmoved($call, 1.5);
        $this->assertSame($before, $row->description);
        $this->assertNoQText($row, $ticketQ);
    }

    public function test_ticket_moved_to_another_client_keeps_description_on_redebit(): void
    {
        $call = $this->debitedCallOnP();
        $before = PrepayTransaction::where('phone_call_id', $call->id)->value('description');

        $clientQ = Client::create(['name' => 'Synthetic Client Q']);
        $this->ticketP->update(['client_id' => $clientQ->id, 'contract_id' => null, 'subject' => self::Q_SUBJECT]);
        app(PrepayService::class)->debitFromPhoneCall($call->fresh());

        $row = $this->assertLedgerUnmoved($call, 1.0);
        $this->assertSame($before, $row->description);
        $this->assertStringNotContainsString(self::Q_SUBJECT, $row->description);
    }

    public function test_soft_deleted_ledger_contract_guards_by_its_own_client(): void
    {
        $call = $this->debitedCallOnP();
        $before = PrepayTransaction::where('phone_call_id', $call->id)->value('description');
        $this->contractP->delete();

        $same = Ticket::factory()->create(['client_id' => $this->clientP->id, 'subject' => 'Synthetic P third ticket']);
        $call->update(['ticket_id' => $same->id]);
        app(PrepayService::class)->debitFromPhoneCall($call->fresh());
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contractP->id, $row->contract_id);
        $this->assertSame("Phone call on Ticket #{$same->id}: Synthetic P third ticket", $row->description);

        $clientQ = Client::create(['name' => 'Synthetic Client Q']);
        $ticketQ = Ticket::factory()->create(['client_id' => $clientQ->id, 'subject' => self::Q_SUBJECT]);
        $call->update(['ticket_id' => $ticketQ->id]);
        app(PrepayService::class)->debitFromPhoneCall($call->fresh());
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contractP->id, $row->contract_id);
        $this->assertEquals(-1.0, (float) $row->hours);
        $this->assertStringNotContainsString(self::Q_SUBJECT, $row->description);
        $this->assertNotSame($before, $row->description);
    }

    public function test_same_client_relink_still_updates_description(): void
    {
        $call = $this->debitedCallOnP();
        $other = Ticket::factory()->create([
            'client_id' => $this->clientP->id, 'contract_id' => $this->contractP->id, 'subject' => 'Synthetic P second ticket',
        ]);
        app(PhoneCallService::class)->linkCallToTicket($call, $other->id);

        $row = $this->assertLedgerUnmoved($call, 1.0);
        $this->assertSame("Phone call on Ticket #{$other->id}: Synthetic P second ticket", $row->description);

        $page = $this->actingAs($this->portalPerson($this->clientP), 'portal')
            ->get(route('portal.contracts.show', $this->contractP));
        $page->assertOk();
        $page->assertSee('Synthetic P second ticket');
    }
}
