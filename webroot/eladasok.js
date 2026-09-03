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

function paymentBadge(method) {
    const map = {
        'Készpénz': 'pm-cash', 'Bankkártya': 'pm-card', 'Átutalás': 'pm-transfer',
        'PayPal': 'pm-paypal', 'Utánvét': 'pm-cod',
    };
    const cls = map[method] || 'pm-other';
    return `<span class="pm-badge ${cls}">${escapeHtml(method)}</span>`;
}

async function loadSales() {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());

    try {
        const res = await fetch('/api/sales-list.php?' + params.toString());
        const data = await res.json();
        renderResults(data.sales || []);
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="6" class="muted">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderResults(sales) {
    resultsCount.textContent = `${sales.length} eladás`;
    resultsBody.innerHTML = sales.length
        ? sales.map(s => `
            <tr class="clickable-row" data-id="${s.id}">
                <td>#${s.id}</td>
                <td>${s.created_at}</td>
                <td>${escapeHtml(s.buyer_name || '—')}</td>
                <td>${fmt(s.total)}</td>
                <td>${paymentBadge(s.payment_method)}</td>
                <td>${s.status === 'invoice_failed' ? 'Sikertelen' : escapeHtml(s.szamlazz_invoice_number || '—')}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="6" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő eladás.</td></tr>';

    resultsBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => openDetail(Number(row.dataset.id)));
    });
}

async function openDetail(id) {
    detailContent.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    detailModal.classList.add('open');
    try {
        const [saleRes, returnsRes] = await Promise.all([
            fetch('/api/sale-detail.php?sale_id=' + id),
            fetch('/api/sale-returns.php?sale_id=' + id),
        ]);
        const data = await saleRes.json();
        if (!saleRes.ok) {
            detailContent.innerHTML = `<p class="feedback error">${escapeHtml(data.error || 'ismeretlen hiba')}</p>`;
            return;
        }
        const returnsData = await returnsRes.json();
        const returnedQty = returnsData.returned_quantities || {};
        const pastReturns = returnsData.returns || [];

        const sale = data.sale;
        const rows = sale.items.map(item => {
            const already = returnedQty[item.id] || 0;
            const maxReturnable = item.qty - already;
            return `
                <tr>
                    <td>${escapeHtml(item.name)}${already ? ` <span class="muted" style="font-size:11px;">(${already} db már visszavéve)</span>` : ''}</td>
                    <td>${item.qty}</td>
                    <td>${fmt(item.unit_price)}</td>
                    <td>${fmt(item.unit_price * item.qty)}</td>
                    <td class="return-qty-cell hidden">
                        ${maxReturnable > 0 ? `<input type="number" min="0" max="${maxReturnable}" value="0" data-sale-item-id="${item.id}" class="return-qty-input" style="width:60px;">` : '—'}
                    </td>
                </tr>
            `;
        }).join('');

        const pastReturnsHtml = pastReturns.length ? `
            <p class="muted" style="margin-top:14px;">Korábbi visszáruk:</p>
            ${pastReturns.map(r => `<div class="muted" style="font-size:12px;">${r.created_at} — ${fmt(r.total_refund)} visszatérítve${r.reason ? ' (' + r.reason + ')' : ''}${r.credit_invoice_number ? ', jóváíró számla: ' + r.credit_invoice_number : ''}</div>`).join('')}
        ` : '';

        detailContent.innerHTML = `
            <p class="muted">Eladás #${sale.id} · ${sale.created_at} · ${paymentBadge(sale.payment_method)}${sale.buyer_name ? ' · ' + escapeHtml(sale.buyer_name) : ''}</p>
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Menny.</th><th>Egységár</th><th>Össz.</th><th class="return-qty-header hidden">Visszaveendő</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <p style="text-align:right; font-weight:700; font-size:16px; margin-top:10px;">Összesen: ${fmt(sale.total)}</p>
            <button class="btn btn-secondary" style="width:100%; margin-top:10px;" onclick="window.open('receipt.html?sale_id=${sale.id}','_blank')">Nyugta megtekintése</button>

            <div id="return-start-wrap" style="margin-top:10px;">
                <button class="btn btn-secondary" id="return-start-btn" style="width:100%; border-color:var(--danger); color:var(--danger);">Visszáru rögzítése</button>
            </div>
            <div id="return-form-wrap" class="hidden" style="margin-top:10px;">
                <label for="return-reason">Indoklás (opcionális)</label>
                <input type="text" id="return-reason" placeholder="pl. hibás termék">
                <p id="return-feedback" class="modal-feedback"></p>
                <button class="btn btn-primary" id="return-submit-btn" style="width:100%;">Visszáru feldolgozása</button>
                ${sale.szamlazz_invoice_number ? `<p class="muted" style="margin-top:8px;">Ehhez az eladáshoz számla tartozik (${sale.szamlazz_invoice_number}) — a jóváíró számlát a visszáru feldolgozása után manuálisan kell kiállítani a Szamlazz.hu felületén.</p>` : ''}
            </div>
            ${pastReturnsHtml}
        `;

        document.getElementById('return-start-btn').addEventListener('click', () => {
            document.getElementById('return-start-wrap').classList.add('hidden');
            document.getElementById('return-form-wrap').classList.remove('hidden');
            document.querySelectorAll('.return-qty-cell, .return-qty-header').forEach(el => el.classList.remove('hidden'));
        });

        document.getElementById('return-submit-btn').addEventListener('click', async () => {
            const items = Array.from(document.querySelectorAll('.return-qty-input'))
                .map(input => ({ sale_item_id: Number(input.dataset.saleItemId), qty: parseInt(input.value, 10) || 0 }))
                .filter(i => i.qty > 0);

            const returnFeedback = document.getElementById('return-feedback');
            if (!items.length) {
                returnFeedback.textContent = 'Adj meg legalább egy visszaveendő mennyiséget.';
                returnFeedback.className = 'modal-feedback error';
                return;
            }
            returnFeedback.textContent = 'Feldolgozás...';
            returnFeedback.className = 'modal-feedback';
            try {
                let staffId = null;
                try {
                    const staffRaw = localStorage.getItem('sm_current_staff');
                    if (staffRaw) staffId = JSON.parse(staffRaw).id;
                } catch (e) { /* ignore */ }

                const res = await fetch('/api/return-create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sale_id: sale.id,
                        items,
                        reason: document.getElementById('return-reason').value.trim(),
                        staff_id: staffId,
                    }),
                });
                const resultData = await res.json();
                if (!res.ok) throw new Error(resultData.error || 'ismeretlen hiba');

                returnFeedback.textContent = `Visszáru rögzítve, visszatérítendő összeg: ${fmt(resultData.total_refund)}.`;
                returnFeedback.className = 'modal-feedback';
                openDetail(sale.id); // refresh with updated returned quantities
            } catch (err) {
                returnFeedback.textContent = 'Hiba: ' + err.message;
                returnFeedback.className = 'modal-feedback error';
            }
        });
    } catch (err) {
        detailContent.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

detailModalClose.addEventListener('click', () => detailModal.classList.remove('open'));

clearFiltersBtn.addEventListener('click', () => {
    fDate.value = '';
    fId.value = '';
    fQuery.value = '';
    loadSales();
});

exportCsvBtn.addEventListener('click', () => {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());
    window.location.href = '/api/export-sales-csv.php?' + params.toString();
});

[fDate, fId].forEach(el => el.addEventListener('change', loadSales));
fQuery.addEventListener('input', loadSales);

const fDateTodayBtn = document.getElementById('f-date-today-btn');
if (fDateTodayBtn) {
    fDateTodayBtn.addEventListener('click', () => {
        fDate.value = new Date().toISOString().slice(0, 10);
        loadSales();
    });
}

document.addEventListener('stockmanager:synced', () => loadSales());

loadSales();
