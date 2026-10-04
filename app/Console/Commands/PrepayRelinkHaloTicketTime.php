<?php

namespace App\Console\Commands;

use App\Enums\PrepayTransactionSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * #5067, ONE-TIME: link Halo-imported ticket_time ledger rows to their ticket note.
 *
 * prepay:backfill-from-halo matched notes on ticket_notes.halo_note_id, which holds
 * Halo's per-ticket action sequence (actionnumber), not the global action id, so most
 * imported rows were written unlinked as "Ticket #<id>: <subject> [<halo action id>]".
 * This command reads an id-only map exported from Halo (halo action id -> halo ticket
 * id + actionnumber) and fills ticket_note_id on those rows.
 *
 * It writes ONLY prepay_transactions.ticket_note_id, where it is NULL, and (r3) the linked
 * note's ticket_notes.time_adjustment_minutes. Halo drew timetaken + timetakenAdjusted from
 * prepay while the imported note holds timetaken alone; the adjustment column carries the
 * difference, so the note prices at the row's hours (TicketNote::pricedMinutes()) and no
 * ledger amount changes. Once a row is linked, every later sync of the note runs
 * PrepayService::debitFromTicketNote() on it: a non-billable note or one with no priced
 * minutes reverses the row, a note with other priced minutes re-sets its hours and moves
 * the contract balance by the difference, and a note stamped to another contract leaves
 * the row refused. That sync may also re-set the row's date and description
 * from the note and its ticket; this command does not check those. A row is linked only
 * when every check passes:
 *   - the map has exactly one row for the action id (no-map otherwise);
 *   - that row's timetakenAdjusted (and, in a v2 map, timetaken and prepayHours) converts to
 *     whole minutes under ADJUSTMENT RULE below (bad-adjustment otherwise);
 *   - that map row's halo ticket id equals tickets.halo_id of the "Ticket #<id>" in the
 *     description; a missing ticket or a NULL halo_id also counts as ticket-mismatch;
 *   - the ticket's client_id equals the contract's client_id (client-mismatch otherwise);
 *   - exactly one ticket note on that ticket has halo_note_id = actionnumber, counting
 *     soft-deleted notes (no-note / multi-note); a soft-deleted match is refused
 *     (trashed-note): linking to a deleted note would hand the row to the note-delete
 *     reversal path;
 *   - the note is billable (not-billable otherwise; NULL counts as not billable); its
 *     time_adjustment_minutes is NULL or already equal to the map's adjustment
 *     (adjustment-mismatch otherwise); time_minutes + the map's adjustment is not negative
 *     (below-zero otherwise), is > 0 and, as hours rounded to 4 places, equals the row's
 *     debit (time-mismatch otherwise); and
 *     its contract stamp is NULL or the row's contract (stamp-mismatch otherwise);
 *   - no other prepay row already holds that note, through ticket_note_id or
 *     moved_ticket_note_id (already-linked-note), and no other candidate in this run
 *     targets it (duplicate-target: all of them are refused);
 *   - the row id is not excluded.
 *
 * Excluded by default: DEFAULT_EXCLUDED (see its comment). --exclude adds ids; it cannot
 * remove a default.
 *
 * ADJUSTMENT RULE: adj_minutes = round(timetakenAdjusted x 60) to the nearest integer (PHP
 * round(), half away from zero), accepted only when timetakenAdjusted is a non-negative
 * number within ADJUSTMENT_TOLERANCE_MINUTES of that integer. Any whole number of minutes
 * written as hours to 4 places is within 0.003 min of it (13 min = 0.2167 h -> 13.002 ->
 * 13), so the rule accepts a 4-place export and refuses a genuine fraction of a minute.
 * A zero adjustment leaves the note's column NULL.
 *
 * MAP: halo-action-map/v1, or halo-action-map/v2, which adds prepayHours, chargeHours,
 * isBillable and optionally chargeProcessed (int or null). Group A (Charlie's ruling relayed
 * by Jeeves 2026-10-03 14:42 PT): where a v2 row's prepayHours is below timetaken +
 * timetakenAdjusted, Halo drew only prepayHours from prepay and charged the rest, so the
 * adjustment carried is prepayHours - timetaken in whole minutes (negative when the prepay
 * part is below timetaken). The note then prices at the prepay part alone, and the charged
 * part is never drawn from prepay; an ordinary edit cannot then change the note's time (see
 * TicketNoteObserver::saving()). Each such row is listed after the counts with its Halo
 * numbers and the note's body length.
 *
 * --dry-run is the default. A write needs --commit and --rollback-file=<new path>; the
 * file (format prepay-relink-rollback/v2: ptx id -> note id linked, each linked row's
 * contract id and hours, and for each note whose adjustment was written its prior value and
 * the value set) is written before the first update and the command refuses if the path
 * exists. --rollback=<that file> takes no other option (--dry-run included: it is refused,
 * not ignored) and undoes a run in one transaction. Per link it locks the note, then the row
 * (debitFromTicketNote()'s order), and refuses unless the row is still linked to the note at
 * the recorded contract and hours and no other row holds the note through ticket_note_id or
 * moved_ticket_note_id; then:
 *   UPDATE prepay_transactions SET ticket_note_id = NULL WHERE id = <ptx> AND ticket_note_id = <note>;
 *   UPDATE ticket_notes SET time_adjustment_minutes = <prior> WHERE id = <note> AND time_adjustment_minutes = <set>;
 * It rolls back entirely if a check fails or either guarded update hits no row. It runs no
 * note sync, so no ledger amount moves.
 * Inside one transaction, each link first locks the note and the row and re-checks the
 * note checks above (live, billable, adjustment, time, stamp) and that the note's
 * adjustment is still the prior value recorded, then re-checks under lock that no row
 * holds the note through ticket_note_id or moved_ticket_note_id, and is guarded on
 * ticket_note_id IS NULL and phone_call_id IS NULL; the adjustment is written in the same
 * transaction, guarded on its prior value. If a re-check fails, the note is held or a
 * guarded update hits no row, the whole run rolls back.
 *
 * Output is counts and ledger row ids only: no subjects, names or descriptions.
 * Running it against production (dry run included) needs Charlie's go for that run.
 *
 * Usage: php artisan prepay:relink-halo-ticket-time --map=<halo-action-map.v1.json>
 *        php artisan prepay:relink-halo-ticket-time --map=<file> --commit --rollback-file=<new file>
 *        php artisan prepay:relink-halo-ticket-time --rollback=<rollback file>
 */
class PrepayRelinkHaloTicketTime extends Command
{
    /**
     * Ledger row ids that are never linked. 2653 is the cross-client row whose repair is a
     * separate decision on card I3EvQKUV (Jeeves's #5067 ruling, 2026-10-03, item 2). It is
     * a default rather than an operator flag so that a run cannot forget it.
     */
    public const DEFAULT_EXCLUDED = [2653];

    public const MAP_FORMAT = 'halo-action-map/v1';

    public const MAP_COLUMNS = ['halo_action_id', 'halo_ticket_id', 'actionnumber', 'timetaken', 'timetakenAdjusted'];

    /** v2 adds Halo's prepay/charge split; a trailing chargeProcessed column is optional. */
    public const MAP_FORMAT_V2 = 'halo-action-map/v2';

    public const MAP_COLUMNS_V2 = [...self::MAP_COLUMNS, 'prepayHours', 'chargeHours', 'isBillable'];

    public const MAP_COLUMN_PROCESSED = 'chargeProcessed';

    /** Refusal classes, in report order. */
    public const CLASSES = [
        'excluded', 'no-map', 'bad-adjustment', 'ticket-mismatch', 'client-mismatch', 'no-note', 'multi-note',
        'trashed-note', 'not-billable', 'adjustment-mismatch', 'below-zero', 'time-mismatch', 'stamp-mismatch',
        'already-linked-note', 'duplicate-target',
    ];

    /** See ADJUSTMENT RULE in the class docblock. */
    public const ADJUSTMENT_TOLERANCE_MINUTES = 0.01;

    public const ROLLBACK_FORMAT = 'prepay-relink-rollback/v2';

    private const NOTE_COLUMNS = ['id', 'deleted_at', 'is_billable', 'time_minutes', 'time_adjustment_minutes', 'contract_id'];

    protected $signature = 'prepay:relink-halo-ticket-time
        {--map= : Path to the halo-action-map/v1 JSON file (required)}
        {--exclude= : Comma-separated prepay_transactions ids to skip, in addition to the default}
        {--dry-run : Report only (the default)}
        {--commit : Write ticket_note_id for every linkable row}
        {--rollback-file= : New file to write the ptx id -> note id rollback list to (required with --commit)}
        {--rollback= : Undo a committed run from its rollback file}';

    protected $description = 'ONE-TIME (#5067): link Halo-imported ticket_time ledger rows to their ticket note by Halo action id';

    public function handle(): int
    {
        $undo = (string) $this->option('rollback');
        if ($undo !== '') {
            if ($this->option('commit') || $this->option('dry-run') || (string) $this->option('map') !== ''
                || trim((string) $this->option('exclude')) !== '' || (string) $this->option('rollback-file') !== '') {
                $this->error('--rollback takes no --map, --commit, --dry-run, --exclude or --rollback-file.');

                return self::FAILURE;
            }

            return $this->rollback($undo);
        }

        $commit = (bool) $this->option('commit');
        if ($commit && $this->option('dry-run')) {
            $this->error('--commit and --dry-run are mutually exclusive.');

            return self::FAILURE;
        }

        $mapPath = (string) $this->option('map');
        if ($mapPath === '') {
            $this->error('--map is required.');

            return self::FAILURE;
        }

        $rollbackPath = (string) $this->option('rollback-file');
        if ($commit && $rollbackPath === '') {
            $this->error('--commit requires --rollback-file.');

            return self::FAILURE;
        }
        if ($commit && file_exists($rollbackPath)) {
            $this->error('Rollback file already exists; refusing to overwrite it.');

            return self::FAILURE;
        }

        try {
            $map = $this->loadMap($mapPath);
            $excluded = $this->excludedIds();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        [$candidates, $links, $refused, $partial] = $this->plan($map, $excluded);

        $this->line(($commit ? 'COMMIT' : 'DRY RUN: nothing will be written').'. Map rows: '.count($map).'.');
        $this->line('candidates: '.$candidates);
        $this->line(($commit ? 'linking: ' : 'would link: ').count($links));
        $this->line(($commit ? 'linking with an adjustment: ' : 'would link with an adjustment: ')
            .count(array_filter($links, fn (array $l) => $l['adj'] !== 0)));
        foreach (self::CLASSES as $class) {
            $ids = $refused[$class];
            $this->line("{$class}: ".count($ids).($ids ? ' (ptx ids: '.implode(', ', $ids).')' : ''));
        }
        $this->reportPartial($partial, $links, $refused);

        if (! $commit) {
            return self::SUCCESS;
        }

        return $this->write($links, $rollbackPath);
    }

    /**
     * Returns: the candidate count; ptx id => link (note id, the row's contract id and hours,
     * adjustment minutes to carry, the note's adjustment when planned); refused ptx ids per
     * class; and the group-A report rows.
     */
    private function plan(array $map, array $excluded): array
    {
        $refused = array_fill_keys(self::CLASSES, []);
        $targets = [];
        $plans = [];
        $partial = [];
        $candidates = 0;

        $rows = DB::table('prepay_transactions')
            ->where('source', PrepayTransactionSource::TicketTime->value)
            ->whereNull('ticket_note_id')
            ->whereNull('phone_call_id')
            ->orderBy('id')
            ->get(['id', 'contract_id', 'hours', 'description']);

        foreach ($rows as $row) {
            if (! preg_match('/\[(\d+)\]$/', (string) $row->description, $m)) {
                continue;
            }
            $candidates++;
            $ptxId = (int) $row->id;
            $actionId = (int) $m[1];

            if (isset($excluded[$ptxId])) {
                $refused['excluded'][] = $ptxId;

                continue;
            }

            $entry = $map[$actionId] ?? null;
            if ($entry === null) {
                $refused['no-map'][] = $ptxId;

                continue;
            }
            if ($entry['partial'] !== null) {
                $partial[$ptxId] = $entry['partial'] + ['body_length' => null];
            }
            if ($entry['adj'] === null) {
                $refused['bad-adjustment'][] = $ptxId;

                continue;
            }

            // A row written for a ticket with no local copy reads "Halo #<id>": no ticket to match.
            $ticket = preg_match('/^Ticket #(\d+):/', (string) $row->description, $t)
                ? DB::table('tickets')->where('id', (int) $t[1])->first(['id', 'halo_id', 'client_id'])
                : null;
            if ($ticket === null || $ticket->halo_id === null || (int) $ticket->halo_id !== $entry['halo_ticket_id']) {
                $refused['ticket-mismatch'][] = $ptxId;

                continue;
            }

            $contractClient = DB::table('contracts')->where('id', $row->contract_id)->value('client_id');
            if ($contractClient === null || $ticket->client_id === null || (int) $contractClient !== (int) $ticket->client_id) {
                $refused['client-mismatch'][] = $ptxId;

                continue;
            }

            $notes = DB::table('ticket_notes')
                ->where('ticket_id', $ticket->id)
                ->where('halo_note_id', $entry['actionnumber'])
                ->get(self::NOTE_COLUMNS);
            if ($notes->count() !== 1) {
                $refused[$notes->isEmpty() ? 'no-note' : 'multi-note'][] = $ptxId;

                continue;
            }
            if (isset($partial[$ptxId])) {
                // The length only: the note text never reaches the output.
                $partial[$ptxId]['body_length'] = (int) mb_strlen((string) DB::table('ticket_notes')->where('id', $notes[0]->id)->value('body'));
            }
            if ($notes[0]->deleted_at !== null) {
                $refused['trashed-note'][] = $ptxId;

                continue;
            }
            $refusal = $this->noteRefusal($notes[0], $row, $entry['adj']);
            if ($refusal !== null) {
                $refused[$refusal][] = $ptxId;

                continue;
            }

            $targets[$ptxId] = (int) $notes[0]->id;
            $plans[$ptxId] = [
                'contract' => (int) $row->contract_id,
                'hours' => round((float) $row->hours, 4),
                'adj' => $entry['adj'],
                'prior' => $notes[0]->time_adjustment_minutes === null ? null : (int) $notes[0]->time_adjustment_minutes,
            ];
        }

        // A note another ledger row holds cannot be taken: through ticket_note_id, or through
        // moved_ticket_note_id on the original row of a contract move.
        $held = [];
        foreach (array_chunk(array_unique(array_values($targets)), 500) as $chunk) {
            foreach (['ticket_note_id', 'moved_ticket_note_id'] as $column) {
                foreach (DB::table('prepay_transactions')->whereIn($column, $chunk)->pluck($column) as $id) {
                    $held[(int) $id] = true;
                }
            }
        }
        $perNote = array_count_values($targets);

        $links = [];
        foreach ($targets as $ptxId => $noteId) {
            if (isset($held[$noteId])) {
                $refused['already-linked-note'][] = $ptxId;
            } elseif ($perNote[$noteId] > 1) {
                $refused['duplicate-target'][] = $ptxId;
            } else {
                $links[$ptxId] = ['note' => $noteId] + $plans[$ptxId];
            }
        }

        return [$candidates, $links, $refused, $partial];
    }

    /**
     * Group A (#5067 r3, Charlie's ruling relayed 2026-10-03 14:42 PT): rows where Halo drew
     * only part of the action from prepay. One line per row: ptx id, outcome and Halo's
     * numbers, plus the matched note's body LENGTH; never the text.
     *
     * @param  array<int, array{timetaken: float, prepay: float, charge: float, processed: ?int, body_length: ?int}>  $partial
     */
    private function reportPartial(array $partial, array $links, array $refused): void
    {
        $this->line('partial-prepay rows: '.count($partial));
        $classOf = [];
        foreach ($refused as $class => $ids) {
            foreach ($ids as $id) {
                $classOf[$id] = $class;
            }
        }
        $h = fn (float $v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
        foreach ($partial as $ptxId => $p) {
            $this->line(sprintf(
                '  ptx %d: %s; timetaken %s h, prepay %s h, charge %s h, processed %s, note body length %s',
                $ptxId,
                isset($links[$ptxId]) ? 'link, adjustment '.$links[$ptxId]['adj'].' min' : 'refused '.($classOf[$ptxId] ?? 'unknown'),
                $h($p['timetaken']), $h($p['prepay']), $h($p['charge']),
                $p['processed'] === null ? 'n/a' : (string) $p['processed'],
                $p['body_length'] === null ? 'n/a' : (string) $p['body_length'],
            ));
        }
    }

    /** @param array<int, array{note: int, contract: int, hours: float, adj: int, prior: ?int}> $links */
    private function write(array $links, string $rollbackPath): int
    {
        // Mode 'x' creates the file and fails if it exists, so a rollback file is never replaced.
        $handle = @fopen($rollbackPath, 'x');
        if ($handle === false) {
            $this->error('Could not create the rollback file; nothing was written.');

            return self::FAILURE;
        }
        $adjustments = [];
        foreach ($links as $link) {
            if ($link['adj'] !== 0 && $link['prior'] === null) {
                $adjustments[(string) $link['note']] = ['prior' => null, 'set' => $link['adj']];
            }
        }
        $payload = json_encode([
            'format' => self::ROLLBACK_FORMAT,
            'links' => (object) array_map(fn (array $l) => $l['note'], $links),
            'rows' => (object) array_map(fn (array $l) => ['contract' => $l['contract'], 'hours' => $l['hours']], $links),
            'adjustments' => (object) $adjustments,
        ], JSON_PRETTY_PRINT);
        $ok = fwrite($handle, $payload."\n") !== false && fflush($handle);
        fclose($handle);
        if (! $ok) {
            $this->error('Could not write the rollback file; no ledger row was changed.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($links) {
                foreach ($links as $ptxId => $link) {
                    $noteId = $link['note'];
                    // Lock order as in debitFromTicketNote(): note, then prepay rows.
                    $note = DB::table('ticket_notes')->where('id', $noteId)->lockForUpdate()->first(self::NOTE_COLUMNS);
                    $row = DB::table('prepay_transactions')->where('id', $ptxId)->lockForUpdate()->first(['contract_id', 'hours']);
                    $current = $note?->time_adjustment_minutes === null ? null : (int) $note->time_adjustment_minutes;
                    if ($note === null || $note->deleted_at !== null || $row === null || $current !== $link['prior']
                        || (int) $row->contract_id !== $link['contract'] || round((float) $row->hours, 4) !== $link['hours']
                        || $this->noteRefusal($note, $row, $link['adj']) !== null) {
                        throw new RuntimeException("Ptx {$ptxId} or note {$noteId} no longer passes the planning checks; rolled back, no ledger row was changed.");
                    }
                    $holder = DB::table('prepay_transactions')
                        ->where(fn ($q) => $q->where('ticket_note_id', $noteId)->orWhere('moved_ticket_note_id', $noteId))
                        ->lockForUpdate()
                        ->value('id');
                    if ($holder !== null) {
                        throw new RuntimeException("Note {$noteId} for ptx {$ptxId} is already held by ptx {$holder}; rolled back, no ledger row was changed.");
                    }
                    $updated = DB::table('prepay_transactions')
                        ->where('id', $ptxId)
                        ->where('source', PrepayTransactionSource::TicketTime->value)
                        ->whereNull('ticket_note_id')
                        ->whereNull('phone_call_id')
                        ->update(['ticket_note_id' => $noteId]);
                    if ($updated !== 1) {
                        throw new RuntimeException("Guarded update matched {$updated} rows for ptx {$ptxId}; rolled back, no ledger row was changed.");
                    }
                    // Query builder, not the model: setting the adjustment must not run the note
                    // observers (the row's hours already equal the note's priced minutes).
                    if ($link['adj'] !== 0 && $link['prior'] === null) {
                        $set = DB::table('ticket_notes')->where('id', $noteId)->whereNull('time_adjustment_minutes')
                            ->update(['time_adjustment_minutes' => $link['adj']]);
                        if ($set !== 1) {
                            throw new RuntimeException("Guarded adjustment update matched {$set} notes for note {$noteId}; rolled back, no ledger row was changed.");
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('linked: '.count($links).'. Rollback file written first.');

        return self::SUCCESS;
    }

    /**
     * Undo a committed run from its rollback file, in one transaction. Per link, under the note
     * lock then the row lock: refuse unless the row is still linked to the recorded note at the
     * recorded contract and hours and no other row holds the note; then unlink it and restore
     * the note's recorded adjustment where it still holds the value the run set. A failed check
     * or a guarded update that hits no row rolls the whole undo back. Writes through the query
     * builder, so no note sync runs and no ledger amount moves.
     */
    private function rollback(string $path): int
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $doc = $raw === false ? null : json_decode($raw, true);
        if (! is_array($doc) || ($doc['format'] ?? null) !== self::ROLLBACK_FORMAT
            || ! is_array($doc['links'] ?? null) || ! is_array($doc['adjustments'] ?? null) || ! is_array($doc['rows'] ?? null)) {
            $this->error('Rollback file is not '.self::ROLLBACK_FORMAT.'.');

            return self::FAILURE;
        }
        foreach ($doc['links'] as $ptx => $note) {
            $r = $doc['rows'][$ptx] ?? null;
            if (! ctype_digit((string) $ptx) || ! is_int($note) || ! is_array($r) || ! is_int($r['contract'] ?? null)
                || ! (is_int($r['hours'] ?? null) || is_float($r['hours'] ?? null))) {
                $this->error('Rollback file has a malformed link entry.');

                return self::FAILURE;
            }
        }
        foreach ($doc['adjustments'] as $note => $a) {
            if (! ctype_digit((string) $note) || ! in_array((int) $note, $doc['links'], true)
                || ! is_array($a) || ! array_key_exists('prior', $a) || ! is_int($a['set'] ?? null)
                || ($a['prior'] !== null && ! is_int($a['prior']))) {
                $this->error('Rollback file has a malformed adjustment entry.');

                return self::FAILURE;
            }
        }

        try {
            DB::transaction(function () use ($doc) {
                foreach ($doc['links'] as $ptxId => $noteId) {
                    $ptxId = (int) $ptxId;
                    $recorded = $doc['rows'][$ptxId];
                    // Lock order as in debitFromTicketNote(): note, then prepay rows.
                    $note = DB::table('ticket_notes')->where('id', $noteId)->lockForUpdate()->first(['id']);
                    $row = DB::table('prepay_transactions')->where('id', $ptxId)->lockForUpdate()->first(['ticket_note_id', 'contract_id', 'hours']);
                    if ($note === null || $row === null || $row->ticket_note_id === null || (int) $row->ticket_note_id !== $noteId
                        || (int) $row->contract_id !== $recorded['contract']
                        || round((float) $row->hours, 4) !== round((float) $recorded['hours'], 4)) {
                        throw new RuntimeException("Ptx {$ptxId} or note {$noteId} no longer matches the rollback file (link, contract or hours); rollback undone, nothing was changed.");
                    }
                    $holder = DB::table('prepay_transactions')
                        ->where('id', '!=', $ptxId)
                        ->where(fn ($q) => $q->where('ticket_note_id', $noteId)->orWhere('moved_ticket_note_id', $noteId))
                        ->lockForUpdate()
                        ->value('id');
                    if ($holder !== null) {
                        throw new RuntimeException("Note {$noteId} for ptx {$ptxId} is also held by ptx {$holder}; rollback undone, nothing was changed.");
                    }
                    $n = DB::table('prepay_transactions')->where('id', $ptxId)->where('ticket_note_id', $noteId)
                        ->update(['ticket_note_id' => null]);
                    if ($n !== 1) {
                        throw new RuntimeException("Ptx {$ptxId} is no longer linked to note {$noteId}; rollback undone, nothing was changed.");
                    }
                    $a = $doc['adjustments'][$noteId] ?? null;
                    if ($a !== null) {
                        $n = DB::table('ticket_notes')->where('id', $noteId)->where('time_adjustment_minutes', $a['set'])
                            ->update(['time_adjustment_minutes' => $a['prior']]);
                        if ($n !== 1) {
                            throw new RuntimeException("Note {$noteId} no longer holds the adjustment this run set; rollback undone, nothing was changed.");
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('unlinked: '.count($doc['links']).'; adjustments restored: '.count($doc['adjustments']).'.');

        return self::SUCCESS;
    }

    /**
     * The refusal class for linking a live note to a ledger row with the map's adjustment
     * ($adj minutes), or null. Once linked, a sync of the note runs debitFromTicketNote() on
     * the row at time_minutes + time_adjustment_minutes: a non-billable note or one with no
     * priced minutes reverses it, other priced minutes re-price it and move the balance, and
     * a stamp to another contract leaves it refused.
     */
    private function noteRefusal(object $note, object $row, int $adj): ?string
    {
        if (! $note->is_billable) {
            return 'not-billable';
        }
        if ($note->time_adjustment_minutes !== null && (int) $note->time_adjustment_minutes !== $adj) {
            return 'adjustment-mismatch';
        }
        $minutes = (int) $note->time_minutes + $adj;
        // A reduction may bring the priced sum down to the prepay part, never below zero.
        if ($minutes < 0) {
            return 'below-zero';
        }
        if ($minutes === 0 || -round($minutes / 60, 4) !== round((float) $row->hours, 4)) {
            return 'time-mismatch';
        }
        if ($note->contract_id !== null && (int) $note->contract_id !== (int) $row->contract_id) {
            return 'stamp-mismatch';
        }

        return null;
    }

    /**
     * Whole minutes for a timetakenAdjusted value in hours under ADJUSTMENT RULE, or null
     * when it is not a non-negative number within ADJUSTMENT_TOLERANCE_MINUTES of one.
     */
    public static function adjustmentMinutes(mixed $hours): ?int
    {
        if (! is_int($hours) && ! is_float($hours)) {
            return null;
        }
        $exact = $hours * 60;
        if (! is_finite($exact) || $exact < 0) {
            return null;
        }
        $minutes = (int) round($exact);

        return abs($exact - $minutes) <= self::ADJUSTMENT_TOLERANCE_MINUTES ? $minutes : null;
    }

    /** @return array<int, array{halo_ticket_id: int, actionnumber: int, adj: ?int}> keyed by halo action id */
    private function loadMap(string $path): array
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException('Map file not readable.');
        }
        $doc = json_decode($raw, true);
        $format = is_array($doc) ? ($doc['format'] ?? null) : null;
        $columns = is_array($doc) ? ($doc['columns'] ?? null) : null;
        $v2 = $format === self::MAP_FORMAT_V2
            && ($columns === self::MAP_COLUMNS_V2 || $columns === [...self::MAP_COLUMNS_V2, self::MAP_COLUMN_PROCESSED]);
        if (! ($v2 || ($format === self::MAP_FORMAT && $columns === self::MAP_COLUMNS)) || ! is_array($doc['rows'] ?? null)) {
            throw new RuntimeException('Map file is not '.self::MAP_FORMAT.' with columns '.implode(',', self::MAP_COLUMNS)
                .', nor '.self::MAP_FORMAT_V2.' with columns '.implode(',', self::MAP_COLUMNS_V2).'[,'.self::MAP_COLUMN_PROCESSED.'].');
        }
        $width = count($columns);

        $map = [];
        $dupes = [];
        foreach ($doc['rows'] as $i => $r) {
            if (! is_array($r) || count($r) !== $width || ! is_int($r[0]) || ! is_int($r[1]) || ! is_int($r[2])
                || ($v2 && (! $this->isNumber($r[3]) || ! $this->isNumber($r[5]) || ! $this->isNumber($r[6])))
                || ($width === 9 && $r[8] !== null && ! is_int($r[8]))) {
                throw new RuntimeException("Map row {$i} is malformed.");
            }
            if (isset($map[$r[0]])) {
                $dupes[$r[0]] = true;
            }
            // A value that is not whole minutes leaves adj null: its rows report as bad-adjustment.
            $adj = self::adjustmentMinutes($r[4]);
            $partial = null;
            if ($v2) {
                // Group A: Halo drew only prepayHours from prepay and charged the rest. The note
                // then carries prepay - timetaken, so it prices at the prepay part alone.
                $taken = self::adjustmentMinutes($r[3]);
                $prepay = self::adjustmentMinutes($r[5]);
                if ($taken === null || $prepay === null) {
                    $adj = null;
                } elseif ($adj !== null && $prepay < $taken + $adj) {
                    $adj = $prepay - $taken;
                    $partial = ['timetaken' => (float) $r[3], 'prepay' => (float) $r[5], 'charge' => (float) $r[6], 'processed' => $width === 9 ? $r[8] : null];
                }
            }
            $map[$r[0]] = ['halo_ticket_id' => $r[1], 'actionnumber' => $r[2], 'adj' => $adj, 'partial' => $partial];
        }
        // An action id mapped twice is ambiguous: drop it, so its rows report as no-map.
        foreach (array_keys($dupes) as $actionId) {
            unset($map[$actionId]);
        }

        return $map;
    }

    private function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    /** @return array<int, true> */
    private function excludedIds(): array
    {
        $ids = array_fill_keys(self::DEFAULT_EXCLUDED, true);
        $extra = trim((string) $this->option('exclude'));
        if ($extra === '') {
            return $ids;
        }
        foreach (explode(',', $extra) as $part) {
            $part = trim($part);
            if (! ctype_digit($part)) {
                throw new RuntimeException('--exclude takes comma-separated integer ids.');
            }
            $ids[(int) $part] = true;
        }

        return $ids;
    }
}
