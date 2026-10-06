<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Support\EmailRedactor;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * withdraw_staged_action — the drafting token takes back its own pending proposal
 * (card XUiMXNEH, Jeeves's ruling 2026-10-05: the GENERAL verb, not a per-vendor
 * supersede).
 *
 * Without it, a staged proposal the drafter knows is wrong can only leave the queue
 * through an operator's Deny, which costs a human a click and records a veto nobody
 * meant. Verbs that refuse a second proposal while one is awaiting approval would
 * otherwise also block the corrected one.
 *
 * Contract:
 *  - Only the token named in proposed_meta.drafted_by_token (the BARE label, the form
 *    TechnicianRun::drafterDisplayName() reads) may withdraw. Every other caller gets
 *    the SAME text as a run that does not exist, so the refusal says nothing about
 *    runs another seat drafted. A run staged with no drafted_by_token matches no
 *    caller at all.
 *  - Only awaiting_approval runs. The transition is a CAS
 *    (TechnicianRun::withdrawByDrafter), so an approval that claims the run first wins
 *    and the withdrawal is refused.
 *  - The run ends Withdrawn, never Superseded or Denied, which calibration reads as
 *    human decisions. proposed_meta records withdrawn_by = 'drafter', the token label,
 *    the reason and the time.
 *  - A non-empty reason is required; it is what the cockpit shows the operator.
 *
 * Not in scope: amending a run in place, withdrawing another seat's run, and the
 * operator Deny / Cancel buttons.
 */
final class WithdrawStagedActionTool
{
    public const NAME = 'withdraw_staged_action';

    /** Recorded in proposed_meta.withdrawn_by; the cockpit lane keys on it. */
    public const WITHDRAWN_BY_DRAFTER = 'drafter';

    /** Longest reason accepted, in characters after trim; it is copied into proposed_meta. */
    public const REASON_MAX = 500;

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Withdraw a staged action THIS token drafted that is still awaiting cockpit approval, so a corrected proposal can be staged in its place. Only the drafting token can withdraw, and only while the run is awaiting approval: an approved, executing, scheduled, queued or finished run is refused. The run is recorded as withdrawn by the drafter with your reason, not as an operator denial. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'run_id' => [
                        'type' => 'integer',
                        'description' => 'The run_id the staging call returned.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'maxLength' => self::REASON_MAX,
                        'description' => 'Why the proposal is being withdrawn. Shown to the operator in the cockpit.',
                    ],
                ],
                'required' => ['run_id', 'reason'],
            ],
        ];
    }

    /**
     * @param  string|null  $tokenLabel  the caller's BARE McpToken.label
     * @param  string  $actorLabel  the prefixed audit label (McpStaffToken::actorLabel())
     * @return array<string, mixed>
     */
    public function execute(array $arguments, ?string $tokenLabel, string $actorLabel): array
    {
        // PSA action tools are self-validating at the MCP boundary (the controller's
        // generic undeclared-argument guard defers to the executor), so an undeclared
        // key is refused HERE, by name, before anything is read or written. Only key
        // names are echoed, capped; values are never read.
        $declared = array_keys(self::definition()['input_schema']['properties']);
        $unknown = array_values(array_diff(array_map('strval', array_keys($arguments)), $declared));
        if ($unknown !== []) {
            sort($unknown);

            return ['error' => 'Unsupported argument(s): '
                .implode(', ', array_map(static fn (string $k): string => mb_substr($k, 0, 64), array_slice($unknown, 0, 10)))
                .'. '.self::NAME.' accepts only: '.implode(', ', $declared).'. Nothing was withdrawn.'];
        }

        $runId = $arguments['run_id'] ?? null;
        if (! is_int($runId) || $runId < 1) {
            return ['error' => 'run_id is required and must be a positive integer.'];
        }

        $reason = is_string($arguments['reason'] ?? null) ? trim($arguments['reason']) : '';
        if ($reason === '') {
            return ['error' => 'reason is required and must not be empty.'];
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            return ['error' => 'reason must be at most '.self::REASON_MAX.' characters.'];
        }

        $run = TechnicianRun::find($runId);
        if ($run === null || ! $this->draftedBy($run, $tokenLabel)) {
            return ['error' => self::notFound($runId)];
        }

        $meta = [
            'withdrawn_by' => self::WITHDRAWN_BY_DRAFTER,
            'withdrawn_by_token' => $tokenLabel,
            'withdrawn_reason' => $reason,
            'withdrawn_at' => now()->toIso8601String(),
        ];

        $withdrawn = DB::transaction(function () use ($run, $meta, $actorLabel, $reason): bool {
            if (! $run->withdrawByDrafter($meta)) {
                return false;
            }

            TechnicianActionLog::create([
                'actor_id' => TechnicianConfig::aiActorUserId(),
                'approver_user_id' => null,
                'actor_label' => $actorLabel,
                'action_type' => self::NAME,
                'tier' => TechnicianTier::Auto->value,
                'result_status' => 'executed',
                'ticket_id' => $run->ticket_id,
                'client_id' => $run->client_id,
                'run_id' => $run->id,
                // Its own hash, as AssetWatchTool::audit() does: the run's content_hash
                // would make this row read as an executed approval of the proposal.
                'content_hash' => hash('sha256', self::NAME.'|'.$run->id),
                'summary' => mb_substr("MCP withdrew staged {$run->action_type} run #{$run->id}: ".EmailRedactor::redact($reason), 0, 1000),
                'correlation_id' => (string) Str::uuid(),
            ]);

            return true;
        });

        if (! $withdrawn) {
            // The CAS is the ONLY state guard: the run was not awaiting_approval at the
            // UPDATE (already approved, executing, scheduled, queued, terminal, or claimed
            // by an approval after the read above). Report what it is now.
            return ['error' => self::notAwaiting($runId, TechnicianRun::find($run->id)?->state)];
        }

        return [
            'success' => true,
            'run_id' => $run->id,
            'ticket_id' => $run->ticket_id,
            'action_type' => $run->action_type,
            'state' => TechnicianRunState::Withdrawn->value,
            'message' => 'Withdrawn. The run is out of the approval queue and no action was taken; you may stage a corrected proposal.',
        ];
    }

    /** The one refusal for "no such run" AND "not yours": the two must be indistinguishable. */
    public static function notFound(int $runId): string
    {
        return "No staged action #{$runId} drafted by this token was found; nothing was withdrawn.";
    }

    private static function notAwaiting(int $runId, ?TechnicianRunState $state): string
    {
        return "Run #{$runId} is not awaiting approval (state: ".($state?->value ?? 'unknown')
            .'), so it cannot be withdrawn; nothing was changed.';
    }

    private function draftedBy(TechnicianRun $run, ?string $tokenLabel): bool
    {
        $drafter = data_get($run->proposed_meta, 'drafted_by_token');

        return is_string($tokenLabel) && $tokenLabel !== ''
            && is_string($drafter) && hash_equals($drafter, $tokenLabel);
    }
}
