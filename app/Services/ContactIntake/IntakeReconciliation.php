<?php

namespace App\Services\ContactIntake;

use Illuminate\Support\Facades\DB;

/**
 * Accepted versus the ledger's rows by state (part-3 acceptance test 9, card qmcqiE7s).
 * "Accepted" is contact_intake_counters.accepted, which SubmissionLedger increments only
 * on a first-time insert. It is not derived from contact_submissions, so a missing row
 * shows up as a difference.
 */
final class IntakeReconciliation
{
    public const STATES = ['pending', 'processing', 'processed', 'quarantined'];

    /** Called by SubmissionLedger inside its transaction, after a non-duplicate insert. */
    public static function countAcceptance(): void
    {
        $updated = DB::table('contact_intake_counters')->where('name', 'accepted')->increment('total');
        if ($updated !== 1) {
            // Throwing rolls back the ledger insert with it, so the two never disagree.
            throw new \RuntimeException('Contact intake accepted counter row is missing.');
        }
    }

    /**
     * Read-only.
     *
     * @return array{accepted: ?int, ledger: int, states: array<string, int>, unknown: array<string, int>, processing: int, balanced: bool}
     */
    public function measure(): array
    {
        $accepted = DB::table('contact_intake_counters')->where('name', 'accepted')->value('total');
        $byState = DB::table('contact_submissions')->selectRaw('state, COUNT(*) AS total')
            ->groupBy('state')->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();
        $states = [];
        foreach (self::STATES as $state) {
            $states[$state] = $byState[$state] ?? 0;
        }
        $unknown = array_diff_key($byState, $states);
        ksort($unknown);
        $ledger = array_sum($states);
        $accepted = $accepted === null ? null : (int) $accepted;

        return [
            'accepted' => $accepted,
            'ledger' => $ledger,
            'states' => $states,
            'unknown' => $unknown,
            'processing' => $states['processing'],
            'balanced' => $accepted !== null && $accepted === $ledger
                && $unknown === [] && $states['processing'] === 0,
        ];
    }
}
