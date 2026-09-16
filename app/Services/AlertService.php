<?php

namespace App\Services;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

class AlertService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Create or update an alert (upsert with dedup).
     */
    public function upsert(
        AlertSource $source,
        string $sourceAlertId,
        array $data,
    ): Alert {
        $existing = Alert::where('source', $source)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if ($existing) {
            // Re-fired — update existing
            $existing->update([
                'message' => $data['message'] ?? $existing->message,
                'fired_at' => $data['fired_at'] ?? $existing->fired_at,
                'refired_count' => $existing->refired_count + 1,
                'metadata' => array_merge($existing->metadata ?? [], $data['metadata'] ?? []),
            ]);

            Log::debug("[Alert] Re-fired {$source->value} alert {$sourceAlertId}", [
                'alert_id' => $existing->id,
                'refired_count' => $existing->refired_count,
            ]);

            return $existing;
        }

        // The `alerts` table has unique(source, source_alert_id)
        // (database/migrations/2026_03_25_000001_create_alerts_table.php:32),
        // so a given key is owned by exactly one row for the table's entire
        // lifetime, even after that row resolves. Without this branch, any
        // source whose alert resolves and later recurs under the same
        // source_alert_id would hit a duplicate-key error on the INSERT below
        // instead of getting an alert: the estate drifts again, and the write
        // meant to report it fails. So a resolved row under this key is
        // revived in place, reset to Active for the new occurrence, rather
        // than a second row being created (which the index forbids) or the
        // occurrence being silently dropped.
        $resolved = Alert::where('source', $source)
            ->where('source_alert_id', $sourceAlertId)
            ->where('status', AlertStatus::Resolved)
            ->first();

        if ($resolved) {
            // Reviving in place means updating client_id to whatever the
            // incoming payload claims - which is exactly how an alert could
            // move between clients silently. Before this branch existed, a
            // cross-client collision on a resolved row hit the unique index
            // and threw a QueryException: loud, and nothing was written.
            // Tactical's fallback key (md5("{hostname}:{checkLabel}"), see
            // TacticalAlertService.php:173) is NOT client-scoped, so two
            // different clients can each have a "SERVER01" with a "Disk
            // Space" check and collide on the same source_alert_id. Refusing
            // here - rather than letting the update proceed - preserves that
            // loud failure instead of quietly reassigning the alert (and its
            // history) to the wrong client. The Leif RMM controller already
            // guards this with its own 422 before calling upsert, so it never
            // reaches this exception; every other source gets the exception
            // instead of the silent move.
            if ($resolved->client_id !== null && ($data['client_id'] ?? null) !== null && $resolved->client_id !== $data['client_id']) {
                throw new \RuntimeException("Refusing to revive alert {$resolved->id}: it belongs to a different client than this {$source->value} alert claims.");
            }

            $metadata = array_merge($resolved->metadata ?? [], $data['metadata'] ?? []);
            if ($resolved->ticket_id !== null) {
                $metadata['previous_ticket_id'] = $resolved->ticket_id;
            }
            if ($resolved->resolved_at !== null) {
                $metadata['previous_resolved_at'] = $resolved->resolved_at->toIso8601String();
            }

            $resolved->update([
                'asset_id' => $data['asset_id'] ?? $resolved->asset_id,
                'client_id' => $data['client_id'] ?? $resolved->client_id,
                'severity' => $data['severity'],
                'status' => AlertStatus::Active,
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'hostname' => $data['hostname'] ?? null,
                'ticket_id' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'resolved_at' => null,
                'refired_count' => $resolved->refired_count + 1,
                'metadata' => $metadata,
                'fired_at' => $data['fired_at'] ?? now(),
            ]);

            Log::info("[Alert] Revived {$source->value} alert {$sourceAlertId}", [
                'alert_id' => $resolved->id,
                'refired_count' => $resolved->refired_count,
            ]);

            return $resolved;
        }

        $alert = Alert::create([
            'asset_id' => $data['asset_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'source' => $source,
            'source_alert_id' => $sourceAlertId,
            'severity' => $data['severity'],
            'status' => AlertStatus::Active,
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'fired_at' => $data['fired_at'] ?? now(),
        ]);

        Log::info("[Alert] Created {$source->value} alert", [
            'alert_id' => $alert->id,
            'severity' => $data['severity']->value ?? $data['severity'],
            'title' => $data['title'],
            'hostname' => $data['hostname'] ?? null,
        ]);

        return $alert;
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Alert $alert, User $user): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Acknowledged,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Convert an alert to a ticket.
     */
    public function createTicket(Alert $alert, ?int $userId = null): ?Ticket
    {
        if ($alert->ticket_id) {
            return $alert->ticket;
        }

        $priority = $alert->severity->toTicketPriority();
        $subject = "[{$alert->source->label()}] {$alert->severity->label()} — {$alert->title} on {$alert->hostname}";

        // Resolve contact from asset's primary user
        $contactId = null;
        if ($alert->asset) {
            $primaryUser = $alert->asset->primaryUser();
            if ($primaryUser) {
                $contactId = $primaryUser->id;
            }
        }

        // Build description
        $descLines = ["**{$alert->source->label()} Alert**"];
        $descLines[] = "- Device: {$alert->hostname}";
        $descLines[] = "- Severity: {$alert->severity->label()}";
        $descLines[] = "- Alert: {$alert->title}";
        if ($alert->fired_at) {
            $descLines[] = "- Fired: {$alert->fired_at->toDateTimeString()}";
        }
        if ($alert->message) {
            $descLines[] = '';
            $descLines[] = '**Details:**';
            $descLines[] = '```';
            $descLines[] = substr($alert->message, 0, 3000);
            $descLines[] = '```';
        }

        $ticket = $this->ticketService->createTicket([
            'subject' => $subject,
            'description' => implode("\n", $descLines),
            'client_id' => $alert->client_id,
            'contact_id' => $contactId,
            'priority' => $priority->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::Alert->value,
            'source_ref' => (string) $alert->id,
        ], $userId);

        if ($ticket && $alert->asset_id) {
            $ticket->assets()->syncWithoutDetaching([$alert->asset_id]);
        }

        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticket?->id,
        ]);

        Log::info('[Alert] Ticket created from alert', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticket?->id,
        ]);

        return $ticket;
    }

    /**
     * Attach an alert to an existing ticket.
     */
    public function attachToTicket(Alert $alert, int $ticketId, ?int $userId = null): void
    {
        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticketId,
        ]);

        // Link asset to ticket if not already linked
        $ticket = Ticket::find($ticketId);
        if ($ticket && $alert->asset_id) {
            $ticket->assets()->syncWithoutDetaching([$alert->asset_id]);
        }

        // Add a system note to the ticket documenting the linkage
        if ($ticket) {
            $authorId = $userId ?? TriageConfig::systemUserId() ?? User::orderBy('id')->value('id');
            if ($authorId) {
                $noteLines = ["**{$alert->source->label()} alert attached to this ticket**"];
                $noteLines[] = "- Alert: {$alert->title}";
                $noteLines[] = "- Severity: {$alert->severity->label()}";
                if ($alert->hostname) {
                    $noteLines[] = "- Device: {$alert->hostname}";
                }
                if ($alert->fired_at) {
                    $noteLines[] = "- Fired: {$alert->fired_at->toAppTz()->format('M j, Y g:ia T')}";
                }
                if ($alert->message) {
                    $noteLines[] = '';
                    $noteLines[] = '**Details:**';
                    $noteLines[] = '```';
                    $noteLines[] = substr($alert->message, 0, 3000);
                    $noteLines[] = '```';
                }

                $this->ticketService->addNote(
                    $ticket,
                    implode("\n", $noteLines),
                    NoteType::System,
                    true,
                    $authorId,
                );
            }
        }

        Log::info('[Alert] Attached to existing ticket', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticketId,
        ]);
    }

    /**
     * Resolve an alert (from RMM or manual).
     */
    public function resolve(Alert $alert, ?string $reason = null): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // Add note to linked ticket if exists
        if ($alert->ticket_id) {
            $ticket = $alert->ticket;
            if ($ticket && $ticket->status->isOpen()) {
                $systemUserId = TriageConfig::systemUserId() ?? User::orderBy('id')->value('id');
                if ($systemUserId) {
                    $noteBody = $reason ?? "Alert resolved by {$alert->source->label()} monitoring.";
                    $this->ticketService->addNote(
                        $ticket,
                        $noteBody,
                        NoteType::System,
                        true,
                        $systemUserId,
                    );
                }
            }
        }

        Log::info('[Alert] Resolved', [
            'alert_id' => $alert->id,
            'source' => $alert->source->value,
            'title' => $alert->title,
        ]);
    }

    /**
     * Bulk acknowledge alerts.
     */
    public function bulkAcknowledge(array $alertIds, User $user): int
    {
        return Alert::whereIn('id', $alertIds)
            ->where('status', AlertStatus::Active)
            ->update([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ]);
    }

    /**
     * Bulk create tickets from alerts.
     */
    public function bulkCreateTickets(array $alertIds, ?int $userId = null): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereNull('ticket_id')
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged])
            ->get();

        $count = 0;
        foreach ($alerts as $alert) {
            if ($this->createTicket($alert, $userId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk resolve alerts.
     */
    public function bulkResolve(array $alertIds): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();

        foreach ($alerts as $alert) {
            $this->resolve($alert, 'Manually resolved.');
        }

        return $alerts->count();
    }
}
