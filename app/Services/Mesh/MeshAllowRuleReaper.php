<?php

namespace App\Services\Mesh;

use App\Models\MeshAllowRule;
use Illuminate\Support\Facades\Log;

/**
 * #1018 criterion 3 — the thing that actually expires a Mesh allow rule.
 *
 * Mesh's `date_expiry` does not expire anything (measured 2026-09-01: a rule
 * past its expiry was still active, unmodified, and readable). The PSA is
 * therefore the enforcement point, and this reaper is it. Everything below is
 * shaped by one rule: a delete is only a reap once the rule is PROVED absent.
 *
 * The proof is a detail GET returning 404. A 200 on the DELETE is not proof —
 * it is a status code from the same call whose effect we are trying to
 * verify. And an UNMEASURABLE post-condition (timeout, 500, anything that is
 * not a clean 404) is not a pass either: those rows go to reap_failed and are
 * retried, because "we could not tell" and "it is gone" are different answers
 * and only one of them means a customer's mail filtering is back to normal.
 *
 * #1133: a rule the caller asked to be permanent has a NULL `expires_at` and
 * is never selected for reaping (see MeshAllowRule::scopeReapable). That is
 * the whole mechanism for "permanent" — there is no flag and no far-future
 * sentinel — so this class NEVER deletes such a rule; removal is by hand in
 * the Mesh portal until mesh_remove_allow_rule exists.
 *
 * Excluded from reaping is not abandoned, though. An UNEXPIRED row that landed
 * unresolved (or reap_failed) is identified here by settleUnexpired(). That
 * covers a permanent row, which has no expiry for anything else to wait on,
 * and a dated row whose expiry is still in the future, which no reap pass
 * selects until that date, possibly months away. Meanwhile the duplicate
 * brake refuses every new allow rule for its sender. The settle pass reads
 * the tenant's rule list and writes only the local row: it never creates,
 * deletes or changes anything in Mesh. What identifying a row SETTLES depends
 * on what its create proved. A row whose 201 proved scope (`scope_proved`)
 * was missing nothing but its id, so when exactly one rule matches it, that
 * id is recorded and the row goes active. A row whose scope was never proved
 * keeps its fault text, stays unresolved and stays counted, because
 * re-reading an id does not prove scope. A row due for reaping (expiry past)
 * is never selected by the settle pass; it stays with reapOne().
 */
class MeshAllowRuleReaper
{
    /**
     * Rows processed per run. A ceiling, not a target — each row costs a
     * DELETE plus a paged list read, and the reaper runs daily, so there is no
     * value in letting one invocation walk an unbounded backlog inside a
     * scheduled window.
     */
    public const BATCH_LIMIT = 100;

    public function __construct(private readonly MeshWriteClient $client) {}

    /**
     * Reap every eligible rule. Returns per-outcome counts.
     *
     * @return array{examined: int, reaped: int, unresolved: int, failed: int}
     */
    public function reap(): array
    {
        $counts = ['examined' => 0, 'reaped' => 0, 'unresolved' => 0, 'failed' => 0];

        if (! $this->client->isConfigured()) {
            Log::warning('[MeshAllowRuleReaper] Mesh is not configured; no allow rules were reaped. Expired rules are not being removed and may still be live upstream.');

            return $counts;
        }

        $counts = $this->settleUnexpired($counts);

        $due = MeshAllowRule::reapable()->orderBy('expires_at')->limit(self::BATCH_LIMIT)->get();

        foreach ($due as $rule) {
            $counts['examined']++;
            $outcome = $this->reapOne($rule);
            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Identify (never delete) UNEXPIRED rules the PSA never settled: permanent
     * rows and dated rows whose expiry is still in the future
     * (MeshAllowRule::scopeUnsettledUnexpired). A row past its expiry is
     * never selected here; it belongs to reapOne().
     *
     * This pass READS the tenant's rule list and writes only the local row. It
     * never creates, deletes or changes anything in Mesh.
     *
     * The id is re-read by sender + PSA-generated comment, and it is recorded
     * only when EXACTLY ONE rule on the tenant matches. Two or more matches
     * are ambiguous: none of their ids is recorded, the row is not settled,
     * and the ambiguity is noted. No match, or an unreadable list,
     * leaves the row unsettled, with a note where the row has none. A row that
     * already carries an id (recorded at create time) is not re-read; that id
     * is used as it is, as reapOne() does.
     *
     * Whether an id SETTLES the row is decided by `scope_proved`, the verdict
     * the 201's `added_for` gave at create time:
     *
     *   scope proved, id now known: nothing is outstanding. The row goes
     *     ACTIVE, the record liveAllowRule() answers "already allowed for this
     *     client" from, which is also the only way the executor's duplicate
     *     brake ever lets this sender through again. A dated row that goes
     *     active is still reaped when its expiry passes (scopeReapable).
     *   scope never proved (or the create never answered): an id is not that
     *     proof, and a read-back cannot supply it because the server
     *     normalises every stored row to organization_level:true. The id is
     *     stored, the row keeps its fault text and stays unsettled, and it
     *     counts as unresolved, so mesh:reap-allow-rules keeps exiting
     *     FAILURE.
     *
     * No branch here marks a row reap_failed: no reap is attempted.
     *
     * `examined` is untouched (these rows were not examined for reaping), so
     * the counts keep meaning what the command prints about expired rules.
     *
     * @param  array{examined: int, reaped: int, unresolved: int, failed: int}  $counts
     * @return array{examined: int, reaped: int, unresolved: int, failed: int}
     */
    private function settleUnexpired(array $counts): array
    {
        $stuck = MeshAllowRule::unsettledUnexpired()->orderBy('id')->limit(self::BATCH_LIMIT)->get();

        foreach ($stuck as $rule) {
            $permanent = $rule->isPermanent();
            $lifetime = $permanent
                ? 'This rule is PERMANENT, so it is never reaped'
                : 'This rule is not due for reaping until its expiry ('.$rule->expires_at->toIso8601String().')';
            $ruleId = is_string($rule->mesh_rule_id) && $rule->mesh_rule_id !== '' ? $rule->mesh_rule_id : null;
            $ambiguous = false;
            $note = "Upstream rule id unresolved: no rule on this tenant matches the recorded sender and comment. {$lifetime}, and the PSA keeps refusing new allow rules for this sender.";

            if ($ruleId === null) {
                try {
                    $matches = $this->client->findRulesByComment(
                        (string) $rule->mesh_customer_id,
                        (string) $rule->sender,
                        (string) $rule->comment,
                    );

                    if (count($matches) === 1) {
                        $ruleId = self::ruleIdOf($matches[0]);
                    } elseif (count($matches) > 1) {
                        // Never guess: the first match is not evidence that it
                        // is the rule this row recorded.
                        $ambiguous = true;
                        $note = 'Upstream rule id unresolved: '.count($matches).' rules on this tenant match the recorded sender and comment, so none of their ids was recorded and this row was not settled. '
                            ."{$lifetime}, and the PSA keeps refusing new allow rules for this sender while this row is unsettled.";
                    }
                } catch (MeshClientException $e) {
                    $note = $permanent
                        ? "Could not read the tenant's rule list to resolve the upstream id of this PERMANENT rule: {$e->getMessage()}"
                        : "Could not read the tenant's rule list to resolve the upstream id of this unexpired rule: {$e->getMessage()}";
                }
            }

            // The one thing this pass CAN settle: a row whose create response
            // proved scope was only ever missing its id, and exactly one id is
            // now in hand.
            $settled = $ruleId !== null && (bool) $rule->scope_proved;

            if ($ruleId !== null) {
                $note = match (true) {
                    $settled && $permanent => "Upstream rule id is '{$ruleId}', and this rule's scope was confirmed by its create response, so the PERMANENT rule is now recorded active. The PSA still never removes it — that stays a human's job in the Mesh portal.",
                    $settled => "Upstream rule id is '{$ruleId}', and this rule's scope was confirmed by its create response, so the rule is now recorded active. It is still selected for reaping once its expiry passes.",
                    $permanent => "Upstream rule id is '{$ruleId}'. An id is not scope evidence, so this PERMANENT rule stays unresolved: the PSA will never remove it, and it keeps refusing new allow rules for this sender. Checking the rule in the Mesh portal does not change that — the record itself has to be cleared by hand.",
                    default => "Upstream rule id is '{$ruleId}'. An id is not scope evidence, so this rule stays unsettled and keeps refusing new allow rules for this sender. Checking the rule in the Mesh portal does not change that.",
                };
            }

            // Scope is proved ONCE, by the create response (scope_proved).
            // Nothing in this pass can prove it, because the server normalises
            // every stored row to organization_level:true, so a row without
            // that proof is never settled however many ids we recover.
            //
            // An unsettled PERMANENT row is written unresolved, as this pass
            // always has. An unsettled DATED row keeps the state it had: a
            // reap_failed row still carries its delete fault, and both states
            // stay in the reap queue for when its expiry passes.
            $update = match (true) {
                $settled => ['state' => MeshAllowRule::STATE_ACTIVE],
                $permanent => ['state' => MeshAllowRule::STATE_UNRESOLVED],
                default => [],
            };

            if ($ruleId !== null) {
                $update['mesh_rule_id'] = $ruleId;
            }

            if ($settled) {
                // The fault is over: scope was proved at create time and the
                // id is now recorded.
                $update['last_error'] = null;
            } elseif (
                trim((string) $rule->last_error) === ''
                || ($ambiguous && (bool) $rule->scope_proved && $rule->state === MeshAllowRule::STATE_UNRESOLVED)
            ) {
                // The row's existing last_error is normally the ONLY record of
                // why it never settled, so this pass writes here only where
                // there is nothing to overwrite. One exception: on an
                // UNRESOLVED scope-proved row the only thing outstanding is
                // the id, so "N rules match" is a more specific account of
                // that fault than the "could not be recovered" note it
                // replaces. A scope fault (scope never proved) and a
                // reap_failed row's delete fault are never overwritten.
                $update['last_error'] = mb_substr($note, 0, 1000);
            }

            if ($update !== []) {
                $rule->forceFill($update)->save();
            }

            $which = $permanent ? 'is permanent' : 'is unexpired';

            if ($settled) {
                Log::info("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} {$which} and now identified; it is recorded active and no longer blocks new allow rules for this sender. {$note}");

                continue;
            }

            Log::warning("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} {$which} and unsettled; it may be live and it blocks new allow rules for this sender. {$note}");

            $counts['unresolved']++;
        }

        return $counts;
    }

    /**
     * @return 'reaped'|'unresolved'|'failed'
     */
    private function reapOne(MeshAllowRule $rule): string
    {
        try {
            $ruleId = $rule->mesh_rule_id ?: $this->resolveRuleId($rule);
        } catch (MeshClientException $e) {
            return $this->markFailed($rule, "Could not read the tenant's rule list to resolve the upstream id: {$e->getMessage()}");
        }

        if ($ruleId === null) {
            // The create was scope-proved by its 201, so the rule existed; we
            // simply cannot name it. Absence from the list read is NOT taken
            // as proof it is gone — a filtered or partial read looks identical
            // — so the row stays unresolved and stays loud (criterion 8).
            $rule->forceFill([
                'state' => MeshAllowRule::STATE_UNRESOLVED,
                'last_error' => 'Upstream rule id unresolved: no rule on this tenant matches the recorded sender and comment. The rule may still be live and cannot be reaped until it is identified.',
            ])->save();

            Log::warning("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} is past expiry but its upstream rule id is unresolved; it cannot be reaped and may still be live.");

            return 'unresolved';
        }

        if ($rule->mesh_rule_id !== $ruleId) {
            $rule->forceFill(['mesh_rule_id' => $ruleId])->save();
        }

        try {
            $this->client->deleteRule($ruleId);
        } catch (MeshClientException $e) {
            // A delete that threw may still have landed, so absence is
            // re-measured below rather than assumed either way.
            Log::warning("[MeshAllowRuleReaper] DELETE failed for mesh_allow_rules#{$rule->id}: {$e->getMessage()}");
        }

        $absent = $this->client->ruleAbsent($ruleId);

        if ($absent !== true) {
            return $this->markFailed($rule, $absent === false
                ? 'The rule was still readable upstream after the delete; it was not reaped.'
                : 'The post-condition read could not be measured, so absence was not proved; the rule is treated as still live.');
        }

        $rule->forceFill([
            'state' => MeshAllowRule::STATE_REAPED,
            'reaped_at' => now(),
            'last_error' => null,
        ])->save();

        return 'reaped';
    }

    /**
     * Recover the upstream id by re-reading the tenant's rules and matching on
     * the recorded sender + PSA-generated comment — the same recovery the
     * create path uses, because the 201 carries no id.
     */
    private function resolveRuleId(MeshAllowRule $rule): ?string
    {
        $match = $this->client->findRuleByComment(
            (string) $rule->mesh_customer_id,
            (string) $rule->sender,
            (string) $rule->comment,
        );

        return $match === null ? null : self::ruleIdOf($match);
    }

    /**
     * A matched row's upstream id as a non-empty string, or null.
     *
     * @param  array<string, mixed>  $match
     */
    private static function ruleIdOf(array $match): ?string
    {
        $id = $match['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function markFailed(MeshAllowRule $rule, string $error): string
    {
        $rule->forceFill([
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => mb_substr($error, 0, 1000),
        ])->save();

        Log::error("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} not reaped: {$error}");

        return 'failed';
    }
}
