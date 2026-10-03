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
        parent::tearDown();
    }

    private function import(): string
    {
        $exit = Artisan::call('prepay:backfill-from-halo', ['--csv' => $this->csv]);
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
}
