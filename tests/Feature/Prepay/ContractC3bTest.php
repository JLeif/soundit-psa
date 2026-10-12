<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayAlertService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractC3bTest extends TestCase
{
    use RefreshDatabase;

    private Contract $a;

    private Contract $b;

    private Ticket $ticket;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic C3b']);
        $attributes = ['client_id' => $client->id, 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_total' => 10, 'prepay_used' => 0,
            'prepay_balance' => 10, 'prepay_as_amount' => false];
        $this->a = Contract::create(['name' => 'Synthetic A'] + $attributes);
        $this->b = Contract::create(['name' => 'Synthetic B'] + $attributes);
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $this->a->id, 'halo_id' => null]);
        $this->user = User::factory()->create();
    }

    private function phone(bool $billable = true): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c3b', 'direction' => 'inbound', 'status' => 'completed',
            'from_number' => '+15555550142', 'ticket_id' => $this->ticket->id,
            'contract_id' => $this->a->id, 'duration' => 1800, 'is_billable' => $billable,
            'started_at' => now(),
        ]));
    }

    private function note(): TicketNote
    {
        return TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $this->ticket->id, 'contract_id' => $this->a->id,
            'author_id' => $this->user->id, 'body' => 'Synthetic time', 'time_minutes' => 30,
            'is_billable' => true, 'noted_at' => now(),
        ]));
    }

    public function test_phone_without_reversal_does_not_alert(): void
    {
        $alerts = \Mockery::mock(PrepayAlertService::class);
        $seen = [];
        $alerts->shouldReceive('checkThreshold')->andReturnUsing(function ($contract) use (&$seen) {
            $seen[] = $contract->id;
        });
        $this->app->instance(PrepayAlertService::class, $alerts);
        $call = $this->phone(false);
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertSame([], $seen);
    }

    public function test_phone_live_debit_and_reversal_alert(): void
    {
        $alerts = \Mockery::mock(PrepayAlertService::class);
        $alerts->shouldReceive('checkThreshold')->twice()->withArgs(fn ($contract) => $contract->id === $this->a->id);
        $this->app->instance(PrepayAlertService::class, $alerts);
        $call = $this->phone();
        $this->assertNotNull(app(PrepayService::class)->debitFromPhoneCall($call));
        $call->is_billable = false;
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertEquals(10, $this->a->fresh()->prepay_balance);
    }

    public function test_phone_trashed_contract_reversal_does_not_alert(): void
    {
        $call = $this->phone();
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->a->delete();
        $alerts = \Mockery::mock(PrepayAlertService::class);
        $seen = [];
        $alerts->shouldReceive('checkThreshold')->andReturnUsing(function ($contract) use (&$seen) {
            $seen[] = $contract->id;
        });
        $this->app->instance(PrepayAlertService::class, $alerts);
        $call->is_billable = false;
        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $this->assertSame([], $seen);
    }

    public function test_move_from_amount_mode_keeps_columns_and_detaches_hours(): void
    {
        $this->moveFromMode(true, 9.5, 0.5);
    }

    public function test_move_from_no_prepay_keeps_columns_and_detaches_hours(): void
    {
        $this->moveFromMode(false, null, 0.5);
    }

    public function test_move_from_hours_mode_adjusts_columns(): void
    {
        $this->moveFromMode(false, 9.5, 0.5);
        $this->assertSame([$this->b->id, $this->b->id], \App\Models\ContractActivity::whereIn('action', ['entry_moved_in', 'entry_moved_out'])->get()->map(fn ($activity) => $activity->changes['requested_contract_id'] ?? null)->all());
    }

    private function moveFromMode(bool $amount, ?float $balance, float $used): void
    {
        $note = $this->note();
        $row = PrepayTransaction::create(['contract_id' => $this->a->id, 'source' => 'ticket_time',
            'ticket_note_id' => $note->id, 'date' => now(), 'hours' => -0.5]);
        $this->a->updateQuietly(['prepay_as_amount' => $amount, 'prepay_balance' => $balance, 'prepay_used' => $used]);
        app(PrepayService::class)->moveEntryContract($note, $this->b, 'Synthetic move', $this->user);
        $hoursMode = ! $amount && $balance !== null;
        $this->assertEquals($hoursMode ? 10 : $balance, $this->a->fresh()->prepay_balance);
        $this->assertEquals($hoursMode ? 0 : $used, $this->a->fresh()->prepay_used);
        $this->assertNull($row->fresh()->ticket_note_id);
        $this->assertSame($note->id, $row->fresh()->moved_ticket_note_id);
        $this->assertEquals(0.5, PrepayTransaction::where('contract_id', $this->a->id)->where('source', 'entry_moved_out')->sole()->hours);
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
    }

    public function test_unledgered_retry_reports_the_debited_contract(): void
    {
        $call = $this->phone();
        $c = $this->a->replicate();
        $c->name = 'Synthetic C';
        $c->save();
        Ticket::whereKey($this->ticket->id)->update(['contract_id' => $c->id]);
        $ordinary = new PrepayService;
        $service = \Mockery::mock(PrepayService::class)->makePartial();
        $changed = false;
        $service->shouldReceive('resolveContractForPhoneCall')->andReturnUsing(function ($entry) use ($ordinary, &$changed) {
            $contract = $ordinary->resolveContractForPhoneCall($entry);
            if (! $changed && $contract?->id === $this->b->id) {
                $changed = true;
                Contract::whereKey($this->b->id)->update(['status' => 'expired']);
            }

            return $contract;
        });
        $result = $service->moveEntryContract($call, $this->b, 'Synthetic retry', $this->user);
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertTrue($changed);
        $this->assertSame($c->id, $row->contract_id);
        $this->assertSame($row->contract_id, $call->fresh()->contract_id);
        $this->assertSame($row->contract_id, $result['to_contract_id']);
        $activities = \App\Models\ContractActivity::whereIn('action', ['entry_moved_in', 'entry_moved_out'])->get();
        $this->assertCount(2, $activities);
        foreach ($activities as $activity) {
            $this->assertSame($c->id, $activity->changes['to_contract_id']);
            $this->assertSame($c->name, $activity->changes['to_contract']);
            $this->assertSame($c->id, $activity->changes['drawn_contract_id']);
            if ($activity->action === 'entry_moved_in') {
                $this->assertSame($c->id, $activity->contract_id);
            }
        }
        $body = TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->sole()->body;
        $this->assertSame('Moved phone call #'.$call->id.' time (0.50 h) from Synthetic A to Synthetic C; it had no prepay ledger row, so 0.50h was drawn from Synthetic C: Synthetic retry', $body);
    }

    public function test_phone_retry_without_draw_reports_stamp(): void
    {
        $this->retryReportsStamp(false, false);
    }

    /** Draw reporting is an r1 control; requested_contract_id is new in r2. */
    public function test_note_retry_draw_reports_stamp(): void
    {
        $this->retryReportsStamp(true, false);
    }

    public function test_phone_retry_held_reports_no_contract_on_requested_activity(): void
    {
        $this->retryReportsStamp(false, true);
    }

    private function retryReportsStamp(bool $isNote, bool $held): void
    {
        // Replace the legacy setup's wildcard fake for these isolated, vendor-free cases.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://synthetic.invalid/*' => Http::response([], 200)]);
        Http::preventStrayRequests();
        $entry = $isNote ? $this->note() : $this->phone();
        $d = $this->a->replicate();
        $d->name = 'Synthetic D';
        $d->prepay_as_amount = ! $isNote;
        $d->save();
        Ticket::whereKey($this->ticket->id)->update(['contract_id' => $held ? null : $d->id]);
        $reads = 0;
        $expired = false;
        // Expire B at its Nth active-status read (1st for a note, 2nd for a phone call). This counts queries; it does not identify the caller. Real debit/retry code runs unmocked.
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$reads, &$expired, $isNote) {
            $sql = str_replace(['"', '`'], '', $query->sql);
            if (! $expired && str_starts_with($sql, 'select * from contracts')
                && str_contains($sql, 'status = ?') && in_array($this->b->id, $query->bindings, false)
                && ++$reads === ($isNote ? 1 : 2)) {
                $expired = true;
                Contract::whereKey($this->b->id)->update(['status' => 'expired']);
            }
        });
        $result = app(PrepayService::class)->moveEntryContract($entry, $this->b, 'Synthetic retry', $this->user);
        $this->assertTrue($expired, 'contract B expired at its counted active-status read');
        $destinationId = $held ? null : $d->id;
        $destination = $held ? 'no contract' : $d->name;
        $this->assertSame($held ? $this->b->id : $d->id, $entry->fresh()->contract_id);
        if ($held) {
            $this->assertNotNull($entry->fresh()->contract_held_at);
        }
        $key = $isNote ? 'ticket_note_id' : 'phone_call_id';
        if ($isNote) {
            $row = PrepayTransaction::where($key, $entry->id)->sole();
            $this->assertSame($d->id, $row->contract_id);
            $this->assertEquals(-0.5, $row->hours);
        } else {
            $this->assertSame(0, PrepayTransaction::where($key, $entry->id)->count());
            $this->assertEquals(10, $d->fresh()->prepay_balance);
        }
        $this->assertSame($destinationId, $result['to_contract_id']);
        $this->assertSame($isNote ? 0.5 : 0.0, $result['drawn_hours']);
        $activities = \App\Models\ContractActivity::whereIn('action', ['entry_moved_in', 'entry_moved_out'])->get();
        $this->assertCount(2, $activities);
        foreach ($activities as $activity) {
            $this->assertSame($destinationId, $activity->changes['to_contract_id']);
            $this->assertSame($held ? null : $d->name, $activity->changes['to_contract']);
            $this->assertArrayHasKey('requested_contract_id', $activity->changes);
            $this->assertSame($this->b->id, $activity->changes['requested_contract_id']);
            $this->assertSame($activity->action === 'entry_moved_in' ? ($destinationId ?? $this->b->id) : $this->a->id, $activity->contract_id);
        }
        $what = $isNote ? 'note' : 'phone call';
        $draw = $isNote ? '; it had no prepay ledger row, so 0.50h was drawn from Synthetic D' : '';
        $body = TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->sole()->body;
        $this->assertSame("Moved {$what} #{$entry->id} time (0.50 h) from Synthetic A to {$destination}{$draw}: Synthetic retry", $body);
        Http::assertNothingSent();
    }

    public function test_note_soft_deleted_after_move_reports_its_stamp(): void
    {
        $note = $this->note();
        $inserted = false;
        $deleted = false;
        // Soft-delete the note at the first read of it after the move's system note is written.
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$inserted, &$deleted, $note) {
            $sql = str_replace(['"', '`'], '', $query->sql);
            if (str_starts_with($sql, 'insert into ticket_notes')) {
                $inserted = true;
            } elseif ($inserted && ! $deleted && in_array($note->id, $query->bindings, false)
                && str_starts_with($sql, 'select * from ticket_notes where ticket_notes.id = ? and ticket_notes.deleted_at is null')) {
                $deleted = true;
                TicketNote::whereKey($note->id)->update(['deleted_at' => now()]);
            }
        });
        $result = app(PrepayService::class)->moveEntryContract($note, $this->b, 'Synthetic delete race', $this->user);
        $this->assertTrue($deleted, 'soft-delete seam reached');
        $trashed = TicketNote::withTrashed()->find($note->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($this->b->id, $trashed->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertSame($this->b->id, $result['to_contract_id']);
        $this->assertSame(0.0, $result['drawn_hours']);
        $activities = \App\Models\ContractActivity::whereIn('action', ['entry_moved_in', 'entry_moved_out'])->get();
        $this->assertCount(2, $activities);
        foreach ($activities as $activity) {
            $this->assertSame($this->b->id, $activity->changes['to_contract_id']);
            $this->assertSame($this->b->name, $activity->changes['to_contract']);
            $this->assertSame($this->b->id, $activity->changes['requested_contract_id']);
            $this->assertSame($activity->action === 'entry_moved_in' ? $this->b->id : $this->a->id, $activity->contract_id);
        }
        $body = TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->sole()->body;
        $this->assertSame("Moved note #{$note->id} time (0.50 h) from Synthetic A to Synthetic B: Synthetic delete race", $body);
    }

    public function test_backfill_excludes_detached_note_and_includes_undebited_note(): void
    {
        $detached = $this->note();
        $undebited = $this->note();
        PrepayTransaction::create(['contract_id' => $this->a->id, 'source' => 'ticket_time',
            'moved_ticket_note_id' => $detached->id, 'date' => now(), 'hours' => -0.5]);
        $this->artisan('prepay:backfill-debits', ['--since' => '2026-01-01'])->assertExitCode(0);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $detached->id)->count());
        $this->assertEquals(-0.5, PrepayTransaction::where('ticket_note_id', $undebited->id)->sole()->hours);
    }
}
