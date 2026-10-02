@extends('layouts.app')

@section('title', 'API Token · '.$token->label)

@php
    $readOnly = $token->isRevoked() || ! auth()->user()?->isAdmin();
    $grantedList = collect($granted);
    $allEndpoints = collect($groups)->flatMap(fn ($g) => $g['endpoints']);
    $totalCount = $allEndpoints->count();
    $grantedCount = $allEndpoints->keys()->filter(fn ($n) => $grantedList->contains($n))->count();
    $writeGranted = $allEndpoints->filter(fn ($e, $n) => $e['access'] === 'write' && $grantedList->contains($n))->count();
    $inert = $grantedList->reject(fn ($n) => $allEndpoints->has($n))->values();
@endphp

@push('styles')
<style>
    .api-cfg .mono { font-family: var(--bs-font-monospace); }
    .api-secret-input { font-family: var(--bs-font-monospace); background: #fff; }
    .api-summary { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color-translucent); border-radius: .6rem; }
    .api-group { border: 1px solid var(--bs-border-color); border-radius: .6rem; overflow: hidden; }
    .api-group + .api-group { margin-top: .6rem; }
    .api-group-head { display: flex; align-items: center; gap: .85rem; padding: .85rem 1rem; border-bottom: 1px solid var(--bs-border-color); }
    .api-group-icon { width: 38px; height: 38px; border-radius: .55rem; display: grid; place-items: center; color: #fff; flex: none; }
    .api-count { font-size: .82rem; font-weight: 700; white-space: nowrap; }
    .api-count .g { color: #0f9d63; }
    .api-meter { width: 118px; height: 6px; border-radius: 3px; background: var(--bs-secondary-bg); overflow: hidden; margin-top: 4px; }
    .api-meter .fill { display: block; height: 100%; background: var(--accent, #fed136); }
    .api-ep { display: flex; gap: .8rem; align-items: flex-start; padding: .6rem 1rem; border-bottom: 1px solid var(--bs-border-color); }
    .api-ep:last-child { border-bottom: 0; }
    .api-ep.granted { background: rgba(15,157,99,.06); }
    .api-ep.granted.write { background: rgba(217,119,6,.08); }
    .api-ep .ep-name { font-family: var(--bs-font-monospace); font-size: .82rem; font-weight: 600; color: var(--bs-emphasis-color); }
    .api-ep .ep-desc { font-size: .8rem; color: var(--bs-secondary-color); margin-top: 2px; }
    .badge-write { background: #fff8ec; color: #b45309; border: 1px solid #fbdca0; }
    .ep-switch:checked { background-color: #0f9d63; border-color: #0f9d63; }
    .ep-switch.write { border-color: #e2b366; }
    .ep-switch.write:checked { background-color: #d97706; border-color: #d97706; }
</style>
@endpush

@section('content')
<div class="api-cfg" data-token-id="{{ $token->id }}">

<a href="{{ route('settings.api-tokens.index') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
    <i class="bi bi-arrow-left me-1"></i>All tokens
</a>

{{-- Success/error flashes render globally in layouts.app. --}}

@if($newToken)
    <div class="alert alert-success shadow-sm" role="alert" data-testid="api-new-token">
        <div class="fw-semibold mb-1"><i class="bi bi-check-circle me-1"></i>Token secret</div>
        <div class="small text-danger fw-semibold mb-2">Copy the secret now. It is stored only as a hash and will not be shown again; to replace it later, use Regenerate secret.</div>
        <div class="input-group">
            <input type="text" class="form-control api-secret-input" id="apiNewToken" value="{{ $newToken }}" readonly aria-label="New token secret">
            <button type="button" class="btn btn-outline-secondary" id="apiCopySecret"><i class="bi bi-clipboard me-1"></i>Copy</button>
        </div>
    </div>
@endif

@if($token->isDraft())
    <div class="alert alert-warning d-flex align-items-center shadow-sm" role="alert">
        <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
        <div class="flex-grow-1">
            <strong>This token is a draft.</strong> It cannot authenticate, even after endpoints are granted. Name it, grant the endpoints it needs, then activate it.
        </div>
        @unless($readOnly)
            <form method="POST" action="{{ route('settings.api-tokens.activate', $token) }}" class="ms-3 flex-shrink-0">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-play-fill me-1"></i>Activate token</button>
            </form>
        @endunless
    </div>
@endif

{{-- header --}}
<div class="d-flex align-items-start gap-3 mb-2 flex-wrap">
    <div class="rounded d-grid" style="width:46px;height:46px;place-items:center;background:linear-gradient(135deg,#1a365d,#234179);color:#fff;flex:none;">
        <i class="bi bi-braces fs-5"></i>
    </div>
    <div class="flex-grow-1 min-width-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="fs-4 fw-bold">{{ $token->label }}</span>
            @include('settings.api-tokens._state_badge', ['token' => $token])
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap text-muted small mt-2">
            <span class="mono"><i class="bi bi-hash me-1"></i>{{ $token->token_prefix }}</span>
            <span><i class="bi bi-clock me-1"></i>Created {{ $token->created_at?->toAppTz()->format('Y-m-d H:i') }}@if($token->createdBy) by {{ $token->createdBy->email }}@endif</span>
            <span><i class="bi bi-activity me-1"></i>{{ $token->last_used_at ? 'Last used '.$token->last_used_at->diffForHumans().' from '.$token->last_used_ip : 'Never used' }}</span>
        </div>
    </div>
    @unless($readOnly)
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
            @if($token->isActive())
                <form method="POST" action="{{ route('settings.api-tokens.pause', $token) }}">@csrf<button class="btn btn-outline-secondary"><i class="bi bi-pause-fill me-1"></i>Pause</button></form>
            @elseif($token->isPaused())
                <form method="POST" action="{{ route('settings.api-tokens.resume', $token) }}">@csrf<button class="btn btn-outline-success"><i class="bi bi-play-fill me-1"></i>Resume</button></form>
            @endif
            <form method="POST" action="{{ route('settings.api-tokens.regenerate', $token) }}"
                  onsubmit="return confirm(@js('Regenerate secret for \''.$token->label.'\'? The current secret stops working immediately. The new secret is shown once.'))">
                @csrf
                <button class="btn btn-outline-warning"><i class="bi bi-arrow-clockwise me-1"></i>Regenerate secret</button>
            </form>
            <form method="POST" action="{{ route('settings.api-tokens.revoke', $token) }}"
                  onsubmit="return confirm(@js($token->isDraft() ? 'Discard this draft token?' : 'Revoke token \''.$token->label.'\'? It will stop authenticating immediately.'))">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger">
                    <i class="bi bi-{{ $token->isDraft() ? 'trash' : 'x-circle' }} me-1"></i>{{ $token->isDraft() ? 'Discard' : 'Revoke' }}
                </button>
            </form>
        </div>
    @endunless
</div>

<ul class="nav nav-tabs mt-3" role="tablist">
    <li class="nav-item"><button class="nav-link {{ $newToken ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button"><i class="bi bi-gear me-1"></i>Settings</button></li>
    <li class="nav-item"><button class="nav-link {{ $newToken ? '' : 'active' }}" data-bs-toggle="tab" data-bs-target="#tab-endpoints" type="button"><i class="bi bi-diagram-2 me-1"></i>Endpoints <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis" id="epTabCount">{{ $grantedCount }}</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button"><i class="bi bi-clock-history me-1"></i>Activity</button></li>
</ul>

<div class="tab-content pt-4">

    {{-- ===== SETTINGS ===== --}}
    <div class="tab-pane fade {{ $newToken ? 'show active' : '' }}" id="tab-settings" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-static shadow-sm">
                    <div class="card-header"><i class="bi bi-pencil me-2"></i>Token</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('settings.api-tokens.update', $token) }}">
                            @csrf @method('PATCH')
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="apiLabel">Name</label>
                                    <input type="text" class="form-control @error('label') is-invalid @enderror" id="apiLabel" name="label" value="{{ old('label', $token->label) }}" maxlength="100" @disabled($readOnly)>
                                    @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Letters, digits and <code>_ . : -</code>. Shown in Activity and audit.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="apiExpires">Expires <span class="text-muted fw-normal">(optional)</span></label>
                                    <input type="date" class="form-control @error('expires_at') is-invalid @enderror" id="apiExpires" name="expires_at" value="{{ old('expires_at', $token->expires_at?->toAppTz()->format('Y-m-d')) }}" @disabled($readOnly)>
                                    @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Leave blank for a token that never expires. An expired token gets the same 401 as a revoked one.</div>
                                </div>
                            </div>
                            @unless($readOnly)
                                <div class="mt-3 d-flex align-items-center gap-3">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                    <span class="small text-muted">Next: grant endpoints on the <b>Endpoints</b> tab, then activate.</span>
                                </div>
                            @endunless
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-static shadow-sm">
                    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Use it from a consumer</div>
                    <div class="card-body small">
                        <dl class="row mb-0">
                            <dt class="col-5">Base URL</dt><dd class="col-7 mono">{{ url('/') }}</dd>
                            <dt class="col-5">Header</dt><dd class="col-7 mono">Authorization: Bearer &lt;secret&gt;</dd>
                            <dt class="col-5">Versioned path</dt><dd class="col-7 mono">/api/v1/…</dd>
                            <dt class="col-5">Compatibility</dt><dd class="col-7 mono">/api/rmm/… <span class="badge rounded-pill bg-info-subtle text-info-emphasis border">alias</span></dd>
                            <dt class="col-5">Rate limit</dt><dd class="col-7">{{ \App\Http\Middleware\VerifyApiToken::TOKEN_LIMIT_PER_MINUTE }} requests / minute for this token</dd>
                        </dl>
                        <div class="form-text mt-2">For LITS RMM, the secret goes into <code>PSA_API_KEY</code> on the RMM server. Paste the secret only into the consumer, never into a ticket or a chat.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== ENDPOINTS ===== --}}
    <div class="tab-pane fade {{ $newToken ? '' : 'show active' }}" id="tab-endpoints" role="tabpanel">
        <div class="api-summary d-flex align-items-center gap-3 flex-wrap p-3 mb-3">
            <div class="fw-semibold"><span id="sumGranted">{{ $grantedCount }}</span> of {{ $totalCount }} endpoints granted</div>
            <div class="small"><span class="badge badge-write rounded-pill" id="sumWrite">{{ $writeGranted }}</span> write enabled</div>
            <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Changes save as you go. Ungranted endpoints are refused with 403.</div>
        </div>

        @if($inert->isNotEmpty())
            <div class="alert alert-warning small" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i>Granted but no longer in the registry (inert; dropped on the next save):
                @foreach($inert as $name)<code class="ms-1">{{ $name }}</code>@endforeach
            </div>
        @endif

        @foreach($groups as $key => $group)
            @php
                $groupTotal = count($group['endpoints']);
                $groupGranted = collect($group['endpoints'])->keys()->filter(fn ($n) => $grantedList->contains($n))->count();
                $hasWrite = collect($group['endpoints'])->contains(fn ($e) => $e['access'] === 'write');
            @endphp
            <div class="api-group" data-group="{{ $key }}">
                <div class="api-group-head">
                    <span class="api-group-icon" style="background: {{ $group['accent'] }};"><i class="bi {{ $group['icon'] }}"></i></span>
                    <span class="flex-grow-1 min-width-0">
                        <span class="fw-semibold d-flex align-items-center gap-2">{{ $group['label'] }}
                            @if($hasWrite)<span class="badge badge-write rounded-pill" style="font-size:.62rem;">has write</span>@endif
                        </span>
                        <span class="d-block text-muted small">{{ $group['blurb'] }}</span>
                    </span>
                    <span class="text-end">
                        <span class="api-count"><span class="g group-granted">{{ $groupGranted }}</span> / {{ $groupTotal }}</span>
                        <span class="api-meter"><span class="fill" style="width: {{ $groupTotal ? round($groupGranted / $groupTotal * 100) : 0 }}%;"></span></span>
                    </span>
                </div>
                @foreach($group['endpoints'] as $name => $ep)
                    @php $isGranted = $grantedList->contains($name); $isWrite = $ep['access'] === 'write'; @endphp
                    <div class="api-ep {{ $isGranted ? 'granted' : '' }} {{ $isWrite ? 'write' : '' }}" data-endpoint="{{ $name }}">
                        <div class="form-check form-switch m-0 pt-1">
                            <input type="checkbox" class="form-check-input ep-switch {{ $isWrite ? 'write' : '' }}" data-endpoint="{{ $name }}"
                                   @checked($isGranted) @disabled($readOnly) aria-label="Grant {{ $name }}">
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <div class="ep-name d-flex align-items-center gap-2">{{ $name }}
                                @if($isWrite)<span class="badge badge-write rounded-pill" style="font-size:.6rem;">Write</span>@else<span class="badge rounded-pill bg-info-subtle text-info-emphasis border" style="font-size:.6rem;">Read</span>@endif
                            </div>
                            <div class="ep-desc">{{ $ep['description'] }}</div>
                            <div class="small mt-1">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border mono">{{ $ep['method'] }}</span>
                                <span class="mono">/api/{{ $ep['path'] }}</span>
                                @foreach($ep['aliases'] as $alias)
                                    <span class="text-muted">· alias</span> <span class="mono text-muted">/api/{{ $alias }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="text-muted small mt-3"><i class="bi bi-info-circle me-1"></i>This list is rendered from the endpoint registry. A new endpoint appears here ungranted on every token.</div>
    </div>

    {{-- ===== ACTIVITY ===== --}}
    <div class="tab-pane fade" id="tab-activity" role="tabpanel">
        <div class="card card-static shadow-sm">
            <div class="card-header d-flex align-items-center"><i class="bi bi-clock-history me-2"></i>Activity<span class="small text-muted ms-auto">Last 50 events</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-brand"><tr><th>Time</th><th>Kind</th><th>Endpoint / action</th><th>Status</th><th>Cause</th><th>From</th></tr></thead>
                    <tbody>
                        @forelse($activity as $row)
                            <tr>
                                <td class="small text-muted">{{ $row->created_at?->toAppTz()->format('Y-m-d H:i:s') }}</td>
                                <td class="small">{{ $row->kind }}</td>
                                <td><code class="small">{{ $row->endpoint ?? $row->path }}</code></td>
                                <td class="small">{{ $row->status ?? '—' }}</td>
                                <td class="small">{{ $row->cause ?? '—' }}</td>
                                <td class="small mono text-muted">{{ $row->actor ?? $row->source_ip }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted small">No activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const root = document.querySelector('.api-cfg');
    if (!root) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const url = @json(route('settings.api-tokens.endpoints', $token));

    document.getElementById('apiCopySecret')?.addEventListener('click', function () {
        const input = document.getElementById('apiNewToken');
        if (input) { input.select(); navigator.clipboard?.writeText(input.value); }
    });

    const switches = Array.from(root.querySelectorAll('.ep-switch'));
    function save() {
        const endpoints = switches.filter(s => s.checked).map(s => s.dataset.endpoint);
        return fetch(url, {
            method: 'PATCH',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({endpoints}),
        }).then(r => r.json()).then(body => {
            if (!body.ok && body.message) { alert(body.message); }
        });
    }
    function refresh() {
        let total = 0, write = 0;
        switches.forEach(s => {
            const row = s.closest('.api-ep');
            row.classList.toggle('granted', s.checked);
            if (s.checked) { total++; if (s.classList.contains('write')) write++; }
        });
        root.querySelectorAll('.api-group').forEach(g => {
            const sw = g.querySelectorAll('.ep-switch');
            const on = Array.from(sw).filter(s => s.checked).length;
            g.querySelector('.group-granted').textContent = on;
            g.querySelector('.api-meter .fill').style.width = (sw.length ? Math.round(on / sw.length * 100) : 0) + '%';
        });
        document.getElementById('sumGranted').textContent = total;
        document.getElementById('sumWrite').textContent = write;
        document.getElementById('epTabCount').textContent = total;
    }
    switches.forEach(s => s.addEventListener('change', () => { refresh(); save(); }));
})();
</script>
@endpush
