<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Setting;
use App\Models\User;
use App\Services\ContactIntake\IntakeReconciliation;
use App\Services\ContactIntake\SubmissionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Card qmcqiE7s: accepted (independent counter) versus the ledger's rows by state. */
class IntakeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('contact_intake_enabled', '1');
    }

    private function payload(array $over = []): array
    {
        return $over + ['submission_id' => (string) Str::uuid(), 'name' => 'Synthetic Visitor',
            'email' => 'visitor@example.test', 'message' => 'Synthetic inquiry',
            'submitted_at' => '2026-09-24T12:00:00Z'];
    }

    private function accept(array $data): array
    {
        return app(SubmissionLedger::class)->accept('website', $data);
    }

    private function measure(): array
    {
        return app(IntakeReconciliation::class)->measure();
    }

    private function index()
    {
        return $this->actingAs(User::factory()->admin()->create(['is_active' => true]))
            ->get(route('contact-intake.index'))->assertOk();
    }

    public function test_balances_after_accepts(): void
    {
        $this->accept($this->payload());
        $this->accept($this->payload(['email' => 'other@example.test']));
        $m = $this->measure();
        $this->assertSame(2, $m['accepted']);
        $this->assertSame(2, $m['ledger']);
        $this->assertTrue($m['balanced']);
        $this->index()->assertSee('data-reconciliation="balanced"', false)
            ->assertDontSee('data-reconciliation="mismatch"', false)
            ->assertSee('Accepted 2 = pending + processing + processed + quarantined 2.');
    }

    public function test_identical_redelivery_does_not_count_as_accepted(): void
    {
        $data = $this->payload();
        $this->accept($data);
        $second = $this->accept($data);
        $this->assertTrue($second['duplicate']);
        $this->assertFalse($second['conflict']);
        $m = $this->measure();
        $this->assertSame(1, $m['accepted']);
        $this->assertTrue($m['balanced']);
    }

    public function test_conflicting_redelivery_does_not_count_as_accepted(): void
    {
        $data = $this->payload();
        $this->accept($data);
        $second = $this->accept(['message' => 'Changed text'] + $data);
        $this->assertTrue($second['conflict']);
        $m = $this->measure();
        $this->assertSame(1, $m['accepted']);
        $this->assertTrue($m['balanced']);
    }

    public function test_counter_failure_rolls_back_the_ledger_insert(): void
    {
        DB::table('contact_intake_counters')->where('name', 'accepted')->delete();
        $thrown = null;
        try {
            $this->accept($this->payload());
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }
        $this->assertSame('Contact intake accepted counter row is missing.', $thrown);
        // The insert and the count commit together or not at all.
        $this->assertSame(0, DB::table('contact_submissions')->count());
        $m = $this->measure();
        $this->assertNull($m['accepted']);
        $this->assertFalse($m['balanced']);
        $this->index()->assertSee('data-reconciliation="mismatch"', false)
            ->assertSee('Accepted unknown (counter row missing); pending + processing + processed + quarantined 0.');
    }

    public function test_removed_row_turns_red_and_page_states_both_numbers(): void
    {
        $this->accept($this->payload());
        $this->accept($this->payload(['email' => 'other@example.test']));
        DB::table('contact_submissions')->where('id', DB::table('contact_submissions')->min('id'))->delete();
        $m = $this->measure();
        $this->assertSame(2, $m['accepted']);
        $this->assertSame(1, $m['ledger']);
        $this->assertFalse($m['balanced']);
        $this->index()->assertSee('data-reconciliation="mismatch"', false)
            ->assertDontSee('data-reconciliation="balanced"', false)
            ->assertSee('Accepted 2; pending + processing + processed + quarantined 1.');
    }

    public function test_row_in_unknown_state_turns_red_even_when_counts_agree(): void
    {
        $this->accept($this->payload());
        // One extra row the counter never saw, in a state no writer uses: known states still sum to accepted.
        $row = (array) DB::table('contact_submissions')->first();
        unset($row['id']);
        DB::table('contact_submissions')->insert(['submission_id' => (string) Str::uuid(),
            'receipt' => (string) Str::uuid(), 'state' => 'archived'] + $row);
        $m = $this->measure();
        $this->assertSame($m['accepted'], $m['ledger']);
        $this->assertSame(['archived' => 1], $m['unknown']);
        $this->assertFalse($m['balanced']);
        $this->index()->assertSee('data-reconciliation="mismatch"', false)
            ->assertSee('Rows in an unrecognised state: archived: 1');
    }

    public function test_committed_processing_row_turns_red_even_when_counts_agree(): void
    {
        $this->accept($this->payload());
        DB::table('contact_submissions')->update(['state' => 'processing']);
        $m = $this->measure();
        $this->assertSame(1, $m['accepted']);
        $this->assertSame(1, $m['ledger']);
        $this->assertSame(1, $m['processing']);
        $this->assertFalse($m['balanced']);
        $this->index()->assertSee('data-reconciliation="mismatch"', false)
            ->assertSee('1 row(s) committed in processing');
    }
}
