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
 * It writes ONLY prepay_transactions.ticket_note_id, and only where it is NULL. Once a row
 * is linked, every later sync of the note runs PrepayService::debitFromTicketNote() on it:
 * a non-billable or zero-time note reverses the row, a note with other time re-sets its
 * hours and moves the contract balance by the difference, and a note stamped to another
 * contract leaves the row refused. That sync may also re-set the row's date and description
 * from the note and its ticket; this command does not check those. A row is linked only
 * when every check passes:
 *   - the map has exactly one row for the action id (no-map otherwise);
 *   - that map row's halo ticket id equals tickets.halo_id of the "Ticket #<id>" in the
 *     description; a missing ticket or a NULL halo_id also counts as ticket-mismatch;
 *   - the ticket's client_id equals the contract's client_id (client-mismatch otherwise);
 *   - exactly one ticket note on that ticket has halo_note_id = actionnumber, counting
 *     soft-deleted notes (no-note / multi-note); a soft-deleted match is refused
 *     (trashed-note): linking to a deleted note would hand the row to the note-delete
 *     reversal path;
 *   - the note is billable (not-billable otherwise; NULL counts as not billable), its
 *     time_minutes > 0 and, as hours rounded to 4 places, equal to the row's debit
 *     (time-mismatch otherwise), and its contract stamp is NULL or the row's contract
 *     (stamp-mismatch otherwise);
 *   - no other prepay row already holds that note, through ticket_note_id or
 *     moved_ticket_note_id (already-linked-note), and no other candidate in this run
 *     targets it (duplicate-target: all of them are refused);
 *   - the row id is not excluded.
 *
 * Excluded by default: DEFAULT_EXCLUDED (see its comment). --exclude adds ids; it cannot
 * remove a default.
 *
 * --dry-run is the default. A write needs --commit and --rollback-file=<new path>; the
 * file (ptx id -> note id linked) is written before the first update and the command
 * refuses if the path exists. Rollback, per entry:
 *   UPDATE prepay_transactions SET ticket_note_id = NULL WHERE id = <ptx> AND ticket_note_id = <note>;
 * Inside one transaction, each update first locks the note and the row and re-checks the
 * note checks above (live, billable, time, stamp), then re-checks under lock that no row
 * holds the note through ticket_note_id or moved_ticket_note_id, and is guarded on
 * ticket_note_id IS NULL and phone_call_id IS NULL; if a re-check fails, the note is held or
 * a guarded update hits no row, the whole run rolls back.
 *
 * Output is counts and ledger row ids only: no subjects, names or descriptions.
 * Running it against production (dry run included) needs Charlie's go for that run.
 *
 * Usage: php artisan prepay:relink-halo-ticket-time --map=<halo-action-map.v1.json>
 *        php artisan prepay:relink-halo-ticket-time --map=<file> --commit --rollback-file=<new file>
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

    /** Refusal classes, in report order. */
    public const CLASSES = [
        'excluded', 'no-map', 'ticket-mismatch', 'client-mismatch', 'no-note', 'multi-note',
        'trashed-note', 'not-billable', 'time-mismatch', 'stamp-mismatch', 'already-linked-note', 'duplicate-target',
    ];

    protected $signature = 'prepay:relink-halo-ticket-time
        {--map= : Path to the halo-action-map/v1 JSON file (required)}
        {--exclude= : Comma-separated prepay_transactions ids to skip, in addition to the default}
        {--dry-run : Report only (the default)}
        {--commit : Write ticket_note_id for every linkable row}
        {--rollback-file= : New file to write the ptx id -> note id rollback list to (required with --commit)}';

    protected $description = 'ONE-TIME (#5067): link Halo-imported ticket_time ledger rows to their ticket note by Halo action id';

    public function handle(): int
    {
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

        [$candidates, $links, $refused] = $this->plan($map, $excluded);

        $this->line(($commit ? 'COMMIT' : 'DRY RUN: nothing will be written').'. Map rows: '.count($map).'.');
        $this->line('candidates: '.$candidates);
        $this->line(($commit ? 'linking: ' : 'would link: ').count($links));
        foreach (self::CLASSES as $class) {
            $ids = $refused[$class];
            $this->line("{$class}: ".count($ids).($ids ? ' (ptx ids: '.implode(', ', $ids).')' : ''));
        }

        if (! $commit) {
            return self::SUCCESS;
        }

        return $this->write($links, $rollbackPath);
    }

    /**
     * @return array{0: int, 1: array<int, int>, 2: array<string, list<int>>} candidate count,
     *                                                                        ptx id => note id to link, refused ptx ids per class
     */
    private function plan(array $map, array $excluded): array
    {
        $refused = array_fill_keys(self::CLASSES, []);
        $targets = [];
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
                ->get(['id', 'deleted_at', 'is_billable', 'time_minutes', 'contract_id']);
            if ($notes->count() !== 1) {
                $refused[$notes->isEmpty() ? 'no-note' : 'multi-note'][] = $ptxId;

                continue;
            }
            if ($notes[0]->deleted_at !== null) {
                $refused['trashed-note'][] = $ptxId;

                continue;
            }
            $refusal = $this->noteRefusal($notes[0], $row);
            if ($refusal !== null) {
                $refused[$refusal][] = $ptxId;

                continue;
            }

            $targets[$ptxId] = (int) $notes[0]->id;
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
                $links[$ptxId] = $noteId;
            }
        }

        return [$candidates, $links, $refused];
    }

    /** @param array<int, int> $links */
    private function write(array $links, string $rollbackPath): int
    {
        // Mode 'x' creates the file and fails if it exists, so a rollback file is never replaced.
        $handle = @fopen($rollbackPath, 'x');
        if ($handle === false) {
            $this->error('Could not create the rollback file; nothing was written.');

            return self::FAILURE;
        }
        $payload = json_encode([
            'format' => 'prepay-relink-rollback/v1',
            'links' => (object) $links,
        ], JSON_PRETTY_PRINT);
        $ok = fwrite($handle, $payload."\n") !== false && fflush($handle);
        fclose($handle);
        if (! $ok) {
            $this->error('Could not write the rollback file; no ledger row was changed.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($links) {
                foreach ($links as $ptxId => $noteId) {
                    // Lock order as in debitFromTicketNote(): note, then prepay rows.
                    $note = DB::table('ticket_notes')->where('id', $noteId)->lockForUpdate()
                        ->first(['id', 'deleted_at', 'is_billable', 'time_minutes', 'contract_id']);
                    $row = DB::table('prepay_transactions')->where('id', $ptxId)->lockForUpdate()->first(['contract_id', 'hours']);
                    if ($note === null || $note->deleted_at !== null || $row === null || $this->noteRefusal($note, $row) !== null) {
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
     * The refusal class for linking a live note to a ledger row, or null. Once linked, a sync
     * of the note runs debitFromTicketNote() on the row: a non-billable or zero-time note
     * reverses it, other time re-prices it and moves the balance, and a stamp to another
     * contract leaves it refused.
     */
    private function noteRefusal(object $note, object $row): ?string
    {
        if (! $note->is_billable) {
            return 'not-billable';
        }
        $minutes = (int) $note->time_minutes;
        if ($minutes <= 0 || -round($minutes / 60, 4) !== round((float) $row->hours, 4)) {
            return 'time-mismatch';
        }
        if ($note->contract_id !== null && (int) $note->contract_id !== (int) $row->contract_id) {
            return 'stamp-mismatch';
        }

        return null;
    }

    /** @return array<int, array{halo_ticket_id: int, actionnumber: int}> keyed by halo action id */
    private function loadMap(string $path): array
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException('Map file not readable.');
        }
        $doc = json_decode($raw, true);
        if (! is_array($doc) || ($doc['format'] ?? null) !== self::MAP_FORMAT || ($doc['columns'] ?? null) !== self::MAP_COLUMNS || ! is_array($doc['rows'] ?? null)) {
            throw new RuntimeException('Map file is not '.self::MAP_FORMAT.' with columns '.implode(',', self::MAP_COLUMNS).'.');
        }

        $map = [];
        $dupes = [];
        foreach ($doc['rows'] as $i => $r) {
            if (! is_array($r) || count($r) !== count(self::MAP_COLUMNS) || ! is_int($r[0]) || ! is_int($r[1]) || ! is_int($r[2])) {
                throw new RuntimeException("Map row {$i} is malformed.");
            }
            if (isset($map[$r[0]])) {
                $dupes[$r[0]] = true;
            }
            $map[$r[0]] = ['halo_ticket_id' => $r[1], 'actionnumber' => $r[2]];
        }
        // An action id mapped twice is ambiguous: drop it, so its rows report as no-map.
        foreach (array_keys($dupes) as $actionId) {
            unset($map[$actionId]);
        }

        return $map;
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
