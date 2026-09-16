<?php

namespace App\Services\Technician\Scheduled;

use App\Services\Cipp\CippRestWriteClient;
use Illuminate\Support\Facades\DB;

final class MailboxDispatch
{
    public function __construct(private ScheduledCoordinator $coordinator, private MailboxEvidence $evidence, private CippRestWriteClient $client) {}

    public function run(int $id): void
    {
        $row = DB::table('scheduled_authorizations')->find($id);
        if (! $row || ! MailboxPlan::supports($row->action_type)) {
            return;
        }
        $nonce = $this->coordinator->claim($id);
        if ($nonce === null || ! $this->coordinator->intent($id, $nonce, $this->evidence)) {
            return;
        }
        // Only the worker that won intent can submit. No dispatch method accepts an old nonce.
        // Crash before/after send leaves intent to the reaper, which NEVER resubmits.
        try {
            $row = DB::table('scheduled_authorizations')->find($id);
            $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
            $plan = $sealed['binding']['payload'];
            $outcome = MailboxResult::classify($plan, $this->client->submitScheduledMailboxOnce($plan));
        } catch (\Throwable) {
            $outcome = 'uncertain';
        }
        $this->coordinator->settle($id, $nonce, $outcome);
    }
}
