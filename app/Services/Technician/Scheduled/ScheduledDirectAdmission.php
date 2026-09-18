<?php

namespace App\Services\Technician\Scheduled;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use InvalidArgumentException;

/**
 * The immediate lane (ruled design point 3): a token holding `<tool>:immediate` that
 * passes `execute_at` gets its row queued DIRECTLY into scheduled_authorizations, with
 * no cockpit proposal to approve, and it runs at that time under the unchanged fire-time
 * safety checks (kill switch, clock health, target identity revalidation, ticket binding).
 *
 * The seam is here rather than in the controller, and it runs AFTER the executor's own
 * staging work rather than instead of it. Two reasons, both load-bearing:
 *
 *  - Everything admission needs — the content hash, the encrypted payload, the scheduled
 *    provenance, the sealed AI confirmation inputs, the cooldown and duplicate checks, the
 *    ticket/asset/person binding — is produced by the executor's stageAction(), in a form
 *    two independent evidence providers already know how to read. Rebuilding that in the
 *    controller would be a SECOND construction of the same authorization payload, free to
 *    drift from the one the cockpit lane uses, and drift here means a token row sealed
 *    against a different binding than an approved row for the identical call.
 *  - ScheduledAdmission::admit() takes a run id, and the run IS the proposal. Keeping the
 *    run means the fire-time preflight, the run/target fences, the note outbox and the
 *    Scheduled tombstone all behave identically on both lanes.
 *
 * So the direct lane is "stage, then immediately admit under the token's own authority".
 * The proposal exists for one transaction and leaves cockpit state Scheduled, never
 * AwaitingApproval — it is never offered to a human to approve, which is the whole point.
 * If admission refuses, the staged proposal is WITHDRAWN rather than left sitting in the
 * cockpit: the caller asked to schedule under its own permission, not to queue work for a
 * technician, and silently converting a refused direct call into an approval request would
 * be a lane the caller never chose.
 */
final class ScheduledDirectAdmission
{
    public function __construct(private ScheduledAdmission $admission) {}

    /**
     * @param  array<string, mixed>  $staged  the executor's successful stageAction() result
     * @return array<string, mixed> the tool result to return to the MCP caller
     */
    public function admit(array $staged, int $tokenId, ExecuteAt $executeAt): array
    {
        $runId = $staged['run_id'] ?? null;
        if (! is_int($runId)) {
            return ['error' => 'scheduled_direct_admission_failed'];
        }
        $run = TechnicianRun::find($runId);
        if (! $run) {
            return ['error' => 'scheduled_direct_admission_failed'];
        }
        // An idempotent repeat of a call that already queued directly: the run is already
        // Scheduled, so re-admitting would refuse on not_awaiting_approval. Report the
        // existing authorization instead of manufacturing a second one.
        if ($run->state === TechnicianRunState::Scheduled) {
            return $this->success($staged, $executeAt, $this->authorizationIdFor($runId), idempotent: true);
        }
        $provenance = is_array($run->proposed_meta) ? ($run->proposed_meta['scheduled_provenance'] ?? null) : null;
        // The instant admitted must be the instant the proposal carries. A staged
        // proposal that came back idempotent for a DIFFERENT instant is already refused by
        // the executor (execute_at_conflicts_with_pending_proposal); this is the belt on
        // that brace, so nothing is ever admitted against provenance it does not match.
        if (! is_array($provenance) || ($provenance['execute_at'] ?? null) !== $executeAt->utc
            || ($provenance['kind'] ?? null) !== 'mcp' || ($provenance['token_id'] ?? null) !== $tokenId) {
            $this->withdraw($run);

            return ['error' => 'scheduled_direct_admission_failed'];
        }
        $human = is_array($run->proposed_meta['scheduled_human_inputs'] ?? null) ? $run->proposed_meta['scheduled_human_inputs'] : [];
        $evidence = TacticalPlan::supports($run->action_type) ? app(TacticalEvidence::class) : app(MailboxEvidence::class);
        // Identical derivation to ScheduledApproval::approve() — the same window from the
        // same instant in the same zone, so a direct row and an approved row for the same
        // call are indistinguishable at the substrate except in who authorised them.
        [$start, $end] = $executeAt->window('UTC');
        try {
            $id = $this->admission->admit($run->id, ScheduledApprover::token($tokenId), (string) $run->content_hash,
                $tokenId, $start, $end, 'UTC', $human, $evidence);
        } catch (InvalidArgumentException|ScheduledUnavailable $e) {
            $this->withdraw($run);

            return ['error' => 'execute_at_admission_refused:'.$e->getMessage()];
        } catch (\Throwable) {
            $this->withdraw($run);

            return ['error' => 'execute_at_admission_refused'];
        }

        return $this->success($staged, $executeAt, $id);
    }

    /**
     * A refused direct admission leaves no cockpit proposal behind. Withdrawn, not denied:
     * Denied records a human veto and would poison calibration with a decision nobody made.
     * Only a still-awaiting run is touched, so a racing approval is never overwritten.
     */
    private function withdraw(TechnicianRun $run): void
    {
        TechnicianRun::whereKey($run->id)->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Withdrawn->value]);
    }

    private function authorizationIdFor(int $runId): ?int
    {
        $id = \Illuminate\Support\Facades\DB::table('scheduled_authorizations')->where('run_id', $runId)
            ->orderByDesc('revision')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $staged */
    private function success(array $staged, ExecuteAt $executeAt, ?int $id, bool $idempotent = false): array
    {
        $result = [
            'success' => true,
            'scheduled' => true,
            'authorization_id' => $id,
            'run_id' => $staged['run_id'] ?? null,
            'execute_at' => $executeAt->utc,
            'message' => ($idempotent ? 'Already queued' : 'Queued').' to run at '.$executeAt->display()
                .' under this token\'s immediate grant; no cockpit approval is required and nothing has executed yet. '
                .'Permissions, target identity and ticket binding are rechecked in the window; withdrawing the '
                .'immediate grant before then cancels it.',
        ];
        if ($idempotent) {
            $result['idempotent'] = true;
        }
        foreach (['ticket_id', 'ticket_display_id'] as $key) {
            if (array_key_exists($key, $staged)) {
                $result[$key] = $staged[$key];
            }
        }

        return $result;
    }
}
