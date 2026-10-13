<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC4Test extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_stamp_moves_without_writing_foreign_activity(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Http::preventStrayRequests();
        $x = Client::create(['name' => 'Synthetic X']);
        $y = Client::create(['name' => 'Synthetic Y']);
        $contracts = [];
        foreach ([$x, $y] as $client) {
            $contracts[] = Contract::create([
                'client_id' => $client->id, 'name' => $client->name.' contract',
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            ]);
        }
        [$foreign, $target] = $contracts;
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $y->id, 'contract_id' => $target->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'author_id' => $user->id, 'body' => 'Synthetic time',
            'time_minutes' => 45, 'is_billable' => true, 'contract_id' => $foreign->id,
        ]));
        $before = ContractActivity::where('contract_id', $foreign->id)->get()->toArray();

        $result = app(PrepayService::class)->moveEntryContract($note, $target, 'Synthetic private reason', $user);

        $this->assertSame($target->id, $note->fresh()->contract_id);
        $in = ContractActivity::where('contract_id', $target->id)->where('action', 'entry_moved_in')->sole();
        $this->assertSame($note->id, $in->changes['entry_id']);
        $this->assertSame($before, ContractActivity::where('contract_id', $foreign->id)->get()->toArray());
        $this->assertNull($in->changes['from_contract_id']);
        $this->assertNull($result['from_contract_id']);
    }
}
