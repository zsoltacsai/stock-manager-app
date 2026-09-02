let allLocations = [];
let selectedProduct = null;

const locationsBody = document.getElementById('locations-body');
const newLocationBtn = document.getElementById('new-location-btn');
const locationModal = document.getElementById('location-modal');
const locationModalFeedback = document.getElementById('location-modal-feedback');
const locationModalClose = document.getElementById('location-modal-close');
const locationModalSave = document.getElementById('location-modal-save');
const locId = document.getElementById('loc-id');
const locName = document.getElementById('loc-name');
const locAddress = document.getElementById('loc-address');
const locDefault = document.getElementById('loc-default');

function getCurrentStaffId() {
    try {
        const raw = localStorage.getItem('sm_current_staff');
        if (raw) return JSON.parse(raw).id;
    } catch (e) { /* ignore */ }
    return null;
}

async function loadLocations() {
    const res = await fetch('/api/locations-list.php');
    const data = await res.json();
    allLocations = data.locations || [];
    renderLocations();
    populateTransferSelects();
}

function renderLocations() {
    locationsBody.innerHTML = allLocations.length ? allLocations.map(l => `
        <tr>
            <td>${escapeHtml(l.name)}</td>
            <td>${l.address || '—'}</td>
            <td>${Number(l.is_default) ? '★' : ''}</td>
            <td><button class="edit-btn" data-id="${l.id}">Módosítás</button></td>
        </tr>
    `).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">Még nincs felvéve telephely.</td></tr>';

    locationsBody.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => openLocationEdit(Number(btn.dataset.id)));
    });
}

function openLocationEdit(id) {
    const loc = id ? allLocations.find(l => l.id === id) : null;
    document.querySelector('#location-modal h2').textContent = loc ? 'Telephely módosítása' : 'Új telephely';
    locId.value = loc ? loc.id : '';
    locName.value = loc ? loc.name : '';
    locAddress.value = loc ? (loc.address || '') : '';
    locDefault.checked = loc ? !!Number(loc.is_default) : false;
    locationModalFeedback.textContent = '';
    locationModal.classList.add('open');
}

newLocationBtn.addEventListener('click', () => openLocationEdit(null));
locationModalClose.addEventListener('click', () => locationModal.classList.remove('open'));
locationModalSave.addEventListener('click', async () => {
    if (!locName.value.trim()) {
        locationModalFeedback.textContent = 'A név megadása kötelező.';
        locationModalFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: locId.value ? parseInt(locId.value, 10) : undefined,
        name: locName.value.trim(),
        address: locAddress.value.trim(),
        is_default: locDefault.checked,
    };
    try {
        const res = await fetch('/api/location-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        locationModal.classList.remove('open');
        loadLocations();
    } catch (err) {
        locationModalFeedback.textContent = 'Hiba: ' + err.message;
        locationModalFeedback.className = 'modal-feedback error';
    }
});

// --- Készletmozgatás ---
const transferProductSearch = document.getElementById('transfer-product-search');
const transferProductResults = document.getElementById('transfer-product-results');
const transferForm = document.getElementById('transfer-form');
const transferProductName = document.getElementById('transfer-product-name');
const transferCurrentStock = document.getElementById('transfer-current-stock');
const transferFrom = document.getElementById('transfer-from');
const transferTo = document.getElementById('transfer-to');
const transferQty = document.getElementById('transfer-qty');
const transferFeedback = document.getElementById('transfer-feedback');
const transferSubmitBtn = document.getElementById('transfer-submit-btn');

function populateTransferSelects() {
    const options = allLocations.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
    transferTo.innerHTML = options;
    transferFrom.innerHTML = '<option value="">— Új készlet (pl. beszerzés) —</option>' + options;
}

let productSearchDebounce = null;
transferProductSearch.addEventListener('input', () => {
    clearTimeout(productSearchDebounce);
    const q = transferProductSearch.value.trim();
    if (q.length < 2) {
        transferProductResults.innerHTML = '';
        return;
    }
    productSearchDebounce = setTimeout(async () => {
        const res = await fetch('/api/global-search.php?query=' + encodeURIComponent(q));
        const data = await res.json();
        const products = data.products || [];
        transferProductResults.innerHTML = products.map(p => `
            <div class="search-result-item" data-id="${p.id}" data-name="${escapeHtml(p.name)}">
                <span>${escapeHtml(p.name)}</span><span>${p.stock_qty} db összesen</span>
            </div>
        `).join('') || '<div class="muted">Nincs találat.</div>';

        transferProductResults.querySelectorAll('.search-result-item').forEach(row => {
            row.addEventListener('click', () => selectProductForTransfer(Number(row.dataset.id), row.dataset.name));
        });
    }, 200);
});

async function selectProductForTransfer(id, name) {
    selectedProduct = { id, name };
    transferProductSearch.value = '';
    transferProductResults.innerHTML = '';
    transferProductName.textContent = name;
    transferForm.classList.remove('hidden');
    transferFeedback.textContent = '';
    transferQty.value = '';

    const res = await fetch('/api/location-stock.php?product_id=' + id);
    const data = await res.json();
    const rows = (data.stock || []).map(s => `${s.location_name}: ${s.stock_qty} db`).join(' · ');
    transferCurrentStock.textContent = rows || 'Nincs még telephelyi bontás ehhez a termékhez.';
}

transferSubmitBtn.addEventListener('click', async () => {
    if (!selectedProduct) return;
    const qty = parseInt(transferQty.value, 10);
    if (!qty || qty <= 0) {
        transferFeedback.textContent = 'Adj meg egy érvényes mennyiséget.';
        transferFeedback.className = 'modal-feedback error';
        return;
    }
    transferFeedback.textContent = 'Mozgatás...';
    transferFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/stock-transfer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                product_id: selectedProduct.id,
                from_location_id: transferFrom.value || null,
                to_location_id: parseInt(transferTo.value, 10),
                qty,
                staff_id: getCurrentStaffId(),
            }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        transferFeedback.textContent = 'Sikeres mozgatás.';
        transferFeedback.className = 'modal-feedback';
        selectProductForTransfer(selectedProduct.id, selectedProduct.name);
        loadTransferHistory();
    } catch (err) {
        transferFeedback.textContent = 'Hiba: ' + err.message;
        transferFeedback.className = 'modal-feedback error';
    }
});

// --- Előzmények ---
async function loadTransferHistory() {
    const body = document.getElementById('transfer-history-body');
    const res = await fetch('/api/stock-transfer-history.php');
    const data = await res.json();
    const transfers = data.transfers || [];
    body.innerHTML = transfers.length ? transfers.map(t => `
        <tr>
            <td>${t.created_at}</td>
            <td>${escapeHtml(t.product_name)}</td>
            <td>${escapeHtml(t.from_name || 'Új készlet')}</td>
            <td>${escapeHtml(t.to_name)}</td>
            <td>${t.qty}</td>
        </tr>
    `).join('') : '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Még nincs mozgatás.</td></tr>';
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
        if (btn.dataset.tab === 'tab-transfer-history') loadTransferHistory();
    });
});

loadLocations();
