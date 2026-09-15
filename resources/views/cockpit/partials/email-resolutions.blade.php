@if(($emailResolutions ?? collect())->isNotEmpty())
<section class="mb-4" aria-label="Email resolution approvals">
    <h2 class="h5">Email resolution approvals ({{ $emailResolutions->count() }})</h2>
    @foreach($emailResolutions as $proposal)
        @php($intent = $proposal->payload)
        <article class="card mb-2">
            <div class="card-body">
                <h3 class="h6">Resolve sender backlog to {{ $proposal->client?->name ?? 'Missing client' }}</h3>
                <p>Sender: {{ $intent['sender'] }} · <strong>{{ $proposal->email_count }} unresolved email(s)</strong></p>
                <p>Exact email IDs: {{ implode(', ', $intent['email_ids']) }}. Target client #{{ $proposal->client_id }}.</p>
                <p>Reason: {{ $intent['reason'] }}</p>
                <p class="small text-muted">Any backlog change refuses this approval and requires a new proposal. This resolves client matching only; it does not create a ticket or contact.</p>
                <a href="{{ route('emails.show', $proposal->email_id) }}">Review source email</a>
                @if($canApproveEmailResolution ?? false)
                    <form method="POST" action="{{ route('email-resolutions.approve', $proposal->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Approve {{ $proposal->email_count }} email(s)</button>
                    </form>
                    <form method="POST" action="{{ route('email-resolutions.deny', $proposal->id) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Deny</button>
                    </form>
                @endif
            </div>
        </article>
    @endforeach
</section>
@endif
