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
const modifyModal = document.getElementById('modify-modal');
const modifyContent = document.getElementById('modify-content');
const modifyModalCancel = document.getElementById('modify-modal-cancel');
const modifyModalSubmit = document.getElementById('modify-modal-submit');
const modifyModalFeedback = document.getElementById('modify-modal-feedback');

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

// 1.1.0: invoice_type badge — a normal/modification/storno üzleti típus
// MEGKÜLÖNBÖZTETVE a technikai lifecycle status-tól (lásd Database
// migrateV22InvoiceOperationsBody() docblockja).
function operationTypeBadge(invoiceType) {
    const styles = {
        modification: 'background:rgba(234,179,8,.15); color:#ca8a04; border-color:#ca8a04;',
        storno: 'background:rgba(239,68,68,.15); color:#ef4444; border-color:#ef4444;',
    };
    const labels = { modification: 'Módosító', storno: 'Sztornó' };
    if (!styles[invoiceType]) return '';
    return `<span class="pm-badge" style="${styles[invoiceType]}">${labels[invoiceType]}</span>`;
}

// A számla-kapcsolat lánc (eredeti → módosítás(ok)/sztornó) megjelenítése,
// bármelyik láncszemet megnyitva — a kör 20. pontja. `root` az EREDETI
// (normal) sor, `operations` a getInvoiceOperationsForOriginal() teljes,
// modification_index szerint rendezett listája.
function renderRelatedChain(root, operations, currentId) {
    if (!root) return '';
    const rows = [{ ...root, _label: 'Eredeti' }, ...operations.map(op => ({ ...op, _label: op.invoice_type === 'storno' ? 'Sztornó' : 'Módosító' }))];
    if (rows.length < 2) return '';

    const items = rows.map(r => {
        const isCurrent = Number(r.id) === Number(currentId);
        return `<div class="clickable-row" data-chain-id="${r.id}" style="display:flex; justify-content:space-between; align-items:center; padding:6px 8px; border-radius:6px; ${isCurrent ? 'background:var(--panel-alt,rgba(255,255,255,.05)); font-weight:600;' : 'cursor:pointer;'}">
            <span>${r._label}: ${escapeHtml(r.invoice_number || '—')}${isCurrent ? ' (ez a számla)' : ''}</span>
            <span>${statusBadge(r.status)}</span>
        </div>`;
    }).join('');

    return `
        <div style="margin-top:16px; border-top:1px solid var(--border); padding-top:10px;">
            <p class="muted" style="margin:0 0 6px;">Kapcsolódó számlák</p>
            ${items}
        </div>
    `;
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

        // 1.1.0: a Számlázz.hu-s MÓDOSÍTÁS/SZTORNÓ bizonytalan (uncertain_manual)
        // kimenetelére KÜLÖN kézi-újrapróbálkozás kell — a meglévő
        // szamlazz-resolve-btn ág (lásd lent) a NORMÁL CREATE-flow sale-
        // szintű feloldása, ami invoice_type='modification'/'storno' sorra
        // nem értelmezhető (nincs analóg sales.status).
        const isSzamlazzOperationRetryable = inv.provider === 'szamlazz' && inv.invoice_type !== 'normal' && inv.status === 'uncertain_manual';

        const szamlazzBlock = inv.provider === 'szamlazz' ? `
            ${inv.pdf_path
                ? `<a href="/api/invoice-pdf.php?id=${inv.id}" target="_blank" class="btn btn-secondary" style="width:100%; margin-top:8px; display:block; text-align:center;">PDF megtekintése</a>`
                : '<p class="muted" style="margin-top:8px;">Nincs elmentett PDF ehhez a számlához.</p>'}
            ${bucket === 'failed' && inv.status !== 'uncertain_manual' ? '<p class="muted" style="margin-top:8px;">A számla manuális újraküldése a Számlázz.hu felületén történik.</p>' : ''}
            ${inv.status === 'uncertain_manual' && inv.invoice_type === 'normal' ? `
                <div style="margin-top:8px; padding:10px; border:1px solid var(--border); border-radius:8px;">
                    <p class="muted" style="margin:0 0 8px;">Ellenőrizd a Számlázz.hu felületén, hogy készült-e számla ehhez az eladáshoz, majd oldd fel a bizonytalan állapotot:</p>
                    <input type="text" id="szamlazz-resolve-invoice-number" placeholder="Számlaszám, ha TALÁLTÁL egyet (üresen hagyva: nem készült számla)" style="margin-bottom:8px;">
                    <button class="btn btn-primary" id="szamlazz-resolve-btn" style="width:100%;">Feloldás</button>
                    <p id="szamlazz-resolve-feedback" class="modal-feedback"></p>
                </div>
            ` : ''}
            ${isSzamlazzOperationRetryable ? `
                <div style="margin-top:8px; padding:10px; border:1px solid var(--border); border-radius:8px;">
                    <p class="muted" style="margin:0 0 8px;">A kérés kimenetele bizonytalan (a Számlázz.hu válasza elveszett) — kézi újrapróbálkozás:</p>
                    <button class="btn btn-primary" id="szamlazz-op-retry-btn" style="width:100%;">Kézi újrapróbálkozás</button>
                    <p id="szamlazz-op-retry-feedback" class="modal-feedback"></p>
                </div>
            ` : ''}
        ` : '<p class="muted" style="margin-top:8px;">Nincs PDF (NAV-számla — a NAV Online Számla API-nak nincs PDF-fogalma).</p>';

        // A backend dönti el, megengedett-e a MODIFY/STORNO (lásd
        // invoice-detail.php can_modify/can_storno — InvoiceService
        // validateOperationRequest()-jével AZONOS szabályok) — a frontend
        // csak megjeleníti a gombokat, NEM dönt pénzügyi jogosultságról.
        const operationButtons = (data.can_modify || data.can_storno) ? `
            <div style="display:flex; gap:8px; margin-top:12px;">
                ${data.can_modify ? '<button class="btn btn-secondary" id="open-modify-btn" style="flex:1;">Módosító számla</button>' : ''}
                ${data.can_storno ? '<button class="btn btn-secondary" id="open-storno-btn" style="flex:1; color:#ef4444; border-color:#ef4444;">Sztornó számla</button>' : ''}
            </div>
            <p id="operation-action-feedback" class="modal-feedback"></p>
        ` : '';

        detailContent.innerHTML = `
            <p class="muted">Számla ${escapeHtml(inv.invoice_number || '— (még nincs kiállítva)')} · ${providerBadge(inv.provider)} ${operationTypeBadge(inv.invoice_type)} ${statusBadge(inv.status)}</p>
            <p class="muted" style="font-size:12px;">${escapeHtml(statusDetailText(inv.status))}</p>
            <p style="margin-top:10px;">Nettó: ${fmt(inv.net_total)} · ÁFA: ${fmt(inv.vat_total)} · <strong>Bruttó: ${fmt(inv.gross_total)}</strong>${inv.issued_at ? ` · Kiállítva: ${escapeHtml(inv.issued_at)}` : ''}</p>
            ${navBlock}
            ${szamlazzBlock}
            ${operationButtons}
            ${renderRelatedChain(data.original, data.operations || [], inv.id)}

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

        const opRetryBtn = document.getElementById('szamlazz-op-retry-btn');
        if (opRetryBtn) {
            opRetryBtn.addEventListener('click', async () => {
                opRetryBtn.disabled = true;
                const feedback = document.getElementById('szamlazz-op-retry-feedback');
                feedback.textContent = 'Újrapróbálkozás...';
                feedback.className = 'modal-feedback';
                try {
                    const retryRes = await fetch('/api/szamlazz-operation-retry.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: inv.id }),
                    });
                    const retryData = await retryRes.json();
                    if (!retryRes.ok) throw new Error(retryData.error || 'ismeretlen hiba');
                    feedback.textContent = 'Sikeres.';
                    feedback.className = 'modal-feedback';
                    openDetail(inv.id);
                    loadInvoices();
                } catch (err) {
                    feedback.textContent = 'Hiba: ' + err.message;
                    feedback.className = 'modal-feedback error';
                    opRetryBtn.disabled = false;
                }
            });
        }

        // Kapcsolódó számlák láncán belüli navigáció.
        detailContent.querySelectorAll('[data-chain-id]').forEach(row => {
            const targetId = Number(row.dataset.chainId);
            if (targetId === inv.id) return;
            row.addEventListener('click', () => openDetail(targetId));
        });

        const stornoBtn = document.getElementById('open-storno-btn');
        if (stornoBtn) {
            stornoBtn.addEventListener('click', async () => {
                if (!confirm('Ez a művelet ÚJ, önálló pénzügyi bizonylatot hoz létre, ami teljesen érvényteleníti a jelenlegi számlát. Biztosan folytatod?')) {
                    return;
                }
                stornoBtn.disabled = true;
                if (document.getElementById('open-modify-btn')) document.getElementById('open-modify-btn').disabled = true;
                const feedback = document.getElementById('operation-action-feedback');
                feedback.textContent = 'Sztornó kezdeményezése...';
                feedback.className = 'modal-feedback';
                try {
                    const res = await fetch('/api/invoice-storno.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ original_invoice_id: inv.id }),
                    });
                    const data2 = await res.json();
                    if (!res.ok) throw new Error(data2.error || 'ismeretlen hiba');
                    feedback.textContent = data2.result && data2.result.pending
                        ? 'Sztornó beütemezve — a háttér-worker feldolgozza.'
                        : 'Sztornó számla kiállítva: ' + (data2.result.invoice_number || '');
                    feedback.className = 'modal-feedback';
                    openDetail(inv.id);
                    loadInvoices();
                } catch (err) {
                    feedback.textContent = 'Hiba: ' + err.message;
                    feedback.className = 'modal-feedback error';
                    stornoBtn.disabled = false;
                    if (document.getElementById('open-modify-btn')) document.getElementById('open-modify-btn').disabled = false;
                }
            });
        }

        const modifyBtn = document.getElementById('open-modify-btn');
        if (modifyBtn) {
            modifyBtn.addEventListener('click', () => openModifyModal(inv, sale));
        }
    } catch (err) {
        detailContent.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

// ---- Módosító (helyesbítő) számla — tételszerkesztő modal ----

let modifyOperationUuid = null;

function openModifyModal(inv, sale) {
    // EGY stabil operation_uuid a modal MEGNYITÁSAKOR — retry/dupla-
    // kattintás (a modal ÚJRA-küldése) UGYANAZT küldi újra, hogy a
    // szerver-oldali idempotencia (operation_key) ténylegesen védjen
    // (lásd InvoiceService::requestModification() docblockja).
    modifyOperationUuid = (crypto.randomUUID ? crypto.randomUUID() : ('op-' + Date.now() + '-' + Math.random().toString(36).slice(2)));

    // Előtöltés a TÉNYLEGESEN kiszámlázott tartalomból (inv.payload_json —
    // lásd Database::upsertInvoiceMirror()/insertQueuedInvoice() 1.1.0
    // payload paramétere), NEM a sale.items-ből — a kettő eltérhet (pl.
    // kedvezmény-arányosítás a számlázási tételeken, lásd sale.php
    // invoiceDiscountRatio), a helyesbítésnek a VALÓDI számla-tartalomból
    // kell kiindulnia. Régi (payload_json nélküli) számláknál sale.items a
    // tartalék.
    let payload = null;
    try { payload = inv.payload_json ? JSON.parse(inv.payload_json) : null; } catch (e) { payload = null; }
    const items = payload && payload.items ? payload.items : (sale && sale.items ? sale.items.map(i => ({
        name: i.name, qty: i.qty, unit_price_gross: i.unit_price, vat_rate: i.vat_rate,
    })) : []);
    const prefillBuyer = payload && payload.buyer ? payload.buyer : { nev: sale && sale.buyer_name ? sale.buyer_name : '', irsz: '', telepules: '', cim: '', adoszam: null };

    modifyContent.innerHTML = `
        <div id="modify-items"></div>
        <button type="button" class="btn btn-secondary" id="modify-add-item-btn" style="margin-top:8px;">+ Tétel hozzáadása</button>
        <p class="muted" style="margin-top:14px; margin-bottom:4px;">Vevő adatai</p>
        <input type="text" id="modify-buyer-nev" placeholder="Név" value="${escapeHtml(prefillBuyer.nev || '')}">
        <div style="display:flex; gap:8px; margin-top:6px;">
            <input type="text" id="modify-buyer-irsz" placeholder="Irányítószám" value="${escapeHtml(prefillBuyer.irsz || '')}" style="flex:1;">
            <input type="text" id="modify-buyer-telepules" placeholder="Település" value="${escapeHtml(prefillBuyer.telepules || '')}" style="flex:2;">
        </div>
        <input type="text" id="modify-buyer-cim" placeholder="Cím" value="${escapeHtml(prefillBuyer.cim || '')}" style="margin-top:6px;">
        <input type="text" id="modify-buyer-adoszam" placeholder="Adószám (opcionális)" value="${escapeHtml(prefillBuyer.adoszam || '')}" style="margin-top:6px;">
    `;

    const itemsWrap = document.getElementById('modify-items');
    const renderItemRow = (item) => {
        const row = document.createElement('div');
        row.className = 'modify-item-row';
        row.style.cssText = 'display:flex; gap:6px; margin-top:6px; align-items:center;';
        row.innerHTML = `
            <input type="text" class="mi-name" placeholder="Megnevezés" value="${escapeHtml(item.name || '')}" style="flex:3;">
            <input type="number" class="mi-qty" placeholder="Menny." value="${escapeHtml(String(item.qty ?? 1))}" step="any" style="flex:1;">
            <input type="number" class="mi-price" placeholder="Bruttó egységár" value="${escapeHtml(String(item.unit_price_gross ?? 0))}" step="any" style="flex:1;">
            <input type="text" class="mi-vat" placeholder="ÁFA %" value="${escapeHtml(String(item.vat_rate ?? '27'))}" style="flex:1;">
            <button type="button" class="btn btn-secondary mi-remove" style="width:auto; padding:0 10px;">✕</button>
        `;
        row.querySelector('.mi-remove').addEventListener('click', () => row.remove());
        itemsWrap.appendChild(row);
    };
    (items.length ? items : [{ name: '', qty: 1, unit_price_gross: 0, vat_rate: '27' }]).forEach(renderItemRow);

    document.getElementById('modify-add-item-btn').addEventListener('click', () => renderItemRow({ name: '', qty: 1, unit_price_gross: 0, vat_rate: '27' }));

    modifyModalFeedback.textContent = '';
    modifyModalFeedback.className = 'modal-feedback';
    modifyModalSubmit.disabled = false;
    modifyModal.classList.add('open');

    modifyModalSubmit.onclick = async () => {
        const itemRows = Array.from(itemsWrap.querySelectorAll('.modify-item-row')).map(row => ({
            name: row.querySelector('.mi-name').value.trim(),
            qty: Number(row.querySelector('.mi-qty').value) || 0,
            unit_price_gross: Number(row.querySelector('.mi-price').value) || 0,
            vat_rate: row.querySelector('.mi-vat').value.trim(),
        }));
        if (itemRows.length === 0 || itemRows.some(i => !i.name || i.qty === 0)) {
            modifyModalFeedback.textContent = 'Minden tételnek legyen megnevezése és nem-nulla mennyisége.';
            modifyModalFeedback.className = 'modal-feedback error';
            return;
        }
        const buyer = {
            nev: document.getElementById('modify-buyer-nev').value.trim(),
            irsz: document.getElementById('modify-buyer-irsz').value.trim(),
            telepules: document.getElementById('modify-buyer-telepules').value.trim(),
            cim: document.getElementById('modify-buyer-cim').value.trim(),
            adoszam: document.getElementById('modify-buyer-adoszam').value.trim() || null,
        };
        if (!buyer.nev || !buyer.irsz || !buyer.telepules || !buyer.cim) {
            modifyModalFeedback.textContent = 'A vevő nevének, irányítószámának, településének és címének megadása kötelező.';
            modifyModalFeedback.className = 'modal-feedback error';
            return;
        }

        if (!confirm('Helyesbítő számla kiállítása a megadott adatokkal — az eredeti számla változatlanul megmarad. Folytatod?')) {
            return;
        }

        modifyModalSubmit.disabled = true;
        modifyModalFeedback.textContent = 'Kiállítás...';
        modifyModalFeedback.className = 'modal-feedback';
        try {
            const res = await fetch('/api/invoice-modify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    original_invoice_id: inv.id,
                    items: itemRows,
                    buyer,
                    operation_uuid: modifyOperationUuid,
                }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
            modifyModalFeedback.textContent = data.result && data.result.pending
                ? 'Helyesbítő számla beütemezve — a háttér-worker feldolgozza.'
                : 'Helyesbítő számla kiállítva: ' + (data.result.invoice_number || '');
            modifyModalFeedback.className = 'modal-feedback';
            setTimeout(() => {
                modifyModal.classList.remove('open');
                openDetail(inv.id);
                loadInvoices();
            }, 900);
        } catch (err) {
            modifyModalFeedback.textContent = 'Hiba: ' + err.message;
            modifyModalFeedback.className = 'modal-feedback error';
            modifyModalSubmit.disabled = false;
        }
    };
}

modifyModalCancel.addEventListener('click', () => modifyModal.classList.remove('open'));

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
