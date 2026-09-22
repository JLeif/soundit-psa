<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Services\PrepayAlertService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create());
        $this->mock(PrepayAlertService::class)->shouldReceive('checkThreshold')->andReturnNull();
    }

    private function makeCall(?Ticket $ticket = null, ?bool $billable = false): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('bulk-test-', true), 'from_number' => '+15555550100',
            'direction' => 'inbound', 'status' => CallStatus::Completed, 'started_at' => now(),
            'ticket_id' => $ticket?->id, 'is_billable' => $billable,
        ]);
        $call->duration = 3600;
        $call->save();

        return $call;
    }

    private function prepaidTicket(): array
    {
        $client = Client::create(['name' => 'Example bulk client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Example prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);

        return [$ticket, $contract];
    }

    private function billable(array $ids, bool $desired = true)
    {
        return $this->postJson(route('calls.bulk-action'), [
            'action' => 'set_billable', 'is_billable' => $desired, 'call_ids' => $ids,
            'status' => 'completed', 'date_from' => today()->toDateString(),
        ]);
    }

    public function test_selected_call_debits_and_reverses_real_prepay_without_touching_other_filtered_calls(): void
    {
        [$ticket, $contract] = $this->prepaidTicket();
        $selected = $this->makeCall($ticket);
        $unselected = $this->makeCall($ticket);
        $this->billable([$selected->id])->assertOk()->assertJsonPath('summary.applied', 1);
        $this->assertTrue($selected->fresh()->is_billable);
        $this->assertFalse($unselected->fresh()->is_billable);
        $this->assertDatabaseHas('prepay_transactions', ['phone_call_id' => $selected->id, 'hours' => -1]);
        $this->assertDatabaseMissing('prepay_transactions', ['phone_call_id' => $unselected->id]);
        $this->assertEquals(9, $contract->fresh()->prepay_balance);
        $this->billable([$selected->id], false)->assertOk()->assertJsonPath('summary.applied', 1);
        $this->assertFalse($selected->fresh()->is_billable);
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
    }

    public function test_duplicates_already_desired_unlinked_and_missing_are_named_skips(): void
    {
        [$ticket] = $this->prepaidTicket();
        $selected = $this->makeCall($ticket);
        $already = $this->makeCall($ticket, true);
        $unlinked = $this->makeCall();
        $response = $this->billable([$selected->id, (string) $selected->id, $already->id, $unlinked->id, 999999]);
        $response->assertOk()->assertJsonPath('summary.applied', 1)->assertJsonPath('summary.skipped', 4)
            ->assertJsonPath('results.1.reason', 'Duplicate submitted ID.')
            ->assertJsonPath('results.2.reason', 'Already in the desired state.')
            ->assertJsonPath('results.3.reason', 'Call must be linked to a ticket to change billability.')
            ->assertJsonPath('results.4.reason', 'Call not found.');
        $this->assertEquals(1, PrepayTransaction::where('phone_call_id', $selected->id)->count());
        $this->assertFalse($unlinked->fresh()->is_billable);
        $this->billable([$selected->id])->assertJsonPath('summary.skipped', 1);
        $this->billable([$unlinked->id], false)->assertJsonPath('summary.skipped', 1);
    }

    public function test_follow_up_uses_selected_ids_and_keeps_original_follow_up_stamp_on_repeat(): void
    {
        $selected = $this->makeCall();
        $unselected = $this->makeCall();
        $data = ['action' => 'mark_followed_up', 'call_ids' => [$selected->id, $selected->id]];
        $this->postJson(route('calls.bulk-action'), $data)->assertOk()
            ->assertJsonPath('summary.applied', 1)->assertJsonPath('summary.skipped', 1);
        $stamp = $selected->fresh()->followed_up_at;
        $this->assertNotNull($stamp);
        $this->assertSame(auth()->id(), $selected->fresh()->followed_up_by);
        $this->assertNull($unselected->fresh()->followed_up_at);
        $this->travel(1)->hours();
        $this->postJson(route('calls.bulk-action'), $data)->assertJsonPath('summary.skipped', 2);
        $this->assertTrue($stamp->equalTo($selected->fresh()->followed_up_at));
    }

    public function test_failure_before_save_does_not_abort_later_calls(): void
    {
        [$ticket] = $this->prepaidTicket();
        $first = $this->makeCall($ticket);
        $second = $this->makeCall($ticket);
        $real = app(PhoneCallService::class);
        $mock = \Mockery::mock(PhoneCallService::class);
        $mock->shouldReceive('setBillable')->withArgs(fn ($call, $desired) => $call->id === $first->id && $desired)
            ->once()->andThrow(new \RuntimeException('before save'));
        $mock->shouldReceive('setBillable')->withArgs(fn ($call, $desired) => $call->id === $second->id && $desired)
            ->once()->andReturnUsing(fn ($call, $desired) => $real->setBillable($call, $desired));
        $this->instance(PhoneCallService::class, $mock);
        $this->billable([$first->id, $second->id])->assertOk()
            ->assertJsonPath('summary.failed', 1)->assertJsonPath('summary.applied', 1);
        $this->assertFalse($first->fresh()->is_billable);
        $this->assertTrue($second->fresh()->is_billable);
    }

    public function test_pre_debit_exception_after_save_requires_verification_too(): void
    {
        [$ticket, $contract] = $this->prepaidTicket();
        $call = $this->makeCall($ticket);
        $this->mock(PrepayService::class)->shouldReceive('debitFromPhoneCall')->once()->andThrow(new \RuntimeException('before debit'));
        $this->billable([$call->id])->assertOk()->assertJsonPath('summary.reconciliation', 1)
            ->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('results.0.reason', 'The billable state persisted and prepay needs verification.');
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $this->assertSame(0, PrepayTransaction::count());
    }

    public function test_invalid_or_unauthenticated_batches_cannot_write(): void
    {
        $call = $this->makeCall();
        foreach ([
            ['action' => 'toggle', 'call_ids' => [$call->id]],
            ['action' => 'set_billable', 'call_ids' => [$call->id]],
            ['action' => 'set_billable', 'is_billable' => 'yes', 'call_ids' => [$call->id]],
            ['action' => 'mark_followed_up', 'call_ids' => []],
            ['action' => 'mark_followed_up', 'call_ids' => ['bad']],
            ['action' => 'mark_followed_up', 'call_ids' => array_fill(0, 101, $call->id)],
        ] as $data) {
            $this->postJson(route('calls.bulk-action'), $data)->assertUnprocessable();
        }
        auth()->logout();
        $this->postJson(route('calls.bulk-action'), ['action' => 'mark_followed_up', 'call_ids' => [$call->id]])->assertUnauthorized();
        $this->assertNull($call->fresh()->followed_up_at);
        $this->assertFalse($call->fresh()->is_billable);
    }

    public function test_html_report_names_each_call_and_index_renders_selection_controls(): void
    {
        $call = $this->makeCall();
        $this->get(route('calls.index'))->assertOk()->assertSee('id="selectAll"', false)
            ->assertSee('name="call_ids[]"', false)->assertSee('Mark billable')->assertSee('Mark non-billable');
        $response = $this->post(route('calls.bulk-action'), [
            'action' => 'set_billable', 'is_billable' => true, 'call_ids' => [$call->id, 999999],
        ]);
        $response->assertRedirect(route('calls.index'))->assertSessionHas('call_bulk_report.summary.skipped', 2);
        $this->get(route('calls.index'))->assertOk()->assertSee('Call #'.$call->id)
            ->assertSee('Call #999999')->assertSee('Call not found.')
            ->assertSee('Call must be linked to a ticket to change billability.');
    }

    public function test_post_commit_alert_exception_is_reconciliation_not_failure_and_later_calls_continue(): void
    {
        [$ticket, $contract] = $this->prepaidTicket();
        $first = $this->makeCall($ticket);
        $second = $this->makeCall($ticket);
        $this->mock(PrepayAlertService::class)->shouldReceive('checkThreshold')->andThrow(new \RuntimeException('private vendor detail'));
        $response = $this->billable([$first->id, $second->id]);
        $response->assertOk()->assertJsonPath('summary.reconciliation', 2)->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('results.0.call_id', $first->id)
            ->assertJsonPath('results.0.reason', 'The billable state persisted and prepay needs verification.')
            ->assertDontSee('private vendor detail');
        $this->assertTrue($first->fresh()->is_billable);
        $this->assertTrue($second->fresh()->is_billable);
        $this->assertEquals(8, $contract->fresh()->prepay_balance);
        $this->assertSame(2, PrepayTransaction::count());
    }
}
