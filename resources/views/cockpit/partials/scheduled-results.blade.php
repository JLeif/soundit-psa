@if($scheduledResults->isNotEmpty())
<section class="mb-4" aria-labelledby="scheduled-results-title">
    <h2 class="h5" id="scheduled-results-title">Scheduled approvals — latest 100</h2>
    <p class="small text-muted">Completed means the vendor reported success. Uncertain means the effect is not known: do not retry; reconcile in CIPP first. A new proposal requires a new approval.</p>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Ticket / action</th><th>Window (selected time zone)</th><th>Result</th><th>Control</th></tr></thead>
        <tbody>@foreach($scheduledResults as $scheduled)
            <tr>
                @php($ticketMoved = (int) $scheduled->ticket_client_id !== (int) $scheduled->client_id)
                <td><a href="{{ route('tickets.show', $scheduled->ticket_id) }}">#{{ $scheduled->ticket_id }}</a><br>{{ str_replace('_', ' ', $scheduled->action_type) }}
                    @if($ticketMoved)<p class="text-danger mb-0">Ticket no longer belongs to the approved client. Shown for reconciliation; it cannot be cancelled here.</p>@endif</td>
                <td>{{ \Carbon\CarbonImmutable::parse($scheduled->not_before, 'UTC')->setTimezone($scheduled->display_timezone)->format('Y-m-d H:i T') }}<br>to {{ \Carbon\CarbonImmutable::parse($scheduled->expires_at, 'UTC')->setTimezone($scheduled->display_timezone)->format('Y-m-d H:i T') }}<br><small>{{ $scheduled->display_timezone }}</small></td>
                <td><strong>{{ ucfirst(str_replace('_', ' ', $scheduled->state)) }}</strong>
                    @if($scheduled->state === 'uncertain')<p class="text-danger mb-0">Effect unknown. Never retry automatically.</p>@endif
                    @if($scheduled->state === 'failed')<p class="text-danger mb-0">Vendor reported failure. No automatic retry.</p>@endif
                </td>
                <td>@if(in_array($scheduled->state, ['waiting', 'claimed'], true) && (int) $scheduled->approver_user_id === (int) auth()->id() && ! $ticketMoved)
                    <form method="POST" action="{{ route('cockpit.schedule.cancel', $scheduled->run_id) }}">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">Cancel schedule</button></form>
                @else<span class="text-muted">Not cancellable by you</span>@endif</td>
            </tr>
        @endforeach</tbody>
    </table></div>
</section>
@endif
