<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * #6744: helpers shared by TacticalOfflineQueueApprovalTest and
 * TacticalOfflineQueueProductionTransactionTest. Each docblock below says what the helper reads
 * or asserts.
 */
trait ReadsTacticalOfflineQueue
{
    /**
     * Reads 7 values from a fresh copy of this run: the columns state, queued_agent_id,
     * queued_dedup_key, queued_at, expires_at and coalesce_count, and one proposed_meta key,
     * queued_approver_id (null when that key is absent). It reads no other proposed_meta key.
     *
     * @return array<string, mixed>
     */
    private function queueFields(TechnicianRun $run): array
    {
        $run = $run->fresh();

        return [
            'state' => $run->state,
            'queued_agent_id' => $run->queued_agent_id,
            'queued_dedup_key' => $run->queued_dedup_key,
            'queued_at' => $run->queued_at?->toDateTimeString(),
            'expires_at' => $run->expires_at?->toDateTimeString(),
            'coalesce_count' => $run->coalesce_count,
            'queued_approver_id' => $run->proposed_meta['queued_approver_id'] ?? null,
        ];
    }

    /**
     * The 7 queueFields() values of a run, read before the call under test. It asserts state
     * QueuedOffline, queued_agent_id 'agent-1', and that each of 4 others (queued_dedup_key,
     * queued_at, expires_at, queued_approver_id) is non-null. It asserts nothing about
     * coalesce_count. It returns all 7.
     *
     * @return array<string, mixed>
     */
    private function queuedFieldsBefore(TechnicianRun $run): array
    {
        $before = $this->queueFields($run);
        $this->assertSame(TechnicianRunState::QueuedOffline, $before['state']);
        $this->assertSame('agent-1', $before['queued_agent_id']);
        foreach (['queued_dedup_key', 'queued_at', 'expires_at', 'queued_approver_id'] as $key) {
            $this->assertNotNull($before[$key], $key.' is set before the call');
        }

        return $before;
    }

    /** @return \Closure(): list<MessageLogged> every MessageLogged record dispatched from this point on */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /**
     * #6743: registers a DB::listen() callback (this trait never removes it). The returned closure
     * gives the statements recorded between registration and the moment the closure is called.
     * The callback records the SQL text of a QueryExecuted statement in exactly two shapes, both
     * lowercase with double-quoted identifiers:
     * - SQL that starts with `insert into "technician_runs"`;
     * - SQL matching `^update "technician_runs" set `, where the text from `set ` up to the first
     *   ' where ' (or the end) contains one of the QUEUE_COLUMNS names in double quotes. A ' where '
     *   inside the SET list cuts it there.
     * It reads the SQL text only, never the bindings. state is not in QUEUE_COLUMNS, so an update
     * that sets only state (and updated_at) is not recorded. Other SQL shapes are not recorded,
     * among them `insert or ignore`, raw SQL that is uppercase or unquoted, a table prefix,
     * backtick-quoted (MySQL/MariaDB grammar) identifiers, and an aliased update (an aliased
     * insert still starts with `insert into "technician_runs"` and is recorded); this list is read
     * from the matching code below. Not measured: whether any of these shapes, or a statement on a
     * second connection, runs in a probe window; by a reading of the two test classes that use
     * this trait, none does. By inference from DB::listen() and the shared event dispatcher,
     * statements on other connections reach the callback, which records them only in the
     * double-quoted shapes above.
     *
     * @return \Closure(): list<string>
     */
    private function captureQueueWrites(): \Closure
    {
        $writes = new \ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($writes): void {
            $sql = $query->sql;
            if (str_starts_with($sql, 'insert into "technician_runs"')) {
                $writes[] = $sql;

                return;
            }
            if (preg_match('/^update "technician_runs" set (.*?)(?: where |$)/s', $sql, $m) !== 1) {
                return;
            }
            foreach (self::QUEUE_COLUMNS as $column) {
                if (str_contains($m[1], '"'.$column.'"')) {
                    $writes[] = $sql;

                    return;
                }
            }
        });

        return fn () => $writes->getArrayCopy();
    }

    /** #6743: the technician_runs columns captureQueueWrites() looks for in an update's SET list. */
    private const QUEUE_COLUMNS = ['queued_agent_id', 'queued_dedup_key', 'queued_at', 'expires_at', 'coalesce_count', 'proposed_meta'];
}
