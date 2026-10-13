<?php

namespace Tests\Feature\Prepay;

use App\Http\Requests\TicketStoreRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Services\ContractNotAllowedException;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ContractC5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_request_rejects_boolean_and_float_contract_ids_but_accepts_integer_and_digit_string(): void
    {
        $client = Client::create(['name' => 'Synthetic C5']);
        $contract = Contract::create(['id' => 1, 'client_id' => $client->id, 'name' => 'Synthetic C5 contract', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        $request = TicketStoreRequest::create('/', 'POST', ['client_id' => $client->id]);
        foreach ([true, 1.0, false, [], 'invalid'] as $value) {
            $this->assertTrue(Validator::make(['contract_id' => $value], ['contract_id' => $request->rules()['contract_id']])->fails(), get_debug_type($value));
        }
        foreach ([$contract->id, (string) $contract->id, null, ''] as $value) {
            $this->assertFalse(Validator::make(['contract_id' => $value], ['contract_id' => $request->rules()['contract_id']])->fails(), get_debug_type($value));
        }
    }

    public function test_outcome_documents_ticket_rule(): void
    {
        $doc = (new \ReflectionClass(\App\Services\TicketContractOutcome::class))->getDocComment();
        $this->assertStringContainsString('picked | ticket | client_default | only_active | none | ambiguous', $doc);
        $this->assertStringContainsString('ticket_contract_id', $doc);
    }

    public function test_boolean_refusal_preserves_boolean_identity(): void
    {
        $this->assertSame('true', ContractNotAllowedException::for(true)->renderedContractId);
        $this->assertSame('false', ContractNotAllowedException::for(false)->renderedContractId);
        $this->assertSame('1', ContractNotAllowedException::for(1)->renderedContractId);
    }

    public function test_no_client_refusal_does_not_assert_contract_ownership(): void
    {
        try {
            app(TicketService::class)->contractForNewTicket(null, 1);
            $this->fail('Expected contract refusal');
        } catch (ContractNotAllowedException $e) {
            $this->assertSame('contract_id 1 cannot be used for this ticket.', $e->getMessage());
            $this->assertSame('contract_id 1 cannot be used for this ticket. Use an active contract of this ticket\'s client (list_client_contracts lists them), or omit contract_id for automatic selection (which may leave the contract unset).', $e->toolRefusal()['error']);
        }
        $this->assertNull(app(TicketService::class)->contractForNewTicket(null, null)->contract);
    }
}
