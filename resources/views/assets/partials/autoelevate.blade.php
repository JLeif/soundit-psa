{{-- AutoElevate (stage 3a, read-only). Written by AutoElevateAssetSyncService; no vendor call is
     made from this page. Exactly one explicit state (C-56): an unlinked asset says WHY.
     Times are rendered in the app display timezone (C-14 toAppTz; Pacific in production)
     with the zone abbreviation shown. --}}
@php
    $ae = \App\Services\AutoElevate\AutoElevateAssetSyncService::assetLinkState($asset);
    $aeMode = $asset->autoelevate_elevation_mode;
@endphp
<div class="card shadow-sm mb-3" id="autoelevate-asset-card" data-state="{{ $ae['state'] }}" @if($ae['reason']) data-reason="{{ $ae['reason'] }}" @endif>
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shield-lock me-2"></i>Privilege Elevation (AutoElevate)</span>
        @if($ae['state'] === 'linked')
            <span class="badge bg-success">Linked</span>
        @else
            <span class="badge bg-secondary">Not linked</span>
        @endif
    </div>
    <div class="card-body">
    @if($ae['state'] === 'linked')
        @if($aeMode === 'audit')
            <div class="alert alert-warning py-1 px-2 small mb-2" role="status" data-audit-callout>
                <i class="bi bi-eye me-1"></i><strong>Audit mode:</strong> elevation requests are only logged on this machine, not enforced.
            </div>
        @endif
        <table class="table table-borderless mb-0">
            <tbody>
                <tr>
                    <th class="text-muted" style="width: 140px;">Elevation mode</th>
                    <td>
                        @if($aeMode === null)
                            <span class="badge bg-secondary">No mode reported</span>
                        @elseif(! in_array($aeMode, \App\Services\AutoElevate\AutoElevateReadService::ELEVATION_MODES, true))
                            <span class="badge bg-warning text-dark">Unrecognised: {{ $aeMode }}</span>
                        @elseif($aeMode === 'audit')
                            <span class="badge bg-warning text-dark">AUDIT</span>
                        @elseif($aeMode === 'live')
                            <span class="badge bg-success">live</span>
                        @else
                            <span class="badge bg-primary">{{ $aeMode }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">Agent version</th>
                    <td>
                        @if($asset->autoelevate_agent_version)
                            {{ $asset->autoelevate_agent_version }}
                        @else
                            <span class="text-muted">Not reported by AutoElevate</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">Last check-in</th>
                    <td>
                        @if($asset->autoelevate_last_checked_in_at)
                            {{ $asset->autoelevate_last_checked_in_at->toAppTz()->format('M j, Y g:i A T') }}
                        @else
                            <span class="text-muted">Never checked in</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">Synced</th>
                    <td>
                        @if($asset->autoelevate_synced_at)
                            {{ $asset->autoelevate_synced_at->toAppTz()->format('M j, Y g:i A T') }}
                        @else
                            <span class="text-muted">Never</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    @elseif($ae['state'] === 'not_mapped')
        <p class="text-muted mb-0" role="status">
            <i class="bi bi-link-45deg me-1"></i><strong>Client not mapped.</strong>
            This asset's client is not linked to an AutoElevate company, so no AutoElevate data was read for it.
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('settings.autoelevate-companies.index') }}" class="ms-1">Map companies</a>
            @endif
        </p>
    @elseif($ae['state'] === 'read_failed')
        <div class="alert alert-danger small mb-0" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i><strong>AutoElevate read failed</strong> ({{ $ae['reason'] }}) on the last sync for this client.
            This is not evidence that the machine is absent from AutoElevate.
        </div>
    @elseif($ae['state'] === 'no_match')
        <p class="text-muted mb-0" role="status">
            <i class="bi bi-info-circle me-1"></i><strong>No AutoElevate match.</strong>
            The last sync read this client's AutoElevate computers and none matched this asset's hostname
            @if($asset->hostname)(<span class="font-monospace">{{ $asset->hostname }}</span>)@endif
            uniquely.
        </p>
    @else
        <p class="text-muted mb-0" role="status">
            <i class="bi bi-hourglass me-1"></i><strong>Not synced yet.</strong>
            This asset's client is mapped to AutoElevate, but no asset sync has been recorded for this asset under its current hostname and company mapping.
        </p>
    @endif
    </div>
</div>
