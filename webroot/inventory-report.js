const fmtHuf = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

function forecastLabel(f) {
    if (!f) return '<span class="muted">—</span>';
    switch (f.status) {
        case 'out_of_stock': return '<span class="stock-badge zero">Elfogyott</span>';
        case 'insufficient_data': return '<span class="muted">Nincs elegendő adat</span>';
        case 'zero_consumption': return '<span class="muted">Jelenleg nem fogy</span>';
        case 'ok': return `<span>${f.estimated_days_remaining} nap múlva fogyhat el</span>`;
        default: return '<span class="muted">—</span>';
    }
}

async function loadOverview() {
    const statsBox = document.getElementById('ir-overview-stats');
    const topBody = document.getElementById('ir-top-value-body');
    try {
        const data = await fetchJson('/api/inventory-report.php');
        const o = data.overview;
        statsBox.innerHTML = [
            `<div class="stat-box"><div class="value">${o.total_products}</div><div class="label">Aktív termék</div></div>`,
            `<div class="stat-box"><div class="value">${o.in_stock}</div><div class="label">Készleten</div></div>`,
            `<div class="stat-box${o.zero_stock > 0 ? ' warn' : ''}"><div class="value">${o.zero_stock}</div><div class="label">Nulla készlet</div></div>`,
            `<div class="stat-box${o.negative_stock > 0 ? ' danger' : ''}"><div class="value">${o.negative_stock}</div><div class="label">Negatív készlet</div></div>`,
            `<div class="stat-box${o.low_stock > 0 ? ' warn' : ''}"><div class="value">${o.low_stock}</div><div class="label">Alacsony készlet</div></div>`,
            `<div class="stat-box"><div class="value">${fmtHuf(o.stock_value_net)}</div><div class="label">Készletérték (nettó)</div></div>`,
        ].join('');

        topBody.innerHTML = o.top_by_value.length
            ? o.top_by_value.map(p => `
                <tr><td>${escapeHtml(p.name)}</td><td>${p.stock_qty} db</td><td>${fmtHuf(p.purchase_price_net)}</td><td>${fmtHuf(p.value)}</td></tr>
            `).join('')
            : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs adat.</td></tr>';

        renderValuation(data.valuation);
    } catch (err) {
        statsBox.innerHTML = `<p class="feedback error">A készletriport betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

// 1.3.0 — a kör 7. pontja: készletérték nettó beszerzési ÉS eladási áron,
// plusz a potenciális árrés-érték (lásd Database::getInventoryValuationSummary()
// docblockja) — csak a megbízható beszerzési árú termékekből.
function renderValuation(v) {
    document.getElementById('ir-valuation-stats').innerHTML = [
        `<div class="stat-box"><div class="value">${fmtHuf(v.cost_value_net)}</div><div class="label">Készletérték (nettó beszerzési áron)</div></div>`,
        `<div class="stat-box"><div class="value">${fmtHuf(v.retail_value_net)}</div><div class="label">Készletérték (nettó eladási áron)</div></div>`,
        `<div class="stat-box"><div class="value">${fmtHuf(v.potential_margin_value_net)}</div><div class="label">Potenciális árrés-érték</div></div>`,
    ].join('');

    const note = document.getElementById('ir-valuation-note');
    const unreliable = v.products_total - v.products_with_reliable_cost;
    note.textContent = unreliable > 0
        ? `${unreliable} készleten lévő termékhez nincs megbízható beszerzési ár (sose lett még beszerezve) — a beszerzési áras érték és a potenciális árrés-érték CSAK a fennmaradó ${v.products_with_reliable_cost} termékre vonatkozik. Az eladási áras érték minden termékre számol.`
        : '';
}

async function loadLowStock() {
    const filter = document.getElementById('ir-filter').value;
    const body = document.getElementById('ir-low-stock-body');
    body.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center; padding:16px;">Betöltés...</td></tr>';
    try {
        const data = await fetchJson(`/api/low-stock-report.php?filter=${encodeURIComponent(filter)}`);
        body.innerHTML = data.products.length
            ? data.products.map(p => `
                <tr>
                    <td>${escapeHtml(p.name)}${p.barcode ? ' <span class="muted">(' + escapeHtml(p.barcode) + ')</span>' : ''}</td>
                    <td>${escapeHtml(p.group_name || '')}</td>
                    <td>${p.stock_qty} db</td>
                    <td>${p.threshold} db</td>
                    <td>${p.suggested_qty} db</td>
                    <td>${forecastLabel(p.forecast)}</td>
                    <td>${escapeHtml(p.supplier_name || '')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="7" class="muted" style="text-align:center; padding:16px;">Nincs találat.</td></tr>';
        document.getElementById('ir-export-csv').href = `/api/export-low-stock-csv.php?filter=${encodeURIComponent(filter)}`;
    } catch (err) {
        body.innerHTML = `<tr><td colspan="7" class="feedback error">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

document.getElementById('ir-filter').addEventListener('change', loadLowStock);

loadOverview();
loadLowStock();
