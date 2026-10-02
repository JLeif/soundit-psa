<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Card I3EvQKUV §5: copy prepay_transactions.contract_id onto ticket notes and
 * phone calls whose contract stamp is still NULL, so each entry records the
 * contract its hours were actually taken from.
 *
 * It writes ONLY entry stamps that are NULL. It never writes
 * prepay_transactions or any contract balance column. An entry whose stamp is
 * set and differs from its ledger row is listed and left alone (ruling Q6).
 * Running it against production needs Charlie's go for that specific run,
 * the dry run included.
 */
class StampEntryContracts extends Command
{
    protected $signature = 'prepay:stamp-entry-contracts
        {--dry-run : Report what would be stamped; write nothing}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Stamp ticket notes and phone calls with the contract their prepay ledger row debits (NULL stamps only)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $sumsBefore = $this->ledgerSums();
        $balancesBefore = $this->balanceColumns();

        $rows = [];
        $mismatches = [];
        foreach (['ticket_note_id' => 'ticket_notes', 'phone_call_id' => 'phone_calls'] as $key => $table) {
            [$rows[$table], $mismatches[$table]] = $this->stampTable($key, $table, $dryRun, $chunk);
        }

        $this->line($dryRun ? 'DRY RUN: nothing was written.' : 'Stamped.');
        $this->table(
            ['entries', 'with ledger row', $dryRun ? 'would stamp' : 'stamped', 'already equal', 'MISMATCH (left)', 'ledger contract missing (left)'],
            [
                ['notes', ...array_values($rows['ticket_notes'])],
                ['calls', ...array_values($rows['phone_calls'])],
            ],
        );

        foreach ($mismatches as $table => $list) {
            foreach ($list as $m) {
                $this->warn("MISMATCH {$table} #{$m['entry_id']}: stamp contract {$m['stamp_contract_id']}, ledger contract {$m['ledger_contract_id']} (left as is)");
            }
        }

        $trashedLedger = DB::table('prepay_transactions')
            ->join('contracts', 'contracts.id', '=', 'prepay_transactions.contract_id')
            ->where(fn ($q) => $q->whereNotNull('prepay_transactions.ticket_note_id')->orWhereNotNull('prepay_transactions.phone_call_id'))
            ->whereNotNull('contracts.deleted_at')
            ->count();
        $this->line("ledger rows on soft-deleted contracts: {$trashedLedger} (stamped; the contract row is kept)");

        $ambiguous = $this->ambiguousClientIds();
        $this->line('clients with >1 active contract and no default: '.count($ambiguous).(count($ambiguous) ? ' (ids: '.implode(', ', $ambiguous).')' : ''));

        if ($dryRun) {
            return self::SUCCESS;
        }

        // Verification: no ledger-backed entry left unstamped except listed mismatches and
        // missing-contract rows, and the ledger and balances byte-identical.
        $unstamped = $this->unstampedWithLedger();
        $sumsSame = $sumsBefore === $this->ledgerSums();
        $balancesSame = $balancesBefore === $this->balanceColumns();
        $this->line("verify: unstamped entries with a ledger row on an existing contract: {$unstamped}");
        $this->line('verify: per-contract SUM(hours) unchanged: '.($sumsSame ? 'yes' : 'NO'));
        $this->line('verify: contract balance columns unchanged: '.($balancesSame ? 'yes' : 'NO'));

        return ($unstamped === 0 && $sumsSame && $balancesSame) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: array<string, int>, 1: list<array<string, int>>}
     */
    private function stampTable(string $key, string $table, bool $dryRun, int $chunk): array
    {
        $counts = ['ledger' => 0, 'stamp' => 0, 'equal' => 0, 'mismatch' => 0, 'missing' => 0];
        $mismatches = [];
        $existingContracts = Contract::withTrashed()->pluck('id')->flip();

        DB::table('prepay_transactions')
            ->join($table, "{$table}.id", '=', "prepay_transactions.{$key}")
            ->whereNotNull("prepay_transactions.{$key}")
            ->select(['prepay_transactions.id as txn_id', "prepay_transactions.{$key} as entry_id", 'prepay_transactions.contract_id as ledger_contract_id', "{$table}.contract_id as stamp_contract_id"])
            ->orderBy('prepay_transactions.id')
            ->chunk($chunk, function ($batch) use (&$counts, &$mismatches, $table, $dryRun, $existingContracts) {
                foreach ($batch as $row) {
                    $counts['ledger']++;
                    $ledger = (int) $row->ledger_contract_id;
                    if ($row->stamp_contract_id === null) {
                        if (! $existingContracts->has($ledger)) {
                            $counts['missing']++;

                            continue;
                        }
                        $counts['stamp']++;
                        if (! $dryRun) {
                            DB::table($table)->where('id', $row->entry_id)->whereNull('contract_id')
                                ->update(['contract_id' => $ledger]);
                        }
                    } elseif ((int) $row->stamp_contract_id === $ledger) {
                        $counts['equal']++;
                    } else {
                        $counts['mismatch']++;
                        $mismatches[] = ['entry_id' => (int) $row->entry_id, 'stamp_contract_id' => (int) $row->stamp_contract_id, 'ledger_contract_id' => $ledger];
                    }
                }
            });

        return [$counts, $mismatches];
    }

    private function unstampedWithLedger(): int
    {
        $total = 0;
        foreach (['ticket_note_id' => 'ticket_notes', 'phone_call_id' => 'phone_calls'] as $key => $table) {
            $total += DB::table('prepay_transactions')
                ->join($table, "{$table}.id", '=', "prepay_transactions.{$key}")
                ->join('contracts', 'contracts.id', '=', 'prepay_transactions.contract_id')
                ->whereNull("{$table}.contract_id")
                ->count();
        }

        return $total;
    }

    /** @return array<int|string, string> */
    private function ledgerSums(): array
    {
        return DB::table('prepay_transactions')
            ->groupBy('contract_id')
            ->orderBy('contract_id')
            ->selectRaw('contract_id, SUM(hours) as h, COUNT(*) as n')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->contract_id => $r->h.'|'.$r->n])
            ->all();
    }

    /** @return array<int|string, string> */
    private function balanceColumns(): array
    {
        return DB::table('contracts')
            ->orderBy('id')
            ->get(['id', 'prepay_total', 'prepay_used', 'prepay_expired', 'prepay_balance'])
            ->mapWithKeys(fn ($r) => [(string) $r->id => implode('|', [$r->prepay_total, $r->prepay_used, $r->prepay_expired, $r->prepay_balance])])
            ->all();
    }

    /** @return list<int> */
    private function ambiguousClientIds(): array
    {
        return DB::table('contracts')
            ->join('clients', 'clients.id', '=', 'contracts.client_id')
            ->where('contracts.status', ContractStatus::Active->value)
            ->whereNull('contracts.deleted_at')
            ->whereNull('clients.default_contract_id')
            ->groupBy('contracts.client_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('contracts.client_id')
            ->pluck('contracts.client_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
