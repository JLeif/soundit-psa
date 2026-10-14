<?php

namespace Tests\Feature\Prepay;

use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrepayDashboardLastTopUpTest extends TestCase
{
    use RefreshDatabase;

    public static function sources(): array
    {
        return [
            'invoice hours' => [PrepayTransactionSource::InvoiceDeposit, false],
            'manual hours' => [PrepayTransactionSource::ManualCredit, false],
            'null hours' => [null, false],
            'invoice amount' => [PrepayTransactionSource::InvoiceDeposit, true],
            'manual amount' => [PrepayTransactionSource::ManualCredit, true],
            'null amount' => [null, true],
        ];
    }

    #[DataProvider('sources')]
    public function test_last_top_up_excludes_only_entry_moved_out(?PrepayTransactionSource $source, bool $asAmount): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
        $contract = Contract::create([
            'client_id' => Client::create(['name' => 'Synthetic dashboard client'])->id,
            'name' => 'Synthetic dashboard block', 'type' => 'managed',
            'status' => 'active', 'start_date' => now()->subMonth()->toDateString(),
            'prepay_total' => 10, 'prepay_balance' => 10, 'prepay_used' => 0,
            'prepay_as_amount' => $asAmount,
        ]);
        $credit = ['contract_id' => $contract->id, $asAmount ? 'amount' : 'hours' => 10];
        $topUp = PrepayTransaction::create($credit + ['source' => $source ?? PrepayTransactionSource::ManualCredit, 'date' => now()->subDays(3)]);
        if ($source === null) {
            // The current schema forbids null; inject a legacy null at hydration, without changing schema.
            PrepayTransaction::retrieved(function (PrepayTransaction $row) use ($topUp) {
                if ($row->id === $topUp->id) {
                    $row->source = null;
                }
            });
        }
        $this->actingAs(User::factory()->create());

        // Positive control before the reversal exists: each purchase source, including null, counts.
        $this->assertLastTopUp(now()->subDays(3)->format('M j, Y'));

        PrepayTransaction::create($credit + ['source' => PrepayTransactionSource::EntryMovedOut, 'date' => now()]);
        $this->assertLastTopUp(now()->subDays(3)->format('M j, Y'));
    }

    private function assertLastTopUp(string $expected): void
    {
        $response = $this->get(route('prepay.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $cells = $xpath->query('//table[thead/tr/th[normalize-space(.)="Last Top-Up"]]/tbody/tr/td[6]');
        $this->assertSame(1, $cells->length);
        $this->assertSame($expected, trim($cells->item(0)->textContent));
    }
}
