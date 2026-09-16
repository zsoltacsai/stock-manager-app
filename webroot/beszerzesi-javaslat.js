// 1.3.0 — a korábbi, beszállító szerint csoportosított nézetet felváltja
// egy lapos, sürgősség szerint szűrhető/rendezett lista, kijelöléssel és
// "beszerzés indítása a kijelöltekkel" funkcióval (lásd a kör 2. pontja).
// A meglévő beszerzes.php sessionStorage-alapú prefill-mechanizmusát
// használja újra (lásd sm_purchase_prefill), nincs új beszerzési logika.

const URGENCY_LABELS = { urgent: 'Sürgős', soon: 'Hamarosan elfogy', low: 'Alacsony készlet' };
const URGENCY_BADGE_CLASS = { urgent: 'negative', soon: 'warn', low: 'ok' };

let currentUrgency = new URLSearchParams(location.search).get('urgency') || '';
let currentRecommendations = [];
const selected = new Map(); // product_id -> {product_id, qty, supplier_id}

function fmtDays(days) {
    if (days === null || days === undefined) return '—';
    return days === 0 ? 'ma' : `${days} nap`;
}

async function loadSuggestions() {
    const body = document.getElementById('bj-body');
    body.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center; padding:16px;">Betöltés...</td></tr>';
    try {
        const q = currentUrgency ? `?urgency=${encodeURIComponent(currentUrgency)}` : '';
        const data = await fetchJson(`/api/purchase-suggestions.php${q}`);
        currentRecommendations = data.recommendations || [];
        renderCounts(data.counts || {});
        renderTable(currentRecommendations);
    } catch (err) {
        body.innerHTML = `<tr><td colspan="7" class="feedback error">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderCounts(counts) {
    document.getElementById('bj-count-all').textContent = counts.all ?? 0;
    document.getElementById('bj-count-urgent').textContent = counts.urgent ?? 0;
    document.getElementById('bj-count-soon').textContent = counts.soon ?? 0;
    document.getElementById('bj-count-low').textContent = counts.low ?? 0;
}

function renderTable(rows) {
    const body = document.getElementById('bj-body');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center; padding:16px;">Jelenleg nincs ilyen javaslat.</td></tr>';
        return;
    }
    body.innerHTML = rows.map(r => `
        <tr>
            <td><input type="checkbox" class="bj-row-check" data-id="${r.id}" ${selected.has(r.id) ? 'checked' : ''}></td>
            <td>
                <span class="stock-badge ${URGENCY_BADGE_CLASS[r.urgency] || 'ok'}" style="margin-right:8px;">${URGENCY_LABELS[r.urgency] || r.urgency}</span>
                ${escapeHtml(r.name)}${r.barcode ? ' <span class="muted">(' + escapeHtml(r.barcode) + ')</span>' : ''}
                ${r.workflow_status === 'in_progress' ? ' <span class="muted">— nemrég rendelve</span>' : ''}
            </td>
            <td>${r.stock_qty} db</td>
            <td>${r.avg_daily_consumption !== null ? r.avg_daily_consumption + ' db/nap' : '—'}</td>
            <td>${fmtDays(r.estimated_days_remaining)}</td>
            <td><input type="number" min="0" value="${r.recommended_qty}" class="bj-qty-input" data-id="${r.id}" style="width:80px;"></td>
            <td class="muted" style="font-size:12px;">${escapeHtml(r.reason)}</td>
        </tr>
    `).join('');

    body.querySelectorAll('.bj-row-check').forEach(cb => {
        cb.addEventListener('change', () => onRowCheckChange(cb));
    });
    body.querySelectorAll('.bj-qty-input').forEach(input => {
        input.addEventListener('input', () => {
            const id = Number(input.dataset.id);
            if (selected.has(id)) {
                selected.get(id).qty = parseInt(input.value, 10) || 0;
                updateSelectedButton();
            }
        });
    });
    syncSelectAllCheckbox();
}

function onRowCheckChange(checkbox) {
    const id = Number(checkbox.dataset.id);
    const row = currentRecommendations.find(r => r.id === id);
    if (!row) return;
    if (checkbox.checked) {
        const qtyInput = document.querySelector(`.bj-qty-input[data-id="${id}"]`);
        selected.set(id, { product_id: id, qty: parseInt(qtyInput.value, 10) || row.recommended_qty, supplier_id: row.supplier_id, name: row.name });
    } else {
        selected.delete(id);
    }
    updateSelectedButton();
    syncSelectAllCheckbox();
}

function syncSelectAllCheckbox() {
    const boxes = Array.from(document.querySelectorAll('.bj-row-check'));
    const selectAll = document.getElementById('bj-select-all');
    selectAll.checked = boxes.length > 0 && boxes.every(b => b.checked);
    selectAll.indeterminate = boxes.some(b => b.checked) && !selectAll.checked;
}

function updateSelectedButton() {
    const btn = document.getElementById('bj-start-purchase-btn');
    document.getElementById('bj-selected-count').textContent = selected.size;
    btn.disabled = selected.size === 0;
}

document.getElementById('bj-select-all').addEventListener('change', (e) => {
    document.querySelectorAll('.bj-row-check').forEach(cb => {
        cb.checked = e.target.checked;
        onRowCheckChange(cb);
    });
});

document.querySelectorAll('.bj-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.bj-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentUrgency = btn.dataset.urgency;
        const url = new URL(location.href);
        if (currentUrgency) url.searchParams.set('urgency', currentUrgency); else url.searchParams.delete('urgency');
        history.replaceState(null, '', url);
        loadSuggestions();
    });
});

document.getElementById('bj-start-purchase-btn').addEventListener('click', () => {
    const items = Array.from(selected.values());
    if (!items.length) return;

    // Ha a kijelölt tételek MIND ugyanahhoz a preferált beszállítóhoz
    // tartoznak, azt előre kitöltjük (lásd beszerzes.js sm_purchase_prefill
    // feldolgozása) — vegyes beszállító esetén üresen hagyjuk, a
    // felhasználó a beszerzés oldalon választja ki/tölti ki kézzel.
    const supplierIds = new Set(items.map(i => i.supplier_id).filter(id => id !== null && id !== undefined));
    const prefill = {
        items: items.map(i => ({ product_id: i.product_id, qty: i.qty })),
    };
    if (supplierIds.size === 1) {
        prefill.supplier_id = [...supplierIds][0];
    }
    sessionStorage.setItem('sm_purchase_prefill', JSON.stringify(prefill));
    location.href = 'beszerzes.php';
});

// Ha a tab-kezdőállapot a URL-ből (pl. Dashboard-linkről) jön, jelöljük ki a megfelelő fület.
if (currentUrgency) {
    const initialTab = document.querySelector(`.bj-tab[data-urgency="${currentUrgency}"]`);
    if (initialTab) {
        document.querySelectorAll('.bj-tab').forEach(b => b.classList.remove('active'));
        initialTab.classList.add('active');
    }
}

loadSuggestions();
