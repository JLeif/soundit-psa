<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card I3EvQKUV §5: prepay:stamp-entry-contracts. Synthetic data only (G-13).
 */
class StampEntryContractsTest extends TestCase
{
    use RefreshDatabase;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $ticket = Ticket::factory()->create();
        $base = [
            'client_id' => $ticket->client_id, 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ];
        $a = Contract::create($base + ['name' => 'Synthetic A']);
        $b = Contract::create($base + ['name' => 'Synthetic B']);
        $ticket->update(['contract_id' => $a->id]);

        $mk = fn (int $minutes) => TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $ticket->id, 'is_billable' => true,
            'time_minutes' => $minutes, 'noted_at' => now(),
        ]);
        $legacy = $mk(60);      // ledger on A, stamp erased -> would stamp A
        $equal = $mk(30);       // ledger on A, stamp A -> equal
        $mismatch = $mk(90);    // ledger on A, stamp B -> the #4332 state, left alone
        $unbilled = TicketNote::forceCreate(['body' => 'Synthetic', 'ticket_id' => $ticket->id, 'noted_at' => now()]);
        DB::table('ticket_notes')->where('id', $legacy->id)->update(['contract_id' => null]);
        DB::table('ticket_notes')->where('id', $mismatch->id)->update(['contract_id' => $b->id]);

        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-backfill', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'ticket_id' => $ticket->id, 'is_billable' => true, 'duration' => 1800, 'started_at' => now(),
        ]));
        app(PrepayService::class)->debitFromPhoneCall($call);
        DB::table('phone_calls')->where('id', $call->id)->update(['contract_id' => null]);

        // A second client with two active contracts and no default: listed by id.
        $other = Client::factory()->create();
        Contract::create(array_merge($base, ['client_id' => $other->id, 'name' => 'Synthetic C']));
        Contract::create(array_merge($base, ['client_id' => $other->id, 'name' => 'Synthetic D']));

        $this->ids = compact('a', 'b', 'legacy', 'equal', 'mismatch', 'unbilled', 'call', 'ticket', 'other');
    }

    private function snapshot(): array
    {
        return [
            DB::table('prepay_transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('contracts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('ticket_notes')->orderBy('id')->pluck('contract_id', 'id')->all(),
            DB::table('phone_calls')->orderBy('id')->pluck('contract_id', 'id')->all(),
        ];
    }

    public function test_b1_dry_run_writes_nothing_and_reports_counts(): void
    {
        $before = $this->snapshot();
        $exit = Artisan::call('prepay:stamp-entry-contracts', ['--dry-run' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString('DRY RUN: nothing was written.', $out);
        $this->assertMatchesRegularExpression('/\|\s*notes\s*\|\s*3\s*\|\s*1\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*calls\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|/', $out);
        $this->assertStringContainsString("MISMATCH ticket_notes #{$this->ids['mismatch']->id}: stamp contract {$this->ids['b']->id}, ledger contract {$this->ids['a']->id} (left as is)", $out);
        $this->assertStringContainsString("clients with >1 active contract and no valid default: 2 (ids: {$this->ids['ticket']->client_id}, {$this->ids['other']->id})", $out);
    }

    public function test_b2_real_run_stamps_nulls_only_leaves_mismatch_and_money_unchanged_then_is_idempotent(): void
    {
        [$ledger, $contracts] = $this->snapshot();
        $exit = Artisan::call('prepay:stamp-entry-contracts');
        $out = Artisan::output();

        $this->assertSame(0, $exit, $out);
        $this->assertSame($this->ids['a']->id, $this->ids['legacy']->fresh()->contract_id);
        $this->assertSame($this->ids['a']->id, $this->ids['equal']->fresh()->contract_id);
        $this->assertSame($this->ids['b']->id, $this->ids['mismatch']->fresh()->contract_id);
        $this->assertNull($this->ids['unbilled']->fresh()->contract_id);
        $this->assertSame($this->ids['a']->id, $this->ids['call']->fresh()->contract_id);
        [$ledgerAfter, $contractsAfter] = $this->snapshot();
        $this->assertSame($ledger, $ledgerAfter);
        $this->assertSame($contracts, $contractsAfter);
        $this->assertStringContainsString('verify: per-contract SUM(hours) unchanged: yes', $out);
        $this->assertStringContainsString('verify: contract balance columns unchanged: yes', $out);

        $snap = $this->snapshot();
        Artisan::call('prepay:stamp-entry-contracts');
        $again = Artisan::output();
        $this->assertSame($snap, $this->snapshot());
        $this->assertMatchesRegularExpression('/\|\s*notes\s*\|\s*3\s*\|\s*0\s*\|\s*2\s*\|\s*1\s*\|\s*0\s*\|/', $again);
        $this->assertMatchesRegularExpression('/\|\s*calls\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|/', $again);
    }
}
