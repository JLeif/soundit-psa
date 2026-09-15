<nav class="d-flex gap-2 flex-wrap my-3" aria-label="Timeline filters">
    <a class="badge bg-light text-dark" href="{{ route('tickets.show', $ticket) }}#notes">All</a>
    @foreach(App\Services\Mcp\TicketTimeline::TYPES as $kind)
        <a class="badge {{ request()->query('types') === [$kind] ? 'bg-primary' : 'bg-light text-dark' }}"
           href="{{ route('tickets.show', ['ticket' => $ticket, 'types' => [$kind]]) }}#notes">{{ str_replace('_', ' ', ucfirst($kind)) }}</a>
    @endforeach
</nav>
<div class="small text-muted mb-2" data-timeline-states>{{ $timelinePage['states'] }}</div>
<nav class="d-flex gap-3 small mb-2" aria-label="Timeline pages">
    @if($timelinePage['after'])
        <a href="{{ route('tickets.show', array_filter(['ticket' => $ticket, 'types' => request()->query('types'), 'after' => $timelinePage['after']])) }}#notes">Newer</a>
    @endif
    @if($timelinePage['before'])
        <a href="{{ route('tickets.show', array_filter(['ticket' => $ticket, 'types' => request()->query('types'), 'before' => $timelinePage['before']])) }}#notes">Older</a>
    @endif
    <span>{{ $timelinePage['has_more'] ? 'More entries available in this direction' : 'End of this direction' }}</span>
</nav>
