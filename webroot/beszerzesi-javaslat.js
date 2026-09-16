const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n));

// 1.2.0 — a kör 11. pontja: a MEGLÉVŐ javaslat-listát egy egyszerű
// készlet-előrejelzéssel egészíti ki (lásd api/purchase-suggestions.php),
// a javasolt-mennyiség logikát NEM módosítja.
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

async function loadSuggestions() {
    const box = document.getElementById('suggestions-content');
    try {
        const data = await fetchJson('/api/purchase-suggestions.php');
        renderGroups(data.groups || []);
    } catch (err) {
        box.innerHTML = `<p class="feedback error">A javaslatok betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

function renderGroups(groups) {
    const box = document.getElementById('suggestions-content');

    if (!groups.length) {
        box.innerHTML = '<p class="muted">Jelenleg nincs alacsony készletű termék.</p>';
        return;
    }

    box.innerHTML = groups.map((group, gi) => `
        <div class="import-card" style="background:var(--panel-light); margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h2 style="margin:0;">${escapeHtml(group.supplier_name)}</h2>
                <button class="btn btn-primary start-purchase-btn" data-group="${gi}" style="width:auto; padding:10px 18px;">
                    Beszerzés indítása ezzel a beszállítóval
                </button>
            </div>
            <div class="sample-table-wrap">
                <table class="sample-table">
                    <thead><tr><th>Termék</th><th>Készlet</th><th>Küszöb</th><th>Javasolt mennyiség</th><th>Előrejelzés</th></tr></thead>
                    <tbody>
                        ${group.products.map((p, pi) => `
                            <tr>
                                <td>${escapeHtml(p.name)}${p.barcode ? ' <span class="muted">(' + escapeHtml(p.barcode) + ')</span>' : ''}</td>
                                <td>${p.stock_qty} db</td>
                                <td>${p.threshold} db</td>
                                <td><input type="number" min="1" value="${p.suggested_qty}" data-group="${gi}" data-index="${pi}" class="suggested-qty-input" style="width:80px;"></td>
                                <td>${forecastLabel(p.forecast)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `).join('');

    box.querySelectorAll('.start-purchase-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = groups[Number(btn.dataset.group)];
            const items = group.products.map((p, pi) => {
                const input = box.querySelector(`.suggested-qty-input[data-group="${btn.dataset.group}"][data-index="${pi}"]`);
                return { product_id: p.id, qty: parseInt(input.value, 10) || p.suggested_qty };
            });
            sessionStorage.setItem('sm_purchase_prefill', JSON.stringify({
                supplier_id: group.supplier_id,
                items,
            }));
            location.href = 'beszerzes.php';
        });
    });
}

loadSuggestions();
