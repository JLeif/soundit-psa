<?php

namespace App\Services\Technician\Scheduled;

use App\Models\TechnicianRun;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Durable substrate: no vendor client, executor, action bus or adapter dispatch. */
final class ScheduledCoordinator
{
    public function __construct(private ScheduledClock $clock, private ScheduledPolicy $policy) {}

    public function claim(int $id): ?string
    {
        if (! config('scheduled_approvals.enabled') || TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy()) {
            return null;
        }

        return DB::transaction(function () use ($id) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            $now = $this->clock->now();
            if (! $row || $row->state !== 'waiting' || $now->lt($row->not_before) || $now->lt($row->next_attempt_at)) {
                return null;
            }
            if ($now->gte($row->expires_at)) {
                $this->transition($row, 'expired', 'window_closed');

                return null;
            }
            if ($row->attempt >= 100) {
                $this->transition($row, 'blocked', 'attempt_limit');

                return null;
            }
            $nonce = (string) Str::uuid();
            DB::table('scheduled_authorizations')->where('id', $id)->where('state', 'waiting')->update([
                'state' => 'claimed', 'nonce' => $nonce, 'claimed_at' => $now, 'attempt' => $row->attempt + 1,
            ]);

            return $nonce;
        }, 3);
    }

    /** Final fence. Evidence is read outside DB transactions; immutable row/nonce checked again inside. */
    public function intent(int $id, string $nonce, ScheduledEvidence $evidence): bool
    {
        $row = DB::table('scheduled_authorizations')->find($id);
        if (! $row || $row->state !== 'claimed' || $row->nonce !== $nonce) {
            return false;
        }
        try {
            $approved = ApprovalEnvelope::open($row->ciphertext ?? '', $row->digest);
            if (! is_array($approved['human_inputs'] ?? null)) {
                throw new InvalidArgumentException('human_confirmation_missing');
            }
            $run = TechnicianRun::findOrFail($row->run_id);
            $user = $this->policy->approver($row->approver_user_id);
            $live = $evidence->revalidate($run, $user, $approved['binding']);
            if (ApprovalEnvelope::canonical($live) !== ApprovalEnvelope::canonical($approved['binding'])) {
                throw new InvalidArgumentException('identity_changed');
            }
        } catch (\Throwable) {
            return $this->block($id, $nonce, 'preflight_refused');
        }

        return DB::transaction(function () use ($id, $nonce, $approved) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'claimed' || $row->nonce !== $nonce) {
                return false;
            }
            $now = $this->clock->now();
            if ($now->gte($row->expires_at)) {
                $this->transition($row, 'expired', 'window_closed');

                return false;
            }
            try {
                // Evidence was evaluated against this exact envelope. Never authorize a
                // ciphertext swapped while the read-only provider was running.
                $lockedEnvelope = ApprovalEnvelope::open($row->ciphertext ?? '', $row->digest);
                if (ApprovalEnvelope::canonical($lockedEnvelope) !== ApprovalEnvelope::canonical($approved)) {
                    throw new InvalidArgumentException('envelope_changed_during_preflight');
                }
                $run = TechnicianRun::whereKey($row->run_id)->lockForUpdate()->firstOrFail();
                $this->policy->approver($row->approver_user_id);
                $this->policy->ticket($run);
                $this->policy->lineage($run, $row->originating_mcp_token_id);
                foreach (['run_id', 'revision', 'client_id', 'ticket_id', 'action_type', 'direct_tool', 'content_hash', 'approver_user_id', 'originating_mcp_token_id', 'display_timezone', 'local_start', 'local_end', 'start_offset', 'end_offset'] as $key) {
                    if ((string) $approved[$key] !== (string) $row->$key) {
                        throw new InvalidArgumentException('envelope_binding_changed');
                    }
                }
                if ($run->state->value !== 'scheduled' || $run->content_hash !== $row->content_hash
                    || $run->action_type !== $row->action_type || $run->ticket_id != $row->ticket_id || $run->client_id != $row->client_id
                    || ApprovalEnvelope::canonical($run->proposed_meta ?? []) !== $approved['provenance']
                    || $approved['not_before'] !== substr($row->not_before, 0, 19) || $approved['expires_at'] !== substr($row->expires_at, 0, 19)
                    || ActionRegistry::directTool($row->action_type) !== $row->direct_tool) {
                    throw new InvalidArgumentException('proposal_changed');
                }
            } catch (\Throwable) {
                $this->transition($row, 'blocked', 'authorization_changed');

                return false;
            }
            if (! config('scheduled_approvals.enabled') || TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy() || $now->lt($row->not_before)) {
                return false;
            }
            // PR1 cannot create a dispatchable intent: allowlist != an installed adapter.
            if (! ActionRegistry::adapterAvailable($row->action_type)) {
                $this->transition($row, 'blocked', 'adapter_unavailable');

                return false;
            }
            $finalNow = $this->clock->now();

            // Re-read after all potentially slow preflight/clock work. Half-open window.
            return DB::table('scheduled_authorizations')->where('id', $id)->where('nonce', $nonce)->where('state', 'claimed')
                ->where('not_before', '<=', $finalNow)->where('expires_at', '>', $finalNow)
                ->update(['state' => 'dispatch_intent', 'intent_at' => $finalNow]) === 1;
        }, 3);
    }

    private function block(int $id, string $nonce, string $reason): bool
    {
        DB::transaction(function () use ($id, $nonce, $reason) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if ($row && $row->state === 'claimed' && $row->nonce === $nonce) {
                $this->transition($row, 'blocked', $reason);
            }
        }, 3);

        return false;
    }

    public function cancel(int $id, int $userId): bool
    {
        $this->policy->approver($userId);

        return DB::transaction(function () use ($id, $userId) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            $this->policy->approver($userId);
            // Only the approver may cancel this substrate; UI policy can narrow further.
            if (! $row || $row->approver_user_id != $userId || ! in_array($row->state, ['waiting', 'claimed'], true)) {
                return false;
            }
            $this->transition($row, 'cancelled', 'operator_cancelled');

            return true;
        }, 3);
    }

    /** Only explicit pre-dispatch availability reasons may defer an attempt. */
    public function defer(int $id, string $nonce, string $reason, ?\Carbon\CarbonImmutable $cooldownUntil = null): bool
    {
        if (! in_array($reason, ['offline', 'read_unavailable', 'cooldown', 'kill_switch', 'clock_unhealthy'], true)) {
            throw new InvalidArgumentException('not_retryable');
        }

        return DB::transaction(function () use ($id, $nonce, $reason, $cooldownUntil) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'claimed' || $row->nonce !== $nonce || $row->intent_at !== null) {
                return false;
            }
            $now = $this->clock->now();
            if ($now->gte($row->expires_at)) {
                $this->transition($row, 'expired', 'window_closed');

                return false;
            }
            if ($row->attempt >= 100) {
                $this->transition($row, 'blocked', 'attempt_limit');

                return false;
            }
            $delay = min(15, 2 ** min(4, max(0, $row->attempt - 1)));
            $next = $now->addMinutes($delay);
            if ($cooldownUntil && $cooldownUntil->gt($next)) {
                $next = $cooldownUntil;
            }
            $next = min($next, \Carbon\CarbonImmutable::parse($row->expires_at, 'UTC'));
            $sequence = $row->transition_sequence;
            if ($row->reason !== $reason) {
                $sequence++;
                DB::table('scheduled_note_outbox')->insert(['authorization_id' => $id, 'transition_sequence' => $sequence,
                    'event' => 'waiting', 'reason' => $reason, 'created_at' => $now]);
            }
            DB::table('scheduled_authorizations')->where('id', $id)->update(['state' => 'waiting', 'nonce' => null,
                'claimed_at' => null, 'reason' => $reason, 'next_attempt_at' => $next, 'transition_sequence' => $sequence]);

            return true;
        }, 3);
    }

    public function recover(int $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $now = $this->clock->now();
            if ($row->state === 'dispatch_intent' && $row->intent_at && $now->gte(\Carbon\CarbonImmutable::parse($row->intent_at, 'UTC')->addMinutes(5))) {
                $this->transition($row, 'uncertain', 'intent_outcome_unknown');
            } elseif (in_array($row->state, ['waiting', 'claimed'], true) && $now->gte($row->expires_at)) {
                $this->transition($row, 'expired', 'window_closed');
            } elseif ($row->state === 'claimed' && $row->claimed_at && $now->gte(\Carbon\CarbonImmutable::parse($row->claimed_at, 'UTC')->addMinutes(5))) {
                // No intent means no send. Invalidate nonce before exposing a retry.
                $delay = min(15, 2 ** min(4, max(0, $row->attempt - 1)));
                DB::table('scheduled_authorizations')->where('id', $id)->update([
                    'state' => 'waiting', 'nonce' => null, 'claimed_at' => null, 'next_attempt_at' => min($now->addMinutes($delay), \Carbon\CarbonImmutable::parse($row->expires_at, 'UTC')),
                ]);
            }
        }, 3);
    }

    /** Terminal settlement never changes an uncertain row or releases it to ordinary approval. */
    public function settle(int $id, string $nonce, string $outcome): bool
    {
        if (! in_array($outcome, ['completed', 'submitted', 'uncertain'], true)) {
            throw new InvalidArgumentException('invalid_outcome');
        }

        return DB::transaction(function () use ($id, $nonce, $outcome) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'dispatch_intent' || $row->nonce !== $nonce) {
                return false;
            }
            $this->transition($row, $outcome, $outcome === 'uncertain' ? 'intent_outcome_unknown' : 'vendor_receipt');

            return true;
        }, 3);
    }

    private function transition(object $row, string $state, string $reason): void
    {
        $now = $this->clock->now();
        $sequence = $row->transition_sequence + 1;
        DB::table('scheduled_authorizations')->where('id', $row->id)->update([
            'state' => $state, 'reason' => $reason, 'finished_at' => $now, 'transition_sequence' => $sequence,
        ]);
        DB::table('scheduled_note_outbox')->insert(['authorization_id' => $row->id, 'transition_sequence' => $sequence, 'event' => $state, 'reason' => $reason, 'created_at' => $now]);
        // Uncertain/submitted reservations remain for explicit read-only reconciliation.
        if (! in_array($state, ['uncertain', 'submitted'], true)) {
            DB::table('scheduled_target_fences')->where('authorization_id', $row->id)->delete();
            DB::table('scheduled_run_fences')->where('authorization_id', $row->id)->delete();
        }
        // The proposal stays Scheduled even at terminal state: legacy reconfirm cannot revive it.
    }
}
