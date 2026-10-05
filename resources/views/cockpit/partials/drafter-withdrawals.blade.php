{{-- Card XUiMXNEH: proposals the drafting token withdrew itself (withdraw_staged_action).
     Informational, like the direct-closes lane: outside the counts-driven filter strip,
     self-clearing after CockpitQuery::DRAFTER_WITHDRAWAL_WINDOW_HOURS. It says "Withdrawn by
     drafter", never Denied: no operator decided anything. --}}
@php($drafterWithdrawals = app(\App\Services\Technician\Cockpit\CockpitQuery::class)->recentDrafterWithdrawals())
@if($drafterWithdrawals->isNotEmpty())
    <section class="cockpit-section mb-4" data-section-key="drafter-withdrawals">
        <div class="cockpit-section-head">
            <h2><i class="bi bi-arrow-return-left me-2"></i>Withdrawn by the drafter</h2>
            <span class="badge rounded-pill text-bg-light border">{{ $drafterWithdrawals->count() }}</span>
        </div>
        <div class="vstack gap-2">
            @foreach ($drafterWithdrawals as $run)
                <article class="card cockpit-item cockpit-row" data-withdrawn-run="{{ $run->id }}">
                    <div class="card-body py-2 small">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                                {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                            </a>
                            @if($run->ticket?->client)
                                <span class="badge rounded-pill bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                            @endif
                            <span class="badge rounded-pill bg-light text-dark border">{{ \App\Support\StagedActionLabels::humanLabel($run->action_type) }}</span>
                            <span class="badge rounded-pill text-bg-secondary"><i class="bi bi-arrow-return-left me-1"></i>Withdrawn</span>
                            <span class="ms-auto text-muted">{{ optional($run->updated_at)->diffForHumans() }}</span>
                        </div>
                        <div class="text-muted">Withdrawn by drafter: {{ $run->proposed_meta['withdrawn_reason'] ?? '' }}</div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
