const fDateFrom = document.getElementById('f-date-from');
const fDateTo = document.getElementById('f-date-to');
const fSupplier = document.getElementById('f-supplier');
const fInvoiceNumber = document.getElementById('f-invoice-number');
const fOperation = document.getElementById('f-operation');
const fCurrency = document.getElementById('f-currency');
const clearFiltersBtn = document.getElementById('clear-filters-btn');
const resultsCount = document.getElementById('results-count');
const resultsBody = document.getElementById('results-body');
const detailModal = document.getElementById('detail-modal');
const detailContent = document.getElementById('detail-content');
const detailModalClose = document.getElementById('detail-modal-close');
const syncStatusText = document.getElementById('sync-status-text');
const syncPeriod = document.getElementById('sync-period');
const syncCustomFrom = document.getElementById('sync-custom-from');
const syncNowBtn = document.getElementById('sync-now-btn');
const syncFeedback = document.getElementById('sync-feedback');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

const OPERATION_LABELS = { CREATE: 'Eredeti', MODIFY: 'Módosító', STORNO: 'Sztornó' };

function operationBadge(operation) {
    const styles = {
        CREATE: 'background:rgba(59,130,246,.15); color:#3b82f6; border-color:#3b82f6;',
        MODIFY: 'background:rgba(234,179,8,.15); color:#ca8a04; border-color:#ca8a04;',
        STORNO: 'background:rgba(239,68,68,.15); color:#ef4444; border-color:#ef4444;',
    };
    return `<span class="pm-badge" style="${styles[operation] || styles.CREATE}">${escapeHtml(OPERATION_LABELS[operation] || operation)}</span>`;
}

function currentFilters() {
    const params = new URLSearchParams();
    if (fDateFrom.value) params.set('date_from', fDateFrom.value);
    if (fDateTo.value) params.set('date_to', fDateTo.value);
    if (fSupplier.value.trim()) params.set('supplier', fSupplier.value.trim());
    if (fInvoiceNumber.value.trim()) params.set('invoice_number', fInvoiceNumber.value.trim());
    if (fOperation.value) params.set('operation', fOperation.value);
    if (fCurrency.value) params.set('currency', fCurrency.value);
    return params;
}

async function loadInvoices() {
    try {
        const res = await fetch('/api/incoming-invoices-list.php?' + currentFilters().toString());
        const data = await res.json();
        renderResults(data.invoices || []);
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="10" class="muted">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderResults(invoices) {
    resultsCount.textContent = `${invoices.length} számla`;
    resultsBody.innerHTML = invoices.length
        ? invoices.map(i => `
            <tr class="clickable-row" data-id="${i.id}">
                <td>${escapeHtml(i.invoice_number || '—')}</td>
                <td>${escapeHtml(i.supplier_name || '—')}</td>
                <td>${escapeHtml(i.supplier_tax_number || '—')}</td>
                <td>${escapeHtml(i.invoice_delivery_date || '—')}</td>
                <td>${escapeHtml(i.invoice_issue_date || '—')}</td>
                <td>${escapeHtml(i.payment_date || '—')}</td>
                <td>${fmt(i.net_total)}</td>
                <td>${fmt(i.vat_total)}</td>
                <td>${fmt(i.gross_total)}</td>
                <td>${operationBadge(i.invoice_operation)}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="10" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő számla.</td></tr>';

    resultsBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => openDetail(Number(row.dataset.id)));
    });
}

async function openDetail(id) {
    detailContent.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    detailModal.classList.add('open');
    try {
        const res = await fetch('/api/incoming-invoice-detail.php?id=' + id);
        const data = await res.json();
        if (!res.ok) {
            detailContent.innerHTML = `<p class="feedback error">${escapeHtml(data.error || 'ismeretlen hiba')}</p>`;
            return;
        }

        const inv = data.invoice;
        const items = data.items || [];

        let originalLinkHtml = '';
        if (inv.original_invoice_number) {
            try {
                const origRes = await fetch('/api/incoming-invoices-list.php?invoice_number=' + encodeURIComponent(inv.original_invoice_number) + '&tax_number=' + encodeURIComponent(inv.supplier_tax_number || ''));
                const origData = await origRes.json();
                const origMatch = (origData.invoices || []).find(x => x.invoice_number === inv.original_invoice_number);
                originalLinkHtml = origMatch
                    ? `<a href="#" id="original-invoice-link" data-id="${origMatch.id}">${escapeHtml(inv.original_invoice_number)}</a>`
                    : escapeHtml(inv.original_invoice_number) + ' (nincs helyben szinkronizálva)';
            } catch (e) {
                originalLinkHtml = escapeHtml(inv.original_invoice_number);
            }
        }

        const itemsRows = items.map(item => `
            <tr>
                <td>${escapeHtml(item.description || '')}</td>
                <td>${escapeHtml(item.quantity ?? '')} ${escapeHtml(item.unit_of_measure || '')}</td>
                <td>${fmt(item.unit_net_price)}</td>
                <td>${item.vat_rate != null ? escapeHtml(item.vat_rate) + '%' : '—'}</td>
                <td>${fmt(item.net_amount)}</td>
                <td>${fmt(item.gross_amount)}</td>
            </tr>
        `).join('');

        detailContent.innerHTML = `
            <p class="muted">Számla ${escapeHtml(inv.invoice_number || '—')} ${operationBadge(inv.invoice_operation)}</p>
            <p style="margin-top:6px;"><strong>${escapeHtml(inv.supplier_name || '—')}</strong> · adószám: ${escapeHtml(inv.supplier_tax_number || '—')}${inv.supplier_country ? ' · ' + escapeHtml(inv.supplier_country) : ''}</p>
            <p class="muted" style="font-size:13px;">Kiállítás: ${escapeHtml(inv.invoice_issue_date || '—')} · Teljesítés: ${escapeHtml(inv.invoice_delivery_date || '—')} · Fiz. határidő: ${escapeHtml(inv.payment_date || '—')}${inv.payment_method ? ' · ' + escapeHtml(inv.payment_method) : ''}</p>
            <p style="margin-top:10px;">Nettó: ${fmt(inv.net_total)} · ÁFA: ${fmt(inv.vat_total)} · <strong>Bruttó: ${fmt(inv.gross_total)}</strong> (${escapeHtml(inv.currency || 'HUF')})</p>
            <p class="muted" style="font-size:12px;">A bruttó összeg helyben számított érték (nettó + ÁFA) — a NAV a kivonatban nem ad külön bruttó mezőt.</p>
            ${inv.original_invoice_number ? `<p class="muted" style="margin-top:8px;">Eredeti számla: ${originalLinkHtml}${inv.modification_index ? ' (módosítás #' + escapeHtml(inv.modification_index) + ')' : ''}</p>` : ''}
            ${data.detail_error ? `<p class="feedback error" style="margin-top:8px;">${escapeHtml(data.detail_error)}</p>` : ''}

            <p class="muted" style="margin-top:16px; border-top:1px solid var(--border); padding-top:10px;">Tételek</p>
            <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Megnevezés</th><th>Menny.</th><th>Egységár</th><th>ÁFA</th><th>Nettó</th><th>Bruttó</th></tr></thead>
                <tbody>${itemsRows || '<tr><td colspan="6" class="muted" style="text-align:center;">Nincs (még) tétel-szintű adat.</td></tr>'}</tbody>
            </table>
            </div>

            <p class="muted" style="margin-top:16px; border-top:1px solid var(--border); padding-top:10px; font-size:12px;">
                NAV tranzakcióazonosító: ${escapeHtml(inv.nav_transaction_id || '—')} · szinkronizálva: ${escapeHtml(inv.last_synced_at || '—')}<br>
                A NAV Online Számla API nem biztosít PDF-et beérkező számlákhoz — csak az itt látható, strukturált adatok érhetők el.
            </p>
        `;

        const originalLink = document.getElementById('original-invoice-link');
        if (originalLink) {
            originalLink.addEventListener('click', (e) => {
                e.preventDefault();
                openDetail(Number(originalLink.dataset.id));
            });
        }
    } catch (err) {
        detailContent.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

detailModalClose.addEventListener('click', () => detailModal.classList.remove('open'));

clearFiltersBtn.addEventListener('click', () => {
    fDateFrom.value = '';
    fDateTo.value = '';
    fSupplier.value = '';
    fInvoiceNumber.value = '';
    fOperation.value = '';
    fCurrency.value = '';
    loadInvoices();
});

[fDateFrom, fDateTo, fOperation, fCurrency].forEach(el => el.addEventListener('change', loadInvoices));
fSupplier.addEventListener('input', loadInvoices);
fInvoiceNumber.addEventListener('input', loadInvoices);

// ---- Sync állapot + manuális "Számlák frissítése" ----

function syncStatusLabel(sync) {
    if (!sync) return 'Sync állapot ismeretlen.';
    const labels = { idle: 'Még nem futott le.', running: 'Éppen fut...', success: 'Sikeres.', retry: 'Átmeneti hiba, újrapróbálja.', failed: 'Sikertelen — admin ellenőrzés szükséges.' };
    const statusText = labels[sync.status] || sync.status;
    const lastAttempt = sync.last_attempt_at ? ` · legutóbbi próbálkozás: ${escapeHtml(sync.last_attempt_at)}` : '';
    const lastSuccess = sync.last_success_at ? ` · legutóbbi siker: ${escapeHtml(sync.last_success_at)}` : '';
    const errorText = sync.last_error ? ` · hiba: ${escapeHtml(sync.last_error)}` : '';
    return `Sync állapot: ${statusText}${lastAttempt}${lastSuccess}${errorText}`;
}

async function loadSyncStatus() {
    try {
        const res = await fetch('/api/nav-incoming-sync-status.php');
        const data = await res.json();
        syncStatusText.textContent = syncStatusLabel(data.sync);
    } catch (err) {
        syncStatusText.textContent = 'Sync állapot betöltése sikertelen.';
    }
}

syncPeriod.addEventListener('change', () => {
    syncCustomFrom.style.display = syncPeriod.value === 'custom' ? '' : 'none';
});

syncNowBtn.addEventListener('click', async () => {
    const period = syncPeriod.value;
    const body = { period };
    if (period === 'custom') {
        if (!syncCustomFrom.value) {
            syncFeedback.textContent = 'Adj meg egy kezdő dátumot az egyedi tartományhoz.';
            syncFeedback.className = 'modal-feedback error';
            return;
        }
        body.date_from = syncCustomFrom.value;
    }

    syncNowBtn.disabled = true;
    syncFeedback.textContent = 'Sync indítása...';
    syncFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/nav-incoming-sync-trigger.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        syncFeedback.textContent = 'Sync lefutott — eredmény: ' + (data.result ? data.result.outcome : 'ismeretlen');
        syncFeedback.className = 'modal-feedback';
        await loadSyncStatus();
        await loadInvoices();
    } catch (err) {
        syncFeedback.textContent = 'Hiba: ' + err.message;
        syncFeedback.className = 'modal-feedback error';
    } finally {
        syncNowBtn.disabled = false;
    }
});

document.addEventListener('stockmanager:synced', () => loadInvoices());

loadSyncStatus();
loadInvoices();
