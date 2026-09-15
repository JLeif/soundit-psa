<?php

namespace App\Services\Huntress;

use App\Enums\AlertSource;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AlertService;
use App\Services\SyncResult;
use App\Services\TicketService;
use App\Support\HuntressConfig;
use Illuminate\Support\Facades\DB;

/** Polling is a lifecycle fallback, never a source of record correspondence. */
abstract class HuntressLinkedReconcileService
{
    abstract protected function recordType(): string;

    public function __construct(
        private readonly HuntressClient $client,
        private readonly TicketService $ticketService,
        private readonly AlertService $alertService,
    ) {}

    public function reconcile(): SyncResult
    {
        $result = new SyncResult;
        // Repairs a durable event whose post-commit promotion failed, without requiring
        // another vendor delivery. Promotion itself never resolves tickets.
        try {
            app(HuntressLinkService::class)->promote();
        } catch (\Throwable) {
            $result->recordError('link_repair_failed');

            return $result;
        }
        Ticket::where('source', TicketSource::Huntress->value)
            ->whereIn('status', array_map(fn ($s) => $s->value,
                array_filter(TicketStatus::cases(), fn ($s) => $s->isOpen())))
            ->chunkById(100, function ($tickets) use ($result) {
                foreach ($tickets as $ticket) {
                    try {
                        $this->poll($ticket, $result);
                    } catch (\Throwable) {
                        // Exception bodies can contain vendor/client data. The ticket id
                        // and stable reason are sufficient to chase this failure.
                        $result->recordError("#{$ticket->id}: reconcile_failed");
                    }
                }
            });

        return $result;
    }

    private function link(Ticket $ticket): ?Alert
    {
        $alerts = Alert::where('source', AlertSource::Huntress->value)
            ->where('ticket_id', $ticket->id)->get();
        if ($alerts->count() !== 1) {
            return null;
        }
        $alert = $alerts->first();
        if (! HuntressConfig::webhooksEnabled() || ! $alert->huntress_event_id
            || $alert->huntress_link_refusal !== null
            || $alert->huntress_record_type !== $this->recordType()
            || ! $alert->huntress_record_id || ! $alert->huntress_org_id
            || (int) $alert->client_id !== (int) $ticket->client_id
            || (int) $ticket->client?->huntress_organization_id !== (int) $alert->huntress_org_id
            || (int) $alert->huntress_account_id !== (int) HuntressConfig::get('webhook_account_id')) {
            return null;
        }
        $event = DB::table('huntress_webhook_events')->find($alert->huntress_event_id);
        if (! $event || $event->record_type !== $this->recordType()
            || (int) $event->record_id !== (int) $alert->huntress_record_id
            || (int) $event->account_id !== (int) $alert->huntress_account_id
            || ! in_array((int) $alert->huntress_org_id, json_decode($event->organization_ids, true), true)) {
            return null;
        }

        return $alert;
    }

    private function poll(Ticket $ticket, SyncResult $result): void
    {
        $alert = $this->link($ticket);
        if (! $alert) {
            $result->recordSkipped("#{$ticket->id}: no_validated_link");

            return;
        }
        try {
            $record = $this->recordType() === 'incident_report'
                ? $this->client->getIncidentReport((int) $alert->huntress_record_id)
                : $this->client->getEscalation((int) $alert->huntress_record_id);
        } catch (\Throwable) {
            $result->recordError("#{$ticket->id}: fetch_failed");

            return;
        }
        $result->details[] = "#{$ticket->id}: checked";
        if (! $this->upstreamScope($record, $alert)) {
            $result->recordSkipped("#{$ticket->id}: upstream_scope_mismatch");

            return;
        }
        $status = strtolower((string) ($record['status'] ?? ''));
        $resolved = $this->recordType() === 'incident_report'
            ? in_array($status, ['closed', 'dismissed'], true) || ! empty($record['closed_at'])
            : $status === 'resolved' || ! empty($record['resolved_at']);
        if (! $resolved) {
            $result->recordSkipped("#{$ticket->id}: still_open");

            return;
        }

        DB::transaction(function () use ($ticket, $alert, $result, $status) {
            // Serialize against promotion/revocation and other pollers. No network call
            // under this lock. Refresh ticket/client/link after the upstream read.
            DB::table('huntress_link_mutex')->where('id', 1)->increment('version');
            $fresh = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
            $current = $fresh ? $this->link($fresh) : null;
            if (! $fresh || ! $fresh->status->isOpen() || ! $current
                || (int) $current->huntress_event_id !== (int) $alert->huntress_event_id
                || (int) $current->huntress_record_id !== (int) $alert->huntress_record_id
                || (int) $current->huntress_org_id !== (int) $alert->huntress_org_id) {
                $result->recordSkipped("#{$ticket->id}: link_changed");

                return;
            }
            $systemTypes = array_map(fn (NoteType $t) => $t->value, NoteType::systemGenerated());
            if (TicketNote::where('ticket_id', $fresh->id)->where(function ($q) use ($systemTypes) {
                $q->whereNotIn('note_type', $systemTypes)->orWhere('who_type', WhoType::EndUser->value);
            })->exists()) {
                $result->recordSkipped("#{$ticket->id}: human_touched");

                return;
            }
            $userId = HuntressConfig::systemUserId();
            if (! $userId) {
                throw new \RuntimeException('Huntress system user unavailable');
            }
            $note = $this->recordType() === 'escalation'
                ? 'Resolved automatically — Huntress resolved this escalation upstream.'
                : ($status === 'dismissed'
                    ? 'Resolved automatically — Huntress dismissed this incident upstream.'
                    : 'Resolved automatically — Huntress remediated and closed this incident upstream.');
            $this->ticketService->changeStatus($fresh, TicketStatus::Resolved, $userId, $note);
            $this->alertService->resolve($current, 'Resolved via validated Huntress link.');
            $result->updated++;
        });
    }

    private function upstreamScope(array $record, Alert $alert): bool
    {
        // By-id reads prove current scope, not ticket binding. Require their returned
        // identity too: a malformed/misdirected response must not authorize closure.
        if ((string) ($record['id'] ?? '') !== (string) $alert->huntress_record_id) {
            return false;
        }
        if ($this->recordType() === 'incident_report') {
            return (string) ($record['organization_id'] ?? '') === (string) $alert->huntress_org_id;
        }
        foreach ($record['organizations'] ?? [] as $org) {
            if (is_array($org) && (string) ($org['id'] ?? '') === (string) $alert->huntress_org_id) {
                return true;
            }
        }

        return false;
    }
}
