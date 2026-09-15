<?php

namespace App\Services\Cipp\Offboarding;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Technician\TechnicianApprovalResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Dedicated admission path: never the generic CIPP execute/releaseClaim path. */
class OffboardingAdmission
{
    public function __construct(private readonly OffboardingScope $scope, private readonly CippRestWriteClient $client) {}

    public function stage(array $input, int $clientId, int $tokenId): array
    {
        try {
            $input = OffboardingPlan::validate($input);
            if ($input['client_id'] !== $clientId) {
                throw new RuntimeException('Offboarding requires an explicitly bound client.');
            }
            $this->scope->token($tokenId);
            $installation = Setting::getValue('cipp_offboarding_installation_id');
            // The setting is validated case-insensitively and namespaced lowercase by the scope;
            // the reference regex is case-sensitive, so normalize here too.
            $reference = 'soundpsa-offboard:'.strtolower((string) $installation).':'.Str::uuid();
            $snapshot = $this->scope->resolve($input, $reference);
            $snapshot['token_id'] = $tokenId;
            (new OffboardingLedger(DB::connection()))->assertAvailable($snapshot);
            $hash = OffboardingPlan::hash($snapshot);
            $run = DB::transaction(function () use ($snapshot, $hash): TechnicianRun {
                // Lock the local ticket to coalesce same-ticket proposals without reviving spent rows.
                DB::table('tickets')->where('id', $snapshot['input']['ticket_id'])->lockForUpdate()->first();
                $existing = TechnicianRun::where('ticket_id', $snapshot['input']['ticket_id'])
                    ->where('action_type', 'cipp_stage_offboard_user')->where('state', TechnicianRunState::AwaitingApproval)->get();
                foreach ($existing as $candidate) {
                    $old = $this->snapshot($candidate);
                    $a = $old;
                    $b = $snapshot;
                    unset($a['body']['reference'], $b['body']['reference']);
                    if (OffboardingPlan::hash($a) === OffboardingPlan::hash($b)) {
                        return $candidate;
                    }
                }

                return TechnicianRun::create([
                    'ticket_id' => $snapshot['input']['ticket_id'], 'client_id' => $snapshot['input']['client_id'],
                    'action_type' => 'cipp_stage_offboard_user', 'content_hash' => $hash,
                    'state' => TechnicianRunState::AwaitingApproval,
                    'proposed_content' => $this->preview($snapshot),
                    'proposed_meta' => ['revision' => 1, 'plan_hash' => $hash, 'actions' => $snapshot['input']['actions'],
                        'encrypted_payload' => Crypt::encryptString(OffboardingPlan::canonical($snapshot))],
                    'tokens_used' => 0,
                ]);
            });

            return ['success' => true, 'run_id' => $run->id, 'revision' => 1, 'plan_hash' => $run->content_hash,
                'admission' => 'awaiting_approval', 'message' => 'Sealed offboarding proposal held for approval. No job submitted; selected effects remain unverified.'];
        } catch (\Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Already attempted or reserved: operation ')) {
                return ['error' => $e->getMessage()];
            }

            // Mapping, upstream and database exception strings can contain customer data.
            return ['error' => 'Offboarding could not be staged: verify explicit grant, client/ticket/identity mappings and installation prerequisites. No offboarding POST was made.'];
        }
    }

    public function approve(TechnicianRun $run, int $approverId, array $approval): TechnicianApprovalResult
    {
        try {
            $this->scope->approver($approverId);
            $snapshot = $this->snapshot($run);
            if (($approval['revision'] ?? null) !== '1' || ($approval['plan_hash'] ?? null) !== $run->content_hash
                || ($approval['actions'] ?? null) !== $snapshot['input']['actions']) {
                throw new RuntimeException('Approval must confirm the displayed revision, hash and every selected action.');
            }
            $this->scope->token($snapshot['token_id']);
            $fresh = $this->scope->resolve($snapshot['input'], $snapshot['body']['reference']);
            $fresh['token_id'] = $snapshot['token_id'];
            if (! hash_equals($run->content_hash, OffboardingPlan::hash($fresh))) {
                throw new RuntimeException('Offboarding identity or plan drift requires a new preview and approval.');
            }
            $this->scope->dependenciesAndScheduler($snapshot);
            $ledger = new OffboardingLedger(DB::connection());
            $operation = $ledger->prepare($run->id, $approverId, $snapshot, $run->content_hash);
            $result = $ledger->dispatch($operation['operation_id'], function (array $sealed) use ($approverId, $run): bool {
                $this->scope->approver($approverId);
                $this->scope->token($sealed['token_id']);
                $current = $this->scope->resolve($sealed['input'], $sealed['body']['reference']);
                $current['token_id'] = $sealed['token_id'];
                if (! hash_equals($run->content_hash, OffboardingPlan::hash($current))) {
                    return false;
                }
                $this->scope->dependenciesAndScheduler($sealed);

                return true;
            }, fn (array $body) => $this->client->submitOffboardingOnce($body));

            return new TechnicianApprovalResult('offboarding_admission', message: 'Operation '.$operation['operation_id'].': '.$result['admission'].'. This is admission evidence, not completed offboarding. No replay is permitted; progress/reconciliation is a separate capability.');
        } catch (\Throwable $e) {
            // Only ledger conflict text is safe to expose (local IDs/date, no source values).
            $message = str_starts_with($e->getMessage(), 'Already attempted or reserved: operation ')
                ? $e->getMessage() : 'Offboarding admission not established. Verify approval seal, active grant, identities, Scheduler.Read and current Sequential compatibility evidence. Existing intent/fences remain; never retry vendor submission.';

            return new TechnicianApprovalResult('gate_declined', message: $message);
        }
    }

    private function snapshot(TechnicianRun $run): array
    {
        if ($run->action_type !== 'cipp_stage_offboard_user') {
            throw new RuntimeException('Wrong staged action type.');
        }
        $snapshot = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ! hash_equals($run->content_hash, OffboardingPlan::hash($snapshot))
            || $snapshot['input']['client_id'] !== $run->client_id || $snapshot['input']['ticket_id'] !== $run->ticket_id
            || $run->proposed_content !== $this->preview($snapshot)
            || ($run->proposed_meta['revision'] ?? null) !== 1
            || ($run->proposed_meta['plan_hash'] ?? null) !== $run->content_hash
            || ($run->proposed_meta['actions'] ?? null) !== $snapshot['input']['actions']) {
            throw new RuntimeException('Offboarding snapshot integrity failure.');
        }

        return $snapshot;
    }

    private function preview(array $snapshot): string
    {
        $lines = [
            'OFFBOARDING — selected plan only; run now after approval, not scheduled.',
            'Target: '.$snapshot['target_upn'],
            'Successor: '.($snapshot['successor_upn'] ?? 'none'),
            'Selected actions: '.implode(', ', $snapshot['input']['actions']),
            'Keep copy: '.(array_key_exists('keep_copy', $snapshot['input']) ? ($snapshot['input']['keep_copy'] ? 'yes' : 'no') : 'not applicable'),
            'Delegation/forwarding expose data and may enable impersonation. OneDrive grants site-admin access, not ownership transfer.',
            'All licenses retained; shared conversion is not a licensing-compliance guarantee. No delete, wipe, password reset, bulk removals, rerun, scheduling or notifications.',
            'Intended vendor step order depends on verified Sequential runtime support. No transaction or automatic compensation; identity can change after validation.',
            'Queue acceptance is not execution or verified effects. Admission cannot prove complete offboarding; read-only reconciliation/progress follows separately.',
            'Reason: '.$snapshot['input']['reason'],
        ];

        return implode("\n", $lines);
    }
}
