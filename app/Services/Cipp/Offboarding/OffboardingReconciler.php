<?php

namespace App\Services\Cipp\Offboarding;

use App\Models\TechnicianRun;
use App\Services\Cipp\CippRestWriteClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only vendor authority. No prepare/dispatch/release and no lease-based successor. */
class OffboardingReconciler
{
    public function __construct(private readonly OffboardingScope $scope, private readonly CippRestWriteClient $vendor) {}

    public function reconcile(int $runId, int $clientId, int $observerId): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Reconciliation requires an independent durable transaction.');
        }
        $run = TechnicianRun::where('client_id', $clientId)->where('action_type', 'cipp_stage_offboard_user')->findOrFail($runId);
        $this->scope->reader($observerId, $run);
        $op = DB::table('cipp_offboarding_operations')->where('staged_run_id', $runId)->where('client_id', $clientId)->first();
        if (! $op) {
            throw new RuntimeException('No admitted operation exists in this client scope.');
        }
        $snapshot = json_decode(Crypt::decryptString($op->snapshot), true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($op->plan_hash, OffboardingPlan::hash($snapshot)) || $op->plan_hash !== $run->content_hash
            || $snapshot['input']['client_id'] !== $clientId || $snapshot['input']['ticket_id'] !== $run->ticket_id
            || $op->ticket_id !== $run->ticket_id || $snapshot['body']['reference'] !== $op->reference) {
            throw new RuntimeException('Offboarding operation binding is invalid.');
        }
        $started = now();
        $prior = DB::table('cipp_offboarding_observations')->where('operation_id', $op->id)->orderByDesc('id')->first();
        $previous = $prior ? json_decode(Crypt::decryptString($prior->observation), true, flags: JSON_THROW_ON_ERROR) : [];
        $taskId = $previous['task_id'] ?? null;
        $deploymentId = $previous['deployment_id'] ?? null;
        if ($op->receipt !== null) {
            $receipt = json_decode(Crypt::decryptString($op->receipt), true, flags: JSON_THROW_ON_ERROR);
            $fromReceipt = $receipt['body']['DeploymentId'] ?? null;
            if (OffboardingProgress::uuid($fromReceipt)) {
                if ($deploymentId !== null && $deploymentId !== $fromReceipt) {
                    throw new RuntimeException('Stored deployment identity conflicts.');
                }
                $deploymentId = $fromReceipt;
            }
        }
        try {
            $this->scope->recoveryIntegration($snapshot);
            // Original sealed tenant, not a remapped current client tenant. No AllTenants fallback.
            $query = ['tenantFilter' => $snapshot['body']['tenantFilter']];
            if ($taskId !== null) {
                $rows = $this->vendor->offboardingRead('scheduled', [...$query, 'Id' => $taskId]);
            } else {
                $query += ['Name' => 'Offboarding: '.$snapshot['target_upn'], 'Type' => 'Invoke-CIPPOffboardingJob'];
                $rows = [...$this->vendor->offboardingRead('scheduled', $query),
                    ...$this->vendor->offboardingRead('scheduled', [...$query, 'ShowHidden' => 'true'])];
            }
            $observation = OffboardingProgress::task($rows, $snapshot, $taskId, $deploymentId);
            if (($observation['task_persisted'] ?? false) && $observation['deployment_id'] !== null) {
                $progress = $this->vendor->offboardingRead('progress', ['DeploymentId' => $observation['deployment_id']]);
                $parsed = OffboardingProgress::parse($progress, $snapshot, $observation['task_id']);
                if (($observation['scheduler_state'] === 'Failed' && $parsed['execution'] === 'reported_succeeded')
                    || (in_array($observation['scheduler_state'], ['Planned', 'Running'], true)
                        && $parsed['execution'] === 'reported_succeeded')) {
                    $parsed = OffboardingProgress::unknown('scheduler_progress_conflict');
                }
                $observation = [...$observation, ...$parsed];
            }
        } catch (\Throwable) {
            // No exception text or foreign vendor rows cross the client boundary.
            $observation = OffboardingProgress::unknown('read_unavailable');
        }
        // Correlation, once observed, is retained even across unavailable reads.
        $observation['task_id'] ??= $taskId;
        $observation['deployment_id'] ??= $deploymentId;
        $this->scope->reader($observerId, $run->fresh());

        DB::transaction(function () use ($op, $prior, $previous, $observation, $observerId, $started): void {
            DB::table('cipp_offboarding_operations')->where('id', $op->id)->lockForUpdate()->first();
            $current = DB::table('cipp_offboarding_observations')->where('operation_id', $op->id)->orderByDesc('id')->first();
            $conflict = ($current?->id !== $prior?->id) || ($current?->conflict ?? false)
                || $this->regresses($previous, $observation);
            DB::table('cipp_offboarding_observations')->insert([
                'operation_id' => $op->id, 'observer_id' => $observerId, 'prior_observation_id' => $prior?->id,
                'observation' => Crypt::encryptString(OffboardingPlan::canonical($observation)),
                'conflict' => $conflict, 'started_at' => $started, 'created_at' => now(),
            ]);
            DB::table('cipp_offboarding_audit')->insert(['operation_id' => $op->id, 'event' => 'reconciled_read_only', 'created_at' => now()]);
            // Admission, dispatch generation, run state, spent-plan and fences are untouched.
        }, 1);

        return app(OffboardingStatus::class)->detail($runId, $clientId);
    }

    private function regresses(array $old, array $new): bool
    {
        if (str_contains($new['evidence'], 'conflict') || str_contains($new['evidence'], 'multiple')) {
            return true;
        }
        // An unavailable read is absence of evidence, not contradictory evidence. It is still
        // retained as its own observation, but it never outranks or invalidates an earlier one,
        // so a transient vendor outage cannot latch the sticky conflict.
        if ($new['evidence'] === 'read_unavailable') {
            return false;
        }
        // partial_or_incomplete is ordinary in-progress evidence, ranked with other terminal-row
        // evidence: queued/running may advance into it, but a reported terminal state may not.
        $ranks = ['queued' => 0, 'running' => 1, 'terminal_reported' => 2, 'partial_or_incomplete' => 2,
            'reported_succeeded' => 3, 'reported_failed' => 3];
        if (isset($ranks[$old['execution'] ?? '']) && ($ranks[$new['execution']] ?? -1) < $ranks[$old['execution']]) {
            return true;
        }
        foreach ($old['steps'] ?? [] as $index => $step) {
            if (in_array($step['reported_status'], ['succeeded', 'failed', 'skipped'], true)
                && OffboardingPlan::canonical($new['steps'][$index] ?? []) !== OffboardingPlan::canonical($step)) {
                return true;
            }
        }

        return false;
    }
}
