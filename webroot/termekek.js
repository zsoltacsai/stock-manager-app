// PERF-09: szerveroldali lapozás (/api/products-page.php). Korábban az egész
// katalógus letöltődött, és minden szűrt sor kirajzolódott (10 000 terméknél
// 181 707 DOM-elem, ~7,9 s). Most csak az aktuális oldal (PAGE_SIZE sor) kerül
// a DOM-ba; a szűrés és a rendezés szabálya a korábbi (lásd
// Database::listProductsPage()). A szűrésnek megfelelő ÖSSZES azonosító
// (lastFilteredIds) továbbra is megvan, így a kijelölés, a „mind kijelölése”,
// az export és a tömeges műveletek a teljes szűrt halmazon működnek, mint eddig.
const PAGE_SIZE = 100;
let pageRows = [];
let totalCount = 0;
let currentOffset = 0;
let loadSeq = 0;
let filterTimer = null;
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
const productsBulkBar = document.getElementById('products-bulk-bar');
const bulkGroupInput = document.getElementById('bulk-group-input');
const bulkGroupList = document.getElementById('bulk-group-list');
const bulkSetGroupBtn = document.getElementById('bulk-set-group-btn');
const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
const productsPager = document.getElementById('products-pager');
const productsPageInfo = document.getElementById('products-page-info');
const productsPrevBtn = document.getElementById('products-prev-btn');
const productsNextBtn = document.getElementById('products-next-btn');

function currentStaffId() {
    try {
        const raw = localStorage.getItem('sm_current_staff');
        return raw ? JSON.parse(raw).id : null;
    } catch (e) { return null; }
}

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

async function loadSettingsForThreshold() {
    try {
        const data = await window.smSettingsPromise;
        globalLowStockThreshold = Number(data.low_stock_default_threshold ?? 5);
    } catch (e) { /* keep default */ }
}

function pageQuery() {
    const q = new URLSearchParams();
    q.set('name', fName.value.trim());
    q.set('cikkszam', fCikkszam.value.trim());
    q.set('barcode', fBarcode.value.trim());
    q.set('group', fGroup.value);
    if (fZeroStock.checked) q.set('zero_stock', '1');
    if (fWebshopOnly.checked) q.set('webshop_only', '1');
    if (fDeleted.checked) q.set('include_deleted', '1');
    q.set('sort', sortColumn);
    q.set('dir', sortDir);
    q.set('offset', String(currentOffset));
    q.set('limit', String(PAGE_SIZE));
    return q.toString();
}

async function loadProducts() {
    const seq = ++loadSeq;
    try {
        let data = await fetchJson('/api/products-page.php?' + pageQuery());
        // Ha a lista rövidebb lett (pl. törlés után), az utolsó létező oldalra lépünk.
        if (seq === loadSeq && data.filtered_count > 0 && currentOffset >= data.filtered_count) {
            currentOffset = Math.floor((data.filtered_count - 1) / PAGE_SIZE) * PAGE_SIZE;
            data = await fetchJson('/api/products-page.php?' + pageQuery());
        }
        if (seq !== loadSeq) return; // egy újabb betöltés már elindult
        pageRows = data.products || [];
        lastFilteredIds = data.ids || [];
        totalCount = data.total_count || 0;
        populateGroupFilter(data.groups || []);
        renderTable();
    } catch (err) {
        if (seq !== loadSeq) return;
        productsBody.innerHTML = `<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Hiba a termékek betöltésekor: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function reloadFromFirstPage() {
    currentOffset = 0;
    loadProducts();
}

function populateGroupFilter(groupNames) {
    const current = fGroup.value;
    const groups = Array.from(new Set(groupNames.filter(Boolean))).sort();
    fGroup.innerHTML = '<option value="">- Mind -</option>' +
        groups.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
    fGroup.value = groups.includes(current) ? current : '';

    if (bulkGroupList) {
        bulkGroupList.innerHTML = groups.map(g => `<option value="${escapeHtml(g)}">`).join('');
    }
}

function effectiveThreshold(p) {
    return p.low_stock_threshold !== null && p.low_stock_threshold !== undefined && p.low_stock_threshold !== ''
        ? Number(p.low_stock_threshold)
        : globalLowStockThreshold;
}

function renderPager() {
    if (!productsPageInfo) return;
    const filtered = lastFilteredIds.length;
    const from = filtered === 0 ? 0 : currentOffset + 1;
    const to = Math.min(currentOffset + PAGE_SIZE, filtered);
    productsPageInfo.textContent = filtered > PAGE_SIZE ? `${from}–${to} / ${filtered}` : '';
    productsPrevBtn.disabled = currentOffset === 0;
    productsNextBtn.disabled = currentOffset + PAGE_SIZE >= filtered;
    productsPager.classList.toggle('hidden', filtered <= PAGE_SIZE);
}

function renderTable() {
    productsCount.textContent = `${lastFilteredIds.length} / ${totalCount} árucikk`;
    productsBody.innerHTML = '';
    updateSelectedCount();

    updateSortIndicators();
    renderPager();

    if (lastFilteredIds.length === 0) {
        productsBody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő árucikk.</td></tr>';
        updateSelectAllCheckbox();
        return;
    }

    for (const p of pageRows) {
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
    if (productsBulkBar) productsBulkBar.classList.toggle('hidden', visibleSelected === 0);
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

async function runBulkAction(action, extra) {
    const ids = lastFilteredIds.filter(id => selectedProductIds.has(id));
    if (!ids.length) return;
    try {
        const res = await fetch('/api/products-bulk.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action, ids, staff_id: currentStaffId() }, extra || {})),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        selectedProductIds.clear();
        loadProducts();
    } catch (err) {
        // UX-12 (Phase 7 audit) — natív alert() helyett az alkalmazás saját,
        // nem-blokkoló visszajelzése (window.showToast, lásd topbar.js).
        window.showToast('Hiba: ' + err.message, 'error');
    }
}

if (bulkDeleteBtn) {
    bulkDeleteBtn.addEventListener('click', () => {
        const count = lastFilteredIds.filter(id => selectedProductIds.has(id)).length;
        if (!confirm(`Biztosan törlöd a kijelölt ${count} árucikket?`)) return;
        runBulkAction('delete');
    });
}
if (bulkSetGroupBtn) {
    bulkSetGroupBtn.addEventListener('click', () => {
        const groupName = bulkGroupInput.value.trim();
        const count = lastFilteredIds.filter(id => selectedProductIds.has(id)).length;
        const label = groupName ? `"${groupName}"` : '(üres — csoport törlése)';
        if (!confirm(`Beállítod a(z) ${count} kijelölt árucikk csoportját erre: ${label}?`)) return;
        runBulkAction('set_group', { group_name: groupName });
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
        reloadFromFirstPage();
    });
});

if (productsPrevBtn) {
    productsPrevBtn.addEventListener('click', () => {
        currentOffset = Math.max(0, currentOffset - PAGE_SIZE);
        loadProducts();
    });
}
if (productsNextBtn) {
    productsNextBtn.addEventListener('click', () => {
        if (currentOffset + PAGE_SIZE < lastFilteredIds.length) {
            currentOffset += PAGE_SIZE;
            loadProducts();
        }
    });
}

function openEdit(id) {
    const product = pageRows.find(p => p.id === id);
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
    const product = pageRows.find(p => p.id === id);
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
    const product = pageRows.find(p => p.id === id);
    if (!product) return;

    const res = await fetch('/api/product-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildFullProductPayload(product, { show_webshop: nextValue })),
    });

    // Ugyanaz a minta, mint toggleDeleted()-nél — teljes újratöltés, ne
    // csak feltételezett sikerű, helyi állapotmódosítás. Enélkül egy
    // sikertelen mentés (hálózati hiba, validációs hiba, 5xx) után a
    // gomb/kártya továbbra is a (valójában el nem mentett) új állapotot
    // mutatta volna a következő teljes újratöltésig.
    if (!res.ok) {
        loadProducts();
        return;
    }
    product.show_webshop = nextValue ? 1 : 0;
}

[fName, fCikkszam, fBarcode].forEach(input => input.addEventListener('input', () => {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(reloadFromFirstPage, 200);
}));
[fGroup, fZeroStock, fWebshopOnly].forEach(input => input.addEventListener('change', reloadFromFirstPage));
fDeleted.addEventListener('change', reloadFromFirstPage);

newArticleBtn.addEventListener('click', () => {
    ProductModal.open(null, '', () => loadProducts());
});

document.addEventListener('stockmanager:synced', () => loadProducts());

loadSettingsForThreshold().then(() => { if (pageRows.length) renderTable(); });
loadProducts();
