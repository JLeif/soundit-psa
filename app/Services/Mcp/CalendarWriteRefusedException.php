<?php

namespace App\Services\Mcp;

/**
 * A calendar write that was refused for a DOMAIN reason before it touched Graph —
 * distinct from GraphClientException (an upstream/transport failure). Thrown by
 * executeCalendarWrite when it cannot proceed safely, e.g. a body edit on a Teams
 * meeting whose join block cannot be confidently preserved (review blocker 3).
 *
 * It is raised BEFORE the Graph write, so both write paths treat it as a clean,
 * NON-retryable refusal: the immediate path returns it as an error, and the staged
 * path releases the claim and declines — never a re-approve-to-retry (nothing failed
 * upstream) and never a cockpit 500.
 */
class CalendarWriteRefusedException extends \RuntimeException {}
