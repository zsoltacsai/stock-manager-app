const fDate = document.getElementById('f-date');
const fId = document.getElementById('f-id');
const fQuery = document.getElementById('f-query');
const clearFiltersBtn = document.getElementById('clear-filters-btn');
const exportCsvBtn = document.getElementById('export-csv-btn');
const resultsCount = document.getElementById('results-count');
const resultsBody = document.getElementById('results-body');
const detailModal = document.getElementById('detail-modal');
const detailContent = document.getElementById('detail-content');
const detailModalClose = document.getElementById('detail-modal-close');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

async function loadPurchases() {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());

    try {
        const res = await fetch('/api/purchases-list.php?' + params.toString());
        const data = await res.json();
        renderResults(data.purchases || []);
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="6" class="muted">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderResults(purchases) {
    resultsCount.textContent = `${purchases.length} beszerzés`;
    resultsBody.innerHTML = purchases.length
        ? purchases.map(p => `
            <tr class="clickable-row" data-id="${p.id}">
                <td>#${p.id}</td>
                <td>${p.created_at}</td>
                <td>${escapeHtml(p.supplier_name || '—')}</td>
                <td>${fmt(p.total_net)}</td>
                <td>${fmt(p.total_gross)}</td>
                <td>${escapeHtml(p.payment_method || '')}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="6" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő beszerzés.</td></tr>';

    resultsBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => openDetail(Number(row.dataset.id)));
    });
}

async function openDetail(id) {
    detailContent.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    detailModal.classList.add('open');
    try {
        const res = await fetch('/api/purchase-detail.php?id=' + id);
        const data = await res.json();
        if (!res.ok) {
            detailContent.innerHTML = `<p class="feedback error">${escapeHtml(data.error || 'ismeretlen hiba')}</p>`;
            return;
        }
        const p = data.purchase;
        const rows = p.items.map(item => `
            <tr><td>${escapeHtml(item.name)}</td><td>${item.qty}</td><td>${fmt(item.unit_cost_net)}</td><td>${fmt(item.line_gross)}</td></tr>
        `).join('');
        detailContent.innerHTML = `
            <p class="muted">Beszerzés #${p.id} · ${p.created_at}${p.supplier_name ? ' · ' + escapeHtml(p.supplier_name) : ''} · ${escapeHtml(p.payment_method || '')}</p>
            <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Menny.</th><th>Nettó egységár</th><th>Bruttó érték</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            </div>
            <p style="text-align:right; font-weight:700; font-size:16px; margin-top:10px;">
                Nettó: ${fmt(p.total_net)} · Bruttó: ${fmt(p.total_gross)}
            </p>
        `;
    } catch (err) {
        detailContent.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

detailModalClose.addEventListener('click', () => detailModal.classList.remove('open'));

clearFiltersBtn.addEventListener('click', () => {
    fDate.value = '';
    fId.value = '';
    fQuery.value = '';
    loadPurchases();
});

exportCsvBtn.addEventListener('click', () => {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());
    window.location.href = '/api/export-purchases-csv.php?' + params.toString();
});

[fDate, fId].forEach(el => el.addEventListener('change', loadPurchases));
fQuery.addEventListener('input', loadPurchases);

const fDateTodayBtn = document.getElementById('f-date-today-btn');
if (fDateTodayBtn) {
    fDateTodayBtn.addEventListener('click', () => {
        fDate.value = new Date().toISOString().slice(0, 10);
        loadPurchases();
    });
}

document.addEventListener('stockmanager:synced', () => loadPurchases());

loadPurchases();
