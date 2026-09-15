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

    /** Internal substrate only. There is no route or evidence implementation in PR1. */
    public function admit(int $runId, int $approverId, string $expectedHash, ?int $tokenId, string $start, string $end, string $zone, array $humanInputs, ScheduledEvidence $evidence): int
    {
        if (! config('scheduled_approvals.enabled') || TechnicianConfig::killSwitchEngaged() || ! $this->clock->healthy()) {
            throw new InvalidArgumentException('scheduling_disabled_or_clock_unhealthy');
        }
        $user = $this->policy->approver($approverId);
        $run = TechnicianRun::findOrFail($runId);
        $this->policy->ticket($run);
        $this->policy->lineage($run, $tokenId);
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

        return DB::transaction(function () use ($run, $approverId, $expectedHash, $tokenId, $start, $end, $zone, $direct, $binding, $meta): int {
            $locked = TechnicianRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->policy->approver($approverId);
            $this->policy->ticket($locked);
            $this->policy->lineage($locked, $tokenId);
            if ($locked->content_hash !== $expectedHash || $locked->action_type !== $run->action_type
                || $locked->ticket_id !== $run->ticket_id || $locked->client_id !== $run->client_id
                || ApprovalEnvelope::canonical($locked->proposed_meta ?? []) !== $meta) {
                throw new InvalidArgumentException('proposal_changed');
            }
            $existing = DB::table('scheduled_run_fences')->where('run_id', $run->id)->first();
            if ($existing) {
                $row = DB::table('scheduled_authorizations')->find($existing->authorization_id);
                $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
                if ($row->approver_user_id != $approverId || $row->local_start !== $start || $row->local_end !== $end || $row->display_timezone !== $zone
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
                'not_before' => $window->start->format('Y-m-d H:i:s'), 'expires_at' => $window->end->format('Y-m-d H:i:s'),
                'display_timezone' => $zone, 'local_start' => $start, 'local_end' => $end,
                'start_offset' => $window->startOffset, 'end_offset' => $window->endOffset];
            $sealed = ApprovalEnvelope::seal($values);
            // All operations on this pinned target serialize, including opposing effects.
            $targetKey = hash('sha256', ApprovalEnvelope::canonical([$run->client_id, $binding['target']['tenant_id'], $binding['target']['object_id']]));
            $id = DB::table('scheduled_authorizations')->insertGetId([
                ...array_diff_key($values, array_flip(['binding', 'provenance'])), ...$sealed,
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
