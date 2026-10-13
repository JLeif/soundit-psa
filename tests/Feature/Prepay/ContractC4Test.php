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

    public function test_missing_ledger_source_refusal(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic missing source']);
        $target = Contract::create(['client_id' => $client->id, 'name' => 'Synthetic target', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $target->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate(['ticket_id' => $ticket->id, 'body' => 'Synthetic time', 'time_minutes' => 45, 'is_billable' => true]));
        \Illuminate\Support\Facades\DB::statement('PRAGMA defer_foreign_keys = ON');
        \Illuminate\Support\Facades\Schema::withoutForeignKeyConstraints(fn () => \App\Models\PrepayTransaction::create([
            'contract_id' => 999999, 'ticket_note_id' => $note->id, 'source' => 'ticket_time', 'hours' => -0.75, 'date' => now(),
        ]));
        $proposal = \App\Models\TimeEntryMoveProposal::create(['entry_type' => 'note', 'entry_id' => $note->id, 'ticket_id' => $ticket->id, 'from_contract_id' => null, 'to_contract_id' => $target->id, 'reason' => 'Synthetic reason', 'drafted_by' => 'synthetic', 'state' => 'pending', 'content_hash' => str_repeat('c', 64)]);
        \Illuminate\Support\Facades\Schema::withoutForeignKeyConstraints(fn () => $proposal->update(['from_contract_id' => 999999]));
        $this->assertSame('Its ledger source contract is missing or belongs to another client, so approving refuses the move. Nothing has moved yet.', app(\App\Services\TimeEntryContractMoveService::class)->approvalEffect($proposal->fresh()));
        $before = \App\Models\PrepayTransaction::get()->toArray();
        try {
            app(PrepayService::class)->moveEntryContract($note, $target, 'Synthetic reason', $user);
            $this->fail('Expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("That note's ledger source contract is missing or belongs to another client; nothing moved.", $e->getMessage());
        }
        $this->assertSame($before, \App\Models\PrepayTransaction::get()->toArray());
        $this->assertNull($note->fresh()->contract_id);
    }

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
