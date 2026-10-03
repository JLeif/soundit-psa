{{--
    Ticket contract change (card I3EvQKUV PR 2, SPEC §4, mockup 3). Earlier time stays on the
    contract it was logged against; a ticked entry is moved through PrepayService::moveEntryContract
    (a visible credit on its contract and a debit on the new one). Expects $ticket and $contractEntries.
--}}
@php
    $ccContracts = ($ticket->client?->contracts ?? collect())->keyBy('id');
    $ccPrepay = $ccContracts->filter(fn ($c) => $c->has_prepay && ! $c->prepay_as_amount)
        ->map(fn ($c) => ['name' => $c->name, 'balance' => (float) $c->prepay_balance]);
@endphp
<div class="modal fade" id="contractChangeModal" tabindex="-1" aria-labelledby="contractChangeTitle"
     data-entries="{{ $contractEntries->count() }}" data-prepay='@json($ccPrepay)'>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="{{ route('tickets.contract.update', $ticket) }}" class="modal-content" id="contractChangeForm">
            @csrf
            @method('PATCH')
            <input type="hidden" name="contract_id" id="contractChangeTo" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="contractChangeTitle">
                    Change contract: <span id="contractChangeFromName">{{ $ticket->contract?->name ?? 'None' }}</span>
                    → <span id="contractChangeToName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    Time already logged <strong>stays on the contract it was logged against</strong>. New time on this ticket will use
                    <strong class="js-cc-to-name"></strong>. Tick any entries that belong on the new contract: each one is credited back to
                    its contract and debited from the new one, and both contracts' history records the move.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3">
                        <thead class="thead-brand">
                            <tr><th></th><th>Entry</th><th>Date</th><th>By</th><th class="text-end">Time</th><th>Logged against</th><th>If moved</th></tr>
                        </thead>
                        <tbody>
                            @foreach($contractEntries as $entry)
                                @php($key = $entry['type'].':'.$entry['id'])
                                <tr class="js-cc-row {{ $entry['locked'] ? 'text-muted' : '' }}" data-contract="{{ $entry['contract_id'] }}"
                                    data-hours="{{ $entry['ledger_hours'] }}" data-locked="{{ $entry['locked'] ? '1' : '0' }}">
                                    <td>
                                        <input type="checkbox" class="form-check-input js-cc-move" name="move[]" value="{{ $key }}"
                                               id="ccMove{{ str_replace(':', '', $key) }}" @disabled($entry['locked'])
                                               aria-label="Move {{ $entry['label'] }}">
                                    </td>
                                    <td><label for="ccMove{{ str_replace(':', '', $key) }}" class="mb-0">{{ $entry['label'] }}</label></td>
                                    <td class="text-nowrap">{{ $entry['date']?->copy()->setTimezone(\App\Support\AppTimezone::get())->format('M j') }}</td>
                                    <td>{{ $entry['by'] ?? '—' }}</td>
                                    <td class="text-end text-nowrap">{{ number_format($entry['hours'], 2) }} h</td>
                                    <td>
                                        @if($entry['contract_name'])
                                            <span class="badge {{ $entry['contract_prepay'] ? 'bg-info text-dark' : 'bg-light text-dark border' }}">{{ $entry['contract_name'] }}</span>
                                        @else
                                            <span class="text-muted small">No contract</span>
                                        @endif
                                    </td>
                                    <td class="small js-cc-effect" data-locked-text="{{ $entry['locked'] }}">{{ $entry['locked'] ?? 'stays' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 mb-3" id="contractChangeTotals"></div>
                <div class="mb-1">
                    <label for="contractChangeReason" class="form-label">Reason for moving <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" name="move_reason" id="contractChangeReason" maxlength="800"
                           placeholder="Required when at least one entry is ticked">
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto small text-muted">Leaving every box unticked changes only the ticket's contract.</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="contractChangeOnly" name="change_only" value="1">Change contract only</button>
                <button type="submit" class="btn btn-accent" id="contractChangeAndMove" disabled>Change and move <span id="contractChangeCount">0</span> entries</button>
            </div>
        </form>
    </div>
</div>
