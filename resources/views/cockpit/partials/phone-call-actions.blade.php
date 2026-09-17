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
            <small>Proposed by {{ $proposal->drafted_by }}. Approval revalidates the call, its ticket link and the phone directory.</small>
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
