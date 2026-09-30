<?php

namespace App\Services\Mcp;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** Shared staff projection. Never exports arguments, raw result/error, or action summary text. */
final class TicketToolActivity
{
    public function page(Ticket $ticket, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, min(10000, $offset));
        $rows = $this->query($ticket)
            ->orderByDesc('created_at')->orderByDesc('id')->orderBy('source')
            ->offset($offset)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn ($row) => $this->present($row))->all();

        return ['items' => $items, 'limit' => $limit, 'offset' => $offset, 'has_more' => $more,
            'next_offset' => $more && $offset + $limit <= 10000 ? $offset + $limit : null,
            'truncated' => $more, 'order' => 'created_at DESC, id DESC, source ASC',
            'pagination' => 'Offset paging; concurrent inserts may shift pages. Maximum offset 10000. Timestamps are UTC.',
            'states' => self::STATES,
            'coverage' => 'Explicitly associated staff calls and ticket action records only; unassociated calls and legacy MCP calls are absent, not proof of no activity. Raw outputs withheld.'];
    }

    public const STATES = 'proposed = staged, not executed; executed = execution recorded; executed_with_fault = execution recorded WITH a fault (the write landed — follow up, do not re-run); failure = refusal or failure, no execution recorded; no_op = recorded as a no-op, nothing was changed; pending = outcome not confirmed; read = read returned.';

    public function query(Ticket $ticket): \Illuminate\Database\Query\Builder
    {
        $calls = DB::table('mcp_audit_logs as m')
            ->leftJoin('technician_action_logs as a', function ($join): void {
                $join->on('a.id', '=', 'm.action_log_id')->on('a.ticket_id', '=', 'm.ticket_id')
                    ->on('a.correlation_id', '=', 'm.correlation_id')
                    ->whereRaw('(a.client_id = m.client_id OR (a.client_id IS NULL AND m.client_id IS NULL))');
            })
            ->where('m.server_name', 'staff')->where('m.method', 'tools/call')
            ->whereNotIn('m.tool_name', ['get_ticket_tool_history', 'get_ticket_timeline'])
            ->where('m.ticket_id', $ticket->id)->where('m.client_id', $ticket->client_id)
            ->selectRaw("m.id, 'call' as source, m.tool_name as tool, m.actor_label as actor, m.created_at, m.ticket_id, m.result_summary, m.activity_kind, a.result_status, m.status as call_status");
        // Preserve independent approvals/execution and historical actions without duplicating linked rows.
        $actions = DB::table('technician_action_logs as a')
            ->where('a.ticket_id', $ticket->id)->where('a.client_id', $ticket->client_id)
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('mcp_audit_logs as m')->whereColumn('m.action_log_id', 'a.id')
                    ->whereColumn('m.ticket_id', 'a.ticket_id')->whereColumn('m.correlation_id', 'a.correlation_id')
                    ->where('m.server_name', 'staff')->where('m.method', 'tools/call')
                    ->whereNotIn('m.tool_name', ['get_ticket_tool_history', 'get_ticket_timeline'])
                    ->whereRaw('(a.client_id = m.client_id OR (a.client_id IS NULL AND m.client_id IS NULL))');
            })
            ->selectRaw("a.id, 'action' as source, a.action_type as tool, a.actor_label as actor, a.created_at, a.ticket_id, NULL as result_summary, NULL as activity_kind, a.result_status, NULL as call_status");

        return DB::query()->fromSub($calls->unionAll($actions), 'activity');
    }

    public function present(object $row): array
    {
        $state = $row->result_status !== null ? match ($row->result_status) {
            'awaiting_approval' => 'proposed',
            'executed' => 'executed',
            // The write LANDED; the fault is follow-up, not a denial of execution.
            // Never 'failure': a consumer keying on state must not re-run a
            // committed side effect.
            'executed_with_fault' => 'executed_with_fault',
            'no_op' => 'no_op',
            'error', 'failed', 'blocked' => 'failure',
            default => 'pending',
        } : ($row->call_status === 'error' ? 'failure' : (in_array($row->activity_kind, ['read', 'failure'], true) ? $row->activity_kind : 'pending'));
        $summary = match ($state) {
            'proposed' => 'Awaiting approval; not executed.',
            'executed' => 'Action execution recorded.',
            'executed_with_fault' => 'Action executed with a fault; follow-up required. Diagnostic payload withheld.',
            'failure' => 'Failure or refusal recorded; diagnostic payload withheld.',
            'no_op' => 'No-op recorded; nothing was changed.',
            'read' => 'Read returned successfully; content withheld.',
            default => 'Pending or held; execution not confirmed.',
        };

        if ($state === 'read' && preg_match('/^Read returned [0-9]{1,8} items; content withheld\\.$/D', $row->result_summary ?? '')) {
            $summary = $row->result_summary;
        }

        return ['id' => $row->source.':'.$row->id, 'tool' => mb_substr($row->tool ?? '', 0, 120),
            'actor' => mb_substr($row->actor ?? 'Unknown', 0, 120), 'time' => $row->created_at,
            'ticket_id' => (int) $row->ticket_id, 'state' => $state, 'summary' => $summary,
            'result_redacted' => true];

    }
}
