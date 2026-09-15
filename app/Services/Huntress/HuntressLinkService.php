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
            $stored = DB::table('huntress_link_candidates')->where('ticket_id', $ticket->id)->first();
            $scope = DB::table('huntress_link_candidates')->where(function ($query) use ($stored, $alert) {
                $query->where('alert_id', $alert->id);
                if ($stored->record_id !== null) {
                    // Only an already-linked competitor needs synchronous revocation.
                    // Other candidates are already dark and repair visits them in chunks.
                    // The validated record/org unique key bounds this to one competitor.
                    $query->orWhere(fn ($q) => $q->where('record_type', $stored->record_type)
                        ->where('record_id', $stored->record_id)->where('organization_id', $stored->organization_id)
                        ->whereExists(fn ($a) => $a->selectRaw('1')->from('alerts')
                            ->whereColumn('alerts.id', 'huntress_link_candidates.alert_id')->whereNotNull('huntress_event_id')));
                }
            });
            $this->clearOrphans($stored->record_type, $stored->record_id, $stored->organization_id, $alert->id);
            $this->promoteLocked($scope);
        }, 3);
    }

    /** Arrival work is record-scoped; polling repair uses bounded transactions. */
    public function promote(?string $recordType = null, ?int $recordId = null): void
    {
        $alerts = DB::table('alerts')->whereNotNull('huntress_event_id');
        $candidates = DB::table('huntress_link_candidates');
        if ($recordType !== null && $recordId !== null) {
            $alerts->where('huntress_record_type', $recordType)->where('huntress_record_id', $recordId);
            $candidates->where('record_type', $recordType)->where('record_id', $recordId);
        }
        // Snapshot high-water marks: concurrent arrivals do their own scoped work.
        $alertMax = (clone $alerts)->max('id');
        $candidateMax = (clone $candidates)->max('id');
        $alerts->where('id', '<=', $alertMax ?? 0)->chunkById(100, function ($rows) {
            DB::transaction(function () use ($rows) {
                $this->lock();
                $this->clearOrphanQuery(DB::table('alerts')->whereIn('id', $rows->pluck('id')));
            }, 3);
        });
        $candidates->where('id', '<=', $candidateMax ?? 0)->chunkById(100, function ($rows) {
            DB::transaction(function () use ($rows) {
                $this->lock();
                $this->promoteLocked(DB::table('huntress_link_candidates')->whereIn('id', $rows->pluck('id')));
            }, 3);
        });
    }

    private function clearOrphans(?string $type, ?int $id, ?int $orgId, int $alertId): void
    {
        $this->clearOrphanQuery(DB::table('alerts')->where(function ($query) use ($type, $id, $orgId, $alertId) {
            $query->where('id', $alertId);
            if ($type !== null && $id !== null) {
                $query->orWhere(fn ($q) => $q->where('huntress_record_type', $type)->where('huntress_record_id', $id)
                    ->where('huntress_org_id', $orgId));
            }
        }));
    }

    private function clearOrphanQuery(\Illuminate\Database\Query\Builder $query): void
    {
        $query->whereNotNull('huntress_event_id')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('huntress_link_candidates as candidate')
                ->join('tickets', 'tickets.id', '=', 'candidate.ticket_id')
                ->whereNull('tickets.deleted_at')
                ->whereColumn('candidate.alert_id', 'alerts.id')
                ->whereColumn('candidate.ticket_id', 'alerts.ticket_id');
        })->update([
            'huntress_account_id' => null, 'huntress_org_id' => null,
            'huntress_record_type' => null, 'huntress_record_id' => null,
            'huntress_event_id' => null, 'huntress_linked_at' => null,
            'huntress_link_refusal' => 'orphaned_link',
        ]);
    }

    private function liveCandidates(): \Illuminate\Database\Query\Builder
    {
        return DB::table('huntress_link_candidates')->whereExists(function ($query) {
            $query->selectRaw('1')->from('tickets')
                ->whereColumn('tickets.id', 'huntress_link_candidates.ticket_id')->whereNull('tickets.deleted_at');
        });
    }

    private function lock(): void
    {
        // An actual write also obtains SQLite's writer lock in synthetic tests.
        DB::table('huntress_link_mutex')->where('id', 1)->increment('version');
    }

    private function promoteLocked(\Illuminate\Database\Query\Builder $candidates): void
    {
        foreach ($candidates->orderBy('id')->lazyById(100) as $candidate) {
            $alert = Alert::find($candidate->alert_id);
            $ticket = Ticket::find($candidate->ticket_id);
            if (! $alert || ! $ticket) {
                continue;
            }
            $reason = $candidate->capture_refusal;
            $event = null;
            // All captured competitors count, not just the first one to link.
            // Revocation on late duplicates prevents arrival order choosing a winner.
            if ($this->liveCandidates()->where('alert_id', $candidate->alert_id)->limit(2)->pluck('id')->count() > 1
                || ($candidate->record_id !== null && $this->liveCandidates()
                    ->where('record_type', $candidate->record_type)->where('record_id', $candidate->record_id)
                    ->where('organization_id', $candidate->organization_id)->limit(2)->pluck('id')->count() > 1)) {
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
                    ->where('record_id', $candidate->record_id)->orderBy('id')->lazyById(100);
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
            $alert->forceFill($values);
            if ($alert->isDirty(array_keys($values))) {
                DB::table('alerts')->where('id', $alert->id)->update($values);
            }
        }
    }
}
