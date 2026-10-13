<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
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
        $this->assertStringContainsString("if (preferId && !preferred) {\n                contractHint.textContent = clearedContractNotice + contractHint.textContent;\n            }", $html);
        $this->assertStringContainsString("contractHint.textContent = (preferId ? clearedContractNotice : '') + contractHints.failed;", $html);
        $this->assertStringContainsString("const clearedContractNotice = 'The previously submitted contract was cleared. Review the contract selection before submitting. ';", $html);
    }

    public function test_form_leaves_due_date_empty_and_web_store_uses_contract_sla(): void
    {
        $this->travelTo(now()->startOfMinute());
        $client = Client::create(['name' => 'Synthetic C7 SLA client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic C7 SLA', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01',
            'sla_terms' => ['resolution' => ['p3' => 8]],
        ]);
        $html = $this->get(route('tickets.create'))->assertOk()->getContent();
        $this->assertStringContainsString('class="form-control" value=""', $html);
        $this->assertStringNotContainsString('updateDueDate', $html);
        $data = ['client_id' => $client->id, 'subject' => 'Synthetic C7 SLA', 'priority' => 'p3', 'type' => 'incident', 'contract_id' => $contract->id, 'due_at' => ''];
        $this->post(route('tickets.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Ticket::latest('id')->first()->due_at->equalTo(now()->addHours(8)));
        $explicit = now()->addHours(3)->format('Y-m-d\TH:i');
        $this->post(route('tickets.store'), array_replace($data, ['due_at' => $explicit]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($explicit, Ticket::latest('id')->first()->due_at->format('Y-m-d\TH:i'));
        $this->withSession(['_old_input' => ['due_at' => $explicit]])->get(route('tickets.create'))
            ->assertOk()->assertSee('value="'.$explicit.'"', false);
    }
}
