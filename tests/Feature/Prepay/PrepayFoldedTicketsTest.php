<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 2, the four #4868 residuals folded in: #4920 (release
 * failure after a committed default), #4931 (status re-checked at debit),
 * #4922 (call hold written under the call lock) and #4918 (stale defaults are
 * ambiguous). Synthetic data only (G-13).
 */
class PrepayFoldedTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->ticket = Ticket::factory()->create();
        $this->client = $this->ticket->client;
    }

    private function contract(string $name, array $overrides = [], ?int $clientId = null): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $clientId ?? $this->client->id, 'name' => $name,
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ], $overrides));
    }

    private function phoneCall(): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
            'ticket_id' => $this->ticket->id,
        ]));
    }

    /** SQL text without identifier quoting, so the probes read the same on SQLite and MariaDB. */
    private static function plain(string $sql): string
    {
        return str_replace(['"', '`'], '', $sql);
    }

    private function logs(): TestHandler
    {
        $handler = new TestHandler;
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);

        return $handler;
    }

    /** #4920: a failing release after the default commits is logged, not a 500. */
    public function test_4920_release_failure_after_committed_default_is_caught_and_logged(): void
    {
        $a = $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $this->mock(PrepayService::class, function ($mock) {
            $mock->shouldReceive('releaseHeldDebits')->andThrow(new \RuntimeException('synthetic lock wait timeout'));
        });
        $logs = $this->logs();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('clients.default-contract.update', $this->client), ['default_contract_id' => $a->id]);

        $response->assertRedirect(route('clients.show', $this->client));
        $this->assertSame($a->id, $this->client->fresh()->default_contract_id, 'the default stays committed');
        $this->assertTrue($logs->hasWarningThatContains('[Prepay] Held debits release failed'));
        $record = collect($logs->getRecords())->first(fn ($r) => $r->message === '[Prepay] Held debits release failed');
        $this->assertSame($this->client->id, $record->context['client_id']);
        $this->assertSame('synthetic lock wait timeout', $record->context['error']);
    }

    /**
     * #4931: the contract expires between resolution and the debit's lock. The note is
     * never drawn from it; it re-resolves once (here to the client default) instead.
     */
    public function test_4931_note_debit_rechecks_status_under_lock_and_re_resolves(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $this->client->forceFill(['default_contract_id' => $b->id])->save();
        $this->ticket->update(['contract_id' => $a->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'body' => 'Synthetic', 'ticket_id' => $this->ticket->id, 'is_billable' => true,
            'time_minutes' => 60, 'noted_at' => now(), 'contract_id' => $a->id,
        ]));
        // The note's only resolver read of A is the stamp check.
        $this->expireAfterActiveRead($a, 1);
        $logs = $this->logs();

        app(PrepayService::class)->debitFromTicketNote($note);

        $this->assertSame($b->id, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->contract_id);
        $this->assertEquals(10, (float) $a->fresh()->prepay_balance, 'the expired contract is never drawn down');
        $this->assertEquals(9, (float) $b->fresh()->prepay_balance);
        $this->assertSame($b->id, $note->fresh()->contract_id);
        $this->assertTrue($logs->hasInfoThatContains('[Prepay] Debit target not active at debit'));
    }

    /** #4931, calls; and with no other contract left to settle it, the entry is held, not drawn. */
    public function test_4931_call_debit_rechecks_status_and_holds_when_ambiguous(): void
    {
        $a = $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $this->contract('Synthetic C');
        $this->ticket->update(['contract_id' => $a->id]);
        $call = $this->phoneCall();
        // A call reads A twice before the lock (stamp, then the money target); expire after the last.
        $this->expireAfterActiveRead($a, 2);
        $logs = $this->logs();

        $this->assertNull(app(PrepayService::class)->debitFromPhoneCall($call));
        $this->assertTrue($logs->hasInfoThatContains('[Prepay] Debit target not active at debit'), 'the race window was reached');

        $this->assertSame(0, PrepayTransaction::count(), 'nothing drawn from the expired contract');
        $this->assertEquals(10, (float) $a->fresh()->prepay_balance);
        $this->assertNotNull($call->fresh()->contract_held_at, 'held under "Needs contract" (B and C, no default)');
    }

    /**
     * Expire $contract (query builder, no observers) right after the resolver has read it
     * as active (DB::listen runs after the query), i.e. between resolution and the debit's
     * contract lock: the #4931 window.
     */
    private function expireAfterActiveRead(Contract $contract, int $nth): void
    {
        $seen = 0;
        DB::listen(function ($q) use (&$seen, $nth, $contract) {
            $sql = self::plain($q->sql);
            if (str_starts_with($sql, 'select * from contracts') && str_contains($sql, 'status = ?')
                && in_array($contract->id, $q->bindings, false) && ++$seen === $nth) {
                DB::table('contracts')->where('id', $contract->id)->update(['status' => 'expired']);
            }
        });
    }

    /**
     * #4922: a debit that finds the call already ledgered (a racing debit won) clears a
     * stale hold marker under the call lock, and the call badge does not show
     * "Needs contract" for a ledgered call.
     */
    public function test_4922_ledgered_call_never_keeps_a_hold_marker(): void
    {
        $a = $this->contract('Synthetic A');
        $call = $this->phoneCall();
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertSame($a->id, PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id'));
        // The losing racer's stale write, as #4922 describes it.
        PhoneCall::whereKey($call->id)->update(['contract_held_at' => now()]);

        app(PrepayService::class)->debitFromPhoneCall($call->fresh());

        $this->assertNull($call->fresh()->contract_held_at);
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count());
    }

    /** #4922: the hold marker and stamp are written only after the call row lock is taken. */
    public function test_4922_call_hold_and_stamp_are_written_inside_the_call_lock(): void
    {
        $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $call = $this->phoneCall();
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no row locks: annotate compiled lock requests (as TicketNotePrepayRaceTest does).
            DB::connection()->setQueryGrammar(new class(DB::connection()) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
            {
                protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
                {
                    return $value ? '/* for update */' : '';
                }
            });
        }
        $events = [];
        DB::listen(function ($q) use (&$events) {
            $events[] = self::plain($q->sql);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$events) {
            $events[] = 'BEGIN';
        });

        app(PrepayService::class)->debitFromPhoneCall($call);

        $this->assertNotNull($call->fresh()->contract_held_at);
        $marker = collect($events)->search(fn ($sql) => str_starts_with($sql, 'update phone_calls') && str_contains($sql, 'contract_held_at'));
        $this->assertNotFalse($marker);
        $begin = array_search('BEGIN', $events, true);
        $this->assertNotFalse($begin);
        $this->assertLessThan($marker, $begin, 'the marker is written inside the transaction');
        $this->assertStringStartsWith('select * from phone_calls', $events[$begin + 1], 'whose first statement locks the call');
        $this->assertStringContainsString('for update', $events[$begin + 1], 'as a locking read');
    }

    /** #4918: a client whose default is set but invalid is listed as ambiguous. */
    public function test_4918_ambiguous_list_includes_stale_defaults(): void
    {
        $other = Client::create(['name' => 'Synthetic Other']);
        $this->contract('Synthetic A');
        $this->contract('Synthetic B');
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        $foreign = $this->contract('Synthetic Foreign', [], $other->id);

        $cases = [
            'expired default' => $expired->id,
            'foreign default' => $foreign->id,
        ];
        foreach ($cases as $why => $defaultId) {
            DB::table('clients')->where('id', $this->client->id)->update(['default_contract_id' => $defaultId]);
            Artisan::call('prepay:stamp-entry-contracts', ['--dry-run' => true]);
            $this->assertStringContainsString("no valid default: 1 (ids: {$this->client->id})", Artisan::output(), $why);
        }

        $deleted = $this->contract('Synthetic Deleted');
        DB::table('clients')->where('id', $this->client->id)->update(['default_contract_id' => $deleted->id]);
        DB::table('contracts')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        Artisan::call('prepay:stamp-entry-contracts', ['--dry-run' => true]);
        $this->assertStringContainsString("no valid default: 1 (ids: {$this->client->id})", Artisan::output(), 'soft-deleted default');

        // Control: a valid default is not ambiguous.
        $a = Contract::where('name', 'Synthetic A')->first();
        DB::table('clients')->where('id', $this->client->id)->update(['default_contract_id' => $a->id]);
        Artisan::call('prepay:stamp-entry-contracts', ['--dry-run' => true]);
        $this->assertStringContainsString('no valid default: 0', Artisan::output());
    }
}
