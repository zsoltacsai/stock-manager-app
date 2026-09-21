const fmtHuf = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

function statBox(value, label, tone) {
    const cls = tone ? ` ${tone}` : '';
    return `<div class="stat-box${cls}"><div class="value">${value}</div><div class="label">${label}</div></div>`;
}

function changeLine(pct) {
    if (pct === null || pct === undefined) return '';
    const sign = pct > 0 ? '+' : '';
    const color = pct > 0 ? 'var(--accent)' : (pct < 0 ? 'var(--danger)' : 'var(--muted)');
    return `<div style="font-size:11px; color:${color}; margin-top:2px;">${sign}${pct}% tegnaphoz képest</div>`;
}

function statBoxWithChange(value, label, changePct, tone) {
    const cls = tone ? ` ${tone}` : '';
    return `<div class="stat-box${cls}"><div class="value">${value}</div><div class="label">${label}</div>${changeLine(changePct)}</div>`;
}

const SYSTEM_STATUS_DISPLAY = {
    ok: { dot: '🟢', color: 'var(--accent)' },
    warning: { dot: '🟠', color: 'var(--warn)' },
    error: { dot: '🔴', color: 'var(--danger)' },
};

function renderSystemStatus(status) {
    const box = document.getElementById('dash-system-status');
    const display = SYSTEM_STATUS_DISPLAY[status.level] || SYSTEM_STATUS_DISPLAY.ok;
    box.innerHTML = `<span style="display:inline-flex; align-items:center; gap:8px; font-size:14px; font-weight:600; color:${display.color};">${display.dot} ${escapeHtml(status.label)}</span>`;
}

function renderHeader(dateInfo) {
    document.getElementById('dash-date').textContent = dateInfo.formatted;
    document.getElementById('dash-nameday').textContent = dateInfo.name_day ? 'Névnap: ' + dateInfo.name_day.split(',')[0].trim() : '';
}

function renderKpis(data) {
    const cmp = data.today_vs_yesterday;
    document.getElementById('dash-kpi-stats').innerHTML = [
        statBoxWithChange(fmtHuf(data.today.revenue_gross), 'Mai forgalom', cmp.revenue_change_pct),
        statBoxWithChange(data.today.sales_count, 'Mai eladások száma', cmp.sales_count_change_pct),
        statBoxWithChange(fmtHuf(data.today.avg_sale_gross), 'Átlagos kosárérték', cmp.avg_sale_change_pct),
        statBox(fmtHuf(data.today.purchase_gross), 'Mai beszerzés'),
        // 1.3.0 — a kör 7. pontja szerint LEGFELJEBB 1-2 készletérték-KPI
        // fér el a Dashboardon (nincs teljes valuation-bontás itt, csak
        // ez az egy szám — a részletek a Készlet riportban).
        statBox(fmtHuf(data.inventory.stock_value_net), 'Készletérték (beszerzési áron)'),
    ].join('');
}

const HEALTH_STATUS_DISPLAY = {
    ok: { dot: '🟢', color: 'var(--accent)' },
    warning: { dot: '🟠', color: 'var(--warn)' },
    error: { dot: '🔴', color: 'var(--danger)' },
    not_configured: { dot: '⚪', color: 'var(--muted)' },
    unknown: { dot: '⚪', color: 'var(--muted)' },
};
const HEALTH_COMPONENT_LABELS = {
    database: 'Adatbázis', backup: 'Backup', woocommerce: 'WooCommerce', nav: 'NAV',
    szamlazz: 'Számlázz.hu', smtp: 'Email (SMTP)', printer: 'Nyomtató', updater: 'Frissítés',
};

// 1.4.0 — kompakt Dashboard-widget (lásd a kör 5. pontja: "Ne legyen nagy")
// — csak a ténylegesen konfigurált (NEM not_configured) komponenseket
// mutatja, PLUSZ mindig az adatbázist. A teljes, minden komponenst felsoroló
// nézet a Rendszerállapot oldalon van.
function renderHealthComponents(components) {
    const list = document.getElementById('dash-health-list');
    const entries = Object.entries(components || {}).filter(([key, c]) => key === 'database' || c.status !== 'not_configured');
    if (!entries.length) {
        list.innerHTML = '<p class="muted" style="margin:0;">Nincs elérhető állapotadat.</p>';
        return;
    }
    list.innerHTML = entries.map(([key, c]) => {
        const display = HEALTH_STATUS_DISPLAY[c.status] || HEALTH_STATUS_DISPLAY.unknown;
        const label = HEALTH_COMPONENT_LABELS[key] || key;
        return `<div style="display:flex; align-items:center; gap:8px; padding:4px 0; font-size:13px;">
            <span style="color:${display.color};">${display.dot}</span>
            <strong>${escapeHtml(label)}</strong>
            <span class="muted">— ${escapeHtml(c.message || '')}</span>
        </div>`;
    }).join('');
}

function renderAttention(items) {
    const card = document.getElementById('dash-attention-card');
    const list = document.getElementById('dash-attention-list');
    if (!items.length) {
        list.innerHTML = '<p class="muted" style="margin:0;">Nincs olyan tétel, ami most figyelmet igényelne. 🎉</p>';
        return;
    }
    list.innerHTML = items.map(item => `
        <a href="${escapeHtml(item.link)}" class="attention-row" style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); text-decoration:none; color:inherit;">
            <span class="stock-badge warn" style="flex-shrink:0;">${item.count}</span>
            <span>${escapeHtml(item.label)}</span>
        </a>
    `).join('');
    const rows = list.querySelectorAll('.attention-row');
    if (rows.length) rows[rows.length - 1].style.borderBottom = 'none';
}

function renderTodayStatus(data) {
    const box = document.getElementById('dash-today-status');
    const s = data.today_status;
    const closingBadge = s.closing_done
        ? '<span class="stock-badge ok">Lezárva</span>'
        : '<span class="stock-badge warn">Még nincs lezárva</span>';
    // Csak akkor jelenik meg, ha a bolt ténylegesen használ pénztárgép-
    // nyilvántartást (van felvéve legalább egy) — egy azt nem használó
    // boltnál ez a sor egyszerűen hiányzik, ugyanaz a "csendben rejtve
    // marad, ha nem releváns" elv, mint a telephely-választónál (app.js).
    const cashRow = s.cash_registers_total > 0
        ? `<p style="margin-bottom:0;">Kassza: <span class="stock-badge ${s.cash_sessions_open > 0 ? 'ok' : 'warn'}">${s.cash_sessions_open} / ${s.cash_registers_total} nyitva</span> <a href="kassza-riport.php" class="muted">(kassza-riport)</a></p>`
        : '';
    box.innerHTML = `
        <p>Napi zárás: ${closingBadge} <a href="zaras.php" class="muted">(Napi zárás megnyitása)</a></p>
        <p>Webshop rendelés feldolgozás alatt: <strong>${s.webshop_draft_count}</strong>${s.webshop_draft_count > 0 ? ' <a href="beerkezo-eladasok.php" class="muted">(megtekintés)</a>' : ''}</p>
        <p${cashRow ? '' : ' style="margin-bottom:0;"'}>Sikertelen számla (7 nap): <strong style="${s.invoice_failures_7d > 0 ? 'color:var(--danger);' : ''}">${s.invoice_failures_7d}</strong></p>
        ${cashRow}
    `;
}

function renderTopProducts(products) {
    const box = document.getElementById('dash-top-products');
    box.innerHTML = products.length
        ? `<div class="sample-table-wrap"><table class="sample-table">
            <thead><tr><th>Termék</th><th>Eladott mennyiség</th></tr></thead>
            <tbody>${products.map(p => `<tr><td>${escapeHtml(p.name)}</td><td>${p.qty} db</td></tr>`).join('')}</tbody>
        </table></div>`
        : '<p class="muted" style="margin:0;">Ma még nem volt eladás.</p>';
}

function renderPaymentMethods(methods) {
    const box = document.getElementById('dash-payment-methods');
    const entries = Object.entries(methods || {});
    box.innerHTML = entries.length
        ? entries.map(([name, row]) => `
            <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border);">
                <span>${escapeHtml(name)}</span>
                <span class="muted">${row.count} db · ${fmtHuf(row.total)} · ${row.percent}%</span>
            </div>
        `).join('')
        : '<p class="muted" style="margin:0;">Ma még nem volt eladás.</p>';
}

async function loadDashboard() {
    const errorBoxes = ['dash-kpi-stats', 'dash-health-list', 'dash-attention-list', 'dash-today-status', 'dash-top-products', 'dash-payment-methods'];
    try {
        const data = await fetchJson('/api/dashboard-summary.php?period=today');
        document.title = 'FountainTrade — Dashboard';
        renderHeader(data.date);
        renderSystemStatus(data.system_status);
        renderHealthComponents(data.system_components);
        renderKpis(data);
        renderAttention(data.attention);
        renderTodayStatus(data);
        renderTopProducts(data.today_top_products);
        renderPaymentMethods(data.today_payment_methods);
    } catch (err) {
        document.getElementById('dash-date').textContent = 'Dashboard';
        document.getElementById('dash-nameday').textContent = '';
        errorBoxes.forEach(id => {
            document.getElementById(id).innerHTML = `<p class="feedback error">Hiba a betöltés során: ${escapeHtml(err.message)}</p>`;
        });
    }
}

function renderBarChart(trend) {
    if (!trend.length) return '<p class="muted">Nincs még adat.</p>';
    const width = 700, height = 180, paddingBottom = 30, paddingTop = 10, barGap = 4;
    const barWidth = Math.max(4, (width / trend.length) - barGap);
    const maxTotal = Math.max(1, ...trend.map(d => d.total));

    let bars = '';
    trend.forEach((d, i) => {
        const barHeight = (d.total / maxTotal) * (height - paddingTop - paddingBottom);
        const x = i * (barWidth + barGap);
        const y = height - paddingBottom - barHeight;
        const dateLabel = d.date.slice(5).replace('-', '.');
        bars += `<rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(1, barHeight)}" fill="var(--accent)" rx="2"><title>${d.date}: ${fmtHuf(d.total)} (${d.count} eladás)</title></rect>`;
        bars += `<text x="${x + barWidth / 2}" y="${height - 10}" font-size="14" fill="var(--muted)" text-anchor="middle">${dateLabel}</text>`;
    });
    return `<svg viewBox="0 0 ${width} ${height}" style="width:100%; height:auto; font-family:inherit;">${bars}</svg>`;
}

async function loadRevenueChart() {
    const box = document.getElementById('dash-revenue-chart');
    box.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    try {
        const data = await fetchJson('/api/revenue-trend.php?days=7');
        const trend = data.trend || [];
        const weekTotal = trend.reduce((s, d) => s + d.total, 0);
        const todayTotal = trend.length ? trend[trend.length - 1].total : 0;
        const yesterdayTotal = trend.length > 1 ? trend[trend.length - 2].total : 0;
        const change = yesterdayTotal > 0 ? Math.round(((todayTotal - yesterdayTotal) / yesterdayTotal) * 1000) / 10 : null;
        box.innerHTML = `
            <p class="muted" style="margin-top:0;">
                7 nap összesen: <strong>${fmtHuf(weekTotal)}</strong> · Ma: <strong>${fmtHuf(todayTotal)}</strong>
                ${change !== null ? ` · ${change > 0 ? '+' : ''}${change}% tegnaphoz képest` : ''}
            </p>
            ${renderBarChart(trend)}
        `;
    } catch (err) {
        box.innerHTML = `<p class="feedback error">A trend betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

loadDashboard();
loadRevenueChart();
