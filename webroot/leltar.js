const listView = document.getElementById('leltar-list-view');
const activeView = document.getElementById('leltar-active-view');
const stockTakesBody = document.getElementById('stock-takes-body');
const newStockTakeBtn = document.getElementById('new-stock-take-btn');
const backBtn = document.getElementById('leltar-back-btn');
const searchInput = document.getElementById('leltar-search-input');
const itemsBody = document.getElementById('leltar-items-body');
const applyCorrectionsCheckbox = document.getElementById('leltar-apply-corrections');
const completeBtn = document.getElementById('leltar-complete-btn');
const feedback = document.getElementById('leltar-feedback');

let currentTake = null;

function getCurrentStaffId() {
    try {
        const raw = localStorage.getItem('sm_current_staff');
        if (raw) return JSON.parse(raw).id;
    } catch (e) { /* ignore */ }
    return null;
}

async function loadStockTakes() {
    const res = await fetch('/api/stock-takes-list.php');
    const data = await res.json();
    const takes = data.stock_takes || [];

    stockTakesBody.innerHTML = takes.length ? takes.map(t => `
        <tr class="clickable-row" data-id="${t.id}">
            <td>${t.started_at}</td>
            <td>${t.completed_at || '<span class="stock-badge warn">folyamatban</span>'}</td>
            <td>${escapeHtml(t.notes || '—')}</td>
            <td><button class="edit-btn" data-id="${t.id}">${t.completed_at ? 'Megtekintés' : 'Folytatás'}</button></td>
        </tr>
    `).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">Még nem volt leltár.</td></tr>';

    stockTakesBody.querySelectorAll('.clickable-row, .edit-btn').forEach(elm => {
        elm.addEventListener('click', (e) => {
            e.stopPropagation();
            openStockTake(Number(elm.dataset.id));
        });
    });
}

newStockTakeBtn.addEventListener('click', async () => {
    const notes = prompt('Megjegyzés a leltárhoz (opcionális):') || '';
    const res = await fetch('/api/stock-take-start.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ staff_id: getCurrentStaffId(), notes }),
    });
    const data = await res.json();
    if (data.id) openStockTake(data.id);
});

async function openStockTake(id) {
    const res = await fetch('/api/stock-take-detail.php?id=' + id);
    const data = await res.json();
    if (!res.ok) {
        alert(data.error || 'A leltár betöltése sikertelen.');
        return;
    }
    currentTake = data.stock_take;
    listView.classList.add('hidden');
    activeView.classList.remove('hidden');
    applyCorrectionsCheckbox.checked = false;
    feedback.textContent = '';
    const isCompleted = !!currentTake.completed_at;
    completeBtn.classList.toggle('hidden', isCompleted);
    applyCorrectionsCheckbox.closest('label').classList.toggle('hidden', isCompleted);
    renderItems();
}

function renderItems() {
    const q = searchInput.value.trim().toLowerCase();
    const isCompleted = !!currentTake.completed_at;
    const filtered = currentTake.items.filter(i => !q || i.name.toLowerCase().includes(q));

    itemsBody.innerHTML = filtered.length ? filtered.map(item => {
        const diff = item.counted_qty !== null ? item.counted_qty - item.expected_qty : null;
        const diffText = diff === null ? '—' : (diff > 0 ? `+${diff}` : diff);
        const diffColor = diff === null ? '' : (diff === 0 ? 'color:var(--muted);' : (diff > 0 ? 'color:var(--accent);' : 'color:var(--danger);'));
        return `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${item.expected_qty}</td>
                <td>
                    ${isCompleted
                        ? (item.counted_qty ?? '—')
                        : `<input type="number" class="counted-input" data-product-id="${item.product_id}" value="${item.counted_qty ?? ''}" placeholder="—" style="width:80px;">`}
                </td>
                <td style="${diffColor}">${diffText}</td>
            </tr>
        `;
    }).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs a szűrésnek megfelelő termék.</td></tr>';

    itemsBody.querySelectorAll('.counted-input').forEach(input => {
        input.addEventListener('change', async () => {
            const productId = Number(input.dataset.productId);
            const value = input.value.trim() === '' ? null : parseInt(input.value, 10);
            await fetch('/api/stock-take-update-count.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ stock_take_id: currentTake.id, product_id: productId, counted_qty: value }),
            });
            const item = currentTake.items.find(i => i.product_id === productId);
            if (item) item.counted_qty = value;
            renderItems();
        });
    });
}

searchInput.addEventListener('input', renderItems);
backBtn.addEventListener('click', () => {
    activeView.classList.add('hidden');
    listView.classList.remove('hidden');
    loadStockTakes();
});

completeBtn.addEventListener('click', async () => {
    const uncounted = currentTake.items.filter(i => i.counted_qty === null).length;
    if (uncounted > 0) {
        const proceed = confirm(`${uncounted} termék még nincs megszámolva. Ezeknél a rendszer szerinti készlet marad érvényben. Folytatod a lezárást?`);
        if (!proceed) return;
    }
    feedback.textContent = 'Lezárás...';
    feedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/stock-take-complete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentTake.id, apply_corrections: applyCorrectionsCheckbox.checked }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        feedback.textContent = 'A leltár lezárva.';
        feedback.className = 'modal-feedback';
        openStockTake(currentTake.id);
    } catch (err) {
        feedback.textContent = 'Hiba: ' + err.message;
        feedback.className = 'modal-feedback error';
    }
});

loadStockTakes();
