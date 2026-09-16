<?php

namespace App\Services\Technician\Scheduled;

use App\Models\Asset;
use App\Models\User;
use App\Services\Tactical\TacticalActionConfirmToken;
use App\Services\Tactical\TacticalActionService;
use Illuminate\Support\Facades\DB;

final class TacticalDispatch
{
    public function __construct(private ScheduledCoordinator $coordinator, private TacticalEvidence $evidence, private TacticalActionService $bus) {}

    public function run(int $id): void
    {
        $row = DB::table('scheduled_authorizations')->find($id);
        if (! $row || ! TacticalPlan::supports($row->action_type)) {
            return;
        }
        $nonce = $this->coordinator->claim($id);
        if ($nonce === null || ! $this->coordinator->intent($id, $nonce, $this->evidence)) {
            return;
        }
        // Only the intent winner sends. Neither transport uncertainty nor process death retries.
        // Uncertainty starts at the send: a failure before it provably left nothing behind.
        $outcome = 'failed';
        try {
            $row = DB::table('scheduled_authorizations')->find($id);
            $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
            $plan = $sealed['binding']['payload'];
            $asset = Asset::with('tacticalAsset')->findOrFail($plan['asset_id']);
            if ($asset->tacticalAsset?->agent_id !== $plan['agent_id'] || (int) $asset->client_id !== (int) $row->client_id) {
                throw new \RuntimeException('dispatch_target_changed');
            }
            $action = new TacticalScheduledAction($plan['type']);
            $user = User::findOrFail($row->approver_user_id);
            $confirm = $action->isDestructive() ? TacticalActionConfirmToken::issue(
                $action->key(), $plan['agent_id'], $user->id, $action->payloadHash($plan['params']),
            ) : null;
            $outcome = 'uncertain';
            $result = $this->bus->dispatch($action, $asset, $user, $plan['params'], $confirm, 'scheduled:'.$id, $row->ticket_id);
            if ($result->isOk() && in_array($result->stdout, ['completed', 'submitted', 'uncertain'], true)) {
                $outcome = $result->stdout;
            } elseif (in_array($result->status, ['denied', 'rejected', 'blocked'], true)) {
                // The bus classifies these before execute(): no request reached the provider.
                $outcome = 'failed';
            }
        } catch (\Throwable) {
            // No raw vendor/command bytes to logs, flash or scheduled notes.
        }
        // Every 'failed' above is decided before the send, so this dispatcher — and only
        // this dispatcher — may state that no request reached the provider.
        $this->coordinator->settle($id, $nonce, $outcome, $outcome === 'failed' ? 'no_vendor_request' : null);
    }
}
