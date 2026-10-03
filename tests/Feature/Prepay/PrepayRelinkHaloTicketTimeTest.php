<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #5067: prepay:relink-halo-ticket-time. Synthetic data and a synthetic map only (G-9/G-13).
 */
class PrepayRelinkHaloTicketTimeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> ledger row id per scenario */
    private array $ptx = [];

    /** @var array<string, int> note id per name */
    private array $notes = [];

    private string $mapPath;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $this->dir = sys_get_temp_dir().'/relink5067-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $p = Client::factory()->create(['name' => 'Synthetic P']);
        $q = Client::factory()->create(['name' => 'Synthetic Q']);
        $base = [
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ];
        $cp = Contract::create($base + ['client_id' => $p->id, 'name' => 'Synthetic P prepay']);
        $cp2 = Contract::create($base + ['client_id' => $p->id, 'name' => 'Synthetic P second prepay']);

        $tp = Ticket::factory()->create(['client_id' => $p->id, 'subject' => 'Synthetic P ticket']);
        $tq = Ticket::factory()->create(['client_id' => $q->id, 'subject' => 'Synthetic Q ticket']);
        DB::table('tickets')->where('id', $tp->id)->update(['halo_id' => 9001]);
        DB::table('tickets')->where('id', $tq->id)->update(['halo_id' => 9002]);

        $note = fn (int $ticketId, int $seq, ?string $deletedAt = null, array $extra = []) => DB::table('ticket_notes')->insertGetId($extra + [
            'ticket_id' => $ticketId, 'halo_note_id' => $seq, 'body' => 'Synthetic', 'is_billable' => true,
            'time_minutes' => 30, 'noted_at' => '2026-01-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
        $this->notes = [
            'link' => $note($tp->id, 1),
            'tm' => $note($tp->id, 2),
            'used' => $note($tp->id, 3),
            'trashed' => $note($tp->id, 4, '2026-02-01 00:00:00'),
            'excl' => $note($tp->id, 5),
            'excl_default' => $note($tp->id, 6),
            'multi_a' => $note($tp->id, 7),
            'multi_b' => $note($tp->id, 7),
            'q' => $note($tq->id, 1),
            'unbillable' => $note($tp->id, 9, null, ['is_billable' => null]),
            'zero' => $note($tp->id, 10, null, ['time_minutes' => 0]),
            'longer' => $note($tp->id, 11, null, ['time_minutes' => 45]),
            'stamped' => $note($tp->id, 12, null, ['contract_id' => $cp2->id]),
        ];

        $row = fn (string $desc, array $extra = []) => DB::table('prepay_transactions')->insertGetId($extra + [
            'contract_id' => $cp->id, 'source' => 'ticket_time', 'ticket_note_id' => null, 'user_id' => null,
            'date' => '2026-01-05 00:00:00', 'hours' => -0.5, 'description' => $desc,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ptx = [
            'link' => $row("Ticket #{$tp->id}: Synthetic P ticket [70001]"),
            'no_map' => $row("Ticket #{$tp->id}: Synthetic P ticket [70002]"),
            'ticket_mismatch' => $row("Ticket #{$tp->id}: Synthetic P ticket [70003]"),
            'client_mismatch' => $row("Ticket #{$tq->id}: Synthetic Q ticket [70004]"),
            'no_note' => $row("Ticket #{$tp->id}: Synthetic P ticket [70005]"),
            'already_linked' => $row("Ticket #{$tp->id}: Synthetic P ticket [70006]"),
            'excluded' => $row("Ticket #{$tp->id}: Synthetic P ticket [70007]"),
            'trashed' => $row("Ticket #{$tp->id}: Synthetic P ticket [70009]"),
            'multi' => $row("Ticket #{$tp->id}: Synthetic P ticket [70010]"),
            'holder' => $row("Ticket #{$tp->id}: Synthetic P ticket", ['ticket_note_id' => $this->notes['used']]),
            'no_suffix' => $row('Synthetic manual entry'),
            'not_billable' => $row("Ticket #{$tp->id}: Synthetic P ticket [70012]"),
            'zero_time' => $row("Ticket #{$tp->id}: Synthetic P ticket [70013]"),
            'time_mismatch' => $row("Ticket #{$tp->id}: Synthetic P ticket [70014]"),
            'stamp_mismatch' => $row("Ticket #{$tp->id}: Synthetic P ticket [70015]"),
        ];
        // The documented default exclusion (card I3EvQKUV) is a fixed ledger id.
        $this->ptx['excluded_default'] = $row("Ticket #{$tp->id}: Synthetic P ticket [70008]", ['id' => 2653]);

        $this->mapPath = $this->dir.'/map.json';
        $this->writeMap([
            [70001, 9001, 1, 0.5, 0.0],
            [70003, 9999, 2, 0.5, 0.0],
            [70004, 9002, 1, 0.5, 0.0],
            [70005, 9001, 50, 0.5, 0.0],
            [70006, 9001, 3, 0.5, 0.0],
            [70007, 9001, 5, 0.5, 0.0],
            [70008, 9001, 6, 0.5, 0.0],
            [70009, 9001, 4, 0.5, 0.0],
            [70010, 9001, 7, 0.5, 0.0],
            [70012, 9001, 9, 0.5, 0.0],
            [70013, 9001, 10, 0.5, 0.0],
            [70014, 9001, 11, 0.5, 0.0],
            [70015, 9001, 12, 0.5, 0.0],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeMap(array $rows): void
    {
        file_put_contents($this->mapPath, json_encode([
            'format' => 'halo-action-map/v1',
            'columns' => ['halo_action_id', 'halo_ticket_id', 'actionnumber', 'timetaken', 'timetakenAdjusted'],
            'rows' => $rows,
        ]));
    }

    private function ledger(): array
    {
        return DB::table('prepay_transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function run5067(array $args = []): array
    {
        $exit = Artisan::call('prepay:relink-halo-ticket-time', ['--map' => $this->mapPath] + $args);

        return [$exit, Artisan::output()];
    }

    private function commit(?string $rollback = null): array
    {
        return $this->run5067([
            '--commit' => true,
            '--rollback-file' => $rollback ?? $this->dir.'/rollback.json',
            '--exclude' => (string) $this->ptx['excluded'],
        ]);
    }

    private function noteOf(string $key): ?int
    {
        $v = DB::table('prepay_transactions')->where('id', $this->ptx[$key])->value('ticket_note_id');

        return $v === null ? null : (int) $v;
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $before = $this->ledger();
        [$exit, $out] = $this->run5067(['--exclude' => (string) $this->ptx['excluded']]);

        $this->assertSame(0, $exit, $out);
        $this->assertSame($before, $this->ledger());
        $this->assertFileDoesNotExist($this->dir.'/rollback.json');
        $this->assertStringContainsString('DRY RUN: nothing will be written', $out);
        $this->assertStringContainsString('candidates: 14', $out);
        $this->assertStringContainsString('would link: 1', $out);
    }

    public function test_commit_links_the_unique_match_only_and_writes_the_rollback_file(): void
    {
        $before = DB::table('prepay_transactions')->orderBy('id')->get()->keyBy('id');
        [$exit, $out] = $this->commit();

        $this->assertSame(0, $exit, $out);
        $this->assertSame($this->notes['link'], $this->noteOf('link'));
        $this->assertStringContainsString('linked: 1', $out);

        // Only ticket_note_id on the linked row changed: hours, dates, descriptions, users unchanged.
        $after = DB::table('prepay_transactions')->orderBy('id')->get()->keyBy('id');
        foreach ($before as $id => $row) {
            $expected = (array) $row;
            if ($id === $this->ptx['link']) {
                $expected['ticket_note_id'] = $this->notes['link'];
            }
            $this->assertSame($expected, (array) $after[$id], "ptx {$id}");
        }
        $this->assertSame(-0.5, (float) DB::table('prepay_transactions')->where('id', $this->ptx['link'])->value('hours'));

        $rollback = json_decode(file_get_contents($this->dir.'/rollback.json'), true);
        $this->assertSame('prepay-relink-rollback/v1', $rollback['format']);
        $this->assertSame([(string) $this->ptx['link'] => $this->notes['link']], $rollback['links']);
    }

    public function test_a_second_commit_run_is_a_no_op(): void
    {
        $this->commit();
        $snap = $this->ledger();

        [$exit, $out] = $this->commit($this->dir.'/rollback-2.json');

        $this->assertSame(0, $exit, $out);
        $this->assertSame($snap, $this->ledger());
        $this->assertStringContainsString('linking: 0', $out);
        $this->assertStringContainsString('candidates: 13', $out);
        $this->assertSame([], json_decode(file_get_contents($this->dir.'/rollback-2.json'), true)['links']);
    }

    public function test_commit_requires_a_new_rollback_file(): void
    {
        $before = $this->ledger();
        [$exit] = $this->run5067(['--commit' => true]);
        $this->assertSame(1, $exit);

        file_put_contents($this->dir.'/exists.json', 'keep');
        [$exit] = $this->commit($this->dir.'/exists.json');
        $this->assertSame(1, $exit);
        $this->assertSame('keep', file_get_contents($this->dir.'/exists.json'));
        $this->assertSame($before, $this->ledger());
    }

    /** Each refusal class: the row stays unlinked and is listed by ptx id under its class. */
    private function assertRefused(string $key, string $class, string $out): void
    {
        $this->assertNull($this->noteOf($key), "{$key} must stay unlinked");
        $this->assertMatchesRegularExpression(
            '/^'.preg_quote($class, '/').': \d+ \(ptx ids: (\d+, )*'.$this->ptx[$key].'(, \d+)*\)$/m',
            $out,
        );
    }

    public function test_refuses_a_row_with_no_map_entry(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('no_map', 'no-map', $out);
    }

    public function test_refuses_when_the_map_ticket_is_not_the_rows_ticket(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('ticket_mismatch', 'ticket-mismatch', $out);
    }

    public function test_refuses_another_clients_ticket_and_prints_no_subject(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('client_mismatch', 'client-mismatch', $out);
        $this->assertStringNotContainsString('Synthetic', $out);
    }

    public function test_refuses_when_no_note_matches(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('no_note', 'no-note', $out);
    }

    public function test_refuses_when_two_notes_match(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('multi', 'multi-note', $out);
    }

    public function test_refuses_a_soft_deleted_note(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('trashed', 'trashed-note', $out);
    }

    /** A later sync of a non-billable note would reverse the row (NULL is not billable). */
    public function test_refuses_a_note_that_is_not_billable(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('not_billable', 'not-billable', $out);
    }

    /** A later sync of a zero-time note would reverse the row. */
    public function test_refuses_a_note_with_no_time(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('zero_time', 'time-mismatch', $out);
    }

    /** A later sync of a 45-minute note would re-price a 0.5h row and move the balance. */
    public function test_refuses_a_note_whose_time_differs_from_the_row(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('time_mismatch', 'time-mismatch', $out);
    }

    public function test_refuses_a_note_stamped_to_another_contract(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('stamp_mismatch', 'stamp-mismatch', $out);
    }

    public function test_refuses_a_note_already_linked_to_another_ledger_row(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('already_linked', 'already-linked-note', $out);
        $this->assertSame($this->notes['used'], $this->noteOf('holder'));
    }

    public function test_refuses_explicitly_excluded_and_default_excluded_rows(): void
    {
        [, $out] = $this->commit();
        $this->assertRefused('excluded', 'excluded', $out);
        $this->assertRefused('excluded_default', 'excluded', $out);
    }

    public function test_a_row_linked_after_planning_aborts_the_whole_write(): void
    {
        // Simulate another writer linking the row between planning and the guarded UPDATE.
        $racer = $this->notes['tm'];
        $ptx = $this->ptx['link'];
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fired, $racer, $ptx) {
            if (! $fired && str_starts_with(strtolower($sql), 'update "prepay_transactions"')) {
                $fired = true;
                DB::table('prepay_transactions')->where('id', $ptx)->update(['ticket_note_id' => $racer]);
            }
        });

        [$exit, $out] = $this->commit();

        $this->assertTrue($fired);
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('rolled back, no ledger row was changed', $out);
        $this->assertNotSame($this->notes['link'], $this->noteOf('link'));
    }

    public function test_refuses_both_rows_when_two_target_the_same_note(): void
    {
        $this->writeMap([[70001, 9001, 1, 0.5, 0.0], [70002, 9001, 1, 0.5, 0.0]]);
        [, $out] = $this->commit();
        $this->assertRefused('link', 'duplicate-target', $out);
        $this->assertRefused('no_map', 'duplicate-target', $out);
    }

    private function scenarioTicketId(): int
    {
        return (int) DB::table('ticket_notes')->where('id', $this->notes['link'])->value('ticket_id');
    }

    /** Attributes of a synthetic ledger row on the scenario contract. */
    private function ledgerRow(string $desc, array $extra = []): array
    {
        return $extra + [
            'contract_id' => (int) DB::table('prepay_transactions')->where('id', $this->ptx['link'])->value('contract_id'),
            'source' => 'ticket_time', 'ticket_note_id' => null, 'user_id' => null,
            'date' => '2026-01-05 00:00:00', 'hours' => -0.5, 'description' => $desc,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_refuses_a_note_held_through_moved_ticket_note_id(): void
    {
        $moved = DB::table('ticket_notes')->insertGetId([
            'ticket_id' => $this->scenarioTicketId(), 'halo_note_id' => 8, 'body' => 'Synthetic', 'is_billable' => true,
            'time_minutes' => 30, 'noted_at' => '2026-01-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The original row of a contract move: ticket_note_id NULL, moved_ticket_note_id = the note.
        $holder = DB::table('prepay_transactions')->insertGetId(
            $this->ledgerRow('Synthetic moved entry', ['moved_ticket_note_id' => $moved]),
        );
        $this->ptx['moved_target'] = DB::table('prepay_transactions')->insertGetId(
            $this->ledgerRow("Ticket #{$this->scenarioTicketId()}: Synthetic P ticket [70011]"),
        );
        $this->writeMap([[70001, 9001, 1, 0.5, 0.0], [70011, 9001, 8, 0.5, 0.0]]);

        [$exit, $out] = $this->commit();

        $this->assertSame(0, $exit, $out);
        $this->assertRefused('moved_target', 'already-linked-note', $out);
        $this->assertSame($this->notes['link'], $this->noteOf('link'));
        $this->assertNull(DB::table('prepay_transactions')->where('id', $holder)->value('ticket_note_id'));
        $this->assertSame($moved, (int) DB::table('prepay_transactions')->where('id', $holder)->value('moved_ticket_note_id'));
    }

    public function test_a_note_made_non_billable_after_planning_aborts_the_whole_write(): void
    {
        // Simulate an edit of the planned note once the write transaction has begun.
        $note = $this->notes['link'];
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $note) {
            if (! $fired) {
                $fired = true;
                DB::table('ticket_notes')->where('id', $note)->update(['is_billable' => false]);
            }
        });

        [$exit, $out] = $this->commit();

        $this->assertTrue($fired);
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString("Ptx {$this->ptx['link']} or note {$note} no longer passes the planning checks; rolled back, no ledger row was changed.", $out);
        $this->assertNull($this->noteOf('link'));
    }

    public function test_a_note_taken_by_another_row_after_planning_aborts_the_whole_write(): void
    {
        // Simulate another writer moving an entry onto the planned note once the write transaction has begun.
        $racer = $this->ledgerRow('Synthetic moved entry', ['moved_ticket_note_id' => $this->notes['link']]);
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $racer) {
            if (! $fired) {
                $fired = true;
                DB::table('prepay_transactions')->insert($racer);
            }
        });

        [$exit, $out] = $this->commit();

        $this->assertTrue($fired);
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString("Note {$this->notes['link']} for ptx {$this->ptx['link']} is already held by ptx ", $out);
        $this->assertStringContainsString('rolled back, no ledger row was changed', $out);
        $this->assertNull($this->noteOf('link'));
    }
}
