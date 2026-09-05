let allProducts = [];
let globalLowStockThreshold = 5;
let sortColumn = 'name';
let sortDir = 'asc';
let selectedProductIds = new Set();
let lastFilteredIds = [];

const fName = document.getElementById('f-name');
const fCikkszam = document.getElementById('f-cikkszam');
const fBarcode = document.getElementById('f-barcode');
const fGroup = document.getElementById('f-group');
const fDeleted = document.getElementById('f-deleted');
const fZeroStock = document.getElementById('f-zero-stock');
const fWebshopOnly = document.getElementById('f-webshop-only');

const productsBody = document.getElementById('products-body');
const productsCount = document.getElementById('products-count');
const productsSelectedCount = document.getElementById('products-selected-count');
const selectAllProducts = document.getElementById('select-all-products');
const exportProductsCsvBtn = document.getElementById('export-products-csv-btn');
const exportProductsXlsBtn = document.getElementById('export-products-xls-btn');
const newArticleBtn = document.getElementById('new-article-btn');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

async function loadSettingsForThreshold() {
    try {
        const data = await window.smSettingsPromise;
        globalLowStockThreshold = Number(data.low_stock_default_threshold ?? 5);
    } catch (e) { /* keep default */ }
}

async function loadProducts() {
    const includeDeleted = fDeleted.checked ? '?include_deleted=1' : '';
    const res = await fetch('/api/products.php' + includeDeleted);
    const data = await res.json();
    allProducts = data.products || [];
    populateGroupFilter();
    renderTable();
}

function populateGroupFilter() {
    const current = fGroup.value;
    const groups = Array.from(new Set(allProducts.map(p => p.group_name).filter(Boolean))).sort();
    fGroup.innerHTML = '<option value="">- Mind -</option>' +
        groups.map(g => `<option value="${g}">${g}</option>`).join('');
    fGroup.value = groups.includes(current) ? current : '';
}

function effectiveThreshold(p) {
    return p.low_stock_threshold !== null && p.low_stock_threshold !== undefined && p.low_stock_threshold !== ''
        ? Number(p.low_stock_threshold)
        : globalLowStockThreshold;
}

function sortValue(p, col) {
    switch (col) {
        case 'name': return (p.name || '').toLowerCase();
        case 'cikkszam': return (p.cikkszam || '').toLowerCase();
        case 'group_name': return (p.group_name || '').toLowerCase();
        case 'barcode': return (p.barcode || '').toLowerCase();
        case 'stock_qty': return Number(p.stock_qty);
        case 'purchase_price_net': return Number(p.purchase_price_net);
        case 'net_price': return Number(p.net_price);
        case 'price': return Number(p.price);
        default: return '';
    }
}

function renderTable() {
    const nameQ = fName.value.trim().toLowerCase();
    const cikkQ = fCikkszam.value.trim().toLowerCase();
    const barcodeQ = fBarcode.value.trim().toLowerCase();
    const groupQ = fGroup.value;
    const zeroOnly = fZeroStock.checked;
    const webshopOnly = fWebshopOnly.checked;

    const filtered = allProducts.filter(p => {
        if (nameQ && !p.name.toLowerCase().includes(nameQ)) return false;
        if (cikkQ && !(p.cikkszam || '').toLowerCase().includes(cikkQ)) return false;
        if (barcodeQ && !(p.barcode || '').toLowerCase().includes(barcodeQ)) return false;
        if (groupQ && p.group_name !== groupQ) return false;
        if (zeroOnly && Number(p.stock_qty) > 0) return false;
        if (webshopOnly && !Number(p.show_webshop)) return false;
        return true;
    });

    filtered.sort((a, b) => {
        const va = sortValue(a, sortColumn);
        const vb = sortValue(b, sortColumn);
        let cmp = 0;
        if (typeof va === 'number' && typeof vb === 'number') cmp = va - vb;
        else cmp = String(va).localeCompare(String(vb), 'hu');
        return sortDir === 'asc' ? cmp : -cmp;
    });

    productsCount.textContent = `${filtered.length} / ${allProducts.length} árucikk`;
    productsBody.innerHTML = '';
    lastFilteredIds = filtered.map(p => p.id);
    updateSelectedCount();

    updateSortIndicators();

    if (filtered.length === 0) {
        productsBody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő árucikk.</td></tr>';
        updateSelectAllCheckbox();
        return;
    }

    for (const p of filtered) {
        const tr = document.createElement('tr');
        if (Number(p.is_deleted)) tr.classList.add('deleted-row');

        const stock = Number(p.stock_qty);
        const threshold = effectiveThreshold(p);
        let stockBadgeClass = 'ok';
        if (stock < 0) stockBadgeClass = 'negative';
        else if (stock === 0) stockBadgeClass = 'zero';
        else if (stock <= threshold) stockBadgeClass = 'warn';
        const warnIcon = stockBadgeClass !== 'ok'
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;margin-right:3px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>'
            : '';

        tr.innerHTML = `
            <td><input type="checkbox" class="row-select-checkbox" data-id="${p.id}"${selectedProductIds.has(p.id) ? ' checked' : ''}></td>
            <td>${escapeHtml(p.name)}</td>
            <td>${escapeHtml(p.cikkszam || '')}</td>
            <td>${escapeHtml(p.group_name || '')}</td>
            <td>${escapeHtml(p.barcode || '')}</td>
            <td><span class="stock-badge ${stockBadgeClass}">${warnIcon}${p.stock_qty} ${escapeHtml(p.unit || 'db')}</span></td>
            <td>${fmt(p.purchase_price_net)}</td>
            <td>${fmt(p.net_price)}</td>
            <td>${fmt(p.price)}</td>
            <td>
                <button type="button" class="toggle-switch webshop-toggle-btn${Number(p.show_webshop) ? ' on' : ''}" data-id="${p.id}" title="Feltüntetve a webáruházban"></button>
            </td>
            <td>
                <div class="row-actions">
                    <button class="edit-btn" data-id="${p.id}">Módosítás</button>
                    <button class="toggle-delete-btn danger" data-id="${p.id}">${Number(p.is_deleted) ? 'Visszaállítás' : 'Törlés'}</button>
                </div>
            </td>
        `;
        tr.addEventListener('click', (e) => {
            if (e.target.closest('.row-actions') || e.target.closest('.webshop-toggle-btn') || e.target.closest('.row-select-checkbox')) return;
            openEdit(p.id);
        });
        productsBody.appendChild(tr);
    }

    productsBody.querySelectorAll('.row-select-checkbox').forEach(cb => {
        cb.addEventListener('click', (e) => e.stopPropagation());
        cb.addEventListener('change', () => {
            const id = Number(cb.dataset.id);
            if (cb.checked) selectedProductIds.add(id); else selectedProductIds.delete(id);
            updateSelectedCount();
            updateSelectAllCheckbox();
        });
    });
    updateSelectAllCheckbox();

    productsBody.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openEdit(Number(btn.dataset.id));
        });
    });
    productsBody.querySelectorAll('.toggle-delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleDeleted(Number(btn.dataset.id));
        });
    });
    productsBody.querySelectorAll('.webshop-toggle-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const nextValue = !btn.classList.contains('on');
            btn.classList.toggle('on', nextValue);
            toggleWebshop(Number(btn.dataset.id), nextValue);
        });
    });
}

function updateSelectedCount() {
    // A kijelölés a szűrés/lapozás nélküli teljes listán él tovább, de a
    // számláló csak az aktuálisan látható (szűrt) sorok közül kijelölteket
    // mutatja, hogy ne legyen zavaró egy korábbi szűrés alatt kijelölt,
    // most épp nem látszó elem miatt.
    const visibleSelected = lastFilteredIds.filter(id => selectedProductIds.has(id)).length;
    productsSelectedCount.textContent = visibleSelected > 0 ? `${visibleSelected} kijelölve` : '';
}

function updateSelectAllCheckbox() {
    if (!selectAllProducts) return;
    const allSelected = lastFilteredIds.length > 0 && lastFilteredIds.every(id => selectedProductIds.has(id));
    const someSelected = lastFilteredIds.some(id => selectedProductIds.has(id));
    selectAllProducts.checked = allSelected;
    selectAllProducts.indeterminate = someSelected && !allSelected;
}

function exportIdsForCurrentView() {
    const visibleSelected = lastFilteredIds.filter(id => selectedProductIds.has(id));
    return visibleSelected.length > 0 ? visibleSelected : lastFilteredIds;
}

if (selectAllProducts) {
    selectAllProducts.addEventListener('change', () => {
        if (selectAllProducts.checked) {
            lastFilteredIds.forEach(id => selectedProductIds.add(id));
        } else {
            lastFilteredIds.forEach(id => selectedProductIds.delete(id));
        }
        renderTable();
    });
}

if (exportProductsCsvBtn) {
    exportProductsCsvBtn.addEventListener('click', () => {
        const ids = exportIdsForCurrentView();
        window.location.href = '/api/export-products.php?format=csv&ids=' + ids.join(',');
    });
}
if (exportProductsXlsBtn) {
    exportProductsXlsBtn.addEventListener('click', () => {
        const ids = exportIdsForCurrentView();
        window.location.href = '/api/export-products.php?format=xls&ids=' + ids.join(',');
    });
}

function updateSortIndicators() {
    document.querySelectorAll('#products-table-head th[data-sort]').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
        if (th.dataset.sort === sortColumn) {
            th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        }
    });
}

document.querySelectorAll('#products-table-head th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.sort;
        if (sortColumn === col) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortColumn = col;
            sortDir = 'asc';
        }
        renderTable();
    });
});

function openEdit(id) {
    const product = allProducts.find(p => p.id === id);
    if (!product) return;
    ProductModal.open(product, '', () => loadProducts());
}

// A product-save.php a teljes árucikk-rekordot várja (nem részleges
// frissítést) — ez a segédfüggvény minden mezőt kitölt a már betöltött
// listaadatból, hogy egy gyors lista-kapcsoló (törlés, webshop) se
// nullázza ki véletlenül a leírást, márkát, képet vagy a szinkron-
// beállítást.
function buildFullProductPayload(product, overrides) {
    return Object.assign({
        id: product.id,
        name: product.name,
        unit: product.unit,
        group_name: product.group_name,
        cikkszam: product.cikkszam,
        vtsz: product.vtsz,
        currency: product.currency,
        vat_rate: product.vat_rate,
        net_price: product.net_price,
        gross_price: product.price,
        barcode: product.barcode,
        weight: product.weight,
        volume: product.volume,
        notes: product.notes,
        low_stock_threshold: product.low_stock_threshold,
        preferred_supplier_id: product.preferred_supplier_id,
        show_pricelist: !!Number(product.show_pricelist),
        show_webshop: !!Number(product.show_webshop),
        is_deleted: !!Number(product.is_deleted),
        short_description: product.short_description || '',
        long_description: product.long_description || '',
        brand: product.brand || '',
        image_filename: product.image_filename || '',
        image_alt: product.image_alt || '',
        sync_to_woocommerce: !!Number(product.sync_to_woocommerce),
    }, overrides);
}

async function toggleDeleted(id) {
    const product = allProducts.find(p => p.id === id);
    if (!product) return;

    const nextDeleted = !Number(product.is_deleted);
    const confirmMsg = nextDeleted
        ? `Törlöd a(z) "${product.name}" árucikket?`
        : `Visszaállítod a(z) "${product.name}" árucikket?`;
    if (!confirm(confirmMsg)) return;

    await fetch('/api/product-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildFullProductPayload(product, { is_deleted: nextDeleted })),
    });

    loadProducts();
}

async function toggleWebshop(id, nextValue) {
    const product = allProducts.find(p => p.id === id);
    if (!product) return;

    await fetch('/api/product-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildFullProductPayload(product, { show_webshop: nextValue })),
    });

    product.show_webshop = nextValue ? 1 : 0;
}

[fName, fCikkszam, fBarcode].forEach(input => input.addEventListener('input', renderTable));
[fGroup, fZeroStock, fWebshopOnly].forEach(input => input.addEventListener('change', renderTable));
fDeleted.addEventListener('change', loadProducts);

newArticleBtn.addEventListener('click', () => {
    ProductModal.open(null, '', () => loadProducts());
});

document.addEventListener('stockmanager:synced', () => loadProducts());

loadSettingsForThreshold().then(() => { if (allProducts.length) renderTable(); });
loadProducts();
