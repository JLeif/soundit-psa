<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\Ticket;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use App\Services\PrepayService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    private function contract(): Contract
    {
        return Contract::create(['client_id' => Client::create(['name' => 'Synthetic C6'])->id,
            'name' => 'Synthetic C6 contract', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
    }

    public function test_activity_renders_entire_escaped_payload_without_line_clamp(): void
    {
        $changes = ['reason' => str_repeat('Synthetic <detail> ', 20), 'drawn_hours' => 0.125, 'drawn_contract_id' => 123];
        $activity = new ContractActivity(['action' => 'entry_moved_in', 'changes' => $changes]);
        $activity->setRelation('user', null)->setRelation('contract', null);
        $html = view('dashboard._activity-item-contract', ['item' => (object) [
            'model' => $activity, 'url' => '/synthetic', 'timestamp' => now(),
        ]])->render();
        $this->assertStringContainsString(e(json_encode($changes)), $html);
        $this->assertStringNotContainsString('text-clamp-2', $html);
        $this->assertStringNotContainsString('<detail>', $html);
    }

    public function test_default_release_failure_is_visible_for_changed_and_unchanged_default(): void
    {
        $contract = $this->contract();
        $user = User::factory()->create(['role' => 'admin']);
        $prepay = \Mockery::mock(PrepayService::class);
        $prepay->shouldReceive('releaseHeldDebits')->twice()->with($contract->client_id)
            ->andThrow(new \RuntimeException('Synthetic private failure detail'));
        $this->app->instance(PrepayService::class, $prepay);
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);
        foreach ([1, 2] as $attempt) {
            $response = $this->actingAs($user)->patch(route('clients.default-contract.update', $contract->client_id),
                ['default_contract_id' => $contract->id]);
            $response->assertRedirect(route('clients.show', $contract->client_id))
                ->assertSessionHas('error', 'Held-debit release failed. Check the ledger before retrying.');
            $this->assertSame($contract->id, $contract->client->fresh()->default_contract_id);
            $this->assertStringNotContainsString('Synthetic private failure detail', json_encode(session()->all()));
        }
        $records = array_values(array_filter($handler->getRecords(), fn ($record) => $record->message === '[Prepay] Held debits release failed'));
        $this->assertCount(2, $records);
        foreach ($records as $record) {
            $this->assertSame(\Monolog\Level::Warning, $record->level);
            $this->assertSame(['client_id' => $contract->client_id, 'default_contract_id' => $contract->id,
                'exception_class' => \RuntimeException::class], $record->context);
        }
    }

    public function test_default_release_success_and_clear_do_not_report_failure(): void
    {
        $contract = $this->contract();
        $prepay = \Mockery::mock(PrepayService::class);
        $prepay->shouldReceive('releaseHeldDebits')->once()->andReturn(1);
        $this->app->instance(PrepayService::class, $prepay);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('clients.default-contract.update', $contract->client_id), ['default_contract_id' => $contract->id])
            ->assertSessionHas('success', 'Default contract updated. 1 held time entry debited.')
            ->assertSessionMissing('error');
        $this->patch(route('clients.default-contract.update', $contract->client_id), ['default_contract_id' => null])
            ->assertSessionHas('success', 'Default contract cleared.')->assertSessionMissing('error');
    }

    public function test_no_client_refusal_is_stale_despite_pending_call_action(): void
    {
        [$call, $proposal, $user] = $this->pendingCallMove(false);
        $moves = app(TimeEntryContractMoveService::class);
        $this->assertStringContainsString('refuses the move as stale', $moves->approvalEffect($proposal));
        $this->assertSame(['error' => "That call is not on a client's ticket."], $moves->approve($proposal, $user));
        $this->assertSame('stale', $proposal->fresh()->state);
    }

    public function test_pending_action_refusal_keeps_move_pending(): void
    {
        [$call, $proposal, $user] = $this->pendingCallMove(true);
        $this->assertSame(['error' => 'A staged action on this call is awaiting approval; approve or deny it first. This move stays pending.'],
            app(TimeEntryContractMoveService::class)->approve($proposal, $user));
        $this->assertSame('pending', $proposal->fresh()->state);
    }

    private function pendingCallMove(bool $hasClient): array
    {
        $contract = $this->contract();
        $ticket = Ticket::factory()->create(['client_id' => $hasClient ? $contract->client_id : null, 'contract_id' => null]);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c6', 'direction' => 'inbound', 'from_number' => '+15555550143',
            'status' => 'completed', 'is_billable' => true, 'duration' => 720, 'ticket_id' => $ticket->id,
        ]));
        PhoneCallActionProposal::create(['phone_call_id' => $call->id, 'action_type' => 'set_call_billable',
            'payload' => [], 'content_hash' => str_repeat('c', 64), 'state' => 'pending', 'drafted_by' => 'synthetic']);
        $proposal = TimeEntryMoveProposal::create(['entry_type' => 'call', 'entry_id' => $call->id,
            'ticket_id' => $ticket->id, 'from_contract_id' => null, 'to_contract_id' => $contract->id,
            'reason' => 'Synthetic correction', 'content_hash' => str_repeat('d', 64), 'state' => 'pending', 'drafted_by' => 'synthetic']);

        return [$call, $proposal, User::factory()->create(['role' => 'admin'])];
    }
}
