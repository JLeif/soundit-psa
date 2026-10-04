<?php

namespace Tests\Feature\Triage;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * #5067 r4: triage's post-classification billability reconcile must not un-bill a Halo-imported
 * note that a Halo prepay ledger row holds, because debitFromTicketNote() would then reverse
 * (delete) the draw Halo recorded. Synthetic data only (G-13).
 */
class TriageBillabilityHaloPrepayTest extends TestCase
{
    use RefreshDatabase;

    private int $contractId;

    private int $ticketId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $client = Client::factory()->create(['name' => 'Synthetic T']);
        $this->contractId = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic T prepay', 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0.5,
            'prepay_balance' => 9.5,
        ])->id;
        $this->ticketId = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Synthetic T ticket'])->id;
    }

    /** A billable 30-minute note; $haloSeq sets halo_note_id (a Halo-imported note). */
    private function note(?int $haloSeq): int
    {
        return DB::table('ticket_notes')->insertGetId([
            'ticket_id' => $this->ticketId, 'halo_note_id' => $haloSeq, 'body' => 'Synthetic', 'is_billable' => true,
            'time_minutes' => 30, 'contract_id' => $this->contractId, 'noted_at' => '2026-01-05 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function debit(int $noteId, string $source = 'ticket_time'): int
    {
        return DB::table('prepay_transactions')->insertGetId([
            'contract_id' => $this->contractId, 'source' => $source, 'ticket_note_id' => $noteId, 'user_id' => null,
            'date' => '2026-01-05 00:00:00', 'hours' => -0.5, 'description' => "Ticket #{$this->ticketId}: Synthetic T ticket [70099]",
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Runs the pipeline's own reconcile step with a managed-covered classification (should be non-billable). */
    private function reconcileAsManagedCovered(): void
    {
        $pipeline = app(TriagePipeline::class);
        $ref = new ReflectionClass($pipeline);
        $ref->getProperty('stageResults')->setValue($pipeline, ['classification' => ['work_covered_by_managed' => true]]);
        $ref->getMethod('reconcileBillabilityAfterClassification')->invoke($pipeline, Ticket::findOrFail($this->ticketId));
    }

    private function balance(): float
    {
        return (float) DB::table('contracts')->where('id', $this->contractId)->value('prepay_balance');
    }

    public function test_a_halo_note_held_by_a_prepay_row_keeps_its_flag_and_debit(): void
    {
        $halo = $this->note(7);
        $ptx = $this->debit($halo);

        $this->reconcileAsManagedCovered();

        $this->assertTrue((bool) DB::table('ticket_notes')->where('id', $halo)->value('is_billable'));
        $this->assertSame($halo, (int) DB::table('prepay_transactions')->where('id', $ptx)->value('ticket_note_id'));
        $this->assertSame(-0.5, (float) DB::table('prepay_transactions')->where('id', $ptx)->value('hours'));
        $this->assertSame(9.5, $this->balance());
    }

    public function test_a_halo_note_held_by_a_halo_sync_row_is_also_skipped(): void
    {
        $halo = $this->note(8);
        $ptx = $this->debit($halo, 'halo_sync');

        $this->reconcileAsManagedCovered();

        $this->assertTrue((bool) DB::table('ticket_notes')->where('id', $halo)->value('is_billable'));
        $this->assertSame($halo, (int) DB::table('prepay_transactions')->where('id', $ptx)->value('ticket_note_id'));
    }

    /** Control: the skip is not wider than Halo's linked notes. A native note is still un-billed and its debit reversed. */
    public function test_a_native_note_with_a_debit_is_still_unbilled_and_reversed(): void
    {
        $native = $this->note(null);
        $ptx = $this->debit($native);

        $this->reconcileAsManagedCovered();

        $this->assertFalse((bool) DB::table('ticket_notes')->where('id', $native)->value('is_billable'));
        $this->assertNull(DB::table('prepay_transactions')->where('id', $ptx)->first());
        $this->assertSame(10.0, $this->balance());
    }

    /** Control: a Halo-imported note that no prepay row holds is reconciled as before. */
    public function test_an_unlinked_halo_note_is_still_reconciled(): void
    {
        $halo = $this->note(9);

        $this->reconcileAsManagedCovered();

        $this->assertFalse((bool) DB::table('ticket_notes')->where('id', $halo)->value('is_billable'));
    }
}
