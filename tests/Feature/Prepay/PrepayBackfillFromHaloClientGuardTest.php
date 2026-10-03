<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #5067: prepay:backfill-from-halo must not import a debit whose local ticket belongs to
 * a different client than the debited contract. Synthetic data only (G-9/G-13).
 */
class PrepayBackfillFromHaloClientGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    private ?string $mapPath = null;

    private Contract $contract;

    private Ticket $own;

    private Ticket $other;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $p = Client::factory()->create(['name' => 'Synthetic P']);
        $q = Client::factory()->create(['name' => 'Synthetic Q']);
        $this->contract = Contract::create([
            'client_id' => $p->id, 'halo_id' => 501, 'name' => 'Synthetic P prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->own = Ticket::factory()->create(['client_id' => $p->id, 'subject' => 'Own client subject']);
        $this->other = Ticket::factory()->create(['client_id' => $q->id, 'subject' => 'Other client subject']);
        DB::table('tickets')->where('id', $this->own->id)->update(['halo_id' => 9101]);
        DB::table('tickets')->where('id', $this->other->id)->update(['halo_id' => 9102]);

        $this->csv = tempnam(sys_get_temp_dir(), 'halo5067');
        file_put_contents($this->csv, implode("\n", [
            'client,ticket,action,prepay_hours,time_taken,date,contract',
            '1,9101,80001,0.5,0.5,1/5/2026 10:00 AM,501',
            '1,9102,80002,0.25,0.25,1/6/2026 10:00 AM,501',
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        if ($this->mapPath !== null) {
            @unlink($this->mapPath);
        }
        parent::tearDown();
    }

    /** A synthetic halo-action-map/v1 file. */
    private function map(array $rows): string
    {
        $this->mapPath = tempnam(sys_get_temp_dir(), 'halo5067map');
        file_put_contents($this->mapPath, json_encode([
            'format' => 'halo-action-map/v1',
            'columns' => ['halo_action_id', 'halo_ticket_id', 'actionnumber', 'timetaken', 'timetakenAdjusted'],
            'rows' => $rows,
        ]));

        return $this->mapPath;
    }

    private function import(array $args = []): string
    {
        $exit = Artisan::call('prepay:backfill-from-halo', ['--csv' => $this->csv] + $args);
        $out = Artisan::output();
        $this->assertSame(0, $exit, $out);

        return $out;
    }

    public function test_still_imports_the_same_client_row(): void
    {
        $this->import();

        $row = DB::table('prepay_transactions')->where('contract_id', $this->contract->id)
            ->where('description', "Ticket #{$this->own->id}: Own client subject [80001]")->first();
        $this->assertNotNull($row);
        $this->assertSame(-0.5, (float) $row->hours);
    }

    public function test_skips_a_cross_client_row_and_reports_its_action_id(): void
    {
        $out = $this->import();

        // The cross-client row is neither imported nor named in this contract's ledger.
        $this->assertSame(1, DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->count());
        $this->assertSame(0, DB::table('prepay_transactions')->where('description', 'like', '%Other client subject%')->count());
        $this->assertStringContainsString('client_mismatch: 1 rows not imported', $out);
        $this->assertStringContainsString('Halo action ids: 80002', $out);
        $this->assertStringNotContainsString('Other client subject', $out);
    }

    /** Import, then what prepay:relink-halo-ticket-time and a later same-client note edit do to the row. */
    private function relinkAndEdit(): void
    {
        $this->import();
        $row = DB::table('prepay_transactions')->where('contract_id', $this->contract->id)
            ->where('description', 'like', '%[80001]')->first();
        $this->assertNotNull($row);

        $noteId = DB::table('ticket_notes')->insertGetId([
            'ticket_id' => $this->own->id, 'halo_note_id' => 1, 'body' => 'Synthetic', 'is_billable' => true,
            'time_minutes' => 30, 'noted_at' => '2026-01-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Relink fills ticket_note_id; PrepayService::debitFromTicketNote, on an edit of that note,
        // rewrites the description without the [action id].
        DB::table('prepay_transactions')->where('id', $row->id)->update([
            'ticket_note_id' => $noteId, 'description' => "Ticket #{$this->own->id}: Own client subject",
        ]);
    }

    public function test_a_rerun_with_the_map_does_not_debit_a_relinked_and_edited_row_again(): void
    {
        $this->relinkAndEdit();

        $out = $this->import(['--map' => $this->map([[80001, 9101, 1, 0.5, 0.0]])]);

        $this->assertSame(1, DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->count(), $out);
        $this->assertSame(-0.5, (float) DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->sum('hours'));
        $this->assertStringNotContainsString('unattributed', $out);
    }

    public function test_a_rerun_without_the_map_imports_nothing_on_that_ticket_and_reports_the_action(): void
    {
        $this->relinkAndEdit();

        $out = $this->import();

        $this->assertSame(1, DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->count(), $out);
        $this->assertSame(-0.5, (float) DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->sum('hours'));
        $this->assertMatchesRegularExpression('/^unattributed: 1 rows not imported .*; Halo action ids: 80001$/m', $out);
    }

    public function test_an_app_written_description_ending_in_digits_does_not_suppress_a_debit(): void
    {
        // A native note's debit: its description ends in the free-text subject, here "[80001]".
        $noteId = DB::table('ticket_notes')->insertGetId([
            'ticket_id' => $this->own->id, 'halo_note_id' => null, 'body' => 'Synthetic', 'is_billable' => true,
            'time_minutes' => 15, 'noted_at' => '2026-01-04 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('prepay_transactions')->insert([
            'contract_id' => $this->contract->id, 'source' => 'ticket_time', 'ticket_note_id' => $noteId, 'user_id' => null,
            'date' => '2026-01-04 00:00:00', 'hours' => -0.25, 'description' => "Ticket #{$this->own->id}: PO approval [80001]",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $out = $this->import();

        $this->assertSame(1, DB::table('prepay_transactions')->where('contract_id', $this->contract->id)
            ->where('description', "Ticket #{$this->own->id}: Own client subject [80001]")->count(), $out);
        $this->assertSame(-0.75, (float) DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->sum('hours'));
    }

    public function test_a_malformed_map_is_refused_and_nothing_is_imported(): void
    {
        $exit = Artisan::call('prepay:backfill-from-halo', [
            '--csv' => $this->csv, '--map' => $this->map([[80001, '9101', 1, 0.5, 0.0]]),
        ]);
        $out = Artisan::output();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('Map row 0 is malformed.', $out);
        $this->assertSame(0, DB::table('prepay_transactions')->where('contract_id', $this->contract->id)->count());
    }
}
