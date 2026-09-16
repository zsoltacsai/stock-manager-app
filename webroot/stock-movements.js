const TYPE_LABELS = { sale: 'Eladás', purchase: 'Beszerzés', return: 'Visszáru', stock_take: 'Leltár', transfer: 'Készlet hozzáadás' };
const PAGE_SIZE = 100;
let currentOffset = 0;

const periodSelect = document.getElementById('sm-period');
const dateFromInput = document.getElementById('sm-date-from');
const dateToInput = document.getElementById('sm-date-to');

function toggleCustomRange() {
    const isCustom = periodSelect.value === 'custom';
    document.getElementById('sm-custom-from').classList.toggle('hidden', !isCustom);
    document.getElementById('sm-custom-to').classList.toggle('hidden', !isCustom);
}
periodSelect.addEventListener('change', toggleCustomRange);
toggleCustomRange();

function buildQuery(offset) {
    let q;
    if (periodSelect.value === 'custom') {
        if (!dateFromInput.value || !dateToInput.value) return null;
        q = `period=custom&date_from=${encodeURIComponent(dateFromInput.value)}&date_to=${encodeURIComponent(dateToInput.value)}`;
    } else {
        q = `period=${encodeURIComponent(periodSelect.value)}`;
    }
    const type = document.getElementById('sm-type').value;
    const productId = document.getElementById('sm-product').value;
    if (type) q += `&type=${encodeURIComponent(type)}`;
    if (productId) q += `&product_id=${encodeURIComponent(productId)}`;
    q += `&limit=${PAGE_SIZE}&offset=${offset}`;
    return q;
}

async function loadProducts() {
    const select = document.getElementById('sm-product');
    try {
        const data = await fetchJson('/api/products.php');
        select.innerHTML = '<option value="">Összes termék</option>' + (data.products || [])
            .map(p => `<option value="${p.id}">${escapeHtml(p.name)}${p.barcode ? ' (' + escapeHtml(p.barcode) + ')' : ''}</option>`).join('');
    } catch (e) { /* nem blokkoló — a szűrő "Összes termék"-en marad */ }
}

async function loadMovements(offset) {
    const q = buildQuery(offset);
    const body = document.getElementById('sm-body');
    if (q === null) return;
    body.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:16px;">Betöltés...</td></tr>';
    try {
        const data = await fetchJson(`/api/stock-movements-report.php?${q}`);
        currentOffset = offset;
        body.innerHTML = data.movements.length
            ? data.movements.map(m => `
                <tr>
                    <td>${escapeHtml(m.date)}</td>
                    <td>${escapeHtml(m.product_name || '(törölt termék)')}</td>
                    <td>${TYPE_LABELS[m.type] || escapeHtml(m.type)}</td>
                    <td style="${m.qty_change < 0 ? 'color:var(--danger);' : 'color:var(--accent);'}">${m.qty_change > 0 ? '+' : ''}${m.qty_change} db</td>
                    <td class="muted">${escapeHtml(m.source_type)}#${m.source_ref}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="5" class="muted" style="text-align:center; padding:16px;">Nincs mozgás ebben az időszakban.</td></tr>';

        document.getElementById('sm-count').textContent = `${data.total} mozgás összesen`;
        document.getElementById('sm-prev').disabled = offset <= 0;
        document.getElementById('sm-next').disabled = !data.has_more;

        const baseQ = q.replace(/&limit=\d+&offset=\d+$/, '');
        document.getElementById('sm-export-csv').href = `/api/export-stock-movements-csv.php?${baseQ}`;
    } catch (err) {
        body.innerHTML = `<tr><td colspan="5" class="feedback error">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

document.getElementById('sm-apply').addEventListener('click', () => loadMovements(0));
document.getElementById('sm-prev').addEventListener('click', () => loadMovements(Math.max(0, currentOffset - PAGE_SIZE)));
document.getElementById('sm-next').addEventListener('click', () => loadMovements(currentOffset + PAGE_SIZE));

loadProducts();
loadMovements(0);
