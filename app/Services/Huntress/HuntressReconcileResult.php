<?php

namespace App\Services\Huntress;

use App\Services\SyncResult;

/** Aggregate, non-identifying diagnostics for the link-only lifecycle pollers. */
class HuntressReconcileResult extends SyncResult
{
    /** Tickets admitted by the validated-link predicate before attempting a read. */
    public int $eligible = 0;

    /** Successful by-id reads, including open and scope-refused responses. */
    public int $checked = 0;

    public array $refusals = [];

    public array $failures = [];

    public function recordSkipped(string $message): void
    {
        parent::recordSkipped($message);
        $reason = $this->reason($message);
        $this->refusals[$reason] = ($this->refusals[$reason] ?? 0) + 1;
    }

    public function recordError(string $message): void
    {
        parent::recordError($message);
        $reason = $this->reason($message);
        $this->failures[$reason] = ($this->failures[$reason] ?? 0) + 1;
    }

    private function reason(string $message): string
    {
        return preg_replace('/^#\d+: /', '', $message);
    }

    public function failed(): bool
    {
        return $this->errors > 0 || ($this->checked === 0 && $this->eligible > 0);
    }

    public function summary(): string
    {
        $summary = "{$this->eligible} eligible, {$this->checked} checked, {$this->updated} resolved, {$this->skipped} skipped, {$this->errors} errors";
        if ($this->eligible === 0 && $this->checked === 0 && $this->errors === 0) {
            $summary .= ', clean no-op: no eligible validated links';
        }
        foreach (['skipped' => $this->refusals, 'failed' => $this->failures] as $kind => $reasons) {
            ksort($reasons);
            foreach ($reasons as $reason => $count) {
                $summary .= ", {$kind}:{$reason}={$count}";
            }
        }

        return $summary;
    }
}
