<?php

namespace App\Services\AutoElevate;

/**
 * Outcome of one AutoElevateAssetSyncService run. Unmatched and ambiguous computers are
 * REPORTED here, never turned into assets. Per-client detail is keyed by client id; machine
 * names stay in this object (for the operator's screen/console) and are never logged.
 */
class AutoElevateAssetSyncReport
{
    /** Assets linked or refreshed this run. */
    public int $linked = 0;

    /** Vendor computers with no live asset of that hostname in the mapped client. */
    public int $unmatched = 0;

    /** Vendor computers whose hostname fits 2+ candidate assets (or 2+ computers share it) — left unlinked. */
    public int $ambiguous = 0;

    /** Assets whose link was cleared because their computer was not in a successful read. */
    public int $cleared = 0;

    /**
     * @var array<int, array{unmatched: list<string>, ambiguous: list<string>, linked: int}>
     */
    public array $perClient = [];

    /** @var array<int, string> client id => AutoElevateReadException reason */
    public array $failedClients = [];

    public const STOP_CONSECUTIVE_429 = 'consecutive_http_429';

    public const STOP_DEADLINE = 'run_deadline';

    /** Why the run stopped reading before the last client (a STOP_* constant), or null. */
    public ?string $stoppedEarly = null;

    /** @var list<int> client ids NOT read because the run stopped early; their links were left unchanged */
    public array $skippedClients = [];

    public function forClient(int $clientId): void
    {
        $this->perClient[$clientId] ??= ['unmatched' => [], 'ambiguous' => [], 'linked' => 0];
    }

    public function recordUnmatched(int $clientId, string $machineName): void
    {
        $this->forClient($clientId);
        $this->perClient[$clientId]['unmatched'][] = $machineName;
        $this->unmatched++;
    }

    public function recordAmbiguous(int $clientId, string $machineName): void
    {
        $this->forClient($clientId);
        $this->perClient[$clientId]['ambiguous'][] = $machineName;
        $this->ambiguous++;
    }

    public function recordLinked(int $clientId): void
    {
        $this->forClient($clientId);
        $this->perClient[$clientId]['linked']++;
        $this->linked++;
    }

    public function recordFailure(int $clientId, string $reason): void
    {
        $this->failedClients[$clientId] = $reason;
    }

    public function recordSkipped(int $clientId): void
    {
        $this->skippedClients[] = $clientId;
    }

    /** A failed read OR an early stop: either way some mapped client was not synced this run. */
    public function hasFailures(): bool
    {
        return $this->failedClients !== [] || $this->stoppedEarly !== null;
    }

    public function summary(): string
    {
        return sprintf(
            '%d linked, %d unmatched, %d ambiguous, %d cleared, %d client read(s) failed, %d client(s) skipped',
            $this->linked, $this->unmatched, $this->ambiguous, $this->cleared, count($this->failedClients),
            count($this->skippedClients),
        );
    }
}
