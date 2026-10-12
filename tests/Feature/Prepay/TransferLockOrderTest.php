<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransferLockOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverse_transfer_selects_contracts_ascending_before_writes(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic transfer client']);
        $attributes = ['client_id' => $client->id, 'name' => 'Synthetic block', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_total' => 10,
            'prepay_balance' => 10, 'prepay_used' => 0, 'prepay_as_amount' => false];
        $low = Contract::create($attributes);
        $high = Contract::create($attributes);
        $grammar = new class(DB::connection()) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
        {
            public array $contractLocks = [];

            public function compileSelect(\Illuminate\Database\Query\Builder $query)
            {
                if ($query->from === 'contracts') {
                    $this->contractLocks[] = $query->lock;
                }

                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = [$query->sql, $query->bindings];
        });

        app(PrepayService::class)->transfer($high, $low, 2, 'Synthetic transfer');

        $this->assertSame(true, $grammar->contractLocks[0] ?? null);
        $this->assertStringContainsString('select * from "contracts"', $queries[0][0]);
        $this->assertStringContainsString('order by "id" asc', $queries[0][0]);
        $this->assertStringContainsString('"id" in (?, ?)', $queries[0][0]);
        $this->assertEqualsCanonicalizing([$low->id, $high->id], $queries[0][1]);
        $this->assertEquals(8, $high->fresh()->prepay_balance);
        $this->assertEquals(12, $low->fresh()->prepay_balance);
    }
}
