<?php

namespace Tests\Feature\Prepay;

use App\Http\Requests\TicketStoreRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ContractNotAllowedException;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ContractC11Test extends TestCase
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

    private function contract(Client $client, string $status = 'active'): Contract
    {
        return Contract::create(['client_id' => $client->id, 'name' => 'Synthetic C11 contract', 'type' => 'managed', 'status' => $status, 'start_date' => '2026-01-01']);
    }

    private function intake(string $surface): Email|PhoneCall
    {
        return $surface === 'emails'
            ? Email::create(['direction' => 'inbound', 'from_address' => 'synthetic@example.test', 'subject' => 'Synthetic C11', 'body_text' => 'Synthetic', 'received_at' => now()])
            : PhoneCall::create(['call_uuid' => uniqid('synthetic-c11-', true), 'direction' => 'inbound', 'from_number' => '+15555550123', 'status' => 'completed', 'started_at' => now()]);
    }

    private function data(Client $client, mixed $id): array
    {
        return ['client_id' => $client->id, 'subject' => 'Synthetic C11', 'type' => 'incident', 'priority' => 'p3', 'contract_id' => $id];
    }

    public static function surfaces(): array
    {
        return [['emails'], ['calls']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_email_and_call_accept_picked_contract_for_ambiguous_client(string $surface): void
    {
        $client = Client::factory()->create();
        $picked = $this->contract($client);
        $this->contract($client);
        $intake = $this->intake($surface);
        $this->post(route($surface.'.store-ticket', $intake), $this->data($client, $picked->id))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($picked->id, Ticket::findOrFail($intake->fresh()->ticket_id)->contract_id, $surface);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_email_and_call_refuse_invalid_picks_without_writes(string $surface): void
    {
        $client = Client::factory()->create();
        $live = $this->contract($client);
        $foreign = $this->contract(Client::factory()->create());
        $inactive = $this->contract($client, 'expired');
        $deleted = $this->contract($client);
        $deleted->delete();
        foreach ([$foreign->id, $inactive->id, $deleted->id, [$live->id], true, '7abc'] as $id) {
            $intake = $this->intake($surface);
            $this->post(route($surface.'.store-ticket', $intake), $this->data($client, $id))
                ->assertSessionHasErrors(['contract_id' => 'The selected contract is not an active contract of this client.']);
            $this->assertNull($intake->fresh()->ticket_id);
            $this->assertSame(0, Ticket::withTrashed()->count());
            $this->assertDatabaseCount('ticket_notes', 0);
        }
    }

    public function test_email_and_call_catch_contract_expiring_after_validation(): void
    {
        $client = Client::factory()->create();
        $live = $this->contract($client);
        $service = app(TicketService::class);
        $mock = \Mockery::mock(TicketService::class)->makePartial();
        $mock->shouldReceive('createTicket')->andReturnUsing(function ($data, $actor) use ($live, $service) {
            Contract::whereKey($live->id)->update(['status' => 'expired']);

            return $service->createTicket($data, $actor);
        });
        $this->app->instance(TicketService::class, $mock);
        foreach (['emails', 'calls'] as $surface) {
            Contract::whereKey($live->id)->update(['status' => 'active']);
            $intake = $this->intake($surface);
            $this->post(route($surface.'.store-ticket', $intake), $this->data($client, $live->id))
                ->assertSessionHasErrors(['contract_id' => 'The selected contract is not an active contract of this client.']);
            $this->assertNull($intake->fresh()->ticket_id);
            $this->assertSame(0, Ticket::withTrashed()->count());
            $this->assertDatabaseCount('ticket_notes', 0);
        }
    }

    public function test_email_and_call_blank_or_omitted_pick_uses_client_rules(): void
    {
        $client = Client::factory()->create();
        foreach (['emails', 'calls'] as $surface) {
            foreach ([null, ''] as $blank) {
                $intake = $this->intake($surface);
                $data = $this->data($client, $blank);
                if ($blank === null) {
                    unset($data['contract_id']);
                }
                $this->post(route($surface.'.store-ticket', $intake), $data)->assertSessionHasNoErrors();
                $this->assertNull(Ticket::findOrFail($intake->fresh()->ticket_id)->contract_id);
            }
        }
        $default = $this->contract($client);
        $this->contract($client);
        Client::whereKey($client->id)->update(['default_contract_id' => $default->id]);
        foreach (['emails', 'calls'] as $surface) {
            $intake = $this->intake($surface);
            $this->post(route($surface.'.store-ticket', $intake), $this->data($client, ''))->assertSessionHasNoErrors();
            $this->assertSame($default->id, Ticket::findOrFail($intake->fresh()->ticket_id)->contract_id);
        }
    }

    public function test_picker_request_and_service_accept_the_same_set(): void
    {
        $client = Client::factory()->create();
        $active = $this->contract($client);
        $default = $this->contract($client);
        Client::whereKey($client->id)->update(['default_contract_id' => $default->id]);
        $inactive = $this->contract($client, 'expired');
        $deleted = $this->contract($client);
        $deleted->delete();
        $foreign = $this->contract(Client::factory()->create());
        $acceptedRequest = $acceptedService = [];
        foreach ([$active, $default, $inactive, $deleted, $foreign] as $contract) {
            $data = $this->data($client, $contract->id);
            $request = TicketStoreRequest::create('/tickets', 'POST', $data);
            if (! Validator::make($data, $request->rules())->errors()->has('contract_id')) {
                $acceptedRequest[] = $contract->id;
            }
            try {
                $ticket = app(TicketService::class)->createTicket($data, null);
                $this->assertSame($contract->id, $ticket->contract_id);
                $acceptedService[] = $contract->id;
            } catch (ContractNotAllowedException) {
                // Refused by the real create service.
            }
        }
        $ids = array_column($this->getJson(route('api.clients.active-contracts', $client))->assertOk()->json(), 'id');
        sort($ids);
        $this->assertSame([$active->id, $default->id], $ids);
        $this->assertSame($ids, $acceptedRequest);
        $this->assertSame($ids, $acceptedService);
    }

    public function test_rendered_picker_executes_visibility_submission_and_stale_read_arms(): void
    {
        foreach (['emails', 'calls'] as $surface) {
            $html = $this->get(route($surface.'.create-ticket', $this->intake($surface)))->assertOk()->getContent();
            preg_match_all('#<script>(.*?)</script>#s', $html, $scripts);
            $picker = collect($scripts[1])->first(fn ($script) => str_contains($script, 'function contractLabel'));
            $this->assertNotNull($picker);
            $harness = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
const initial = process.argv[1] || '';
const nodes = {};
for (const id of ['client_id', 'contract_id', 'contractGroup', 'contractHint']) {
    nodes[id] = {value: '', dataset: {}, children: [], hidden: true, disabled: true,
        classList: {add() {}}, addEventListener(event, fn) { this[event] = fn; },
        appendChild(option) { this.children.push(option); },
        set innerHTML(value) { this.children = []; this.value = ''; }};
}
nodes.client_id.value = initial ? '1' : '';
nodes.contract_id.dataset.old = initial;
const pending = [];
const document = {getElementById: id => nodes[id], createElement: () => ({}),
    addEventListener: (event, fn) => fn()};
vm.runInNewContext(source, {document, fetch: () => new Promise((resolve, reject) => pending.push({resolve, reject}))});
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const row = (id, is_default = false) => ({id, is_default, name: 'Synthetic', prepay_balance: null, type_label: 'Managed'});
const settle = async rows => { pending.shift().resolve({ok: true, json: () => Promise.resolve(rows)}); await flush(); };
const change = id => { nodes.client_id.value = id; nodes.client_id.change(); };
const hidden = () => { assert.equal(nodes.contractGroup.hidden, true); assert.equal(nodes.contract_id.disabled, true); assert.equal(nodes.contract_id.value, ''); };
(async () => {
    hidden();
    if (initial) {
        await settle([row(1), row(2)]);
        assert.equal(nodes.contract_id.value, initial === '2' ? '2' : '');
        assert.equal(nodes.contractHint.textContent, (initial === '2' ? '' : 'The contract you submitted is not an active contract of this client; pick one. ') + 'Select a contract for this ticket.');
        change('');
    }
    for (const rows of [[], [row(1)], [row(1), row(2, true)]]) {
        change('1'); hidden(); await settle(rows); hidden();
    }
    change('1'); await settle([row(1), row(2)]);
    assert.equal(nodes.contractGroup.hidden, false);
    assert.equal(nodes.contract_id.disabled, false);
    assert.deepEqual(nodes.contract_id.children.map(c => c.value), ['', '1', '2']);
    assert.equal(nodes.contractHint.textContent, 'Select a contract for this ticket.');
    nodes.contract_id.value = '2'; change(''); hidden();
    change('1'); change('2');
    await settle([row(1), row(2)]); hidden();
    await settle([row(3)]); hidden();
    change('1'); change('2');
    pending.shift().reject(new Error('synthetic')); await flush(); hidden();
    await settle([row(3), row(4)]);
    assert.equal(nodes.contractGroup.hidden, false);
    assert.equal(nodes.contract_id.disabled, false);
    change('1'); pending.shift().reject(new Error('synthetic')); await flush(); hidden();
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;
            foreach (['', '2', '99'] as $old) {
                $process = new \Symfony\Component\Process\Process(['node', '-e', $harness, $old]);
                $process->setInput(json_encode($picker));
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $surface.': '.$process->getErrorOutput());
            }
        }
    }

    public function test_email_and_call_render_hidden_disabled_picker_before_loading(): void
    {
        foreach (['emails', 'calls'] as $surface) {
            $html = $this->get(route($surface.'.create-ticket', $this->intake($surface)))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/id="contractGroup"[^>]*hidden/', $html);
            $this->assertMatchesRegularExpression('/<select name="contract_id" id="contract_id"[^>]*disabled>/s', $html);
        }
    }
}
