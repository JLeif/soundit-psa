<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use App\Support\AppTimezone;
use Illuminate\Support\Carbon;
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

    /** #5030: rendered source no longer fills due_at; a blank web due_at takes the contract SLA, an explicit one wins. */
    public function test_form_leaves_due_date_empty_and_web_store_uses_contract_sla(): void
    {
        $html = $this->withSession(['_old_input' => ['priority' => 'p1']])
            ->get(route('tickets.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('updateDueDate', $html);
        $this->assertStringNotContainsString('dueAtInput', $html);
        $this->assertStringNotContainsString('Auto-set from priority SLA', $html);
        $this->assertStringContainsString('<small class="form-text text-muted">Optional due date override</small>', $html);
        $this->assertMatchesRegularExpression('/<input type="datetime-local" name="due_at" id="due_at"\s+class="form-control" value="">/', $html);

        $this->travelTo(now()->startOfSecond());
        $client = Client::create(['name' => 'Synthetic C7 SLA client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic C7 SLA', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
            'sla_terms' => ['resolution' => ['p3' => 8]],
        ]);
        $data = ['client_id' => $client->id, 'subject' => 'Synthetic C7 SLA', 'description' => 'Synthetic body',
            'priority' => 'p3', 'type' => 'incident', 'contract_id' => (string) $contract->id];

        $this->post(route('tickets.store'), $data + ['due_at' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $blank = Ticket::latest('id')->firstOrFail();
        $this->assertSame($contract->id, $blank->contract_id);
        $this->assertTrue($blank->due_at->equalTo(now()->addHours(8)), (string) $blank->due_at);

        $this->post(route('tickets.store'), $data + ['due_at' => '2026-12-01T09:00'])->assertRedirect()->assertSessionHasNoErrors();
        $explicit = Ticket::latest('id')->firstOrFail();
        $this->assertNotSame($blank->id, $explicit->id);
        $this->assertTrue($explicit->due_at->equalTo(Carbon::createFromFormat('Y-m-d\TH:i', '2026-12-01T09:00', AppTimezone::get())->utc()), (string) $explicit->due_at);
    }
}
