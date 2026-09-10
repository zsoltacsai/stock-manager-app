const fDate = document.getElementById('f-date');
const fId = document.getElementById('f-id');
const fProvider = document.getElementById('f-provider');
const fStatus = document.getElementById('f-status');
const fQuery = document.getElementById('f-query');
const clearFiltersBtn = document.getElementById('clear-filters-btn');
const exportCsvBtn = document.getElementById('export-csv-btn');
const resultsCount = document.getElementById('results-count');
const resultsBody = document.getElementById('results-body');
const detailModal = document.getElementById('detail-modal');
const detailContent = document.getElementById('detail-content');
const detailModalClose = document.getElementById('detail-modal-close');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

// Ugyanaz a bucket-leképezés, mint Database::INVOICE_STATUS_BUCKETS —
// a NAV teljes állapotgépe (queued/processing/.../uncertain/dead_letter)
// technikai részlet, a kasszásnak csak a 3 felhasználóbarát csoport
// releváns.
const STATUS_BUCKETS = {
    done: ['done'],
    pending: ['queued', 'processing', 'processing_status_check', 'processing_uncertain_recovery', 'submitted'],
    failed: ['failed', 'dead_letter', 'uncertain', 'uncertain_manual'],
};

function statusBucket(status) {
    for (const bucket in STATUS_BUCKETS) {
        if (STATUS_BUCKETS[bucket].includes(status)) return bucket;
    }
    return 'pending';
}

function statusBadge(status) {
    const styles = {
        done: 'background:rgba(22,163,74,.15); color:#16a34a; border-color:#16a34a;',
        pending: 'background:rgba(234,179,8,.15); color:#ca8a04; border-color:#ca8a04;',
        failed: 'background:rgba(239,68,68,.15); color:#ef4444; border-color:#ef4444;',
    };
    const labels = { done: 'Kiállítva', pending: 'Folyamatban', failed: 'Sikertelen' };
    const bucket = statusBucket(status);
    return `<span class="pm-badge" style="${styles[bucket]}">${labels[bucket]}</span>`;
}

function providerBadge(provider) {
    return provider === 'nav'
        ? '<span class="pm-badge" style="background:rgba(59,130,246,.15); color:#3b82f6; border-color:#3b82f6;">NAV</span>'
        : '<span class="pm-badge" style="background:rgba(34,197,94,.15); color:#22c55e; border-color:#22c55e;">Számlázz.hu</span>';
}

// A státusz-buckethez tartozó, RÉSZLETNÉZETBEN mutatott pontosabb szöveg
// — a lista-badge marad az egyszerű 3-csoportos címke, de a
// részletnézetben (ahol a kasszás/admin ténylegesen dönthet valamit)
// érdemes megkülönböztetni pl. a "kimerült retry"-t egy sima "hibától".
function statusDetailText(status) {
    const texts = {
        dead_letter: 'Ismételt próbálkozás kimerült — admin kézi újrapróbálkozása szükséges.',
        uncertain: 'Bizonytalan kimenetel — a rendszer egyezteti a NAV-val, hogy a korábbi kérés megérkezett-e.',
        uncertain_manual: 'Bizonytalan kimenetel — az automatikus egyeztetés kimerült, a rendszer TÖBBÉ NEM próbálkozik automatikusan. Admin kézi újrapróbálkozása szükséges (lásd az alábbi hibaüzenetet).',
        failed: 'A NAV/Számlázz.hu véglegesen elutasította a számlát.',
        queued: 'A számla be van ütemezve, hamarosan beküldésre kerül.',
        processing: 'A számla beküldése éppen folyamatban van.',
        processing_status_check: 'A NAV feldolgozási állapotának ellenőrzése folyamatban.',
        processing_uncertain_recovery: 'A bizonytalan kimenetelű kérés egyeztetése folyamatban.',
        submitted: 'A NAV feldolgozza a beküldött számlát.',
        done: 'A számla véglegesen kiállítva.',
    };
    return texts[status] || status;
}

async function loadInvoices() {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fProvider.value) params.set('provider', fProvider.value);
    if (fStatus.value) params.set('status', fStatus.value);
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());

    try {
        const res = await fetch('/api/invoices-list.php?' + params.toString());
        const data = await res.json();
        renderResults(data.invoices || []);
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="7" class="muted">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderResults(invoices) {
    resultsCount.textContent = `${invoices.length} számla`;
    resultsBody.innerHTML = invoices.length
        ? invoices.map(i => `
            <tr class="clickable-row" data-id="${i.id}">
                <td>#${i.id}</td>
                <td>${escapeHtml(i.created_at || '')}</td>
                <td>${escapeHtml(i.invoice_number || '—')}</td>
                <td>${escapeHtml(i.sale_buyer_name || '—')}</td>
                <td>${providerBadge(i.provider)}</td>
                <td>${fmt(i.gross_total)}</td>
                <td>${statusBadge(i.status)}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="7" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő számla.</td></tr>';

    resultsBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => openDetail(Number(row.dataset.id)));
    });
}

async function openDetail(id) {
    detailContent.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    detailModal.classList.add('open');
    try {
        const res = await fetch('/api/invoice-detail.php?id=' + id);
        const data = await res.json();
        if (!res.ok) {
            detailContent.innerHTML = `<p class="feedback error">${escapeHtml(data.error || 'ismeretlen hiba')}</p>`;
            return;
        }

        const inv = data.invoice;
        const sale = data.sale;
        const bucket = statusBucket(inv.status);

        const itemsRows = (sale && sale.items ? sale.items : []).map(item => `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${item.qty}</td>
                <td>${fmt(item.unit_price)}</td>
                <td>${fmt(item.unit_price * item.qty)}</td>
            </tr>
        `).join('');

        const navBlock = inv.provider === 'nav' ? `
            <p class="muted" style="margin-top:14px;"><strong>NAV tranzakcióazonosító</strong> (NEM számlaszám — a NAV nem ad ki külön számlaszámot, a beküldés technikai nyomon-követő azonosítója): ${escapeHtml(inv.provider_ref || '—')}</p>
            <p class="muted">Próbálkozások száma: ${inv.attempts ?? 0}${inv.next_attempt_at ? ` · következő próbálkozás: ${escapeHtml(inv.next_attempt_at)}` : ''}</p>
            ${inv.last_error ? `<p class="feedback error">${escapeHtml(inv.last_error)}</p>` : ''}
            ${bucket === 'failed' ? '<button class="btn btn-primary" id="nav-retry-btn" style="width:100%; margin-top:8px;">Manuális újrapróbálás most</button><p id="nav-retry-feedback" class="modal-feedback"></p>' : ''}
        ` : '';

        const szamlazzBlock = inv.provider === 'szamlazz' ? `
            ${inv.pdf_path
                ? `<a href="/api/invoice-pdf.php?id=${inv.id}" target="_blank" class="btn btn-secondary" style="width:100%; margin-top:8px; display:block; text-align:center;">PDF megtekintése</a>`
                : '<p class="muted" style="margin-top:8px;">Nincs elmentett PDF ehhez a számlához.</p>'}
            ${bucket === 'failed' && inv.status !== 'uncertain_manual' ? '<p class="muted" style="margin-top:8px;">A számla manuális újraküldése a Számlázz.hu felületén történik.</p>' : ''}
            ${inv.status === 'uncertain_manual' ? `
                <div style="margin-top:8px; padding:10px; border:1px solid var(--border); border-radius:8px;">
                    <p class="muted" style="margin:0 0 8px;">Ellenőrizd a Számlázz.hu felületén, hogy készült-e számla ehhez az eladáshoz, majd oldd fel a bizonytalan állapotot:</p>
                    <input type="text" id="szamlazz-resolve-invoice-number" placeholder="Számlaszám, ha TALÁLTÁL egyet (üresen hagyva: nem készült számla)" style="margin-bottom:8px;">
                    <button class="btn btn-primary" id="szamlazz-resolve-btn" style="width:100%;">Feloldás</button>
                    <p id="szamlazz-resolve-feedback" class="modal-feedback"></p>
                </div>
            ` : ''}
        ` : '<p class="muted" style="margin-top:8px;">Nincs PDF (NAV-számla — a NAV Online Számla API-nak nincs PDF-fogalma).</p>';

        detailContent.innerHTML = `
            <p class="muted">Számla ${escapeHtml(inv.invoice_number || '— (még nincs kiállítva)')} · ${providerBadge(inv.provider)} ${statusBadge(inv.status)}</p>
            <p class="muted" style="font-size:12px;">${escapeHtml(statusDetailText(inv.status))}</p>
            <p style="margin-top:10px;">Nettó: ${fmt(inv.net_total)} · ÁFA: ${fmt(inv.vat_total)} · <strong>Bruttó: ${fmt(inv.gross_total)}</strong>${inv.issued_at ? ` · Kiállítva: ${escapeHtml(inv.issued_at)}` : ''}</p>
            ${navBlock}
            ${szamlazzBlock}

            <p class="muted" style="margin-top:16px; border-top:1px solid var(--border); padding-top:10px;">Kapcsolódó eladás #${sale ? sale.id : inv.sale_id}${sale ? ' · ' + escapeHtml(sale.created_at) : ''}</p>
            <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Menny.</th><th>Egységár</th><th>Össz.</th></tr></thead>
                <tbody>${itemsRows}</tbody>
            </table>
            </div>
        `;

        const retryBtn = document.getElementById('nav-retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', async () => {
                retryBtn.disabled = true;
                const feedback = document.getElementById('nav-retry-feedback');
                feedback.textContent = 'Újraütemezés...';
                feedback.className = 'modal-feedback';
                try {
                    const retryRes = await fetch('/api/nav-invoice-retry.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: inv.id }),
                    });
                    const retryData = await retryRes.json();
                    if (!retryRes.ok) throw new Error(retryData.error || 'ismeretlen hiba');
                    feedback.textContent = 'Újraütemezve — a háttér-worker a következő futáskor feldolgozza.';
                    feedback.className = 'modal-feedback';
                    openDetail(inv.id);
                    loadInvoices();
                } catch (err) {
                    feedback.textContent = 'Hiba: ' + err.message;
                    feedback.className = 'modal-feedback error';
                    retryBtn.disabled = false;
                }
            });
        }

        const resolveBtn = document.getElementById('szamlazz-resolve-btn');
        if (resolveBtn) {
            resolveBtn.addEventListener('click', async () => {
                resolveBtn.disabled = true;
                const feedback = document.getElementById('szamlazz-resolve-feedback');
                feedback.textContent = 'Feloldás...';
                feedback.className = 'modal-feedback';
                const invoiceNumber = document.getElementById('szamlazz-resolve-invoice-number').value.trim();
                try {
                    const resolveRes = await fetch('/api/szamlazz-invoice-resolve-uncertain.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sale_id: inv.sale_id, invoice_number: invoiceNumber }),
                    });
                    const resolveData = await resolveRes.json();
                    if (!resolveRes.ok) throw new Error(resolveData.error || 'ismeretlen hiba');
                    // Ha nem volt megadott számlaszám, a tükör-sor törlődött
                    // (lásd Database::resolveUncertainSzamlazzInvoice()) — a
                    // részletnézet újratöltése emiatt itt szándékosan
                    // NEM történik meg (404-et adna), csak a lista frissül,
                    // a modal bezárásával.
                    detailModal.classList.remove('open');
                    loadInvoices();
                } catch (err) {
                    feedback.textContent = 'Hiba: ' + err.message;
                    feedback.className = 'modal-feedback error';
                    resolveBtn.disabled = false;
                }
            });
        }
    } catch (err) {
        detailContent.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

detailModalClose.addEventListener('click', () => detailModal.classList.remove('open'));

clearFiltersBtn.addEventListener('click', () => {
    fDate.value = '';
    fId.value = '';
    fProvider.value = '';
    fStatus.value = '';
    fQuery.value = '';
    loadInvoices();
});

exportCsvBtn.addEventListener('click', () => {
    const params = new URLSearchParams();
    if (fDate.value) params.set('date', fDate.value);
    if (fId.value.trim()) params.set('id', fId.value.trim());
    if (fProvider.value) params.set('provider', fProvider.value);
    if (fStatus.value) params.set('status', fStatus.value);
    if (fQuery.value.trim()) params.set('query', fQuery.value.trim());
    window.location.href = '/api/export-invoices-csv.php?' + params.toString();
});

[fDate, fId, fProvider, fStatus].forEach(el => el.addEventListener('change', loadInvoices));
fQuery.addEventListener('input', loadInvoices);

const fDateTodayBtn = document.getElementById('f-date-today-btn');
if (fDateTodayBtn) {
    fDateTodayBtn.addEventListener('click', () => {
        // Helyi dátum, NEM new Date().toISOString() — lásd eladasok.js
        // ugyanezen mintáját.
        const now = new Date();
        fDate.value = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        loadInvoices();
    });
}

document.addEventListener('stockmanager:synced', () => loadInvoices());

loadInvoices();
