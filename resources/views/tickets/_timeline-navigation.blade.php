<nav class="d-flex gap-2 flex-wrap my-3" aria-label="Timeline filters">
    <a class="badge bg-light text-dark" href="{{ route('tickets.show', $ticket) }}#notes">All</a>
    @foreach(App\Services\Mcp\TicketTimeline::TYPES as $kind)
        <a class="badge {{ request()->query('types') === [$kind] ? 'bg-primary' : 'bg-light text-dark' }}"
           href="{{ route('tickets.show', ['ticket' => $ticket, 'types' => [$kind]]) }}#notes">{{ str_replace('_', ' ', ucfirst($kind)) }}</a>
    @endforeach
</nav>
<div class="small text-muted mb-2" data-timeline-states>{{ $timelinePage['states'] }}</div>
<div class="small text-muted mb-2" data-timeline-count="{{ $timelinePage['shown'] }}">
    @if($timelinePage['truncated'])
        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Showing the newest {{ $timelinePage['shown'] }} entries; older entries are not shown on this page.
    @else
        {{ $timelinePage['shown'] }} {{ Str::plural('entry', $timelinePage['shown']) }}, full history.
    @endif
</div>
