@props([
    'nodes',            // Collection<array{id:int, path:string}> of ACTIVE taxonomy nodes
    'current' => null,  // ?TicketCategory — the ticket's current node (edit form); null on create
    'selectedId' => null, // id to pre-select among the active nodes (current id, or old() on create)
    'id' => 'categoryNode', // select/label id — the edit form passes 'editCategoryNode'
])
@php
    // A soft-retired current node is absent from the ACTIVE-only $nodes list.
    // Re-surface it here, pre-selected + flagged, so an unrelated save re-posts
    // this exact id (a no-op) instead of the blank option — which would silently
    // null a retired node. TicketUpdateRequest grandfathers this exact id.
    // (Create has no current node, so this branch never fires there.)
    $currentIsRetired = $current && ! $current->is_active;
    $selectedId = filled($selectedId) ? (int) $selectedId : null;
@endphp
{{-- ITIL taxonomy category (so-0ftg): the node that carries the SOP.
     Distinct from the legacy free-text category/subcategory. --}}
<div class="mb-2">
    <label for="{{ $id }}" class="form-label small text-muted mb-1">
        SOP Category <span class="fw-normal">(taxonomy)</span>
    </label>
    <select name="category_id" class="form-select form-select-sm @error('category_id') is-invalid @enderror" id="{{ $id }}">
        <option value="">-- Uncategorized --</option>
        @if($currentIsRetired)
            <option value="{{ $current->id }}" selected>{{ $current->pathString() }} (retired)</option>
        @endif
        @foreach($nodes as $node)
            <option value="{{ $node['id'] }}" @selected($selectedId === (int) $node['id'])>{{ $node['path'] }}</option>
        @endforeach
    </select>
    {{-- Field-level validation feedback, like the form's other required fields
         (UX REVISE, PR #314) — explains the fix where the decision is made. --}}
    @error('category_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    @if($current)
        <div class="small mt-1">
            @if($current->hasSop())
                <span class="text-success"><i class="bi bi-file-earmark-text me-1"></i>SOP available</span>
            @else
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No SOP yet</span>
            @endif
            <a href="{{ route('ticket-categories.show', $current) }}" class="ms-1">manage</a>
        </div>
    @endif
</div>
