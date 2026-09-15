@if(($phoneCallResolutions ?? collect())->isNotEmpty())
<section class="mb-4">
    <h2 class="h5">Phone call identity approvals ({{ $phoneCallResolutions->count() }})</h2>
    @foreach($phoneCallResolutions as $proposal)
        <div class="card mb-2"><div class="card-body">
            <strong>Call #{{ $proposal->phone_call_id }}</strong>
            → Client #{{ $proposal->client_id }}, contact #{{ $proposal->payload['contact_id'] ?? '?' }}
            <p class="mb-1">Ticket association is preserved. Approval revalidates identity and ownership.</p>
            <p>{{ $proposal->payload['reason'] ?? '' }}</p>
            <small>Proposed by {{ $proposal->drafted_by }}</small>
            @if($canApprovePhoneCallResolution ?? false)
                <form method="POST" action="{{ route('phone-call-resolutions.approve', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-success">Approve caller identity</button>
                </form>
                <form method="POST" action="{{ route('phone-call-resolutions.deny', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-danger">Deny</button>
                </form>
            @endif
        </div></div>
    @endforeach
</section>
@endif
