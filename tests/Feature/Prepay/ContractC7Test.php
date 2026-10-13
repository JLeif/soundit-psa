<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC7Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_array_contract_is_rejected_and_validation_redirect_renders(): void
    {
        $client = Client::create(['name' => 'Synthetic C7 client']);
        $data = ['client_id' => $client->id, 'subject' => 'Synthetic C7', 'priority' => 'p3', 'type' => 'incident', 'contract_id' => [123]];
        $this->postJson(route('tickets.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        $this->from(route('tickets.create'))->post(route('tickets.store'), $data)
            ->assertRedirect(route('tickets.create'))->assertSessionHasErrors('contract_id');
        $this->get(route('tickets.create'))->assertOk()->assertSee('data-old=""', false);
        $this->assertSame(0, Ticket::count());
    }

    public function test_rendered_source_guards_stale_contract_failure(): void
    {
        $html = $this->get(route('tickets.create'))->assertOk()->getContent();
        $this->assertStringContainsString(".catch(function() {\n            if (clientSelect.value !== String(clientId)) {\n                return;\n            }\n            // Leave it blank", $html);
    }

    public function test_rendered_source_reports_cleared_preference_on_empty_missing_and_failed_reads(): void
    {
        $html = $this->withSession(['_old_input' => ['contract_id' => '123']])
            ->get(route('tickets.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-old="123"', $html);
        $this->assertStringContainsString("contractHint.textContent = (preferId ? clearedContractNotice : '') + contractHints.none;", $html);
        $this->assertStringContainsString("if (preferId && !preferred) {\n                contractHint.textContent = (fallback ? replacedContractNotice(fallback) : clearedContractNotice) + contractHint.textContent;\n            }", $html);
        $this->assertStringContainsString("const replacedContractNotice = c => \"The previously submitted contract is not one of this client's active contracts, so the client's \"\n        + (c.is_default ? 'default' : 'only active') + ' contract is selected instead. Review the contract selection before submitting. ';", $html);
        $this->assertStringContainsString("contractHint.textContent = (preferId ? clearedContractNotice : '') + contractHints.failed;", $html);
        $this->assertStringContainsString("const clearedContractNotice = 'The previously submitted contract was cleared. Review the contract selection before submitting. ';", $html);
    }
}
