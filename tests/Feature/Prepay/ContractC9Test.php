<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContractC9Test extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $client = Client::create(['name' => 'Synthetic C9']);
        $attributes = ['client_id' => $client->id, 'name' => 'Synthetic source', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01'];
        $source = Contract::create($attributes);
        $target = Contract::create(array_merge($attributes, ['name' => 'Synthetic target']));
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $target->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate(['ticket_id' => $ticket->id, 'contract_id' => $source->id, 'body' => 'Synthetic time', 'note_type' => 'note', 'is_billable' => true, 'time_minutes' => 45]));

        return [$note, $target, User::factory()->create()];
    }

    private function stage(TicketNote $note, Contract $target): TimeEntryMoveProposal
    {
        $result = app(TimeEntryContractMoveService::class)->agentMove(['entry_type' => 'note', 'entry_id' => $note->id, 'contract_id' => $target->id, 'reason' => 'Synthetic correction'], 'synthetic-c9', true);
        $this->assertTrue($result['success'] ?? false, json_encode($result));

        return TimeEntryMoveProposal::findOrFail($result['proposal_id']);
    }

    private function activity(TicketNote $note, Contract $target): ContractActivity
    {
        return ContractActivity::create(['contract_id' => $target->id, 'action' => 'entry_moved_in', 'created_at' => now(), 'changes' => ['entry_type' => 'note', 'entry_id' => $note->id]]);
    }

    private function duplicate(TimeEntryMoveProposal $proposal, string $state = 'pending'): array
    {
        return array_merge($proposal->only(['entry_type', 'entry_id', 'ticket_id', 'from_contract_id', 'to_contract_id', 'reason', 'content_hash', 'drafted_by', 'created_at', 'updated_at']), ['state' => $state]);
    }

    public function test_raw_duplicate_pending_insert_is_rejected(): void
    {
        [$note, $target] = $this->fixture();
        $proposal = $this->stage($note, $target);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('time_entry_move_proposals')->insert($this->duplicate($proposal));
    }

    public function test_staging_race_returns_winner_after_initial_select(): void
    {
        [$note, $target] = $this->fixture();
        $winner = null;
        $injected = false;
        $dispatcher = DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher(clone $dispatcher);
        DB::listen(function ($query) use ($note, $target, &$winner, &$injected) {
            if (! $injected && str_starts_with($query->sql, 'select * from "time_entry_move_proposals"') && str_contains($query->sql, '"content_hash"')) {
                $injected = true;
                $winner = DB::table('time_entry_move_proposals')->insertGetId([
                    'entry_type' => 'note', 'entry_id' => $note->id, 'ticket_id' => $note->ticket_id,
                    'from_contract_id' => $note->contract_id, 'to_contract_id' => $target->id,
                    'reason' => 'Synthetic correction', 'state' => 'pending', 'drafted_by' => 'synthetic-c9',
                    'content_hash' => hash('sha256', json_encode(['note', $note->id, $note->contract_id, $target->id, 'Synthetic correction'], JSON_THROW_ON_ERROR)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        try {
            $proposal = $this->stage($note, $target);
            $this->assertSame($winner, $proposal->id);
            $this->assertSame(1, TimeEntryMoveProposal::count());
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
        }
    }

    public function test_same_second_earlier_move_does_not_stale_proposal(): void
    {
        [$note, $target, $by] = $this->fixture();
        $activity = $this->activity($note, $target);
        $proposal = $this->stage($note, $target);
        $this->assertTrue($activity->created_at->equalTo($proposal->created_at));
        $result = app(TimeEntryContractMoveService::class)->approve($proposal, $by);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame('done', $proposal->fresh()->state);
        $this->assertSame($target->id, $note->fresh()->contract_id);
    }

    public function test_later_move_stales_proposal_even_in_same_second(): void
    {
        [$note, $target, $by] = $this->fixture();
        $proposal = $this->stage($note, $target);
        $activity = $this->activity($note, $target);
        $this->assertTrue($activity->created_at->equalTo($proposal->created_at));
        $result = app(TimeEntryContractMoveService::class)->approve($proposal, $by);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('stale', $proposal->fresh()->state);
        $this->assertSame($proposal->from_contract_id, $note->fresh()->contract_id);
    }

    public function test_non_pending_duplicates_are_allowed(): void
    {
        [$note, $target] = $this->fixture();
        $proposal = $this->stage($note, $target);
        try {
            foreach (['done', 'denied', 'stale'] as $state) {
                DB::table('time_entry_move_proposals')->insert($this->duplicate($proposal, $state));
                DB::table('time_entry_move_proposals')->insert($this->duplicate($proposal, $state));
            }
        } catch (UniqueConstraintViolationException) {
            $this->fail('Non-pending duplicates must be allowed.');
        }
        $this->assertSame(7, TimeEntryMoveProposal::count());
    }

    public function test_legacy_null_watermark_uses_timestamp_fallback(): void
    {
        [$note, $target, $by] = $this->fixture();
        $this->activity($note, $target);
        $proposal = $this->stage($note, $target);
        if (Schema::hasColumn('time_entry_move_proposals', 'activity_watermark')) {
            $proposal->update(['activity_watermark' => null]);
        }
        $result = app(TimeEntryContractMoveService::class)->approve($proposal, $by);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('stale', $proposal->fresh()->state);
    }

    public function test_migration_down_up_preserves_existing_rows(): void
    {
        [$note, $target] = $this->fixture();
        $proposal = $this->stage($note, $target);
        $path = database_path('migrations/2026_10_13_000001_add_pending_key_and_watermark_to_time_entry_move_proposals.php');
        // At base, exercise the table's original migration with an empty table.
        if (! is_file($path)) {
            TimeEntryMoveProposal::query()->delete();
            $migration = require database_path('migrations/2026_10_04_000002_create_time_entry_move_proposals_table.php');
            $migration->down();
            $migration->up();
            $this->assertTrue(Schema::hasTable('time_entry_move_proposals'));

            return;
        }
        $before = $this->duplicate($proposal);
        $migration = require $path;
        $migration->down();
        $this->assertFalse(Schema::hasColumn('time_entry_move_proposals', 'pending_key'));
        $this->assertFalse(Schema::hasColumn('time_entry_move_proposals', 'activity_watermark'));
        $this->assertEquals($before, $this->duplicate($proposal->fresh()));
        $migration->up();
        $this->assertNull($proposal->fresh()->activity_watermark);
        $this->assertNotNull($proposal->fresh()->pending_key);
        $this->assertEquals($before, $this->duplicate($proposal->fresh()));
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('time_entry_move_proposals')->insert($before);
    }
}
