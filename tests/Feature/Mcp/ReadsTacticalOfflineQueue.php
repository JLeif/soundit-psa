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
     * #6743: from this point on, records the SQL of every executed statement that is either an
     * insert into technician_runs, or an update of technician_runs whose SET list names one of
     * the columns in QUEUE_COLUMNS. Only the SET list is read, not the WHERE clause. state is not
     * in QUEUE_COLUMNS, so an update that sets only state (and updated_at) is not recorded. It
     * reads statements on every connection.
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
