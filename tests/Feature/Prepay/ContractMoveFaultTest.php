<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use App\Services\PrepayService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class ContractMoveFaultTest extends TestCase
{
    use RefreshDatabase;

    private Contract $from;

    private Contract $to;

    private Ticket $ticket;

    private User $user;

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic fault client']);
        $attributes = ['client_id' => $client->id, 'name' => 'Synthetic block', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_total' => 10,
            'prepay_balance' => 10, 'prepay_used' => 0, 'prepay_as_amount' => false];
        $this->from = Contract::create($attributes);
        $this->to = Contract::create($attributes);
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $this->from->id]);
        $this->user = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->user->id);
        $this->logs = new TestHandler;
        Log::getLogger()->pushHandler($this->logs);
    }

    private function note(): TicketNote
    {
        return TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $this->ticket->id, 'contract_id' => $this->from->id,
            'author_id' => $this->user->id, 'body' => 'Synthetic time', 'time_minutes' => 30,
            'is_billable' => true, 'noted_at' => now(),
        ]));
    }

    private function injectFault(TicketNote $note): void
    {
        $service = \Mockery::mock(PrepayService::class)->makePartial();
        $service->shouldReceive('debitFromTicketNote')->withArgs(fn ($entry) => $entry->id === $note->id)
            ->once()->andThrow(new \RuntimeException('Synthetic private exception text'));
        $service->shouldReceive('debitFromTicketNote')->withAnyArgs()->passthru();
        $this->app->instance(PrepayService::class, $service);
    }

    private function args(TicketNote $note): array
    {
        return ['entry_type' => 'note', 'entry_id' => $note->id, 'contract_id' => $this->to->id, 'reason' => 'Synthetic move'];
    }

    private function assertFault(array $result, TicketNote $note): void
    {
        $this->assertSame('executed_with_fault', $result['status'] ?? null);
        $this->assertFalse($result['success']);
        $this->assertSame('Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.', $result['message']);
        $this->assertSame($this->to->id, $note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertFaultLog($note, false);
    }

    private function assertFaultLog(TicketNote $note, ?bool $debitRecorded): void
    {
        $records = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === '[Prepay] Contract move follow-on fault'));
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Error, $records[0]->level);
        $this->assertSame([
            'ticket_note_id' => $note->id, 'from_contract_id' => $this->from->id,
            'to_contract_id' => $this->to->id, 'exception_class' => \RuntimeException::class,
            'debit_recorded' => $debitRecorded,
        ], $records[0]->context);
    }

    public function test_agent_reports_and_logs_committed_move_with_debit_fault(): void
    {
        $note = $this->note();
        $this->injectFault($note);
        $thrown = null;
        try {
            $result = app(TimeEntryContractMoveService::class)->agentMove($this->args($note), 'synthetic', false);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown?->getMessage());
        $this->assertFault($result, $note);
    }

    public function test_approval_reports_fault_and_leaves_proposal_done(): void
    {
        $note = $this->note();
        $staged = app(TimeEntryContractMoveService::class)->agentMove($this->args($note), 'synthetic', true);
        $proposal = TimeEntryMoveProposal::findOrFail($staged['proposal_id']);
        $this->injectFault($note);
        $result = app(TimeEntryContractMoveService::class)->approve($proposal, $this->user);
        $this->assertFault($result, $note);
        $this->assertSame('done', $proposal->fresh()->state);
    }

    public function test_batch_counts_faulted_move_and_continues_with_later_entry(): void
    {
        $first = $this->note();
        $second = $this->note();
        $this->injectFault($first);
        $result = app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->to->id, [
            ['type' => 'note', 'id' => $first->id], ['type' => 'note', 'id' => $second->id],
        ], 'Synthetic move', $this->user);
        $this->assertSame(2, $result['moved']);
        $this->assertSame(0.5, $result['hours']);
        $this->assertSame(['Entry note #'.$first->id.': Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.'], $result['errors']);
        $this->assertSame($this->to->id, $first->fresh()->contract_id);
        $this->assertSame($this->to->id, $second->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $first->id)->count());
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $second->id)->sole()->hours);
    }

    public function test_debit_alert_fault_reports_committed_draw(): void
    {
        $note = $this->note();
        $alerts = \Mockery::mock(\App\Services\PrepayAlertService::class);
        $alerts->shouldReceive('checkThreshold')->once()->andThrow(new \RuntimeException('Synthetic alert fault'));
        $this->app->instance(\App\Services\PrepayAlertService::class, $alerts);
        $result = app(TimeEntryContractMoveService::class)->agentMove($this->args($note), 'synthetic', false);
        $this->assertSame('executed_with_fault', $result['status'] ?? null);
        $this->assertFalse($result['success']);
        $this->assertSame('Time entry moved and its prepay debit was recorded, but a step after the debit failed; check the ledger before acting.', $result['message']);
        $this->assertFaultLog($note, true);
        $this->assertSame(0.5, $result['drawn_hours']);
        $this->assertStringContainsString('so 0.50h was drawn from Synthetic block', TicketNote::where('ticket_id', $this->ticket->id)
            ->where('body', 'like', 'Moved note #'.$note->id.' %')->sole()->body);
        $this->assertEquals(0.5, \App\Models\ContractActivity::where('contract_id', $this->to->id)
            ->where('action', 'entry_moved_in')->sole()->changes['drawn_hours']);
        $this->assertSame($this->to->id, $note->fresh()->contract_id);
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
    }

    public function test_phone_debit_fault_preserves_move_and_reports_fault(): void
    {
        $call = \App\Models\PhoneCall::withoutEvents(fn () => \App\Models\PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-move-fault', 'direction' => 'inbound', 'status' => 'completed', 'from_number' => '+15555550142',
            'ticket_id' => $this->ticket->id, 'contract_id' => $this->from->id,
            'duration' => 1800, 'is_billable' => true, 'started_at' => now(),
        ]));
        $service = \Mockery::mock(PrepayService::class)->makePartial();
        $service->shouldReceive('debitFromPhoneCall')->once()->andThrow(new \RuntimeException('Synthetic private text'));
        $this->app->instance(PrepayService::class, $service);
        $result = app(TimeEntryContractMoveService::class)->agentMove([
            'entry_type' => 'call', 'entry_id' => $call->id, 'contract_id' => $this->to->id, 'reason' => 'Synthetic move',
        ], 'synthetic', false);
        $this->assertSame('executed_with_fault', $result['status'] ?? null);
        $this->assertFalse($result['success']);
        $this->assertSame($this->to->id, $call->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $records = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === '[Prepay] Contract move follow-on fault'));
        $this->assertCount(1, $records);
        $this->assertSame(\Monolog\Level::Error, $records[0]->level);
        $this->assertSame([
            'phone_call_id' => $call->id, 'from_contract_id' => $this->from->id,
            'to_contract_id' => $this->to->id, 'exception_class' => \RuntimeException::class,
            'debit_recorded' => false,
        ], $records[0]->context);
    }

    public function test_non_billable_phone_without_debit_skips_alert_and_preserves_move(): void
    {
        $call = \App\Models\PhoneCall::withoutEvents(fn () => \App\Models\PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-non-billable-alert-fault', 'direction' => 'inbound', 'status' => 'completed', 'from_number' => '+15555550142',
            'ticket_id' => $this->ticket->id, 'contract_id' => $this->from->id,
            'duration' => 1800, 'is_billable' => false, 'started_at' => now(),
        ]));
        $alerts = \Mockery::mock(\App\Services\PrepayAlertService::class);
        $alerts->shouldNotReceive('checkThreshold');
        $this->app->instance(\App\Services\PrepayAlertService::class, $alerts);
        $result = app(TimeEntryContractMoveService::class)->agentMove([
            'entry_type' => 'call', 'entry_id' => $call->id, 'contract_id' => $this->to->id, 'reason' => 'Synthetic move',
        ], 'synthetic', false);
        $this->assertNull($result['status'] ?? null);
        $this->assertTrue($result['success']);
        $this->assertSame('Time entry moved. It had no prepay ledger row and no prepay hours were drawn.', $result['message']);
        $this->assertSame($this->to->id, $call->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $records = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === '[Prepay] Contract move follow-on fault'));
        $this->assertCount(0, $records);
    }

    public function test_approve_route_flashes_fault_instead_of_success(): void
    {
        $this->user->forceFill(['role' => 'admin', 'is_active' => true])->save();
        $note = $this->note();
        $staged = app(TimeEntryContractMoveService::class)->agentMove($this->args($note), 'synthetic', true);
        $this->injectFault($note);
        $this->actingAs($this->user)->post(route('time-entry-moves.approve', $staged['proposal_id']))
            ->assertRedirect()->assertSessionHas('error', 'Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.')
            ->assertSessionMissing('success');
        $this->assertSame($this->to->id, $note->fresh()->contract_id);
    }

    public function test_modal_flashes_moved_count_and_debit_fault(): void
    {
        $note = $this->note();
        $this->injectFault($note);
        $this->actingAs($this->user)->patch(route('tickets.contract.update', $this->ticket), [
            'contract_id' => $this->to->id, 'move' => ['note:'.$note->id], 'move_reason' => 'Synthetic move',
        ])->assertRedirect()->assertSessionHas('success', 'Ticket contract updated. Moved 1 time entry (0.00 h).')
            ->assertSessionHas('error', 'Entry note #'.$note->id.': Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.');
        $this->assertSame($this->to->id, $note->fresh()->contract_id);
    }

    public function test_modal_alert_fault_flashes_committed_draw_hours(): void
    {
        $this->ticket->forceFill(['contract_id' => $this->to->id])->saveQuietly();
        $note = $this->note();
        $alerts = \Mockery::mock(\App\Services\PrepayAlertService::class);
        $alerts->shouldReceive('checkThreshold')->once()->andThrow(new \RuntimeException('Synthetic alert fault'));
        $this->app->instance(\App\Services\PrepayAlertService::class, $alerts);
        $this->actingAs($this->user)->patch(route('tickets.contract.update', $this->ticket), [
            'contract_id' => $this->to->id, 'move' => ['note:'.$note->id], 'move_reason' => 'Synthetic move',
        ])->assertRedirect()->assertSessionHas('success', 'Ticket contract updated. Moved 1 time entry (0.50 h).')
            ->assertSessionHas('error', 'Entry note #'.$note->id.': Time entry moved and its prepay debit was recorded, but a step after the debit failed; check the ledger before acting.');
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
    }

    public function test_modal_omits_hours_when_fault_leaves_ledger_unread(): void
    {
        $note = $this->note();
        $service = \Mockery::mock(PrepayService::class)->makePartial();
        $service->shouldReceive('debitFromTicketNote')->withArgs(fn ($entry) => $entry->id === $note->id)
            ->once()->andReturnUsing(function () {
                DB::statement('ALTER TABLE prepay_transactions RENAME TO synthetic_unread_ledger');

                throw new \RuntimeException('Synthetic private exception text');
            });
        $service->shouldReceive('debitFromTicketNote')->withAnyArgs()->passthru();
        $this->app->instance(PrepayService::class, $service);
        $response = $this->actingAs($this->user)->patch(route('tickets.contract.update', $this->ticket), [
            'contract_id' => $this->to->id, 'move' => ['note:'.$note->id], 'move_reason' => 'Synthetic move',
        ]);
        DB::statement('ALTER TABLE synthetic_unread_ledger RENAME TO prepay_transactions');
        $response->assertRedirect()->assertSessionHas('success', 'Ticket contract updated. Moved 1 time entry.')
            ->assertSessionHas('error', 'Entry note #'.$note->id.': Time entry moved, but whether its follow-on prepay debit was recorded could not be confirmed; check the ledger before acting.');
        $this->assertFaultLog($note, null);
        $this->assertSame($this->to->id, $note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
    }

    public function test_lookup_fault_reports_no_row_and_logs_false(): void
    {
        $note = $this->note();
        DB::connection()->beforeExecuting(function ($query) {
            if ($query === 'select * from "ticket_notes" where "ticket_notes"."id" = ? and "ticket_notes"."deleted_at" is null limit 1') {
                throw new \RuntimeException('Synthetic lookup fault');
            }
        });
        $result = app(PrepayService::class)->moveEntryContract($note, $this->to, 'Synthetic move', $this->user);
        $this->assertSame('Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.', $result['message'] ?? null);
        $this->assertFaultLog($note, false);
    }

    public function test_no_injected_fault_returns_normal_agent_success(): void
    {
        $note = $this->note();
        $result = app(TimeEntryContractMoveService::class)->agentMove($this->args($note), 'synthetic', false);
        $this->assertTrue($result['success']);
        $this->assertSame(0.5, $result['drawn_hours']);
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertSame([], array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === '[Prepay] Contract move follow-on fault')));
    }
}
