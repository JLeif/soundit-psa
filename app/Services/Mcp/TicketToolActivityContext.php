<?php

namespace App\Services\Mcp;

use App\Models\TechnicianActionLog;
use App\Models\Ticket;

/** One controller dispatch only; never inferred from arguments or ambient client context. */
final class TicketToolActivityContext
{
    public ?int $ticketId = null;

    public ?int $clientId = null;

    public ?int $actionLogId = null;

    public ?string $correlationId = null;

    public string $summary = 'Outcome pending; no execution confirmed.';

    public string $kind = 'pending';

    public static function current(): ?self
    {
        $context = request()->attributes->get(self::class);

        return $context instanceof self ? $context : null;
    }

    public function validated(Ticket $ticket): void
    {
        if ($this->actionLogId !== null) {
            return;
        }
        $this->ticketId = $ticket->id;
        $this->clientId = $ticket->client_id;
    }

    public function produced(TechnicianActionLog $log): void
    {
        if ($log->ticket_id === null) {
            return;
        }
        $ticket = Ticket::find($log->ticket_id);
        if (! $ticket || $ticket->client_id !== $log->client_id) {
            return;
        }
        $this->ticketId = $ticket->id;
        $this->clientId = $ticket->client_id;
        $this->actionLogId = $log->id;
        $this->correlationId = $log->correlation_id;
    }

    /** Deliberately allowlisted aggregate, not free text or serialized result/error bodies. */
    public function finish(mixed $result, bool $read = false): void
    {
        $failed = is_array($result) && isset($result['error']);
        $this->kind = $failed ? 'failure' : ($read ? 'read' : 'pending');
        $this->summary = $failed ? 'Call failed; diagnostic payload withheld.'
            : ($read ? 'Read returned successfully; content withheld.' : 'Call returned; execution is not confirmed by call success.');
        if ($read && is_array($result) && array_is_list($result) && ! $failed) {
            $this->summary = 'Read returned '.count($result).' items; content withheld.';
        }
    }
}
