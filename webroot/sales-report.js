const fmtHuf = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

const periodSelect = document.getElementById('sr-period');
const dateFromInput = document.getElementById('sr-date-from');
const dateToInput = document.getElementById('sr-date-to');
const paymentSelect = document.getElementById('sr-payment-method');
const groupSelect = document.getElementById('sr-top-group');

function toggleCustomRange() {
    const isCustom = periodSelect.value === 'custom';
    document.getElementById('sr-custom-from').classList.toggle('hidden', !isCustom);
    document.getElementById('sr-custom-to').classList.toggle('hidden', !isCustom);
}
periodSelect.addEventListener('change', toggleCustomRange);
toggleCustomRange();

function buildPeriodQuery() {
    if (periodSelect.value === 'custom') {
        if (!dateFromInput.value || !dateToInput.value) return null;
        return `period=custom&date_from=${encodeURIComponent(dateFromInput.value)}&date_to=${encodeURIComponent(dateToInput.value)}`;
    }
    return `period=${encodeURIComponent(periodSelect.value)}`;
}

async function loadPaymentMethods() {
    try {
        const data = await fetchJson('/api/settings.php');
        const methods = data.payment_methods || [];
        paymentSelect.innerHTML = '<option value="">Összes</option>' + methods.map(m => `<option value="${escapeHtml(m.value)}">${escapeHtml(m.value)}</option>`).join('');
    } catch (e) { /* a szűrő ilyenkor csak "Összes"-t mutat — nem blokkoló */ }
}

async function loadGroups() {
    try {
        const data = await fetchJson('/api/products.php');
        const groups = Array.from(new Set((data.products || []).map(p => p.group_name).filter(Boolean))).sort();
        groupSelect.innerHTML = '<option value="">Összes</option>' + groups.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
    } catch (e) { /* nem blokkoló */ }
}

async function loadReport() {
    const q = buildPeriodQuery();
    if (q === null) return;
    const pm = paymentSelect.value ? `&payment_method=${encodeURIComponent(paymentSelect.value)}` : '';

    document.getElementById('sr-totals').innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    try {
        const data = await fetchJson(`/api/sales-report.php?${q}${pm}`);
        renderReport(data.report);
        document.getElementById('sr-export-csv').href = `/api/export-sales-report-csv.php?${q}${pm}`;
    } catch (err) {
        document.getElementById('sr-totals').innerHTML = `<p class="feedback error">A riport betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

function renderReport(r) {
    document.getElementById('sr-totals').innerHTML = [
        `<div class="stat-box"><div class="value">${fmtHuf(r.total_gross)}</div><div class="label">Bruttó forgalom</div></div>`,
        `<div class="stat-box"><div class="value">${fmtHuf(r.total_net)}</div><div class="label">Nettó forgalom</div></div>`,
        `<div class="stat-box"><div class="value">${r.sales_count}</div><div class="label">Eladások száma</div></div>`,
        `<div class="stat-box"><div class="value">${fmtHuf(r.avg_sale_gross)}</div><div class="label">Átlagos kosárérték</div></div>`,
        `<div class="stat-box"><div class="value">${fmtHuf(r.total_returns)}</div><div class="label">Visszáru</div></div>`,
    ].join('');

    const paymentEntries = Object.entries(r.by_payment_method);
    document.getElementById('sr-payment-body').innerHTML = paymentEntries.length
        ? paymentEntries.map(([method, row]) => `
            <tr><td>${escapeHtml(method)}</td><td>${row.count}</td><td>${fmtHuf(row.total)}</td><td>${row.percent}%</td></tr>
        `).join('')
        : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs adat ebben az időszakban.</td></tr>';

    document.getElementById('sr-daily-body').innerHTML = r.by_day.length
        ? r.by_day.map(row => `
            <tr><td>${row.date}</td><td>${fmtHuf(row.gross)}</td><td>${fmtHuf(row.net)}</td><td>${row.count}</td></tr>
        `).join('')
        : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs adat ebben az időszakban.</td></tr>';
}

async function loadTopProducts() {
    const q = buildPeriodQuery();
    if (q === null) return;
    const group = groupSelect.value ? `&group=${encodeURIComponent(groupSelect.value)}` : '';
    const minQty = `&min_qty=${encodeURIComponent(document.getElementById('sr-top-min-qty').value || 0)}`;

    const body = document.getElementById('sr-top-body');
    body.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Betöltés...</td></tr>';
    try {
        const data = await fetchJson(`/api/top-products-report.php?${q}${group}${minQty}`);
        body.innerHTML = data.products.length
            ? data.products.map(p => `
                <tr><td>${escapeHtml(p.name)}</td><td>${escapeHtml(p.group_name || '')}</td><td>${p.qty}</td><td>${fmtHuf(p.revenue)}</td></tr>
            `).join('')
            : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs találat.</td></tr>';
    } catch (err) {
        body.innerHTML = `<tr><td colspan="4" class="feedback error">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

document.getElementById('sr-apply').addEventListener('click', () => { loadReport(); loadTopProducts(); });
document.getElementById('sr-top-apply').addEventListener('click', loadTopProducts);

loadPaymentMethods();
loadGroups();
loadReport();
loadTopProducts();
