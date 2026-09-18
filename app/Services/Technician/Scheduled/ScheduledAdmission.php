<?php

namespace App\Services\Technician\Scheduled;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ScheduledAdmission
{
    public function __construct(private ScheduledClock $clock, private ScheduledPolicy $policy) {}

    /**
     * Transactional admission; per-action evidence binds independently captured confirmations.
     *
     * $approver is the TYPED authority for this row: a human approver (the cockpit Approve)
     * or the MCP token itself (the immediate lane, ruled design point 3). It is never an
     * integer with a magic value — a token-approved row writes approver_user_id NULL into
     * both the row and the sealed envelope, so nothing downstream can mistake it for a
     * human approval.
     */
    public function admit(int $runId, ScheduledApprover $approver, string $expectedHash, ?int $tokenId, string $start, string $end, string $zone, array $humanInputs, ScheduledEvidence $evidence): int
    {
        if (app(ScheduledQuiescence::class)->at() !== null) {
            throw new InvalidArgumentException('scheduling_quiesced');
        }
        // No global on/off (ruled design point 4): the per-tool token grant is the only
        // permission gate; the kill switch stays the emergency stop, the clock the sanity gate.
        if (TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy()) {
            throw new InvalidArgumentException('kill_switch_or_clock_unhealthy');
        }
        // A token-approved admission has NO approver to validate and no User to hand the
        // evidence provider. It gets null, not a stand-in: neither installed provider reads
        // the approver (both bind on the run's own payload and live vendor identity), and
        // manufacturing the system user or the AI actor here would put a human's id where
        // the record says a human decided, which is exactly the provenance this lane must
        // not forge.
        $user = $approver->isToken() ? null : $this->policy->approver((int) $approver->userId);
        $run = TechnicianRun::findOrFail($runId);
        if ($refusal = ActionRegistry::admissionRefusal($run->action_type)) {
            throw new InvalidArgumentException($refusal);
        }
        $this->policy->ticket($run);
        $this->policy->lineage($run, $tokenId, $approver);
        $direct = ActionRegistry::directTool($run->action_type);
        if ($direct === null || ! hash_equals($run->content_hash, $expectedHash)) {
            throw new InvalidArgumentException('unsupported_or_changed');
        }
        // Read-only upstream evidence OUTSIDE the transaction; recheck row version inside.
        $binding = $evidence->approve($run, $user, $humanInputs);
        if (! is_array($binding['payload'] ?? null) || ! is_array($binding['target'] ?? null)
            || ! is_string($binding['target']['tenant_id'] ?? null) || $binding['target']['tenant_id'] === ''
            || ! is_string($binding['target']['object_id'] ?? null) || $binding['target']['object_id'] === '') {
            throw new InvalidArgumentException('identity_evidence_missing');
        }
        $meta = ApprovalEnvelope::canonical($run->proposed_meta ?? []);

        $approverId = $approver->userId;

        return DB::transaction(function () use ($run, $approver, $approverId, $expectedHash, $tokenId, $start, $end, $zone, $direct, $binding, $meta, $humanInputs): int {
            if (app(ScheduledQuiescence::class)->atForUpdate() !== null) {
                throw new InvalidArgumentException('scheduling_quiesced');
            }
            $locked = TechnicianRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $approver->isToken()) {
                $this->policy->approver((int) $approverId);
            }
            $this->policy->ticket($locked);
            $this->policy->lineage($locked, $tokenId, $approver);
            if ($locked->content_hash !== $expectedHash || $locked->action_type !== $run->action_type
                || $locked->ticket_id !== $run->ticket_id || $locked->client_id !== $run->client_id
                || ApprovalEnvelope::canonical($locked->proposed_meta ?? []) !== $meta) {
                throw new InvalidArgumentException('proposal_changed');
            }
            $existing = DB::table('scheduled_run_fences')->where('run_id', $run->id)->first();
            if ($existing) {
                $row = DB::table('scheduled_authorizations')->find($existing->authorization_id);
                $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
                // A repeat admission is idempotent only if EVERY sealed field matches this
                // confirmation; a changed content revision/action/binding must refuse here,
                // not be returned as a success sealed against the old proposal.
                //
                // The approver comparison is NULL-EXACT, not loose: `!=` would call
                // NULL == 0 and NULL == null both true, so a token row arriving later with
                // a human approver (or the reverse) would be returned as an idempotent
                // success sealed against the other party's authority. Compare the typed
                // identity instead — a mode change is a conflict, always.
                $rowApprover = $row->approver_user_id === null ? null : (int) $row->approver_user_id;
                if ($rowApprover !== ($approverId === null ? null : (int) $approverId)
                    || $row->local_start !== $start || $row->local_end !== $end || $row->display_timezone !== $zone
                    || ! hash_equals((string) $row->content_hash, $expectedHash)
                    || $row->action_type !== $locked->action_type || $row->direct_tool !== $direct
                    || $row->client_id != $locked->client_id || $row->ticket_id != $locked->ticket_id
                    || $row->originating_mcp_token_id != $tokenId
                    || ($sealed['provenance'] ?? null) !== $meta
                    || ! array_key_exists('human_inputs', $sealed)
                    || ApprovalEnvelope::canonical($sealed['human_inputs']) !== ApprovalEnvelope::canonical($humanInputs)
                    || ApprovalEnvelope::canonical($sealed['binding']) !== ApprovalEnvelope::canonical($binding)) {
                    throw new InvalidArgumentException('existing_authorization_conflict');
                }

                return $row->id;
            }
            if ($locked->state !== TechnicianRunState::AwaitingApproval) {
                throw new InvalidArgumentException('not_awaiting_approval');
            }
            $now = $this->clock->now();
            $window = ApprovalWindow::fromLocal($start, $end, $zone, $now->toDateTimeImmutable());
            $revision = (int) DB::table('scheduled_authorizations')->where('run_id', $run->id)->max('revision') + 1;
            $values = ['schema_version' => 1, 'run_id' => $run->id, 'revision' => $revision,
                'client_id' => $run->client_id, 'ticket_id' => $run->ticket_id, 'action_type' => $run->action_type,
                'direct_tool' => $direct, 'content_hash' => $expectedHash, 'approver_user_id' => $approverId,
                'originating_mcp_token_id' => $tokenId, 'binding' => $binding, 'provenance' => $meta,
                // Independently capture the exact confirmation, even if evidence drops fields.
                // These values exist ONLY inside the authenticated encrypted envelope.
                'human_inputs' => $humanInputs,
                'not_before' => $window->start->format('Y-m-d H:i:s'), 'expires_at' => $window->end->format('Y-m-d H:i:s'),
                'display_timezone' => $zone, 'local_start' => $start, 'local_end' => $end,
                'start_offset' => $window->startOffset, 'end_offset' => $window->endOffset];
            $sealed = ApprovalEnvelope::seal($values);
            // All operations on this pinned target serialize, including opposing effects.
            $targetKey = hash('sha256', ApprovalEnvelope::canonical([$run->client_id, $binding['target']['tenant_id'], $binding['target']['object_id']]));
            $id = DB::table('scheduled_authorizations')->insertGetId([
                ...array_diff_key($values, array_flip(['binding', 'provenance', 'human_inputs'])), ...$sealed,
                'target_key' => $targetKey, 'effect_key' => hash('sha256', ApprovalEnvelope::canonical([$direct, $binding])),
                'approved_at' => $now, 'next_attempt_at' => $window->start, 'state' => 'waiting', 'transition_sequence' => 1,
            ]);
            // Unique reservations fail the entire transaction on conflict, including its outbox.
            DB::table('scheduled_run_fences')->insert(['run_id' => $run->id, 'authorization_id' => $id]);
            DB::table('scheduled_target_fences')->insert(['target_key' => $targetKey, 'authorization_id' => $id]);
            $changed = TechnicianRun::whereKey($run->id)->where('state', TechnicianRunState::AwaitingApproval->value)
                ->where('content_hash', $expectedHash)->update(['state' => TechnicianRunState::Scheduled->value]);
            if ($changed !== 1) {
                throw new InvalidArgumentException('proposal_changed');
            }
            DB::table('scheduled_note_outbox')->insert(['authorization_id' => $id, 'transition_sequence' => 1, 'event' => 'scheduled', 'created_at' => $now]);

            return $id;
        }, 3);
    }
}
