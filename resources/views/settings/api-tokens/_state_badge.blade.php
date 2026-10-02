@php
    $stateMap = [
        'draft' => ['Draft · inactive', 'secondary', 'dash-circle'],
        'active' => ['Active', 'success', 'check-circle-fill'],
        'paused' => ['Paused', 'warning', 'pause-circle'],
        'revoked' => ['Revoked', 'danger', 'x-circle'],
    ];
    [$stateLabel, $stateVariant, $stateIcon] = $stateMap[$token->state()] ?? $stateMap['draft'];
@endphp
<span class="badge rounded-pill bg-{{ $stateVariant }}-subtle text-{{ $stateVariant }}-emphasis border border-{{ $stateVariant }}-subtle">
    <i class="bi bi-{{ $stateIcon }} me-1"></i>{{ $stateLabel }}
</span>
@if(! $token->isRevoked() && $token->isExpired())
    <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">
        <i class="bi bi-exclamation-circle me-1"></i>Expired
    </span>
@endif
