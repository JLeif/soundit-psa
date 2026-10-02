@extends('layouts.app')

@section('title', 'API Tokens')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h4 class="section-title mb-1">API Tokens</h4>
        <div class="text-muted small">
            Bearer tokens for the PSA REST API. Each token is granted specific endpoints; everything else is refused.
            <code class="ms-1">{{ url('/api/v1') }}</code>
        </div>
    </div>
    @if(auth()->user()?->isAdmin())
        <form method="POST" action="{{ route('settings.api-tokens.store') }}" class="flex-shrink-0">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Create token
            </button>
        </form>
    @endif
</div>

<div class="card card-static shadow-sm">
    @if($tokens->isEmpty())
        <div class="card-body text-center py-5">
            <div class="mb-3"><i class="bi bi-braces fs-1 text-muted"></i></div>
            <h5 class="mb-2">No tokens yet</h5>
            <p class="text-muted mb-0 mx-auto" style="max-width: 42ch;">
                Create a token to give a service access to specific API endpoints. It starts inactive with nothing granted.
            </p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Token</th>
                        <th>Status</th>
                        <th>Endpoints granted</th>
                        <th>Expires</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tokens as $token)
                        @php $granted = $token->grantedEndpoints(); @endphp
                        <tr class="{{ $token->isRevoked() ? 'opacity-50' : '' }}">
                            <td>
                                <a href="{{ route('settings.api-tokens.show', $token) }}" class="text-decoration-none fw-semibold">{{ $token->label }}</a>
                                <div class="font-monospace text-muted small">{{ $token->token_prefix }}</div>
                            </td>
                            <td>@include('settings.api-tokens._state_badge', ['token' => $token])</td>
                            <td class="small">
                                @if(count($granted) === 0)
                                    <span class="text-muted">No endpoints granted</span>
                                @else
                                    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border me-1">{{ count($granted) }}</span>
                                    @foreach(array_slice($granted, 0, 2) as $name)
                                        <code class="small me-1">{{ $name }}</code>
                                    @endforeach
                                    @if(count($granted) > 2)
                                        <span class="text-muted">+{{ count($granted) - 2 }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="small {{ $token->isExpired() ? 'text-danger' : 'text-muted' }}">
                                {{ $token->expires_at ? $token->expires_at->toAppTz()->format('Y-m-d') : 'Never' }}
                            </td>
                            <td class="small text-muted">
                                {{ $token->created_at?->toAppTz()->format('Y-m-d H:i') }}
                                @if($token->createdBy)<br>by {{ $token->createdBy->email }}@endif
                            </td>
                            <td class="small">
                                @if($token->last_used_at)
                                    {{ $token->last_used_at->diffForHumans() }}
                                    <div class="font-monospace text-muted">{{ $token->last_used_ip }}</div>
                                @else
                                    <span class="text-muted">never</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('settings.api-tokens.show', $token) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-gear me-1"></i>Open
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    New tokens start as inactive drafts with no endpoints granted, so a token is never live with the wrong access. Unknown, draft, paused, revoked and expired tokens all get the same 401. 401s for a draft, paused, revoked or expired token, and 403s, appear in that token's Activity. Requests refused by the per-IP limit (429) are not attributed to any token.
</div>
@endsection
