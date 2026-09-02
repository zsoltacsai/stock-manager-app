const overviewStats = document.getElementById('overview-stats');
const wcStatus = document.getElementById('wc-status');
const backupStatus = document.getElementById('backup-status');
const configStatus = document.getElementById('config-status');
const syncLogBody = document.getElementById('sync-log-body');

function statBox(value, label, warn) {
    return `<div class="stat-box"><div class="value" style="${warn ? 'color:var(--warn);' : ''}">${value}</div><div class="label">${label}</div></div>`;
}

function badge(ok, okText, badText) {
    return ok
        ? `<span class="stock-badge ok">${okText}</span>`
        : `<span class="stock-badge warn">${badText}</span>`;
}

function formatDate(iso) {
    if (!iso) return 'még nem futott';
    return new Date(iso).toLocaleString('hu-HU');
}

function fmt(n) {
    return new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';
}

async function loadRevenueTrend(days) {
    const chartBox = document.getElementById('revenue-chart');
    chartBox.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    try {
        const res = await fetch('/api/revenue-trend.php?days=' + days);
        const data = await res.json();
        chartBox.innerHTML = renderBarChart(data.trend || []);
    } catch (e) {
        chartBox.innerHTML = '<p class="feedback error">A trend betöltése sikertelen.</p>';
    }
}

function renderBarChart(trend) {
    if (!trend.length) return '<p class="muted">Nincs még adat.</p>';

    const width = 700;
    const height = 220;
    const paddingBottom = 30;
    const paddingTop = 10;
    const barGap = 2;
    const barWidth = Math.max(2, (width / trend.length) - barGap);
    const maxTotal = Math.max(1, ...trend.map(d => d.total));
    const labelEvery = Math.ceil(trend.length / 5); // fewer labels — keeps them legible once the SVG shrinks on a narrow screen

    let bars = '';
    trend.forEach((d, i) => {
        const barHeight = (d.total / maxTotal) * (height - paddingTop - paddingBottom);
        const x = i * (barWidth + barGap);
        const y = height - paddingBottom - barHeight;
        const dateLabel = d.date.slice(5).replace('-', '.'); // MM.DD
        bars += `<rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(1, barHeight)}" fill="var(--accent)" rx="1">
            <title>${d.date}: ${fmt(d.total)} (${d.count} eladás)</title>
        </rect>`;
        if (i % labelEvery === 0 || i === trend.length - 1) {
            bars += `<text x="${x + barWidth / 2}" y="${height - 10}" font-size="16" fill="var(--muted)" text-anchor="middle">${dateLabel}</text>`;
        }
    });

    const totalSum = trend.reduce((s, d) => s + d.total, 0);
    const avgPerDay = totalSum / trend.length;

    return `
        <p class="muted" style="margin-top:0;">Összesen ebben az időszakban: <strong>${fmt(totalSum)}</strong> · napi átlag: ${fmt(avgPerDay)}</p>
        <svg viewBox="0 0 ${width} ${height}" style="width:100%; height:auto; font-family:inherit;">
            ${bars}
        </svg>
    `;
}

document.getElementById('trend-days-select').addEventListener('change', (e) => {
    loadRevenueTrend(e.target.value);
});

async function loadStatus() {
    try {
        const res = await fetch('/api/system-status.php');
        const data = await res.json();
        render(data);
    } catch (err) {
        overviewStats.innerHTML = `<p class="feedback error">A rendszerállapot betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

function render(data) {
    overviewStats.innerHTML = [
        statBox(data.products, 'Aktív árucikk'),
        statBox(data.sales_today, 'Mai eladás'),
        statBox(data.low_stock_count, 'Alacsony készletű termék', data.low_stock_count > 0),
        statBox(data.invoice_failures_7d, 'Sikertelen számla (7 nap)', data.invoice_failures_7d > 0),
        statBox(data.sync_failures_24h, 'Sync hiba (24 óra)', data.sync_failures_24h > 0),
        statBox(data.driver.toUpperCase(), 'Adatbázis'),
    ].join('');

    wcStatus.innerHTML = `
        <p>Konfigurálva: ${badge(data.wc_sync.configured, 'igen', 'nincs beállítva')}</p>
        <p>Automatikus szinkron: ${badge(data.wc_sync.auto_enabled, 'bekapcsolva', 'kikapcsolva')}</p>
        <p class="muted">Utolsó futás: ${formatDate(data.wc_sync.last_run_at)}${data.wc_sync.last_summary ? ' — ' + data.wc_sync.last_summary : ''}</p>
    `;

    backupStatus.innerHTML = `
        <p>Automatikus mentés: ${badge(data.backup.auto_enabled, 'bekapcsolva', 'kikapcsolva')}</p>
        <p>Felhő szolgáltató: <strong>${data.backup.cloud_provider === 'none' ? 'nincs' : data.backup.cloud_provider}</strong></p>
        <p class="muted">Utolsó mentés: ${formatDate(data.backup.last_run_at)}${data.backup.last_summary ? ' — ' + data.backup.last_summary : ''}</p>
    `;

    configStatus.innerHTML = `
        <p>Hálózati nyomtató: ${badge(data.printer.enabled && data.printer.ip_set, 'beállítva', 'nincs beállítva')}</p>
        <p>Számlázz.hu kulcs: ${badge(data.szamlazz_configured, 'megadva', 'hiányzik')}</p>
        <p>Törzsvásárlói pontrendszer: ${badge(data.loyalty_enabled, 'bekapcsolva', 'kikapcsolva')}</p>
        <p class="muted">PHP verzió: ${data.php_version}</p>
        <p class="muted">Stock Manager verzió: ${data.app_version}</p>
    `;

    syncLogBody.innerHTML = data.recent_sync_log.length
        ? data.recent_sync_log.map(row => `
            <tr>
                <td>${row.created_at}</td>
                <td>${row.direction}</td>
                <td>${row.product_id ?? '—'}</td>
                <td style="${row.message && row.message.startsWith('FAILED') ? 'color:var(--danger);' : ''}">${row.message || ''}</td>
            </tr>
        `).join('')
        : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Még nincs napló bejegyzés.</td></tr>';
}

loadStatus();
loadRevenueTrend(30);
