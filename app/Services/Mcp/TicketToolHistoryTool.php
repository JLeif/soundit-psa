<?php

namespace App\Services\Mcp;

use App\Models\Ticket;

final class TicketToolHistoryTool
{
    public static function definition(): array
    {
        return ['name' => 'get_ticket_tool_history',
            'description' => 'Staff-only ticket tool calls and action outcomes. Proposed is not executed. Explicit associations only; raw results redacted. Newest first, bounded offset pagination (concurrent inserts may shift pages); has_more/next_offset/truncated describe remaining rows. Complements get_ticket_notes.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'ticket_id' => ['type' => ['integer', 'string']],
                'client_id' => ['type' => 'integer'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
            ], 'required' => ['ticket_id', 'client_id']]];
    }

    public function execute(array $input, ?int $clientId): array
    {
        if (! $clientId || (! is_int($input['ticket_id'] ?? null) && ! is_string($input['ticket_id'] ?? null))) {
            return ['error' => 'Ticket and client context required'];
        }
        foreach (['limit' => [1, 50], 'offset' => [0, 10000]] as $key => [$min, $max]) {
            if (array_key_exists($key, $input) && (! is_int($input[$key]) || $input[$key] < $min || $input[$key] > $max)) {
                return ['error' => "Invalid {$key}"];
            }
        }
        $ticket = Ticket::resolveReference($input['ticket_id'], $clientId);
        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }
        TicketToolActivityContext::current()?->validated($ticket);
        $result = app(TicketToolActivity::class)->page($ticket, $input['limit'] ?? 20, $input['offset'] ?? 0);
        TicketToolActivityContext::current()?->finish($result, read: true);

        return $result;
    }
}
