const overviewStats = document.getElementById('overview-stats');
const syncLogBody = document.getElementById('sync-log-body');

function statBox(value, label, warn) {
    return `<div class="stat-box"><div class="value" style="${warn ? 'color:var(--warn);' : ''}">${value}</div><div class="label">${label}</div></div>`;
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
    const labelEvery = Math.ceil(trend.length / 5);

    let bars = '';
    trend.forEach((d, i) => {
        const barHeight = (d.total / maxTotal) * (height - paddingTop - paddingBottom);
        const x = i * (barWidth + barGap);
        const y = height - paddingBottom - barHeight;
        const dateLabel = d.date.slice(5).replace('-', '.');
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

// -----------------------------------------------------------------
// 1.4.0 — Rendszer komponensek / Figyelmet igényel / Eseménynapló.
// Ugyanaz az 5-állapotú modell (OK/WARNING/ERROR/NOT_CONFIGURED/UNKNOWN),
// mint amit a HealthMonitor (src/HealthMonitor.php) számol — lásd ott a
// pontos definíciót minden állapotra.
// -----------------------------------------------------------------

const HEALTH_STATUS_DISPLAY = {
    ok: { dot: '🟢', label: 'OK', color: 'var(--accent)' },
    warning: { dot: '🟠', label: 'Figyelmeztetés', color: 'var(--warn)' },
    error: { dot: '🔴', label: 'Hiba', color: 'var(--danger)' },
    not_configured: { dot: '⚪', label: 'Nincs beállítva', color: 'var(--muted)' },
    unknown: { dot: '⚪', label: 'Ismeretlen', color: 'var(--muted)' },
};
const HEALTH_COMPONENT_LABELS = {
    database: 'Adatbázis', backup: 'Backup', woocommerce: 'WooCommerce', nav: 'NAV',
    szamlazz: 'Számlázz.hu', smtp: 'Email (SMTP)', printer: 'Nyomtató', updater: 'Frissítés',
};
// Melyik komponensnél van ténylegesen elérhető "Kapcsolat tesztelése"
// végpont — Számlázz.hu-nál SZÁNDÉKOSAN nincs (lásd a kör 9. pontja:
// a teszt "ne hozzon létre számlát" — a Számlázz.hu Agent API-nak nincs
// dokumentált, dokumentum-létrehozás nélküli kapcsolat-teszt művelete,
// ezért itt nincs kitalálva egy nem-létező API-hívás).
const HEALTH_TEST_ENDPOINTS = {
    woocommerce: '/api/wc-test-connection.php',
    nav: '/api/nav-test-connection.php',
    smtp: null, // az SMTP teszt valódi email-címet igényel (smtp-test.php) — lásd beallitasok.php Email fül
    printer: null, // a nyomtató-teszt IP/port konfigurációt igényel (printer-test.php) — lásd beallitasok.php Nyomtató fül
};

function renderOverallStatus(overall) {
    const box = document.getElementById('health-overall-status');
    const display = HEALTH_STATUS_DISPLAY[overall.level] || HEALTH_STATUS_DISPLAY.ok;
    box.innerHTML = `<span style="display:inline-flex; align-items:center; gap:8px; font-size:15px; font-weight:600; color:${display.color};">${display.dot} ${escapeHtml(overall.label)}</span>`;
}

function renderHealthAttention(items) {
    const card = document.getElementById('health-attention-card');
    const list = document.getElementById('health-attention-list');
    if (!items.length) {
        card.style.display = 'none';
        return;
    }
    card.style.display = '';
    list.innerHTML = items.map(item => `
        <a href="${escapeHtml(item.link)}" class="health-attention-row" style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); text-decoration:none; color:inherit;">
            <span class="stock-badge warn" style="flex-shrink:0;">${item.count}</span>
            <span>${escapeHtml(item.label)}</span>
        </a>
    `).join('');
    const rows = list.querySelectorAll('.health-attention-row');
    if (rows.length) rows[rows.length - 1].style.borderBottom = 'none';
}

function renderHealthComponents(components) {
    const box = document.getElementById('health-components-list');
    // Csak a ténylegesen konfigurált integrációk jelennek meg (lásd a kör
    // 4. pontja) — az Adatbázis kivétel, az mindig megjelenik (nem
    // "opcionális integráció", hanem alap üzemeltetési előfeltétel).
    const entries = Object.entries(components).filter(([key, c]) => key === 'database' || c.status !== 'not_configured');
    if (!entries.length) {
        box.innerHTML = '<p class="muted" style="margin:0;">Nincs elérhető állapotadat.</p>';
        return;
    }
    box.innerHTML = entries.map(([key, c]) => {
        const display = HEALTH_STATUS_DISPLAY[c.status] || HEALTH_STATUS_DISPLAY.unknown;
        const label = HEALTH_COMPONENT_LABELS[key] || key;
        const testEndpoint = HEALTH_TEST_ENDPOINTS[key];
        const testBtn = testEndpoint
            ? `<button type="button" class="btn btn-secondary health-test-btn" data-endpoint="${escapeHtml(testEndpoint)}" data-key="${escapeHtml(key)}" style="margin-left:auto; flex-shrink:0;">Kapcsolat tesztelése</button>`
            : '';
        return `
            <div style="display:flex; align-items:flex-start; flex-wrap:wrap; gap:12px; padding:12px 0; border-bottom:1px solid var(--border);">
                <span style="font-size:18px; line-height:1.4;">${display.dot}</span>
                <div style="flex:1; min-width:200px;">
                    <div style="font-weight:600;">${escapeHtml(label)} <span class="muted" style="font-weight:400;">— ${display.label}</span></div>
                    <div class="muted" style="font-size:13px; margin-top:2px;">${escapeHtml(c.message || '')}</div>
                    <div class="muted" style="font-size:12px; margin-top:2px;">
                        ${c.last_checked_at ? 'Utolsó ellenőrzés: ' + formatDate(c.last_checked_at) : ''}
                        ${c.last_success_at ? ' · Utolsó sikeres: ' + formatDate(c.last_success_at) : ''}
                    </div>
                    ${c.queue_summary ? `<div style="margin-top:4px; font-size:13px;"><a href="${escapeHtml(c.queue_summary.link)}">${escapeHtml(label)} queue: ${c.queue_summary.pending} pending / ${c.queue_summary.failed} failed</a></div>` : ''}
                    <div id="health-test-result-${escapeHtml(key)}" style="margin-top:6px;"></div>
                </div>
                ${testBtn}
            </div>
        `;
    }).join('');

    box.querySelectorAll('.health-test-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const endpoint = btn.dataset.endpoint;
            const key = btn.dataset.key;
            const resultBox = document.getElementById('health-test-result-' + key);
            btn.disabled = true;
            resultBox.innerHTML = '<span class="muted">Tesztelés...</span>';
            try {
                const csrf = (await (await fetch('/api/auth-status.php')).json()).csrf_token;
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: '{}',
                });
                const data = await res.json();
                resultBox.innerHTML = data.success
                    ? '<span class="feedback ok" style="padding:2px 0;">✓ Sikeres kapcsolat.</span>'
                    : `<span class="feedback error" style="padding:2px 0;">✗ ${escapeHtml(data.error || 'Sikertelen teszt.')}</span>`;
                // A komponens-lista frissítése (új last_checked_at) egy
                // röviddel KÉSŐBB, hogy a fenti visszajelzés ténylegesen
                // látható legyen a felhasználónak — egy azonnali
                // újrarenderelés korábban NYOMBAN felülírta/eltüntette
                // volna ezt az üzenetet (élesben megfigyelt UX-hiba).
                setTimeout(loadHealth, 2500);
            } catch (err) {
                resultBox.innerHTML = `<span class="feedback error" style="padding:2px 0;">✗ ${escapeHtml(err.message)}</span>`;
            } finally {
                btn.disabled = false;
            }
        });
    });
}

const EVENT_CATEGORY_LABELS = {
    backup: 'Backup', woocommerce: 'WooCommerce', nav: 'NAV', updater: 'Frissítés',
    printer: 'Nyomtató', smtp: 'Email', auth: 'Belépés', database: 'Adatbázis', ai: 'AI asszisztens',
};

function renderEventsLog(events) {
    const body = document.getElementById('events-log-body');
    body.innerHTML = events.length
        ? events.map(ev => {
            const display = HEALTH_STATUS_DISPLAY[ev.severity] || HEALTH_STATUS_DISPLAY.unknown;
            return `
                <tr>
                    <td class="muted">${formatDate(ev.created_at)}</td>
                    <td>${escapeHtml(EVENT_CATEGORY_LABELS[ev.category] || ev.category)}</td>
                    <td>${escapeHtml(ev.user_message)}</td>
                    <td><span style="color:${display.color};">${display.dot} ${display.label}</span></td>
                </tr>
            `;
        }).join('')
        : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Még nincs rögzített esemény.</td></tr>';
}

async function loadEvents() {
    const category = document.getElementById('events-category-filter').value;
    const severity = document.getElementById('events-severity-filter').value;
    const params = new URLSearchParams();
    if (category) params.set('category', category);
    if (severity) params.set('severity', severity);
    try {
        const res = await fetch('/api/system-events.php?' + params.toString());
        const data = await res.json();
        renderEventsLog(data.events || []);
    } catch (err) {
        document.getElementById('events-log-body').innerHTML = `<tr><td colspan="4" class="feedback error">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}
document.getElementById('events-category-filter').addEventListener('change', loadEvents);
document.getElementById('events-severity-filter').addEventListener('change', loadEvents);

async function loadHealth() {
    try {
        const res = await fetch('/api/system-health.php');
        const data = await res.json();
        renderOverallStatus(data.overall);
        renderHealthAttention(data.attention);
        renderHealthComponents(data.components);
    } catch (err) {
        document.getElementById('health-components-list').innerHTML = `<p class="feedback error">A rendszerállapot betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

async function loadOverviewStats() {
    try {
        const res = await fetch('/api/system-status.php');
        const data = await res.json();
        overviewStats.innerHTML = [
            statBox(data.products, 'Aktív árucikk'),
            statBox(data.sales_today, 'Mai eladás'),
            statBox(data.low_stock_count, 'Alacsony készletű termék', data.low_stock_count > 0),
            statBox(data.invoice_failures_7d, 'Sikertelen számla (7 nap)', data.invoice_failures_7d > 0),
            statBox(data.sync_failures_24h, 'Sync hiba (24 óra)', data.sync_failures_24h > 0),
            statBox(data.driver.toUpperCase(), 'Adatbázis'),
        ].join('');

        syncLogBody.innerHTML = data.recent_sync_log.length
            ? data.recent_sync_log.map(row => `
                <tr>
                    <td>${row.created_at}</td>
                    <td>${escapeHtml(row.direction)}</td>
                    <td>${row.product_id ?? '—'}</td>
                    <td style="${row.message && row.message.startsWith('FAILED') ? 'color:var(--danger);' : ''}">${escapeHtml(row.message || '')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Még nincs napló bejegyzés.</td></tr>';
    } catch (err) {
        overviewStats.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

loadOverviewStats();
loadHealth();
loadEvents();
loadRevenueTrend(30);
