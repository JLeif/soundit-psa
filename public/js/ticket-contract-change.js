// Ticket contract-change modal (card I3EvQKUV PR 2, SPEC §4, mockup 3).
// Choosing a contract on a ticket with time entries opens the modal; with none it submits as before.
// Earlier time never moves unless a box is ticked, and a tick needs a reason.
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('ticketContractSelect');
    const modalEl = document.getElementById('contractChangeModal');
    if (!select || !modalEl) {
        return;
    }
    const form = document.getElementById('contractChangeForm');
    const toInput = document.getElementById('contractChangeTo');
    const reason = document.getElementById('contractChangeReason');
    const moveBtn = document.getElementById('contractChangeAndMove');
    const onlyBtn = document.getElementById('contractChangeOnly');
    const count = document.getElementById('contractChangeCount');
    const totals = document.getElementById('contractChangeTotals');
    const prepay = JSON.parse(modalEl.dataset.prepay || '{}');
    const rows = Array.from(modalEl.querySelectorAll('.js-cc-row'));
    const fmt = (h) => (Math.round(h * 100) / 100).toFixed(2);
    // Jeeves 2026-10-02 21:38 PT: an entry with no ledger row moving onto an hours-prepay
    // contract is drawn right after the move; the row must say so before it is moved.
    const INVOICED_ADVICE = 'If this time was already invoiced by hand, untick billable instead of moving.';
    let toId = '';

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function refresh() {
        const ticked = rows.filter((r) => r.querySelector('.js-cc-move').checked);
        count.textContent = String(ticked.length);
        moveBtn.disabled = ticked.length === 0;
        reason.required = ticked.length > 0;
        const deltas = {};
        rows.forEach((r) => {
            const effect = r.querySelector('.js-cc-effect');
            const from = r.dataset.contract;
            const hours = parseFloat(r.dataset.hours || '0');
            const ledgered = r.dataset.ledger === '1';
            const draw = parseFloat(r.dataset.drawHours || '0');
            if (r.dataset.locked === '1') {
                return;
            }
            const box = r.querySelector('.js-cc-move');
            effect.textContent = '';
            if (!box.checked) {
                effect.textContent = 'stays';
                return;
            }
            if (!ledgered) {
                if (prepay[toId] && draw > 0) {
                    effect.append(el('span', 'text-danger fw-semibold', 'will draw ' + fmt(draw) + 'h from ' + prepay[toId].name),
                        el('br'), el('span', 'text-muted', INVOICED_ADVICE));
                    deltas[toId] = (deltas[toId] || 0) - draw;
                } else {
                    effect.textContent = 'moves (no prepay)';
                }
                return;
            }
            if (hours > 0 && prepay[from]) {
                effect.append(el('span', 'text-success fw-semibold', '+' + fmt(hours) + ' h'), ' back to ' + prepay[from].name);
                deltas[from] = (deltas[from] || 0) + hours;
            }
            if (prepay[toId] && (hours > 0 || !prepay[from])) {
                if (effect.childNodes.length) effect.append(' · ');
                effect.append(el('span', 'text-danger fw-semibold', '−' + fmt(hours) + ' h'), ' from ' + prepay[toId].name);
                deltas[toId] = (deltas[toId] || 0) - hours;
            }
            if (!effect.childNodes.length) {
                effect.textContent = 'moves (no prepay)';
            }
        });
        totals.textContent = '';
        Object.keys(deltas).forEach((id) => {
            const c = prepay[id];
            const after = c.balance + deltas[id];
            const col = el('div', 'col-md-6');
            const box = el('div', 'border rounded p-2 small');
            box.append(el('strong', '', c.name), ' (prepay)', el('br'),
                'Balance ' + fmt(c.balance) + ' h → ',
                el('strong', deltas[id] >= 0 ? 'text-success' : 'text-danger', fmt(after) + ' h'));
            col.append(box);
            totals.append(col);
        });
    }

    select.addEventListener('change', function () {
        toId = select.value;
        if (rows.length === 0) {
            select.form.submit();
            return;
        }
        toInput.value = toId;
        const name = toId ? select.options[select.selectedIndex].text.trim() : 'None';
        document.getElementById('contractChangeToName').textContent = name;
        modalEl.querySelectorAll('.js-cc-to-name').forEach((n) => { n.textContent = name; });
        rows.forEach((r) => {
            const box = r.querySelector('.js-cc-move');
            const effect = r.querySelector('.js-cc-effect');
            const already = toId !== '' && r.dataset.contract === toId;
            const locked = effect.dataset.lockedText;
            box.checked = false;
            box.disabled = !toId || already || !!locked;
            r.dataset.locked = (already || locked) ? '1' : '0';
            r.classList.toggle('text-muted', already || !!locked);
            effect.textContent = locked || (already ? 'Already on the new contract' : (toId ? 'stays' : 'stays (no contract chosen)'));
        });
        refresh();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    modalEl.addEventListener('change', (e) => {
        if (e.target.classList.contains('js-cc-move')) refresh();
    });
    modalEl.addEventListener('hidden.bs.modal', () => {
        if (!form.dataset.submitting) select.value = select.dataset.current || '';
    });
    onlyBtn.addEventListener('click', () => {
        rows.forEach((r) => { r.querySelector('.js-cc-move').checked = false; });
        reason.required = false;
    });
    // Enter in a field must never submit through "Change contract only" (the form's first
    // submit button, whose click unticks every entry): from the reason it submits the move.
    form.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
        e.preventDefault();
        if (e.target === reason && !moveBtn.disabled) form.requestSubmit(moveBtn);
    });
    form.addEventListener('submit', () => { form.dataset.submitting = '1'; });
});
