let allOrders = [];
let currentStatusFilter = 'draft';
let paymentMethodOptions = [
    { value: 'Készpénz', color: '#16a34a' }, { value: 'Bankkártya', color: '#3b82f6' },
];

const tabButtons = document.querySelectorAll('.wo-tab-btn');
const resultsCount = document.getElementById('results-count');
const resultsBody = document.getElementById('results-body');
const detailModal = document.getElementById('detail-modal');
const detailContent = document.getElementById('detail-content');
const detailModalClose = document.getElementById('detail-modal-close');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

function statusBadge(status) {
    const map = {
        draft: '<span class="pm-badge pm-other">Piszkozat</span>',
        confirmed: '<span class="pm-badge" style="background:rgba(22,163,74,.15); color:#16a34a; border-color:#16a34a;">Leadva</span>',
        rejected: '<span class="pm-badge" style="background:rgba(239,68,68,.15); color:var(--danger); border-color:var(--danger);">Elutasítva</span>',
    };
    return map[status] || status;
}

window.smSettingsPromise.then((data) => {
    if (Array.isArray(data.payment_methods) && data.payment_methods.length) {
        paymentMethodOptions = data.payment_methods;
    }
}).catch(() => { /* marad az alapértelmezett lista */ });

async function loadOrders() {
    resultsBody.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center; padding:24px;">Betöltés...</td></tr>';
    try {
        const res = await fetch('/api/webshop-orders-list.php?status=' + encodeURIComponent(currentStatusFilter));
        const data = await res.json();
        allOrders = data.orders || [];
        renderResults();
        updateTabBadge(data.draft_count || 0);
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="7" class="muted">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function updateTabBadge(draftCount) {
    const draftTab = document.querySelector('.wo-tab-btn[data-status="draft"] .wo-tab-count');
    if (draftTab) draftTab.textContent = draftCount > 0 ? ` (${draftCount})` : '';
}

function renderResults() {
    resultsCount.textContent = `${allOrders.length} rendelés`;
    resultsBody.innerHTML = allOrders.length
        ? allOrders.map(o => `
            <tr class="clickable-row" data-id="${o.id}">
                <td>#${escapeHtml(o.order_number || o.wc_order_id)}</td>
                <td>${o.created_at}</td>
                <td>${escapeHtml(o.customer_name || '—')}</td>
                <td>${fmt(o.total)}</td>
                <td>${escapeHtml(o.payment_method || '—')}</td>
                <td>${statusBadge(o.status)}</td>
                <td>${o.items.length} tétel</td>
            </tr>
        `).join('')
        : '<tr><td colspan="7" class="muted" style="text-align:center; padding:24px;">Nincs ide tartozó rendelés.</td></tr>';

    resultsBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => openDetail(Number(row.dataset.id)));
    });
}

tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        tabButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentStatusFilter = btn.dataset.status;
        loadOrders();
    });
});

function buyerAddressLine(billing) {
    const parts = [billing.irsz, billing.telepules, billing.cim].filter(Boolean);
    return parts.join(', ') + (billing.orszag ? ' (' + billing.orszag + ')' : '');
}

function itemsTableHtml(items) {
    const rows = items.map(i => `
        <tr>
            <td>${escapeHtml(i.name)}${i.sku ? ` <span class="muted" style="font-size:11px;">(${escapeHtml(i.sku)})</span>` : ''}</td>
            <td>${i.qty}</td>
            <td>${fmt(i.unit_price)}</td>
            <td>${fmt(i.unit_price * i.qty)}</td>
            <td>${i.product_id
                ? '<span class="pm-badge" style="background:rgba(22,163,74,.15); color:#16a34a; border-color:#16a34a;">Párosítva</span>'
                : '<span class="pm-badge" style="background:rgba(239,68,68,.15); color:var(--danger); border-color:var(--danger);">Nincs helyi termék</span>'}</td>
        </tr>
    `).join('');
    return `
        <div class="sample-table-wrap">
        <table class="sample-table">
            <thead><tr><th>Termék</th><th>Menny.</th><th>Egységár</th><th>Össz.</th><th>Készlet</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
        </div>
    `;
}

async function openDetail(id) {
    const order = allOrders.find(o => o.id === id);
    if (!order) return;

    const billing = order.billing || {};
    const hasInvoiceableBuyer = !!(billing.nev && billing.irsz && billing.telepules && billing.cim);
    const paymentOptionsHtml = paymentMethodOptions.map(m =>
        `<option value="${escapeHtml(m.value)}"${m.value === order.payment_method ? ' selected' : ''}>${escapeHtml(m.value)}</option>`
    ).join('') + (paymentMethodOptions.some(m => m.value === order.payment_method) || !order.payment_method
        ? ''
        : `<option value="${escapeHtml(order.payment_method)}" selected>${escapeHtml(order.payment_method)}</option>`);

    detailModal.classList.add('open');
    detailContent.innerHTML = `
        <p class="muted">
            Rendelés #${escapeHtml(order.order_number || order.wc_order_id)} (WooCommerce #${order.wc_order_id})
            · ${order.created_at} · ${statusBadge(order.status)}
        </p>
        <div class="field-row">
            <div>
                <label>Vevő</label>
                <p style="margin:2px 0;">${escapeHtml(order.customer_name || '—')}</p>
                <p class="muted" style="margin:2px 0; font-size:12px;">${escapeHtml(order.customer_email || '')}</p>
                <p class="muted" style="margin:2px 0; font-size:12px;">${escapeHtml(buyerAddressLine(billing))}</p>
            </div>
            <div>
                <label for="wo-payment-method">Fizetési mód</label>
                <select id="wo-payment-method" ${order.status !== 'draft' ? 'disabled' : ''}>${paymentOptionsHtml}</select>
            </div>
        </div>
        ${order.customer_note ? `<p class="muted" style="font-size:12px;">Vevői megjegyzés: ${escapeHtml(order.customer_note)}</p>` : ''}
        ${itemsTableHtml(order.items)}
        <p style="text-align:right; font-weight:700; font-size:16px; margin-top:10px;">Összesen: ${fmt(order.total)}</p>

        <div id="wo-action-area" style="margin-top:14px;"></div>
        <p id="wo-feedback" class="modal-feedback"></p>
    `;

    const actionArea = document.getElementById('wo-action-area');
    const feedback = document.getElementById('wo-feedback');

    if (order.status === 'draft') {
        actionArea.innerHTML = `
            <label class="checkbox-line">
                <input type="checkbox" id="wo-issue-invoice" ${hasInvoiceableBuyer ? 'checked' : 'disabled'}>
                Számla kiállítása azonnal (Számlázz.hu)${hasInvoiceableBuyer ? '' : ' — a rendelés számlázási címe hiányos'}
            </label>
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button class="btn btn-secondary" id="wo-reject-btn" style="flex:1; border-color:var(--danger); color:var(--danger);">Elutasítás</button>
                <button class="btn btn-primary" id="wo-confirm-btn" style="flex:2;">Rendelés leadása</button>
            </div>
        `;

        const rejectBtn = document.getElementById('wo-reject-btn');
        const confirmBtn = document.getElementById('wo-confirm-btn');

        rejectBtn.addEventListener('click', async () => {
            if (!confirm('Biztosan elutasítod ezt a rendelést? A készlet nem változik, a rendelés archiválva marad.')) return;
            rejectBtn.disabled = true;
            confirmBtn.disabled = true;
            feedback.textContent = 'Feldolgozás...';
            feedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/webshop-order-reject.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: order.id }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                detailModal.classList.remove('open');
                loadOrders();
            } catch (err) {
                feedback.textContent = 'Hiba: ' + err.message;
                feedback.className = 'modal-feedback error';
                rejectBtn.disabled = false;
                confirmBtn.disabled = false;
            }
        });

        confirmBtn.addEventListener('click', async () => {
            const paymentMethod = document.getElementById('wo-payment-method').value;
            const issueInvoice = document.getElementById('wo-issue-invoice').checked;
            rejectBtn.disabled = true;
            confirmBtn.disabled = true;
            feedback.textContent = 'Feldolgozás...';
            feedback.className = 'modal-feedback';
            try {
                const res = await fetch('/api/webshop-order-confirm.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: order.id, payment_method: paymentMethod, issue_invoice: issueInvoice }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
                if (data.invoice && !data.invoice.success) {
                    feedback.textContent = 'A rendelés leadva, de a számla kiállítása sikertelen: ' + (data.invoice.error || 'ismeretlen hiba');
                    feedback.className = 'modal-feedback error';
                    setTimeout(() => { detailModal.classList.remove('open'); loadOrders(); }, 3000);
                } else {
                    detailModal.classList.remove('open');
                    loadOrders();
                }
            } catch (err) {
                feedback.textContent = 'Hiba: ' + err.message;
                feedback.className = 'modal-feedback error';
                rejectBtn.disabled = false;
                confirmBtn.disabled = false;
            }
        });
    } else if (order.status === 'confirmed') {
        actionArea.innerHTML = '<p class="muted">Betöltés...</p>';
        try {
            const res = await fetch('/api/sale-detail.php?sale_id=' + order.sale_id);
            const data = await res.json();
            const sale = data.sale;
            if (sale && sale.szamlazz_invoice_number) {
                actionArea.innerHTML = `
                    <p class="muted">Eladás #${sale.id} — számla: ${escapeHtml(sale.szamlazz_invoice_number)}</p>
                    <button class="btn btn-secondary" style="width:100%;" onclick="window.open('receipt.html?sale_id=${sale.id}','_blank')">Nyugta / számla megtekintése</button>
                `;
            } else {
                actionArea.innerHTML = `
                    <p class="muted">Eladás #${sale ? sale.id : order.sale_id} — még nincs kiállított számla.</p>
                    <button class="btn btn-primary" id="wo-issue-invoice-btn" style="width:100%;" ${hasInvoiceableBuyer ? '' : 'disabled'}>Számla kiállítása</button>
                    ${hasInvoiceableBuyer ? '' : '<p class="muted" style="font-size:12px;">A rendelés számlázási címe hiányos.</p>'}
                `;
                const issueBtn = document.getElementById('wo-issue-invoice-btn');
                if (issueBtn) {
                    issueBtn.addEventListener('click', async () => {
                        issueBtn.disabled = true;
                        feedback.textContent = 'Számla kiállítása...';
                        feedback.className = 'modal-feedback';
                        try {
                            const invRes = await fetch('/api/webshop-order-invoice.php', {
                                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: order.id }),
                            });
                            const invData = await invRes.json();
                            if (!invRes.ok) throw new Error(invData.error || 'ismeretlen hiba');
                            if (!invData.invoice.success) throw new Error(invData.invoice.error || 'ismeretlen hiba');
                            feedback.textContent = 'Számla kiállítva: ' + invData.invoice.invoice_number;
                            feedback.className = 'modal-feedback';
                            openDetail(id);
                        } catch (err) {
                            feedback.textContent = 'Hiba: ' + err.message;
                            feedback.className = 'modal-feedback error';
                            issueBtn.disabled = false;
                        }
                    });
                }
            }
        } catch (e) {
            actionArea.innerHTML = '<p class="muted">Az eladás adatai nem tölthetők be.</p>';
        }
    } else {
        actionArea.innerHTML = '<p class="muted">Ez a rendelés el lett utasítva, a készletet nem érintette.</p>';
    }
}

detailModalClose.addEventListener('click', () => detailModal.classList.remove('open'));
detailModal.addEventListener('click', (e) => { if (e.target === detailModal) detailModal.classList.remove('open'); });

document.addEventListener('stockmanager:synced', () => loadOrders());

loadOrders();
