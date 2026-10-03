<?php

namespace Tests\Feature\Prepay;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ContactIntake\StaffWorkflow;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use App\Services\ContractNotAllowedException;
use App\Services\PhoneCallService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 3 (spec §7, §9 C1-C3, C7, U1): the contract a new ticket
 * takes, enforced once in TicketService::createTicket, and the web form's
 * picker and endpoint. Synthetic data only (G-13).
 */
class ContractAtCreationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        $this->client = Client::create(['name' => 'Synthetic Client C']);
    }

    private function contract(string $name, array $o = [], ?Client $client = null): Contract
    {
        return Contract::create(array_merge([
            'client_id' => ($client ?? $this->client)->id, 'name' => $name, 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ], $o));
    }

    private function setDefault(Contract $contract): void
    {
        Client::whereKey($contract->client_id)->update(['default_contract_id' => $contract->id]);
    }

    private function data(array $o = []): array
    {
        return array_merge([
            'client_id' => $this->client->id, 'subject' => 'Synthetic subject',
            'description' => 'Synthetic body', 'priority' => 'p3', 'type' => 'incident',
        ], $o);
    }

    private function create(array $o = []): Ticket
    {
        return app(TicketService::class)->createTicket($this->data($o), null);
    }

    private function sla(int $resolution, int $response): array
    {
        return ['sla_terms' => ['resolution' => ['p3' => $resolution], 'response' => ['p3' => $response]]];
    }

    // ── C1 / C2: the one rule in createTicket ───────────────────────────────

    public function test_c1_create_ticket_refuses_another_clients_contract_and_writes_nothing(): void
    {
        $other = Client::create(['name' => 'Synthetic Other']);
        $foreign = $this->contract('Synthetic Foreign', [], $other);

        try {
            $this->create(['contract_id' => $foreign->id]);
            $this->fail('A foreign contract must be refused.');
        } catch (ContractNotAllowedException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame("contract_id {$foreign->id} is not an active contract of this client", $e->getMessage());
        }
        $this->assertSame(0, Ticket::withTrashed()->count());
    }

    public function test_c2_create_ticket_refuses_an_inactive_contract_of_the_same_client(): void
    {
        foreach (['expired', 'cancelled'] as $status) {
            $inactive = $this->contract("Synthetic {$status}", ['status' => $status]);
            try {
                $this->create(['contract_id' => $inactive->id]);
                $this->fail("A {$status} contract must be refused.");
            } catch (ContractNotAllowedException $e) {
                $this->assertSame("contract_id {$inactive->id} is not an active contract of this client", $e->getMessage());
            }
        }
        $deleted = $this->contract('Synthetic deleted');
        $deleted->delete();
        $this->expectException(ContractNotAllowedException::class);
        try {
            $this->create(['contract_id' => $deleted->id]);
        } finally {
            $this->assertSame(0, Ticket::withTrashed()->count());
        }
    }

    public function test_c1_malformed_or_unknown_contract_ids_are_refused(): void
    {
        foreach ([999999, '12abc', -3, 0, [1], 1.5] as $bad) {
            try {
                $this->create(['contract_id' => $bad]);
                $this->fail('Refusal expected for '.json_encode($bad));
            } catch (ContractNotAllowedException) {
                // refused
            }
        }
        $this->assertSame(0, Ticket::withTrashed()->count());
    }

    // ── C3: the rule reported when contract_id is omitted ───────────────────

    public function test_c3_each_rule_is_applied_and_reported(): void
    {
        $svc = app(TicketService::class);

        $none = $this->create();
        $this->assertNull($none->contract_id);
        $this->assertSame('none', TicketService::contractOutcomeOf($none)->rule);

        $only = $this->contract('Synthetic Only');
        $t = $this->create();
        $this->assertSame($only->id, $t->contract_id);
        $this->assertSame('only_active', TicketService::contractOutcomeOf($t)->rule);

        $second = $this->contract('Synthetic Second');
        $amb = $this->create();
        $this->assertNull($amb->contract_id);
        $outcome = TicketService::contractOutcomeOf($amb);
        $this->assertSame('ambiguous', $outcome->rule);
        $this->assertSame([$only->id, $second->id], array_column($outcome->toArray()['candidate_contracts'], 'id'));

        $this->setDefault($second);
        $def = $this->create();
        $this->assertSame($second->id, $def->contract_id);
        $this->assertSame('client_default', TicketService::contractOutcomeOf($def)->rule);
        $this->assertSame(
            ['contract' => ['id' => $second->id, 'name' => 'Synthetic Second', 'rule' => 'client_default'], 'contract_rule' => 'client_default'],
            TicketService::contractOutcomeOf($def)->toArray(),
        );

        $picked = $this->create(['contract_id' => (string) $only->id]);
        $this->assertSame($only->id, $picked->contract_id);
        $this->assertSame('picked', TicketService::contractOutcomeOf($picked)->rule);
        $this->assertSame('picked', $svc->contractForNewTicket($this->client->id, $only->id)->rule);
    }

    public function test_c3_a_stale_default_is_not_used(): void
    {
        $old = $this->contract('Synthetic Old', ['status' => 'expired']);
        $this->setDefault($old);
        $only = $this->contract('Synthetic Live');

        $t = $this->create();
        $this->assertSame($only->id, $t->contract_id);
        $this->assertSame('only_active', TicketService::contractOutcomeOf($t)->rule);
    }

    // ── C7: intake tickets take the default and its SLA (Q2) ────────────────

    public function test_c7_an_intake_ticket_takes_the_default_contract_and_its_sla_due_dates(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->contract('Synthetic Other Active');
        $default = $this->contract('Synthetic SLA Default', $this->sla(8, 2));
        $this->setDefault($default);

        $email = \App\Models\Email::create([
            'direction' => \App\Enums\EmailDirection::Inbound, 'from_address' => 'someone@example.test',
            'subject' => 'Synthetic intake', 'body_text' => 'Synthetic', 'received_at' => now(),
            'client_id' => $this->client->id,
        ]);
        $ticket = app(\App\Services\EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame($default->id, $ticket->contract_id);
        $this->assertSame('client_default', TicketService::contractOutcomeOf($ticket)->rule);
        $this->assertTrue($ticket->due_at->equalTo(now()->addHours(8)), (string) $ticket->due_at);
        $this->assertTrue($ticket->response_due_at->equalTo(now()->addHours(2)));
    }

    public function test_c7_an_ambiguous_intake_ticket_has_no_contract_and_no_sla_due_date(): void
    {
        $this->contract('Synthetic A', $this->sla(8, 2));
        $this->contract('Synthetic B', $this->sla(4, 1));

        $call = PhoneCall::create([
            'call_uuid' => 'synthetic-c7', 'direction' => CallDirection::Inbound,
            'from_number' => '+15555550142', 'status' => CallStatus::Completed, 'started_at' => now(),
        ]);
        $call->client_id = $this->client->id;
        $call->save();
        $ticket = app(PhoneCallService::class)->createTicketFromCall($call);

        $this->assertNull($ticket->contract_id);
        $this->assertNull($ticket->due_at);
        $this->assertNull($call->fresh()->contract_id);
    }

    // ── Contact intake: no contract until verified ──────────────────────────

    public function test_unverified_contact_intake_ticket_gets_no_contract_until_staff_verify(): void
    {
        $this->travelTo(now()->startOfSecond());
        $default = $this->contract('Synthetic SLA Default', $this->sla(8, 2));
        $this->setDefault($default);
        $staff = User::factory()->admin()->create(['is_active' => true]);
        Setting::setValue('contact_intake_enabled', '1');
        app(SubmissionLedger::class)->accept('website', ['submission_id' => (string) Str::uuid(),
            'name' => 'Synthetic', 'email' => 'visitor@example.test', 'message' => 'Synthetic message',
            'inquiry' => 'unknown-slug', 'submitted_at' => '2026-09-24T12:00:00Z']);
        $row = ContactSubmission::latest('id')->firstOrFail();
        $workflow = app(StaffWorkflow::class);
        $workflow->act($row->id, $staff, 'quarantine', 'Synthetic hold');
        $workflow->act($row->id, $staff, 'resolve_client', 'Synthetic selection', $this->client->id);
        $row = app(SubmissionProcessor::class)->process($row->id);

        $ticket = Ticket::findOrFail($row->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertNull($ticket->contract_id);
        $this->assertNull($ticket->due_at);

        $workflow->act($row->id, $staff, 'verify', 'Synthetic verification');
        $ticket->refresh();
        $this->assertFalse($ticket->isUnverifiedContactIntake());
        $this->assertSame($default->id, $ticket->contract_id);
        $this->assertTrue($ticket->due_at->equalTo(now()->addHours(8)));
        $this->assertTrue($ticket->response_due_at->equalTo(now()->addHours(2)));
    }

    // ── Web form (C1 web, U1) ───────────────────────────────────────────────

    private function staff(): User
    {
        return User::factory()->create();
    }

    public function test_c1_web_form_refuses_another_clients_contract_with_no_ticket_and_no_audit(): void
    {
        $other = Client::create(['name' => 'Synthetic Other']);
        $foreign = $this->contract('Synthetic Foreign', [], $other);
        $this->contract('Synthetic Own');

        $this->actingAs($this->staff())->from(route('tickets.create'))
            ->post(route('tickets.store'), $this->data(['contract_id' => $foreign->id]))
            ->assertRedirect(route('tickets.create'))
            ->assertSessionHasErrors(['contract_id' => 'The selected contract is not an active contract of this client.']);

        $this->assertSame(0, Ticket::withTrashed()->count());
        $this->assertSame(0, \App\Models\TechnicianActionLog::count());
    }

    public function test_web_form_refuses_an_inactive_contract_and_accepts_an_active_one(): void
    {
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        $live = $this->contract('Synthetic Live');
        $this->contract('Synthetic Live Two');
        $user = $this->staff();

        $this->actingAs($user)->post(route('tickets.store'), $this->data(['contract_id' => $expired->id]))
            ->assertSessionHasErrors('contract_id');
        $this->actingAs($user)->post(route('tickets.store'), $this->data(['contract_id' => [$live->id]]))
            ->assertSessionHasErrors('contract_id');
        $this->assertSame(0, Ticket::count());

        $this->actingAs($user)->post(route('tickets.store'), $this->data(['contract_id' => $live->id]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($live->id, Ticket::sole()->contract_id);
    }

    public function test_web_request_closure_refuses_foreign_inactive_and_malformed_contracts_by_itself(): void
    {
        // The FormRequest rule on its own (the controller's catch is a second line).
        $other = Client::create(['name' => 'Synthetic Other']);
        $foreign = $this->contract('Synthetic Foreign', [], $other);
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        $live = $this->contract('Synthetic Live');

        $fails = function (mixed $contractId): bool {
            $data = $this->data(['contract_id' => $contractId]);
            $request = \App\Http\Requests\TicketStoreRequest::create('/tickets', 'POST', $data);

            return \Illuminate\Support\Facades\Validator::make($data, $request->rules())->errors()->has('contract_id');
        };

        $this->assertTrue($fails($foreign->id));
        $this->assertTrue($fails($expired->id));
        $this->assertTrue($fails('7abc'));
        $this->assertFalse($fails($live->id));
        $this->assertFalse($fails((string) $live->id));
        $this->assertFalse($fails(null));
    }

    public function test_web_form_blank_contract_takes_the_client_rules(): void
    {
        $default = $this->contract('Synthetic Default');
        $this->contract('Synthetic Other');
        $this->setDefault($default);

        $this->actingAs($this->staff())->post(route('tickets.store'), $this->data(['contract_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame($default->id, Ticket::sole()->contract_id);
    }

    public function test_web_store_refuses_a_contract_that_stops_being_active_after_validation(): void
    {
        $live = $this->contract('Synthetic Live');
        // The race: the request rule passes (the framework validates in its own
        // afterResolving hook, registered first), then the contract expires before the write.
        $this->app->afterResolving(\App\Http\Requests\TicketStoreRequest::class, function () use ($live) {
            Contract::whereKey($live->id)->update(['status' => 'expired']);
        });

        $this->actingAs($this->staff())->post(route('tickets.store'), $this->data(['contract_id' => $live->id]))
            ->assertSessionHasErrors('contract_id');
        $this->assertSame(0, Ticket::count());
    }

    public function test_u1_active_contracts_endpoint_lists_only_this_clients_active_contracts(): void
    {
        $other = Client::create(['name' => 'Synthetic Other']);
        $this->contract('Synthetic Foreign', [], $other);
        $this->contract('Synthetic Expired', ['status' => 'expired']);
        $this->contract('Synthetic Cancelled', ['status' => 'cancelled']);
        $this->contract('Synthetic Deleted')->delete();
        $block = $this->contract('Synthetic Block', ['prepay_balance' => 12.5]);
        $managed = $this->contract('Synthetic Managed', ['prepay_balance' => null, 'prepay_total' => null]);
        $this->setDefault($block);

        $rows = $this->actingAs($this->staff())
            ->getJson(route('api.clients.active-contracts', $this->client))
            ->assertOk()->json();

        $this->assertSame([$block->id, $managed->id], array_column($rows, 'id'));
        $this->assertSame([true, false], array_column($rows, 'is_default'));
        $this->assertSame('managed', $rows[0]['type']);
        $this->assertEquals(12.5, $rows[0]['prepay_balance']);
        $this->assertSame('hours', $rows[0]['prepay_unit']);
        $this->assertNull($rows[1]['prepay_balance']);
        $this->assertSame(['id', 'name', 'type', 'type_label', 'prepay_balance', 'prepay_unit', 'is_default'], array_keys($rows[0]));
    }

    public function test_u1_endpoint_marks_no_default_when_the_default_is_stale(): void
    {
        $old = $this->contract('Synthetic Old', ['status' => 'expired']);
        $this->setDefault($old);
        $this->contract('Synthetic A');
        $this->contract('Synthetic B');

        $rows = $this->actingAs($this->staff())
            ->getJson(route('api.clients.active-contracts', $this->client))->assertOk()->json();
        $this->assertSame([false, false], array_column($rows, 'is_default'));
    }

    /**
     * r2 (review r1 context:8, contract-s1:4): the hint claims "goes to this contract" only
     * in the state that shows one; the zero-contract and load-failure states say what is
     * true there, and the several-no-default copy says time is held as "Needs contract".
     */
    public function test_r2_create_form_contract_copy_fits_each_picker_state(): void
    {
        $html = $this->actingAs($this->staff())->get(route('tickets.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<div class="form-text" id="contractHint">Only the chosen client\'s active contracts are listed\.</div>#', $html);
        $this->assertSame(1, substr_count($html, 'goes to this contract'), 'only the contract-shown state says it');
        $this->assertStringContainsString("chosen: \"Only this client's active contracts are listed. New time on this ticket goes to this contract unless a time entry picks another.\"", $html);
        $this->assertStringContainsString("none: 'This client has no active contracts, so the ticket is created without one.'", $html);
        $this->assertStringContainsString('contractHint.textContent = contractHints.none;', $html);
        $this->assertStringContainsString("failed: \"Contracts could not be loaded. Leave it blank and the server uses the client's default or only active contract; with several and no default, the ticket gets none.\"", $html);
        $this->assertStringContainsString('contractHint.textContent = contractHints.failed;', $html);
        $this->assertStringContainsString('active contracts. Pick one now; time logged on this ticket without a contract is held as “Needs contract” until one is chosen.', $html);
        $this->assertStringNotContainsString('each time entry will ask', $html);
        $this->assertStringNotContainsString('choose when time is logged', $html);
    }

    public function test_u1_create_form_renders_the_contract_picker_disabled_until_a_client_is_chosen(): void
    {
        $html = $this->actingAs($this->staff())->get(route('tickets.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select name="contract_id" id="contract_id"[^>]*\bdisabled>/s', $html);
        $this->assertStringContainsString('Choose a client first', $html);
        $this->assertStringContainsString("'/active-contracts'", $html);
        $this->assertStringContainsString('No contract — time logged without one is held as “Needs contract”', $html);
        $this->assertStringContainsString("' · default'", $html);
    }
}
