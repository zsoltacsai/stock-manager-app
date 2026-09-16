const fmtHuf = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';
const fmtNum = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n));

function statBox(value, label, tone) {
    const cls = tone ? ` ${tone}` : '';
    return `<div class="stat-box${cls}"><div class="value">${value}</div><div class="label">${label}</div></div>`;
}

function periodQuery() {
    const period = document.getElementById('dashboard-period-select').value;
    if (period === 'custom') {
        const from = document.getElementById('dashboard-date-from').value;
        const to = document.getElementById('dashboard-date-to').value;
        if (!from || !to) return null;
        return `period=custom&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}`;
    }
    return `period=${encodeURIComponent(period)}`;
}

const PERIOD_LABELS = {
    today: 'Ma', yesterday: 'Tegnap', last_7_days: 'Utolsó 7 nap', last_30_days: 'Utolsó 30 nap',
    this_month: 'Aktuális hónap', last_month: 'Előző hónap', custom: 'Egyedi időszak',
};

async function loadDashboard() {
    const q = periodQuery();
    if (q === null) return;

    document.getElementById('dashboard-today-stats').innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    document.getElementById('dashboard-period-stats').innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';

    try {
        const data = await fetchJson(`/api/dashboard-summary.php?${q}`);
        renderDashboard(data);
    } catch (err) {
        document.getElementById('dashboard-today-stats').innerHTML = `<p class="feedback error">A dashboard betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
        document.getElementById('dashboard-period-stats').innerHTML = '';
    }
}

function renderDashboard(data) {
    document.getElementById('dashboard-today-stats').innerHTML = [
        statBox(fmtHuf(data.today.revenue_gross), 'Mai árbevétel'),
        statBox(data.today.sales_count, 'Mai eladások száma'),
        statBox(fmtHuf(data.today.avg_sale_gross), 'Mai átlagos kosárérték'),
        statBox(fmtHuf(data.today.purchase_gross), 'Mai beszerzés'),
    ].join('');

    document.getElementById('dashboard-period-title').textContent = PERIOD_LABELS[data.period.period] || 'Kiválasztott időszak';
    document.getElementById('dashboard-period-stats').innerHTML = [
        statBox(fmtHuf(data.period_summary.revenue_gross), 'Bruttó árbevétel'),
        statBox(fmtHuf(data.period_summary.revenue_net), 'Nettó árbevétel'),
        statBox(data.period_summary.sales_count, 'Eladások száma'),
        statBox(fmtHuf(data.period_summary.avg_sale_gross), 'Átlagos kosárérték'),
    ].join('');

    document.getElementById('dashboard-inventory-stats').innerHTML = [
        statBox(data.inventory.total_products, 'Aktív termék'),
        statBox(data.inventory.low_stock, 'Alacsony készletű', data.inventory.low_stock > 0 ? 'warn' : ''),
        statBox(data.inventory.zero_stock, 'Nulla készletű', data.inventory.zero_stock > 0 ? 'warn' : ''),
        statBox(data.inventory.negative_stock, 'Negatív készletű', data.inventory.negative_stock > 0 ? 'danger' : ''),
        statBox(fmtHuf(data.inventory.stock_value_net), 'Készletérték (nettó)'),
    ].join('');

    const wcBox = document.getElementById('dashboard-wc-status');
    if (!data.woocommerce_configured) {
        wcBox.innerHTML = '<p class="muted">A WooCommerce szinkron nincs beállítva ezen a telepítésen.</p>';
    } else {
        wcBox.innerHTML = `
            <p>Várakozó: <strong>${data.woocommerce.queued + data.woocommerce.processing}</strong>
               ${data.woocommerce.failed > 0 ? ` · <span style="color:var(--danger);">Sikertelen: <strong>${data.woocommerce.failed}</strong></span>` : ''}
            </p>
        `;
    }

    const navBox = document.getElementById('dashboard-nav-status');
    if (data.nav_invoices.provider !== 'nav') {
        navBox.innerHTML = '<p class="muted">A NAV Online Számla nincs beállítva számlázási szolgáltatóként (jelenleg: Számlázz.hu).</p>';
    } else {
        navBox.innerHTML = `
            <p>Függőben: <strong>${data.nav_invoices.pending}</strong>
               ${data.nav_invoices.failed > 0 ? ` · <span style="color:var(--danger);">Sikertelen: <strong>${data.nav_invoices.failed}</strong></span>` : ''}
            </p>
        `;
    }
}

function renderBarChart(trend) {
    if (!trend.length) return '<p class="muted">Nincs még adat.</p>';
    const width = 700, height = 220, paddingBottom = 30, paddingTop = 10, barGap = 2;
    const barWidth = Math.max(2, (width / trend.length) - barGap);
    const maxTotal = Math.max(1, ...trend.map(d => d.total));
    const labelEvery = Math.ceil(trend.length / 5);

    let bars = '';
    trend.forEach((d, i) => {
        const barHeight = (d.total / maxTotal) * (height - paddingTop - paddingBottom);
        const x = i * (barWidth + barGap);
        const y = height - paddingBottom - barHeight;
        const dateLabel = d.date.slice(5).replace('-', '.');
        bars += `<rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(1, barHeight)}" fill="var(--accent)" rx="1"><title>${d.date}: ${fmtHuf(d.total)} (${d.count} eladás)</title></rect>`;
        if (i % labelEvery === 0 || i === trend.length - 1) {
            bars += `<text x="${x + barWidth / 2}" y="${height - 10}" font-size="16" fill="var(--muted)" text-anchor="middle">${dateLabel}</text>`;
        }
    });
    return `<svg viewBox="0 0 ${width} ${height}" style="width:100%; height:auto; font-family:inherit;">${bars}</svg>`;
}

async function loadRevenueChart() {
    const box = document.getElementById('dashboard-revenue-chart');
    box.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    try {
        const data = await fetchJson('/api/revenue-trend.php?days=30');
        box.innerHTML = renderBarChart(data.trend || []);
    } catch (err) {
        box.innerHTML = `<p class="feedback error">A trend betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

document.getElementById('dashboard-period-select').addEventListener('change', (e) => {
    document.getElementById('dashboard-custom-range').classList.toggle('hidden', e.target.value !== 'custom');
    if (e.target.value !== 'custom') loadDashboard();
});
document.getElementById('dashboard-custom-apply').addEventListener('click', loadDashboard);

loadDashboard();
loadRevenueChart();
