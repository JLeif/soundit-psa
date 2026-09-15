<?php

namespace App\Services\Huntress;

use App\Models\Alert;
use App\Models\Client;
use App\Models\Ticket;
use App\Support\HuntressConfig;
use Illuminate\Support\Facades\DB;

/** Stores immutable CW evidence; only verified events can promote it. */
class HuntressLinkService
{
    public function __construct(private readonly HuntressLinkPolicy $policy) {}

    public function capture(Alert $alert, Ticket $ticket, string $description, string $receivedAt): void
    {
        DB::transaction(function () use ($alert, $ticket, $description, $receivedAt) {
            $this->lock();
            // A retry cannot replace the original candidate with edited text.
            if (! DB::table('huntress_link_candidates')->where('ticket_id', $ticket->id)->exists()) {
                $capture = $this->policy->candidates($description);
                $candidate = $capture['candidate'];
                DB::table('huntress_link_candidates')->insert([
                    'alert_id' => $alert->id,
                    'ticket_id' => $ticket->id,
                    'client_id' => $ticket->client_id,
                    'organization_id' => $ticket->client?->huntress_organization_id,
                    'record_type' => $candidate['record_type'] ?? null,
                    'record_id' => $candidate['record_id'] ?? null,
                    'candidate_org_id' => $candidate['organization_id'] ?? null,
                    'capture_refusal' => $capture['reason'],
                    'received_at' => $receivedAt,
                ]);
            }
            $this->promoteLocked();
        }, 3);
    }

    /** Called after durable event commit, including on a duplicate delivery. */
    public function promote(): void
    {
        DB::transaction(function () {
            $this->lock();
            $this->promoteLocked();
        }, 3);
    }

    private function lock(): void
    {
        // An actual write also obtains SQLite's writer lock in synthetic tests.
        DB::table('huntress_link_mutex')->where('id', 1)->increment('version');
    }

    private function promoteLocked(): void
    {
        foreach (DB::table('huntress_link_candidates')->orderBy('id')->get() as $candidate) {
            $alert = Alert::find($candidate->alert_id);
            $ticket = Ticket::find($candidate->ticket_id);
            if (! $alert || ! $ticket) {
                continue;
            }
            $reason = $candidate->capture_refusal;
            $event = null;
            // All captured competitors count, not just the first one to link.
            // Revocation on late duplicates prevents arrival order choosing a winner.
            if (DB::table('huntress_link_candidates')->where('alert_id', $candidate->alert_id)->count() > 1
                || ($candidate->record_id !== null && DB::table('huntress_link_candidates')
                    ->where('record_type', $candidate->record_type)->where('record_id', $candidate->record_id)
                    ->where('organization_id', $candidate->organization_id)->count() > 1)) {
                $reason = 'ambiguous_binding';
            }
            if (! $reason && ((int) $alert->ticket_id !== (int) $ticket->id
                || (int) $alert->client_id !== (int) $candidate->client_id
                || (int) $ticket->client_id !== (int) $candidate->client_id)) {
                $reason = 'scope_or_identity_mismatch';
            }
            if (! $reason && ! HuntressConfig::webhooksEnabled()) {
                $reason = 'linking_disabled';
            }
            if (! $reason) {
                $reason = 'unvalidated_candidate';
                $events = DB::table('huntress_webhook_events')->where('record_type', $candidate->record_type)
                    ->where('record_id', $candidate->record_id)->orderBy('id')->get();
                foreach ($events as $row) {
                    $evidence = (array) $row;
                    $evidence['record_id'] = (int) $row->record_id;
                    $evidence['account_id'] = $row->account_id === null ? null : (int) $row->account_id;
                    $evidence['agent_id'] = $row->agent_id === null ? null : (int) $row->agent_id;
                    $evidence['organization_ids'] = json_decode($row->organization_ids, true, 512, JSON_THROW_ON_ERROR);
                    $reason = $this->policy->refusal([
                        'record_type' => $candidate->record_type,
                        'record_id' => (int) $candidate->record_id,
                        'organization_id' => (int) $candidate->candidate_org_id,
                    ], $evidence, (int) HuntressConfig::get('webhook_account_id'),
                        $candidate->organization_id === null ? null : (int) $candidate->organization_id,
                        ($org = Client::find($ticket->client_id)?->huntress_organization_id) === null ? null : (int) $org,
                        $candidate->received_at);
                    if ($reason === null) {
                        $event = $row;
                        break;
                    }
                }
            }
            $values = [
                'huntress_account_id' => $event?->account_id,
                'huntress_org_id' => $event ? $candidate->organization_id : null,
                'huntress_record_type' => $event?->record_type,
                'huntress_record_id' => $event?->record_id,
                'huntress_event_id' => $event?->id,
                'huntress_linked_at' => $event ? ($alert->huntress_linked_at ?? now()) : null,
                'huntress_link_refusal' => $reason,
            ];
            DB::table('alerts')->where('id', $alert->id)->update($values);
        }
    }
}
