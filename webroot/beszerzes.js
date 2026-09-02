let allProducts = [];
const purchaseCart = new Map();

const barcodeInput   = document.getElementById('barcode-input');
const scanFeedback   = document.getElementById('scan-feedback');
const searchInput    = document.getElementById('search-input');
const searchResults  = document.getElementById('search-results');
const cartBody       = document.getElementById('cart-body');
const cartTotalValue = document.getElementById('cart-total-value');
const saveBtn        = document.getElementById('save-btn');
const saveFeedback   = document.getElementById('save-feedback');
const newProductBtn  = document.getElementById('new-product-btn');

const supplierToggle = document.getElementById('supplier-toggle');
const supplierFields = document.getElementById('supplier-fields');
const supplierPicker = document.getElementById('supplier-picker');
const supplierName    = document.getElementById('supplier-name');
const supplierCountry = document.getElementById('supplier-country');
const supplierZip     = document.getElementById('supplier-zip');
const supplierCity    = document.getElementById('supplier-city');
const supplierAddress = document.getElementById('supplier-address');
const supplierTaxnum  = document.getElementById('supplier-taxnum');
const purchasePaymentMethod = document.getElementById('purchase-payment-method');
const purchasePaid = document.getElementById('purchase-paid');
const purchaseNote = document.getElementById('purchase-note');

let savedSuppliers = [];

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

supplierToggle.addEventListener('change', () => {
    supplierFields.classList.toggle('hidden', !supplierToggle.checked);
});

async function loadSuppliers() {
    try {
        const res = await fetch('/api/suppliers-list.php');
        const data = await res.json();
        savedSuppliers = data.suppliers || [];
        supplierPicker.innerHTML = '<option value="">— Új / eseti beszállító —</option>' +
            savedSuppliers.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
    } catch (e) { /* picker just stays empty on failure — manual entry still works */ }
}

supplierPicker.addEventListener('change', () => {
    const supplier = savedSuppliers.find(s => String(s.id) === supplierPicker.value);
    if (!supplier) return;
    supplierName.value = supplier.name || '';
    supplierZip.value = supplier.zip || '';
    supplierCity.value = supplier.city || '';
    supplierAddress.value = supplier.address || '';
    supplierTaxnum.value = supplier.tax_number || '';
    supplierCountry.value = supplier.country || 'Magyarország';
});

async function loadProducts() {
    const res = await fetch('/api/products.php');
    const data = await res.json();
    allProducts = data.products || [];
}

Promise.all([loadSuppliers(), loadProducts()]).then(applyPurchasePrefill);

// A beszerzési javaslat oldalról érkező, előre kiválasztott tételek
// betöltése — sessionStorage-on keresztül adja át, mert ez csak az adott
// böngésző-fülre/munkamenetre vonatkozó, egyszeri átadás. Csak akkor fut,
// miután a termékek ÉS a beszállítók is betöltődtek, hogy a beszállító
// legördülőben már létezzen a kiválasztandó opció.
function applyPurchasePrefill() {
    const raw = sessionStorage.getItem('sm_purchase_prefill');
    if (!raw) return;
    sessionStorage.removeItem('sm_purchase_prefill');
    try {
        const prefill = JSON.parse(raw);
        (prefill.items || []).forEach(item => {
            const product = allProducts.find(p => p.id === item.product_id);
            if (!product) return;
            addToPurchaseCart(product);
            const line = purchaseCart.get(product.id);
            if (line) line.qty = item.qty;
        });
        renderCart();
        if (prefill.supplier_id) {
            supplierToggle.checked = true;
            supplierFields.classList.remove('hidden');
            supplierPicker.value = String(prefill.supplier_id);
            supplierPicker.dispatchEvent(new Event('change'));
        }
    } catch (e) { /* malformed prefill — just skip it */ }
}

// A beszerzési javaslat oldalról érkező, előre kiválasztott tételek
// betöltése — sessionStorage-on keresztül adja át, mert ez csak az adott
// böngésző-fülre/munkamenetre vonatkozó, egyszeri átadás.
function applyPurchasePrefill() {
    const raw = sessionStorage.getItem('sm_purchase_prefill');
    if (!raw) return;
    sessionStorage.removeItem('sm_purchase_prefill');
    try {
        const prefill = JSON.parse(raw);
        (prefill.items || []).forEach(item => {
            const product = allProducts.find(p => p.id === item.product_id);
            if (!product) return;
            addToPurchaseCart(product);
            const line = purchaseCart.get(product.id);
            if (line) line.qty = item.qty;
        });
        renderCart();
        if (prefill.supplier_id) {
            supplierToggle.checked = true;
            supplierFields.classList.remove('hidden');
            supplierPicker.value = String(prefill.supplier_id);
            supplierPicker.dispatchEvent(new Event('change'));
        }
    } catch (e) { /* malformed prefill — just skip it */ }
}

function vatPercentOf(vatRate) {
    const n = parseFloat(vatRate);
    return isNaN(n) ? 0 : n / 100;
}
function grossFromNet(net, vatRate) {
    return Math.round(net * (1 + vatPercentOf(vatRate)) * 100) / 100;
}
function netFromGross(gross, vatRate) {
    return Math.round((gross / (1 + vatPercentOf(vatRate))) * 100) / 100;
}

let lastFailedBarcode = '';

barcodeInput.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const code = barcodeInput.value.trim();
    barcodeInput.value = '';
    if (!code) return;

    const product = allProducts.find(p => p.barcode === code);
    if (!product) {
        lastFailedBarcode = code;
        showScanFeedback(`Nincs találat: ${code} — vidd fel új termékként.`, 'error');
        return;
    }
    addToPurchaseCart(product);
    showScanFeedback(`Hozzáadva: ${product.name}`, 'ok');
});

function showScanFeedback(msg, kind) {
    scanFeedback.textContent = msg;
    scanFeedback.className = 'feedback ' + (kind || '');
}

searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim().toLowerCase();
    searchResults.innerHTML = '';
    if (!q) return;

    allProducts
        .filter(p => p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q))
        .slice(0, 20)
        .forEach(p => {
            const row = document.createElement('div');
            row.className = 'search-result-item';
            row.innerHTML = `<span>${escapeHtml(p.name)}</span><span>${p.stock_qty} db készleten</span>`;
            row.addEventListener('click', () => {
                addToPurchaseCart(p);
                searchInput.value = '';
                searchResults.innerHTML = '';
            });
            searchResults.appendChild(row);
        });
});

function addToPurchaseCart(product) {
    const existing = purchaseCart.get(product.id);
    if (existing) {
        existing.qty += 1;
    } else {
        purchaseCart.set(product.id, {
            product,
            qty: 1,
            vat_rate: product.vat_rate,
            unit_cost_net: Number(product.purchase_price_net) || 0,
        });
    }
    renderCart();
}

function renderCart() {
    cartBody.innerHTML = '';

    if (purchaseCart.size === 0) {
        cartBody.innerHTML = '<tr class="empty-row"><td colspan="7">Nincs még felvitt tétel — szkennelj be egy terméket</td></tr>';
        cartTotalValue.textContent = fmt(0);
        saveBtn.disabled = true;
        return;
    }

    let totalGross = 0;

    for (const [id, line] of purchaseCart.entries()) {
        const unitCostGross = grossFromNet(line.unit_cost_net, line.vat_rate);
        const lineGross = unitCostGross * line.qty;
        totalGross += lineGross;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(line.product.name)}</td>
            <td>
                <div class="qty-stepper">
                    <button type="button" class="qty-step-btn purchase-qty-down" data-id="${id}">−</button>
                    <input type="number" min="1" value="${line.qty}" data-id="${id}" class="qty-input" style="width:44px;">
                    <button type="button" class="qty-step-btn purchase-qty-up" data-id="${id}">+</button>
                </div>
            </td>
            <td>${isNaN(parseFloat(line.vat_rate)) ? line.vat_rate : line.vat_rate + '%'}</td>
            <td><input type="number" min="0" step="0.01" value="${line.unit_cost_net}" data-id="${id}" class="net-input" style="width:90px;"></td>
            <td>${fmt(unitCostGross)}</td>
            <td>${fmt(lineGross)}</td>
            <td><button class="remove-btn" data-id="${id}" title="Törlés"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button></td>
        `;
        cartBody.appendChild(tr);
    }

    cartTotalValue.textContent = fmt(totalGross);
    saveBtn.disabled = false;

    cartBody.querySelectorAll('.qty-input').forEach(input => {
        input.addEventListener('change', (e) => {
            const id = Number(e.target.dataset.id);
            const qty = Math.max(1, parseInt(e.target.value, 10) || 1);
            purchaseCart.get(id).qty = qty;
            renderCart();
        });
    });
    cartBody.querySelectorAll('.purchase-qty-down').forEach(btn => {
        btn.addEventListener('click', () => {
            const line = purchaseCart.get(Number(btn.dataset.id));
            if (line) line.qty = Math.max(1, line.qty - 1);
            renderCart();
        });
    });
    cartBody.querySelectorAll('.purchase-qty-up').forEach(btn => {
        btn.addEventListener('click', () => {
            const line = purchaseCart.get(Number(btn.dataset.id));
            if (line) line.qty += 1;
            renderCart();
        });
    });
    cartBody.querySelectorAll('.net-input').forEach(input => {
        input.addEventListener('change', (e) => {
            const id = Number(e.target.dataset.id);
            const val = Math.max(0, parseFloat(e.target.value) || 0);
            purchaseCart.get(id).unit_cost_net = val;
            renderCart();
        });
    });
    cartBody.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            purchaseCart.delete(Number(e.target.dataset.id));
            renderCart();
        });
    });
}

saveBtn.addEventListener('click', async () => {
    saveBtn.disabled = true;
    saveFeedback.textContent = 'Mentés...';
    saveFeedback.className = 'feedback';

    const items = Array.from(purchaseCart.entries()).map(([id, line]) => ({
        product_id: id,
        qty: line.qty,
        vat_rate: line.vat_rate,
        unit_cost_net: line.unit_cost_net,
        unit_cost_gross: grossFromNet(line.unit_cost_net, line.vat_rate),
    }));

    const payload = { items };

    if (supplierToggle.checked) {
        payload.supplier = {
            nev: supplierName.value.trim(),
            orszag: supplierCountry.value.trim(),
            irsz: supplierZip.value.trim(),
            telepules: supplierCity.value.trim(),
            cim: supplierAddress.value.trim(),
            adoszam: supplierTaxnum.value.trim(),
        };
        if (supplierPicker.value) {
            payload.supplier_id = parseInt(supplierPicker.value, 10);
        }
    }
    payload.payment_method = purchasePaymentMethod.value;
    payload.paid = purchasePaid.checked;
    payload.note = purchaseNote.value.trim();

    try {
        const res = await fetch('/api/purchase-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok) {
            saveFeedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            saveFeedback.className = 'feedback error';
            saveBtn.disabled = false;
            return;
        }

        let msg = `Beszerzés rögzítve (#${data.purchase_id}), bruttó összesen ${fmt(data.total_gross)}.`;
        if (data.wc_push_errors && data.wc_push_errors.length) {
            msg += ' WooCommerce sync hiba: ' + data.wc_push_errors.join('; ');
        }
        saveFeedback.textContent = msg;
        saveFeedback.className = 'feedback ok';

        purchaseCart.clear();
        renderCart();
        loadProducts();
        barcodeInput.focus();
    } catch (err) {
        saveFeedback.textContent = 'Hiba: ' + err.message;
        saveFeedback.className = 'feedback error';
    } finally {
        saveBtn.disabled = purchaseCart.size === 0;
    }
});

// -------------------------------------------------------------------------
// Új / szerkesztő árucikk modal ("Árucikk módosítása") — megosztva a termekek.php-vel
// -------------------------------------------------------------------------
newProductBtn.addEventListener('click', () => {
    ProductModal.open(null, lastFailedBarcode, (savedProduct) => {
        loadProducts();
        addToPurchaseCart(savedProduct);
        showScanFeedback(`Új termék létrehozva és hozzáadva: ${savedProduct.name}`, 'ok');
        lastFailedBarcode = '';
    });
});

document.addEventListener('stockmanager:synced', () => loadProducts());

renderCart();
