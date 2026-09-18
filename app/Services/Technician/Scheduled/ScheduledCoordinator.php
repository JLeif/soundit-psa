<?php

namespace App\Services\Technician\Scheduled;

use App\Models\TechnicianRun;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Durable claim/intent/settlement fence. Vendor I/O stays outside transactions. */
final class ScheduledCoordinator
{
    public function __construct(private ScheduledClock $clock, private ScheduledPolicy $policy) {}

    public function claim(int $id): ?string
    {
        if (TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy()) {
            return null;
        }
        // Quiescing is not cancellation. A live sweep reads the marker HERE, before it
        // can claim: otherwise it would claim, issue intent and then hit the send fence,
        // burning a never-in-flight waiting approval to terminal abandoned_no_send.
        if (app(ScheduledQuiescence::class)->at() !== null) {
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
            // Re-read the marker INSIDE this transaction, as late as possible. The check
            // above it is read-then-act, so on its own it would still hand a waiting row to
            // a worker whose only remaining exit is the send fence.
            // This MUST be the locking read: the ordinary reads above have already opened
            // this transaction's REPEATABLE READ view, so a plain at() would be answered
            // from that older snapshot and miss a marker committed after it. The lock is
            // held until commit, so no marker can land between this check and the UPDATE.
            if (app(ScheduledQuiescence::class)->atForUpdate() !== null) {
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
        if (TechnicianConfig::killSwitchEngaged()) {
            $this->defer($id, $nonce, 'kill_switch');

            return false;
        }
        try {
            $approved = ApprovalEnvelope::open($row->ciphertext ?? '', $row->digest);
            if (! is_array($approved['human_inputs'] ?? null)
                || ! is_array($approved['binding']['human_inputs'] ?? null)
                || ApprovalEnvelope::canonical($approved['human_inputs']) !== ApprovalEnvelope::canonical($approved['binding']['human_inputs'])) {
                throw new InvalidArgumentException('human_confirmation_mismatch');
            }
            $run = TechnicianRun::findOrFail($row->run_id);
            // A token-approved row (immediate lane) has no approver to revalidate; its
            // authority is the token grant, rechecked by lineage() inside the transaction
            // below. Calling approver(null) would throw approver_revoked and block every
            // such row, so the mode is read from the row itself.
            $approver = ScheduledApprover::fromRow(
                $row->approver_user_id === null ? null : (int) $row->approver_user_id,
                $row->originating_mcp_token_id === null ? null : (int) $row->originating_mcp_token_id,
            );
            $user = $approver->isToken() ? null : $this->policy->approver((int) $approver->userId);
            $live = $evidence->revalidate($run, $user, $approved['binding']);
            if (ApprovalEnvelope::canonical($live) !== ApprovalEnvelope::canonical($approved['binding'])) {
                throw new InvalidArgumentException('identity_changed');
            }
        } catch (ScheduledUnavailable $e) {
            $this->defer($id, $nonce, $e->getMessage());

            return false;
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
                $lockedApprover = ScheduledApprover::fromRow(
                    $row->approver_user_id === null ? null : (int) $row->approver_user_id,
                    $row->originating_mcp_token_id === null ? null : (int) $row->originating_mcp_token_id,
                );
                if (! $lockedApprover->isToken()) {
                    $this->policy->approver((int) $lockedApprover->userId);
                }
                $this->policy->ticket($run);
                // Token-approved: lineage() now demands the `<tool>:immediate` grant is
                // STILL held. A downgrade, pause or revoke lands the row blocked here.
                $this->policy->lineage($run, $row->originating_mcp_token_id, $lockedApprover);
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
            if (TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy() || $now->lt($row->not_before)) {
                return false;
            }
            // Only the explicitly installed mailbox slice may create dispatch intent.
            if (! ActionRegistry::adapterAvailable($row->action_type)) {
                $this->transition($row, 'blocked', 'adapter_unavailable');

                return false;
            }
            // The evidence revalidation above is live vendor I/O outside any transaction and
            // can span seconds, so a drain may have persisted the marker while it ran. A claim
            // taken before the marker is released back to waiting HERE: committing intent now
            // would leave the send fence as its only exit, burning a never-dispatched,
            // human-confirmed approval to terminal abandoned_no_send.
            // This MUST be the locking read: the approver/ticket/lineage reads above are plain
            // consistent reads, so they have already opened this transaction's read view and a
            // plain at() here would be answered from that older snapshot.
            if (app(ScheduledQuiescence::class)->atForUpdate() !== null) {
                $this->defer($id, $nonce, 'quiesced_no_send');

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
            // Only the approver may cancel a HUMAN-approved row; UI policy can narrow further.
            // A token-approved row (approver_user_id NULL) has no approver, so that rule would
            // make it uncancellable by anyone — any active Admin/Tech, already validated by the
            // approver() call above, may stop it (ruled design point 4). Cancel is stop-only:
            // it issues no dispatch intent and mutates nothing upstream.
            $tokenApproved = $row !== null && $row->approver_user_id === null;
            if (! $row || (! $tokenApproved && (int) $row->approver_user_id !== $userId)
                || ! in_array($row->state, ['waiting', 'claimed'], true)) {
                return false;
            }
            $this->transition($row, 'cancelled', 'operator_cancelled');

            return true;
        }, 3);
    }

    /** Only explicit pre-dispatch availability reasons may defer an attempt. */
    public function defer(int $id, string $nonce, string $reason, ?\Carbon\CarbonImmutable $cooldownUntil = null): bool
    {
        // quiesced_no_send is retryable HERE and only here: nothing was dispatched, so the
        // claim returns to waiting for a later sweep instead of becoming terminal evidence.
        if (! in_array($reason, ['offline', 'read_unavailable', 'cooldown', 'kill_switch', 'clock_unhealthy', 'quiesced_no_send'], true)) {
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
            if ($row->state === 'dispatch_intent' && $row->intent_at && $now->gte(\Carbon\CarbonImmutable::parse($row->intent_at, 'UTC')->addSeconds(ScheduledPolicy::MAX_TRANSPORT_SECONDS + ScheduledPolicy::RECEIPT_GRACE_SECONDS))) {
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
    public function settle(int $id, string $nonce, string $outcome, ?string $failureReason = null): bool
    {
        if (! in_array($outcome, ['completed', 'failed', 'submitted', 'uncertain'], true)) {
            throw new InvalidArgumentException('invalid_outcome');
        }
        // Only the calling adapter knows whether a request left the PSA, so the
        // pre-send reason is opt-in and cannot be attached to any other outcome.
        if ($failureReason !== null && ($outcome !== 'failed' || $failureReason !== 'no_vendor_request')) {
            throw new InvalidArgumentException('invalid_reason');
        }

        return DB::transaction(function () use ($id, $nonce, $outcome, $failureReason) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'dispatch_intent' || $row->nonce !== $nonce) {
                return false;
            }
            // settle() is shared: an adapter that derives 'failed' from a vendor response
            // body (mailbox) reports a genuine vendor receipt, while an adapter whose
            // 'failed' is provably pre-send (tactical) passes 'no_vendor_request' itself.
            // Never infer "no request reached the provider" from the outcome alone.
            $this->transition($row, $outcome, match ($outcome) {
                'uncertain' => 'intent_outcome_unknown',
                'failed' => $failureReason ?? 'vendor_receipt',
                default => 'vendor_receipt',
            });

            return true;
        }, 3);
    }

    /** Live last-moment fence. It cannot close the read-to-HTTP window. */
    public function beforeSend(int $id, string $nonce): bool
    {
        return DB::transaction(function () use ($id, $nonce) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            // A stale holder must never overwrite another nonce or terminal evidence.
            if (! $row || $row->state !== 'dispatch_intent' || $row->nonce !== $nonce) {
                return false;
            }
            if (app(ScheduledQuiescence::class)->at() !== null) {
                $this->transition($row, 'abandoned_no_send', 'quiesced_no_send');

                return false;
            }

            return true;
        }, 3);
    }

    /** Append-only late evidence; never reverse terminal state or release its fences. */
    public function lateReceipt(int $id, string $nonce, string $vendor, ?string $vendorId, string $outcome): void
    {
        DB::transaction(function () use ($id, $nonce, $vendor, $vendorId, $outcome) {
            $row = DB::table('scheduled_authorizations')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->nonce !== $nonce) {
                return;
            }
            DB::table('scheduled_late_receipts')->insert([
                'authorization_id' => $id, 'nonce' => $nonce, 'vendor' => $vendor,
                'vendor_id' => $vendorId, 'outcome' => $outcome,
                'intent_at' => $row->intent_at, 'received_at' => $this->clock->now(),
            ]);
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
        // Only uncertain reservations remain, for explicit read-only reconciliation: a
        // submitted receipt is an observed send, so it must not fence the target forever.
        if ($state !== 'uncertain') {
            DB::table('scheduled_target_fences')->where('authorization_id', $row->id)->delete();
            DB::table('scheduled_run_fences')->where('authorization_id', $row->id)->delete();
        }
        // The proposal stays Scheduled even at terminal state: legacy reconfirm cannot revive it.
    }
}
