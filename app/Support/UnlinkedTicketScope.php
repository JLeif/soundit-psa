<?php

namespace App\Support;

/** Explicit ticket scope: never client zero, global scope, or any-client access. */
enum UnlinkedTicketScope
{
    case Unlinked;

    public static function admits(string $tool): bool
    {
        return in_array($tool, ['move_ticket_to_client', 'close_ticket', 'stage_close_ticket'], true);
    }
}
