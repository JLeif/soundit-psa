{{-- Two or more adjacent tool entries, collapsed into one row that expands to each entry. --}}
@php
    $tools = collect($run->toolCounts())->map(fn ($n, $name) => $n > 1 ? $name.' ×'.$n : $name)->values();
@endphp
<details class="py-3 border-bottom" data-timeline-kind="tool-run" data-timeline-run-count="{{ $run->count() }}">
    {{-- summary keeps its default display so the browser draws the disclosure marker. --}}
    <summary class="small">
        <i class="bi bi-gear text-muted" aria-hidden="true"></i>
        <strong>{{ $run->count() }} tool calls</strong>
        <span class="badge bg-light text-dark">Tool</span>
        <time class="text-muted">{{ $run->oldest()->at }} – {{ $run->newest()->at }} UTC</time>
        <span class="d-block text-muted ps-3">{{ $tools->implode(', ') }}</span>
    </summary>
    <div class="ps-4">
        @foreach($run->entries as $entry)
            @include('tickets._timeline-entry', ['entry' => $entry])
        @endforeach
    </div>
</details>
