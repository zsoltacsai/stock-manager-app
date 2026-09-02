const dateInput = document.getElementById('zaras-date');
const refreshBtn = document.getElementById('zaras-refresh-btn');
const printBtn = document.getElementById('zaras-print-btn');
const exportBtn = document.getElementById('zaras-export-btn');
const heading = document.getElementById('zaras-heading');
const statsGrid = document.getElementById('zaras-stats');
const paymentTableBody = document.querySelector('#zaras-payment-table tbody');
const vatTableBody = document.querySelector('#zaras-vat-table tbody');
const transactionsBody = document.querySelector('#zaras-transactions-table tbody');
const closingStatus = document.getElementById('zaras-closing-status');
const closeBtn = document.getElementById('zaras-close-btn');
const closeFeedback = document.getElementById('zaras-close-feedback');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

function paymentBadge(method) {
    const map = {
        'Készpénz': 'pm-cash', 'Bankkártya': 'pm-card', 'Átutalás': 'pm-transfer',
        'PayPal': 'pm-paypal', 'Utánvét': 'pm-cod',
    };
    const cls = map[method] || 'pm-other';
    return `<span class="pm-badge ${cls}">${method}</span>`;
}

function todayLocalISO() {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const local = new Date(now.getTime() - offset * 60000);
    return local.toISOString().slice(0, 10);
}

dateInput.value = todayLocalISO();

async function loadSummary() {
    const date = dateInput.value;
    heading.textContent = `Forgalmi összesítő — ${date}`;

    try {
        const res = await fetch('/api/daily-summary.php?date=' + encodeURIComponent(date));
        const data = await res.json();
        if (!res.ok) {
            statsGrid.innerHTML = `<div class="feedback error">Hiba: ${escapeHtml(data.error || 'ismeretlen hiba')}</div>`;
            return;
        }
        renderSummary(data.summary);
        renderClosing(data.closing);
    } catch (err) {
        statsGrid.innerHTML = `<div class="feedback error">Hiba: ${escapeHtml(err.message)}</div>`;
    }
}

function renderSummary(summary) {
    statsGrid.innerHTML = `
        <div class="stat-box"><div class="value">${summary.sales_count}</div><div class="label">Eladások száma</div></div>
        <div class="stat-box"><div class="value">${fmt(summary.total_gross)}</div><div class="label">Bruttó forgalom</div></div>
        <div class="stat-box"><div class="value">${fmt(summary.total_net)}</div><div class="label">Nettó forgalom</div></div>
        <div class="stat-box"><div class="value">${fmt(summary.total_vat)}</div><div class="label">ÁFA összesen</div></div>
    `;

    const paymentRows = Object.entries(summary.by_payment_method);
    paymentTableBody.innerHTML = paymentRows.length
        ? paymentRows.map(([method, row]) => `
            <tr><td>${method}</td><td>${row.count}</td><td>${fmt(row.total)}</td></tr>
        `).join('')
        : '<tr><td colspan="3" class="muted">Nincs adat erre a napra.</td></tr>';

    const vatRows = Object.entries(summary.by_vat_rate);
    vatTableBody.innerHTML = vatRows.length
        ? vatRows.map(([rate, row]) => `
            <tr>
                <td>${isNaN(parseFloat(rate)) ? rate : rate + '%'}</td>
                <td>${fmt(row.net)}</td>
                <td>${fmt(row.vat)}</td>
                <td>${fmt(row.gross)}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="4" class="muted">Nincs adat erre a napra.</td></tr>';

    transactionsBody.innerHTML = summary.sales.length
        ? summary.sales.map(sale => {
            const time = (sale.created_at || '').slice(11, 16) || sale.created_at;
            const invoiceLabel = sale.status === 'invoice_failed'
                ? 'Sikertelen'
                : escapeHtml(sale.szamlazz_invoice_number || '—');
            return `
                <tr>
                    <td>#${sale.id}</td>
                    <td>${time}</td>
                    <td>${fmt(sale.total)}</td>
                    <td>${paymentBadge(sale.payment_method)}</td>
                    <td>${invoiceLabel}</td>
                    <td class="no-print"><button class="row-actions-btn" onclick="window.open('receipt.html?sale_id=${sale.id}','_blank')">Nyugta</button></td>
                </tr>
            `;
        }).join('')
        : '<tr><td colspan="6" class="muted">Nincs eladás erre a napra.</td></tr>';
}

function renderClosing(closing) {
    if (closing) {
        closingStatus.textContent =
            `Ezen a napon már történt zárás: ${closing.closed_at} — ${fmt(closing.total_gross)} (${closing.sales_count} eladás). Az újbóli rögzítés felülírja.`;
    } else {
        closingStatus.textContent = 'Ezen a napon még nem történt zárás.';
    }
}

closeBtn.addEventListener('click', async () => {
    closeBtn.disabled = true;
    closeFeedback.textContent = 'Rögzítés...';
    closeFeedback.className = 'feedback';

    try {
        const res = await fetch('/api/daily-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ date: dateInput.value }),
        });
        const data = await res.json();
        if (!res.ok) {
            closeFeedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            closeFeedback.className = 'feedback error';
            return;
        }
        closeFeedback.textContent = `Napi zárás rögzítve — ${fmt(data.closing.total_gross)}.`;
        closeFeedback.className = 'feedback ok';
        renderClosing(data.closing);
    } catch (err) {
        closeFeedback.textContent = 'Hiba: ' + err.message;
        closeFeedback.className = 'feedback error';
    } finally {
        closeBtn.disabled = false;
    }
});

refreshBtn.addEventListener('click', loadSummary);
printBtn.addEventListener('click', () => window.print());
exportBtn.addEventListener('click', () => {
    window.location.href = '/api/export-zaras-csv.php?date=' + encodeURIComponent(dateInput.value);
});

document.addEventListener('stockmanager:synced', () => loadSummary());

loadSummary();
