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

    public function test_active_contracts_emits_resolution_map_and_empty_json_object(): void
    {
        $client = Client::create(['name' => 'Synthetic C7 JSON client']);
        foreach ([['resolution' => ['p1' => 2, 'p3' => 8]], null, ['response' => ['p3' => 1]]] as $i => $terms) {
            Contract::create([
                'client_id' => $client->id, 'name' => 'Synthetic '.$i, 'type' => 'managed',
                'status' => 'active', 'start_date' => '2026-01-01', 'sla_terms' => $terms,
            ]);
        }
        $response = $this->getJson(route('api.clients.active-contracts', $client))->assertOk();
        $response->assertJsonPath('0.resolution_sla_hours', ['p1' => 2, 'p3' => 8]);
        $rows = json_decode($response->getContent());
        $this->assertCount(3, $rows);
        foreach ([1, 2] as $i) {
            $this->assertObjectHasProperty('resolution_sla_hours', $rows[$i]);
            $this->assertInstanceOf(\stdClass::class, $rows[$i]->resolution_sla_hours);
            $this->assertSame('{}', json_encode($rows[$i]->resolution_sla_hours));
        }
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
        $this->assertStringContainsString("contractHint.textContent = (preferId ? 'The contract you submitted is not an active contract of this client. ' : '') + contractHints.none;", $html);
        $this->assertStringContainsString(<<<'JS'
if (preferId && !preferred) {
                const notice = fallback
                    ? 'The contract you submitted is not an active contract of this client, so this client\'s ' + (fallback.is_default ? 'default' : 'only active') + ' contract is selected instead. '
                    : 'The contract you submitted is not an active contract of this client; pick one. ';
                contractHint.textContent = notice + contractHint.textContent;
            }
JS, $html);
        $this->assertStringContainsString("contractHint.textContent = (preferId ? 'The contract you submitted could not be restored. ' : '') + contractHints.failed;", $html);
    }

    public function test_rendered_source_pins_due_date_arms_and_web_store_uses_contract_sla(): void
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
        $this->assertStringContainsString('<small class="form-text text-muted" id="dueAtHint">Auto-set from priority SLA</small>', $html);
        $this->assertStringContainsString(<<<'JS'
if (dueAtDirty) {
            dueAtHint.textContent = 'Optional due date override';
            return;
        }
JS, $html);
        $this->assertStringContainsString(<<<'JS'
if (h !== null && h !== undefined) {
            dueAtInput.value = '';
            dueAtHint.textContent = 'Leave blank and this contract\'s ' + h + 'h resolution SLA sets the due date.';
            return;
        }
JS, $html);
        $this->assertStringContainsString('let dueAtDirty = !!dueAtInput.value;', $html);
        $this->assertStringContainsString("dueAtInput.addEventListener('input', function() {\n        dueAtDirty = true;\n        updateDueDate();\n    });", $html);
        $this->assertStringContainsString('JSON.parse(selectedContract.dataset.resolutionSlaHours || \'{}\')', $html);
        $this->assertStringContainsString('opt.dataset.resolutionSlaHours = JSON.stringify(resolutionSlaHours);', $html);
        $this->assertStringContainsString('contractOption(String(c.id), contractLabel(c), c.resolution_sla_hours)', $html);
        $this->assertStringContainsString('const slaHours = parseInt(selected.dataset.slaHours || 24);', $html);
        $this->assertStringContainsString('due.setHours(due.getHours() + slaHours);', $html);
        $this->assertStringContainsString("dueAtHint.textContent = 'Auto-set from priority SLA';", $html);
        $this->assertStringContainsString("prioritySelect.addEventListener('change', updateDueDate);", $html);
        $this->assertStringContainsString("contractSelect.addEventListener('change', updateDueDate);", $html);
        $this->assertStringContainsString('}).finally(updateDueDate);', $html);
        $data = ['client_id' => $client->id, 'subject' => 'Synthetic C7 SLA', 'priority' => 'p3', 'type' => 'incident', 'contract_id' => $contract->id, 'due_at' => ''];
        $this->post(route('tickets.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Ticket::latest('id')->first()->due_at->equalTo(now()->addHours(8)));
        $explicit = now()->addHours(3)->format('Y-m-d\TH:i');
        $this->post(route('tickets.store'), array_replace($data, ['due_at' => $explicit]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($explicit, Ticket::latest('id')->first()->due_at->format('Y-m-d\TH:i'));
        $this->withSession(['_old_input' => ['due_at' => $explicit]])->get(route('tickets.create'))
            ->assertOk()->assertSee('value="'.$explicit.'"', false)
            ->assertSee('<small class="form-text text-muted" id="dueAtHint">Optional due date override</small>', false)
            ->assertDontSee('<small class="form-text text-muted" id="dueAtHint">Auto-set from priority SLA</small>', false);
    }
}
