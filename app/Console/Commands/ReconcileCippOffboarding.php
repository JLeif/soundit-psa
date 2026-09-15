<?php

namespace App\Console\Commands;

use App\Services\Cipp\Offboarding\OffboardingReconciler;
use Illuminate\Console\Command;

/** Operator-invoked bounded read; deliberately not scheduled or a send-capable job. */
class ReconcileCippOffboarding extends Command
{
    protected $signature = 'cipp:reconcile-offboarding {run : Local staged run ID} {client : Explicit local client ID} {observer : Authorized active staff user ID}';

    protected $description = 'Read and retain scoped CIPP offboarding evidence once; never submit, retry or release a reservation';

    public function handle(OffboardingReconciler $reconciler): int
    {
        foreach (['run', 'client', 'observer'] as $key) {
            if (! ctype_digit($this->argument($key)) || (int) $this->argument($key) < 1) {
                $this->error('Positive local identifiers are required.');

                return self::FAILURE;
            }
        }
        try {
            $result = $reconciler->reconcile((int) $this->argument('run'), (int) $this->argument('client'), (int) $this->argument('observer'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Reconciliation unavailable or unauthorized. Existing intent and evidence remain; do not retry submission.');

            return self::FAILURE;
        }
    }
}
