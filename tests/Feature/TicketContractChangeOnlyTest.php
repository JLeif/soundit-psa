<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketContractChangeOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_only_ignores_selected_unledgered_note(): void
    {
        [$ticket, $note, $source, $target, $user] = $this->scenario();
        $ledger = PrepayTransaction::orderBy('id')->get()->toArray();
        $balances = [$source->fresh()->prepay_balance, $target->fresh()->prepay_balance];

        $this->actingAs($user)->post(route('tickets.contract.update', $ticket), [
            '_method' => 'PATCH', 'contract_id' => $target->id,
            'change_only' => '1', 'move' => ['note:'.$note->id],
            'move_reason' => 'Synthetic reclassification',
        ])->assertRedirect(route('tickets.show', $ticket))->assertSessionHasNoErrors();

        $this->assertSame($target->id, $ticket->fresh()->contract_id);
        $this->assertSame($source->id, $note->fresh()->contract_id);
        $this->assertSame($ledger, PrepayTransaction::orderBy('id')->get()->toArray());
        $this->assertSame($balances, [$source->fresh()->prepay_balance, $target->fresh()->prepay_balance]);
    }

    public function test_without_change_only_selected_unledgered_note_moves(): void
    {
        [$ticket, $note, $source, $target, $user] = $this->scenario();
        $sourceBalance = $source->fresh()->prepay_balance;

        $this->actingAs($user)->post(route('tickets.contract.update', $ticket), [
            '_method' => 'PATCH', 'contract_id' => $target->id,
            'move' => ['note:'.$note->id], 'move_reason' => 'Synthetic reclassification',
        ])->assertRedirect(route('tickets.show', $ticket))->assertSessionHasNoErrors();

        $this->assertSame($target->id, $ticket->fresh()->contract_id);
        $this->assertSame($target->id, $note->fresh()->contract_id);
        $this->assertSame($target->id, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->contract_id);
        $this->assertEquals(-0.5, (float) PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertEquals(9.5, (float) $target->fresh()->prepay_balance);
        $this->assertSame($sourceBalance, $source->fresh()->prepay_balance);
    }

    private function scenario(): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic Change Only Client']);
        $source = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Managed Source',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
        ]);
        $target = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay Target',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $user = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $source->id]);
        $note = TicketNote::forceCreate([
            'body' => 'Synthetic maintenance', 'ticket_id' => $ticket->id, 'author_id' => $user->id,
            'is_billable' => true, 'time_minutes' => 30, 'noted_at' => now(),
        ]);
        $this->assertSame($source->id, $note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());

        return [$ticket, $note, $source, $target, $user];
    }
}
