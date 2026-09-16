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
    ].join('');
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
    box.innerHTML = `
        <p>Napi zárás: ${closingBadge} <a href="zaras.php" class="muted">(Napi zárás megnyitása)</a></p>
        <p>Webshop rendelés feldolgozás alatt: <strong>${s.webshop_draft_count}</strong>${s.webshop_draft_count > 0 ? ' <a href="beerkezo-eladasok.php" class="muted">(megtekintés)</a>' : ''}</p>
        <p style="margin-bottom:0;">Sikertelen számla (7 nap): <strong style="${s.invoice_failures_7d > 0 ? 'color:var(--danger);' : ''}">${s.invoice_failures_7d}</strong></p>
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
    const errorBoxes = ['dash-kpi-stats', 'dash-attention-list', 'dash-today-status', 'dash-top-products', 'dash-payment-methods'];
    try {
        const data = await fetchJson('/api/dashboard-summary.php?period=today');
        document.title = 'FountainTrade — Dashboard';
        renderHeader(data.date);
        renderSystemStatus(data.system_status);
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
