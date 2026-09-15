<div class="d-flex gap-3 py-3 border-bottom" data-timeline-kind="{{ $entry->kind }}" data-timeline-id="{{ $entry->id }}">
    <div class="text-muted"><i class="bi {{ $entry->kind === 'tool' ? 'bi-gear' : 'bi-envelope' }}"></i></div>
    <div class="flex-grow-1">
        <div class="d-flex gap-2 small align-items-center">
            <strong>{{ $entry->actor }}</strong>
            <span class="badge bg-light text-dark">{{ $entry->kind === 'tool' ? 'Tool' : ucfirst($entry->kind) }}
                @if(isset($entry->direction)) · {{ $entry->direction }} @endif
            </span>
            @if(isset($entry->state)) <span class="badge bg-light text-dark">{{ $entry->state }}</span> @endif
            <time class="text-muted ms-auto">{{ $entry->at }} UTC</time>
        </div>
        @if(isset($entry->tool)) <div class="small text-muted">{{ $entry->tool }}</div> @endif
        <div class="small mt-1" style="white-space: pre-wrap">{{ $entry->summary }}</div>
    </div>
</div>
