<?php

namespace App\Services\Cipp\Offboarding;

use App\Enums\TechnicianRunState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Durable single-send primitive. Never retries a closure which can send bytes. */
final class OffboardingLedger
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Called only after authorization and fresh identity checks by the admission adapter.
     * Snapshot contains server-derived namespace, target identity, normalized input and body.
     * A failure rolls back run claim, fences, operation and audit together.
     */
    public function prepare(int $runId, int $approverId, array $snapshot, string $seal): array
    {
        $this->outsideTransaction();
        if (! hash_equals(OffboardingPlan::hash($snapshot), $seal)) {
            throw new LogicException('Offboarding snapshot seal mismatch.');
        }
        $this->validateSnapshot($snapshot);

        return $this->db->transaction(function () use ($runId, $approverId, $snapshot, $seal): array {
            $run = $this->db->table('technician_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->action_type !== 'cipp_stage_offboard_user'
                || $run->client_id !== $snapshot['input']['client_id']
                || $run->ticket_id !== $snapshot['input']['ticket_id']
                || ! hash_equals($run->content_hash, $seal)) {
                throw new LogicException('Offboarding run binding mismatch.');
            }
            $prior = $this->db->table('cipp_offboarding_operations')->where('staged_run_id', $runId)->first();
            if ($prior) {
                if (! hash_equals($prior->plan_hash, $seal)) {
                    throw new LogicException('Offboarding operation seal mismatch.');
                }

                return ['operation_id' => $prior->id, 'admission' => $prior->admission, 'created' => false];
            }
            if ($run->state !== TechnicianRunState::AwaitingApproval->value) {
                throw new RuntimeException('Offboarding proposal is no longer awaiting approval.');
            }
            $namespace = $snapshot['namespace'];
            $keys = [
                $this->key(['target', $namespace, strtolower($snapshot['target_id'])]),
                $this->key(['upn', $namespace, strtolower($snapshot['target_upn'])]),
            ];
            sort($keys, SORT_STRING);
            $planKey = $this->key(['plan', $namespace, strtolower($snapshot['target_id']),
                $snapshot['input']['actions'], $snapshot['successor_id'], $snapshot['input']['keep_copy'] ?? null]);
            $spent = $this->db->table('cipp_offboarding_spent_plans')->where('plan_key', $planKey)->first();
            if ($spent) {
                throw new RuntimeException($this->conflict($spent->operation_id, $run->client_id));
            }
            foreach ($keys as $key) {
                $fence = $this->db->table('cipp_offboarding_target_fences')->where('fence_key', $key)->first();
                if ($fence) {
                    throw new RuntimeException($this->conflict($fence->operation_id, $run->client_id));
                }
            }
            $now = now();
            $id = (string) Str::uuid();
            $this->db->table('cipp_offboarding_operations')->insert([
                'id' => $id, 'staged_run_id' => $runId, 'client_id' => $run->client_id,
                'ticket_id' => $run->ticket_id, 'plan_hash' => $seal, 'revision' => 1,
                'snapshot' => Crypt::encryptString(OffboardingPlan::canonical($snapshot)),
                'reference' => $snapshot['body']['reference'], 'admission' => 'prepared',
                'approver_id' => $approverId, 'approved_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            // Unique inserts, not a read-only preflight, arbitrate concurrent different runs.
            // A duplicate/deadlock aborts the whole transaction; caller may inspect, never send.
            foreach ($keys as $key) {
                $this->db->table('cipp_offboarding_target_fences')->insert([
                    'fence_key' => $key, 'operation_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->db->table('cipp_offboarding_spent_plans')->insert([
                'plan_key' => $planKey, 'operation_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $claimed = $this->db->table('technician_runs')->where('id', $runId)
                ->where('state', TechnicianRunState::AwaitingApproval->value)
                ->where('content_hash', $seal)
                ->update(['state' => TechnicianRunState::Executing->value, 'claimed_at' => $now, 'updated_at' => $now]);
            if ($claimed !== 1) {
                throw new LogicException('Offboarding run claim lost.');
            }
            $this->audit($id, 'prepared');

            return ['operation_id' => $id, 'admission' => 'prepared', 'created' => true];
        }, 1);
    }

    /**
     * Preflight callback must recheck authorization, identity, dependency evidence and scheduler.
     * Network mutation callback receives ONLY the sealed body, once, AFTER intent COMMIT.
     * Repeated delivery after intent does not even execute preflight.
     */
    public function dispatch(string $id, callable $preflight, callable $send): array
    {
        $this->outsideTransaction();
        $operation = $this->db->table('cipp_offboarding_operations')->where('id', $id)->first();
        if (! $operation) {
            throw new LogicException('Unknown offboarding operation.');
        }
        if ($operation->admission !== 'prepared') {
            return ['operation_id' => $id, 'admission' => $operation->admission, 'sent' => false];
        }
        $snapshot = json_decode(Crypt::decryptString($operation->snapshot), true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($operation->plan_hash, OffboardingPlan::hash($snapshot))) {
            throw new LogicException('Offboarding snapshot integrity failure.');
        }
        $this->validateSnapshot($snapshot);
        if ($preflight($snapshot, $operation) !== true) {
            throw new RuntimeException('Offboarding preflight was not established; no send authorized.');
        }
        $generation = (string) Str::uuid();
        $won = $this->db->transaction(function () use ($id, $operation, $snapshot, $generation): bool {
            $changed = $this->db->table('cipp_offboarding_operations')->where('id', $id)
                ->where('admission', 'prepared')->where('plan_hash', $operation->plan_hash)
                ->whereNull('send_intent_at')->update([
                    'admission' => 'send_intent', 'send_intent_at' => now(),
                    'dispatch_generation' => $generation,
                    'payload_digest' => OffboardingPlan::hash($snapshot['body']), 'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                return false;
            }
            $this->audit($id, 'send_intent');

            return true;
        }, 1);
        if (! $won) {
            return ['operation_id' => $id, 'admission' => 'already_claimed', 'sent' => false];
        }
        // No transaction, retry, releaseClaim or lease recovery around this call.
        try {
            $response = $send($snapshot['body']);
            $receipt = OffboardingReceipt::classify($response, $snapshot['target_upn']);
        } catch (\Throwable) {
            $response = null;
            $receipt = ['admission' => 'ambiguous', 'response_class' => 'transport_unknown', 'http_status' => null];
        }
        try {
            $this->db->transaction(function () use ($id, $generation, $receipt, $response): void {
                $encoded = json_encode($response, JSON_THROW_ON_ERROR);
                $changed = $this->db->table('cipp_offboarding_operations')->where('id', $id)
                    ->where('admission', 'send_intent')->where('dispatch_generation', $generation)
                    ->update([
                        ...$receipt, 'response_digest' => hash('sha256', $encoded),
                        'receipt' => strlen($encoded) <= 16384 ? Crypt::encryptString($encoded) : null,
                        'updated_at' => now(),
                    ]);
                if ($changed !== 1) {
                    throw new LogicException('Offboarding receipt generation lost.');
                }
                $this->audit($id, $receipt['admission']);
            }, 1);
        } catch (\Throwable) {
            // The committed intent and fences remain. Never call generic claim release.
            return ['operation_id' => $id, 'admission' => 'ambiguous', 'sent' => true, 'receipt_persisted' => false];
        }

        return ['operation_id' => $id, 'admission' => $receipt['admission'], 'sent' => true, 'receipt_persisted' => true];
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('Offboarding admission requires an independent durable transaction.');
        }
    }

    private function key(array $value): string
    {
        // Must survive application encryption-key rotation: fences are permanent.
        return hash('sha256', OffboardingPlan::canonical($value));
    }

    private function audit(string $id, string $event): void
    {
        $this->db->table('cipp_offboarding_audit')->insert(['operation_id' => $id, 'event' => $event, 'created_at' => now()]);
    }

    private function conflict(string $id, int $clientId): string
    {
        $prior = $this->db->table('cipp_offboarding_operations')->where('id', $id)->first();
        if (! $prior || $prior->client_id !== $clientId) {
            return 'Offboarding target has a conflicting reservation outside this client scope; an authorized operator must investigate on a separate new card.';
        }

        return "Already attempted or reserved: operation {$prior->id}, ticket {$prior->ticket_id}, date {$prior->approved_at} UTC. No reset or replay is available; request a separately authorized lifecycle decision on a new card.";
    }

    private function validateSnapshot(array $snapshot): void
    {
        foreach (['namespace', 'target_id', 'target_upn', 'successor_id', 'input', 'body'] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new LogicException('Incomplete offboarding snapshot.');
            }
        }
        if (! is_array($snapshot['namespace']) || count($snapshot['namespace']) !== 3
            || array_filter($snapshot['namespace'], fn ($v) => ! is_string($v) || trim($v) === '')
            || ! is_string($snapshot['target_id']) || $snapshot['target_id'] === ''
            || ($snapshot['successor_id'] !== null && (! is_string($snapshot['successor_id'])
                || $snapshot['successor_id'] === '' || strcasecmp($snapshot['successor_id'], $snapshot['target_id']) === 0))) {
            throw new LogicException('Canonical offboarding namespace and target are required.');
        }
        $expected = OffboardingPlan::serialize($snapshot['input'], $snapshot['body']['tenantFilter'], $snapshot['target_upn'], $snapshot['successor_upn'] ?? null, $snapshot['body']['reference']);
        if (OffboardingPlan::canonical($expected) !== OffboardingPlan::canonical($snapshot['body'])) {
            throw new LogicException('Offboarding body differs from selected plan.');
        }
    }
}
