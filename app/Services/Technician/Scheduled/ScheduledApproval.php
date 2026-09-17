<?php

namespace App\Services\Technician\Scheduled;

use App\Models\TechnicianRun;
use App\Services\Technician\TechnicianApprovalResult;
use InvalidArgumentException;

/**
 * The cockpit's ordinary Approve for a staged proposal that carries `execute_at`
 * (ruled design point 2): instead of executing now, admit it through
 * ScheduledAdmission with the window [execute_at, execute_at + 60 min]. The approver's
 * click is the human factor; the AI's confirmation inputs (sealed at staging as
 * `scheduled_human_inputs`) plus any cockpit-entered sensitive mailbox inputs are the
 * sealed human_inputs. Every refusal is a named `gate_declined`; nothing here can fall
 * through to an immediate send.
 */
final class ScheduledApproval
{
    public function __construct(private ScheduledAdmission $admission) {}

    /** The instant a staged proposal is asking to run at, or null for an ordinary proposal. */
    public static function executeAtFor(TechnicianRun $run): ?ExecuteAt
    {
        $provenance = is_array($run->proposed_meta) ? ($run->proposed_meta['scheduled_provenance'] ?? null) : null;
        if (! is_array($provenance) || ($provenance['version'] ?? null) !== 1 || ! array_key_exists('execute_at', $provenance)) {
            return null;
        }

        return ExecuteAt::fromProvenance($provenance);
    }

    /** Whether Approve must take the scheduled path for this run (a malformed instant still must NOT run now). */
    public static function wantsScheduling(TechnicianRun $run): bool
    {
        $provenance = is_array($run->proposed_meta) ? ($run->proposed_meta['scheduled_provenance'] ?? null) : null;

        return is_array($provenance) && array_key_exists('execute_at', $provenance);
    }

    /**
     * @param  array<string, mixed>  $cockpitInputs  the validated sensitive inputs the approver typed on the card
     */
    public function approve(TechnicianRun $run, int $approverId, array $cockpitInputs = []): TechnicianApprovalResult
    {
        $executeAt = self::executeAtFor($run);
        if ($executeAt === null) {
            return new TechnicianApprovalResult('gate_declined', message: 'This proposal names a run time that cannot be read. It was not executed; ask for a fresh proposal.');
        }
        if (! ExecuteAt::supportsStaged($run->action_type)) {
            return new TechnicianApprovalResult('gate_declined', message: ExecuteAt::refusalFor($run->action_type));
        }
        $provenance = $run->proposed_meta['scheduled_provenance'];
        $tokenId = ($provenance['kind'] ?? null) === 'mcp' ? ($provenance['token_id'] ?? null) : null;
        $sealed = is_array($run->proposed_meta['scheduled_human_inputs'] ?? null) ? $run->proposed_meta['scheduled_human_inputs'] : [];
        $human = array_merge($sealed, array_intersect_key($cockpitInputs, array_flip(['external_smtp', 'internal_message', 'external_message'])));
        $evidence = TacticalPlan::supports($run->action_type) ? app(TacticalEvidence::class) : app(MailboxEvidence::class);
        [$start, $end] = $executeAt->window('UTC');
        try {
            $id = $this->admission->admit($run->id, $approverId, (string) $run->content_hash, is_int($tokenId) ? $tokenId : null,
                $start, $end, 'UTC', $human, $evidence);
        } catch (InvalidArgumentException|ScheduledUnavailable $e) {
            return new TechnicianApprovalResult('gate_declined', message: 'Scheduling was refused ('.$e->getMessage().'). Nothing was sent; the proposal is still awaiting approval unless it was already handled.');
        } catch (\Throwable) {
            return new TechnicianApprovalResult('gate_declined', message: 'Scheduling was refused. Nothing was sent; verify the target and permissions before retrying.');
        }

        return new TechnicianApprovalResult('scheduled', message: 'Approved to run at '.$executeAt->display().' (authorization #'.$id.'). It has not executed; permissions and target identity will be rechecked in the window.');
    }
}
