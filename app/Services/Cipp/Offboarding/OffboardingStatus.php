<?php

namespace App\Services\Cipp\Offboarding;

use App\Models\TechnicianRun;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Local-only detail mode, including terminal receipts. No network, replay or reset. */
class OffboardingStatus
{
    public function detail(int $runId, ?int $clientId): array
    {
        if (! $clientId || $runId < 1) {
            return ['error' => 'Offboarding detail requires explicit client scope and a positive run_id.'];
        }
        $run = TechnicianRun::where('client_id', $clientId)->where('action_type', 'cipp_stage_offboard_user')->find($runId);
        if (! $run) {
            return ['error' => 'Offboarding run not found in this client scope.'];
        }
        $op = DB::table('cipp_offboarding_operations')->where('staged_run_id', $runId)->where('client_id', $clientId)->where('ticket_id', $run->ticket_id)->first();
        $row = $op ? DB::table('cipp_offboarding_observations')->where('operation_id', $op->id)->orderByDesc('id')->first() : null;
        $observation = OffboardingProgress::unknown('not_observed');
        if ($row) {
            try {
                $observation = json_decode(Crypt::decryptString($row->observation), true, flags: JSON_THROW_ON_ERROR);
                if ($row->conflict) {
                    $observation = OffboardingProgress::unknown('conflicting_observations');
                }
            } catch (\Throwable) {
                $observation = OffboardingProgress::unknown('unreadable_observation');
            }
        }

        return ['run_id' => $run->id, 'operation_id' => $op?->id, 'ticket_id' => $run->ticket_id,
            'revision' => $op?->revision ?? 1, 'run_state' => $run->state->value,
            'admission' => $op?->admission ?? $run->state->value,
            'queue_acceptance_reported' => in_array($op?->response_class, ['queue_reported', 'queue_reported_uncorrelated'], true),
            'task_persisted' => $observation['task_persisted'] ?? false,
            'scheduler_state' => $observation['scheduler_state'] ?? null,
            'execution' => $observation['execution'], 'verification' => 'unverified',
            'evidence' => $observation['evidence'], 'steps' => $observation['steps'],
            'observed_at' => $row ? \Carbon\CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String() : null,
            'stale' => ! $row || \Carbon\CarbonImmutable::parse($row->created_at, 'UTC')->lt(now()->subMinutes(5)),
            'needs_operator_review' => true, 'can_retry' => false,
            'next_action' => $op ? 'Authorized read-only reconciliation; no replay or fence release. Effects remain unverified.' : 'Review the held proposal; no job admission is recorded.'];
    }
}
