<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #5067: prepay:backfill-from-halo is retired. Given a Halo CSV that the pre-retirement
 * importer would have imported (one same-client row), it must fail, write no ledger row and
 * move no balance, with or without --dry-run, and its output must say it is retired.
 * Synthetic data only (G-9/G-13).
 */
class PrepayBackfillFromHaloClientGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $p = Client::factory()->create(['name' => 'Synthetic P']);
        $this->contract = Contract::create([
            'client_id' => $p->id, 'halo_id' => 501, 'name' => 'Synthetic P prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $own = Ticket::factory()->create(['client_id' => $p->id, 'subject' => 'Own client subject']);
        DB::table('tickets')->where('id', $own->id)->update(['halo_id' => 9101]);

        $this->csv = tempnam(sys_get_temp_dir(), 'halo5067');
        file_put_contents($this->csv, implode("\n", [
            'client,ticket,action,prepay_hours,time_taken,date,contract',
            '1,9101,80001,0.5,0.5,1/5/2026 10:00 AM,501',
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function optionSets(): array
    {
        return [
            'no options' => [[]],
            '--dry-run' => [['--dry-run' => true]],
            '--dry-run --verified-only' => [['--dry-run' => true, '--verified-only' => true]],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('optionSets')]
    public function test_refuses_and_writes_no_ledger_row(array $options): void
    {
        $exit = Artisan::call('prepay:backfill-from-halo', ['--csv' => $this->csv] + $options);
        $out = Artisan::output();

        $this->assertNotSame(0, $exit, $out);
        $this->assertSame(0, DB::table('prepay_transactions')->count(), $out);
        $this->assertSame(10.0, (float) $this->contract->fresh()->prepay_balance);
        $this->assertStringNotContainsString('Parsed', $out);
    }

    public function test_output_names_the_retirement(): void
    {
        foreach ([[], ['--dry-run' => true]] as $options) {
            Artisan::call('prepay:backfill-from-halo', ['--csv' => $this->csv] + $options);
            $out = Artisan::output();

            $this->assertStringContainsString('prepay:backfill-from-halo is retired', $out);
            $this->assertStringContainsString('#5067', $out);
        }
    }

    public function test_refuses_before_reading_the_csv(): void
    {
        $exit = Artisan::call('prepay:backfill-from-halo', ['--csv' => $this->csv.'.absent', '--dry-run' => true]);
        $out = Artisan::output();

        $this->assertNotSame(0, $exit, $out);
        $this->assertStringContainsString('is retired', $out);
        $this->assertStringNotContainsString('CSV file not found', $out);
    }
}
