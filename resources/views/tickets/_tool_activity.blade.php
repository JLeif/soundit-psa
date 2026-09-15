<section class="card shadow-sm mt-4" id="tool-activity">
    <div class="card-header">Tool activity <span class="text-muted small">Staff only</span></div>
    <div class="card-body">
        <p class="small text-muted">{{ $toolActivity['coverage'] }}</p>
        @forelse($toolActivity['items'] as $activity)
            <div class="border-bottom py-2">
                <strong>{{ $activity['tool'] }}</strong>
                <span class="badge bg-secondary">{{ $activity['state'] }}</span>
                <div class="small text-muted">{{ $activity['actor'] }} · {{ $activity['time'] }} UTC · Ticket {{ $activity['ticket_id'] }}</div>
                <div>{{ $activity['summary'] }}</div>
            </div>
        @empty
            <p>No associated tool activity recorded.</p>
        @endforelse
        <p class="small text-muted mt-2">{{ $toolActivity['pagination'] }}</p>
        @if($toolActivity['next_offset'] !== null)
            <a href="{{ request()->fullUrlWithQuery(['tool_offset' => $toolActivity['next_offset']]) }}#tool-activity">Older tool activity</a>
        @elseif($toolActivity['has_more'])
            <p>History truncated at the pagination boundary.</p>
        @endif
        @if($toolActivity['offset'] > 0)
            <a href="{{ request()->fullUrlWithQuery(['tool_offset' => 0]) }}#tool-activity">Newest tool activity</a>
        @endif
    </div>
</section>
