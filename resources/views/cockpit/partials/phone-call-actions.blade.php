@if(($phoneCallActions ?? collect())->isNotEmpty())
<section class="mb-4">
    <h2 class="h5">Call log action approvals ({{ $phoneCallActions->count() }})</h2>
    @foreach($phoneCallActions as $proposal)
        @php($type = 'stage_'.$proposal->action_type)
        <div class="card mb-2"><div class="card-body">
            <span class="badge bg-warning text-dark">{{ \App\Support\StagedActionLabels::humanLabel($type) }}</span>
            <strong>Call #{{ $proposal->phone_call_id }}</strong>
            @if($proposal->action_type === 'set_call_billable')
                → mark {{ ($proposal->payload['billable'] ?? false) ? 'BILLABLE' : 'NON-BILLABLE' }}
                <p class="mb-1">Approving re-runs the prepay debit for this call, so it moves prepay hours on the client contract. Nothing has moved yet.</p>
            @elseif($proposal->action_type === 'block_caller')
                → add caller to the <strong>Blocked</strong> list
                <p class="mb-1">Approving makes the IVR hang up on future calls from this number. The number is not blocked yet.</p>
            @else
                → add caller to the <strong>Allowed</strong> list
                <p class="mb-1">Approving lets future calls from this number ring through. An existing directory entry is never overwritten.</p>
            @endif
            <p>{{ $proposal->payload['reason'] ?? '' }}</p>
            {{-- The assurance MUST match what approve() actually rechecks for THIS
                 action type. snapshot() returns the ticket/client/contract keys only
                 for set_call_billable; for block/allow the stale check is the caller
                 number and the directory alone. A shared sentence made the billable
                 card claim a directory recheck and the block/allow cards claim a
                 ticket/client/contract recheck, neither of which happens — false
                 assurance on the one surface whose whole job is to inform approval. --}}
            @if($proposal->action_type === 'set_call_billable')
                <small>Proposed by {{ $proposal->drafted_by }}. Approval revalidates the call, its ticket link, that ticket's client, the prepay contract the debit resolves to, and the billed duration. It does not check the phone directory.</small>
            @else
                <small>Proposed by {{ $proposal->drafted_by }}. Approval revalidates the call, that it is inbound, the caller number, and that the phone directory still has no entry for it. It does not check any ticket, client or contract.</small>
            @endif
            @if($canApprovePhoneCallAction ?? false)
                <form method="POST" action="{{ route('phone-call-actions.approve', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-success">Approve call action</button>
                </form>
                <form method="POST" action="{{ route('phone-call-actions.deny', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-danger">Deny</button>
                </form>
            @endif
        </div></div>
    @endforeach
</section>
@endif
