{{-- Held agent moves of a time entry's contract (card I3EvQKUV PR 2, ruling Q9). --}}
@php($timeEntryMoves = \App\Models\TimeEntryMoveProposal::where('state', 'pending')->with(['fromContract', 'toContract'])->orderBy('id')->get())
@php($canApproveTimeEntryMove = app(\App\Services\PhoneCallActionService::class)->canApprove(auth()->user()))
@php($timeEntryMoveService = app(\App\Services\TimeEntryContractMoveService::class))
@if($timeEntryMoves->isNotEmpty())
<section class="mb-4">
    <h2 class="h5">Time entry contract moves ({{ $timeEntryMoves->count() }})</h2>
    @foreach($timeEntryMoves as $proposal)
        @php($type = 'stage_move_time_entry_contract')
        <div class="card mb-2"><div class="card-body">
            <span class="badge bg-warning text-dark">{{ \App\Support\StagedActionLabels::humanLabel($type) }}</span>
            <strong>{{ $proposal->entry_type === 'note' ? 'Note' : 'Call' }} #{{ $proposal->entry_id }}</strong>
            on <a href="{{ route('tickets.show', $proposal->ticket_id) }}">ticket #{{ $proposal->ticket_id }}</a>:
            {{ $proposal->fromContract?->name ?? 'no contract' }} → <strong>{{ $proposal->toContract?->name ?? 'contract #'.$proposal->to_contract_id }}</strong>
            <p class="mb-1">{{ $timeEntryMoveService->approvalEffect($proposal) }}</p>
            <p>{{ $proposal->reason }}</p>
            <small>Proposed by {{ $proposal->drafted_by }}. Approval refuses the move as stale if the entry is no longer on this ticket, its contract has changed or it was moved since staging, and re-checks that the new contract is an active contract of the ticket's client.</small>
            @if($canApproveTimeEntryMove)
                <form method="POST" action="{{ route('time-entry-moves.approve', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-success">Approve move</button>
                </form>
                <form method="POST" action="{{ route('time-entry-moves.deny', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-danger">Deny</button>
                </form>
            @endif
        </div></div>
    @endforeach
</section>
@endif
