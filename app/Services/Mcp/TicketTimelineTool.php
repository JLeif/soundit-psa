<?php

namespace App\Services\Mcp;

use App\Models\Ticket;
use InvalidArgumentException;

final class TicketTimelineTool
{
    public static function definition(): array
    {
        return ['name' => 'get_ticket_timeline',
            'description' => 'Staff ticket timeline: notes, calls, inbound/outbound emails, AI chats and redacted tool activity, newest first. Explicit grant and client context required. before/after are opaque cursors from a previous page (older/newer respectively), bound to ticket/client/types. after returns the nearest newer page in newest-first order. has_more/next_cursor describe further rows in the requested direction. Proposals are not executed; executed_with_fault means the write landed: follow up, never blindly retry. Raw tool payloads withheld. Replaces separate history reads without removing them.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'ticket_id' => ['type' => ['integer', 'string']],
                'client_id' => ['type' => 'integer'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'before' => ['type' => 'string'], 'after' => ['type' => 'string'],
                'types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => TicketTimeline::TYPES]],
            ], 'required' => ['ticket_id', 'client_id']]];
    }

    public function execute(array $input, ?int $clientId): array
    {
        if (! $clientId || (! is_int($input['ticket_id'] ?? null) && ! is_string($input['ticket_id'] ?? null))) {
            return ['error' => 'Ticket and client context required'];
        }
        $ticket = Ticket::resolveReference($input['ticket_id'], $clientId);
        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }
        try {
            $result = app(TicketTimeline::class)->page($ticket, $input);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        TicketToolActivityContext::current()?->validated($ticket);
        TicketToolActivityContext::current()?->finish($result, read: true);

        return $result;
    }
}
