<div class="col-md-3" id="contractGroup" hidden>
    <label for="contract_id" class="form-label">Contract</label>
    <select name="contract_id" id="contract_id"
            class="form-select @error('contract_id') is-invalid @enderror"
            data-old="{{ is_scalar(old('contract_id')) ? old('contract_id') : '' }}"
            aria-describedby="contractHint" disabled>
        <option value="">Choose a client first</option>
    </select>
    <div class="form-text" id="contractHint">Select a contract for this ticket.</div>
</div>
@error('contract_id')
    <div class="invalid-feedback d-block">{{ $message }}</div>
@enderror

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const clientSelect = document.getElementById('client_id');
    const contractSelect = document.getElementById('contract_id');
    const contractGroup = document.getElementById('contractGroup');
    const contractHint = document.getElementById('contractHint');
    let generation = 0;

    function contractOption(value, text) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        return opt;
    }

    function contractLabel(c) {
        let label = c.name + (c.is_default ? ' · default' : '');
        if (c.prepay_balance !== null && c.prepay_balance !== undefined) {
            label += ' · ' + Number(c.prepay_balance).toFixed(2) + (c.prepay_unit === 'dollars' ? ' $ prepay' : ' h prepay');
        } else if (c.type_label) {
            label += ' · ' + c.type_label;
        }
        return label;
    }

    function loadContracts(preferId) {
        const clientId = clientSelect.value;
        const request = ++generation;
        contractGroup.hidden = true;
        contractSelect.disabled = true;
        contractSelect.innerHTML = '';
        if (!clientId) {
            return;
        }
        fetch('/api/clients/' + clientId + '/active-contracts', {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => { if (!r.ok) { throw new Error('contracts'); } return r.json(); })
        .then(contracts => {
            if (request !== generation || clientSelect.value !== String(clientId)) {
                return;
            }
            const fallback = contracts.find(c => c.is_default) || (contracts.length === 1 ? contracts[0] : null);
            if (contracts.length < 2 || fallback) {
                return;
            }
            contractSelect.appendChild(contractOption('', '-- None --'));
            contracts.forEach(c => contractSelect.appendChild(contractOption(String(c.id), contractLabel(c))));
            const preferred = preferId && contracts.some(c => String(c.id) === String(preferId)) ? String(preferId) : null;
            contractSelect.value = preferred || '';
            contractHint.textContent = 'Select a contract for this ticket.';
            if (preferId && !preferred) {
                contractHint.textContent = 'The contract you submitted is not an active contract of this client; pick one. ' + contractHint.textContent;
            }
            contractHint.classList.add('text-warning-emphasis');
            contractSelect.disabled = false;
            contractGroup.hidden = false;
        })
        .catch(function() {
            if (request !== generation || clientSelect.value !== String(clientId)) {
                return;
            }
            contractGroup.hidden = true;
            contractSelect.disabled = true;
            contractSelect.innerHTML = '';
        });
    }

    clientSelect.addEventListener('change', function() { loadContracts(null); });
    loadContracts(contractSelect.dataset.old);
});
</script>
@endpush
