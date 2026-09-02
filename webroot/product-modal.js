/**
 * Megosztott új/szerkesztő árucikk modal ("Árucikk módosítása"). A befogadó
 * oldalnak tartalmaznia kell a modal HTML-jét (lásd beszerzes.php /
 * termekek.php) a szokásos elem-azonosítókkal. Hívd meg a
 * window.ProductModal.open(meglevoTermekVagyNull, elotoltottVonalkod,
 * mentesUtan) függvényt a megjelenítéséhez.
 */
window.ProductModal = (function () {
    const modal = document.getElementById('product-modal');
    const feedback = document.getElementById('product-modal-feedback');
    const title = modal.querySelector('h2');

    const pName = document.getElementById('p-name');
    const pUnit = document.getElementById('p-unit');
    const pGroup = document.getElementById('p-group');
    const pCikkszam = document.getElementById('p-cikkszam');
    const pVtsz = document.getElementById('p-vtsz');
    const pCurrency = document.getElementById('p-currency');
    const pVat = document.getElementById('p-vat');
    const pNet = document.getElementById('p-net');
    const pGross = document.getElementById('p-gross');
    const pBarcode = document.getElementById('p-barcode');
    const pWeight = document.getElementById('p-weight');
    const pVolume = document.getElementById('p-volume');
    const pNotes = document.getElementById('p-notes');
    const pLowStock = document.getElementById('p-low-stock');
    const pPreferredSupplier = document.getElementById('p-preferred-supplier');

    let cachedSuppliers = null;
    async function ensureSuppliersLoaded() {
        if (cachedSuppliers || !pPreferredSupplier) return;
        try {
            const res = await fetch('/api/suppliers-list.php');
            const data = await res.json();
            cachedSuppliers = data.suppliers || [];
            pPreferredSupplier.innerHTML = '<option value="">— Nincs megadva —</option>' +
                cachedSuppliers.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
        } catch (e) { /* the field just stays with only the empty option */ }
    }
    const pShowPricelist = document.getElementById('p-show-pricelist');
    const pShowWebshop = document.getElementById('p-show-webshop');
    const pDeleted = document.getElementById('p-deleted');
    const pPriceHistoryBox = document.getElementById('p-price-history-box');
    const pPriceHistoryList = document.getElementById('p-price-history-list');
    const pGenerateBarcodeBtn = document.getElementById('p-generate-barcode-btn');
    const pPrintLabelBtn = document.getElementById('p-print-label-btn');

    let onSavedCallback = null;
    let editingId = null;

    const fmtPrice = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

    async function loadPriceHistory(productId) {
        if (!pPriceHistoryBox) return;
        if (!productId) {
            pPriceHistoryBox.classList.add('hidden');
            return;
        }
        pPriceHistoryBox.classList.remove('hidden');
        pPriceHistoryList.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
        try {
            const res = await fetch('/api/price-history.php?product_id=' + productId);
            const data = await res.json();
            const history = data.history || [];
            pPriceHistoryList.innerHTML = history.length
                ? history.map(h => `<div>${h.changed_at} — ${fmtPrice(h.old_price)} → ${fmtPrice(h.new_price)} (bruttó)</div>`).join('')
                : 'Még nem változott az ár.';
        } catch (e) {
            pPriceHistoryList.textContent = 'Az ártörténet betöltése sikertelen.';
        }
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

    modal.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            modal.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            modal.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');
        });
    });

    [pShowPricelist, pShowWebshop, pDeleted].forEach(toggle => {
        if (toggle) toggle.addEventListener('click', () => toggle.classList.toggle('on'));
    });

    pNet.addEventListener('input', () => {
        if (pNet.value === '') return;
        pGross.value = grossFromNet(parseFloat(pNet.value) || 0, pVat.value);
    });
    pGross.addEventListener('input', () => {
        if (pGross.value === '') return;
        pNet.value = netFromGross(parseFloat(pGross.value) || 0, pVat.value);
    });
    pVat.addEventListener('change', () => {
        if (pNet.value !== '') pGross.value = grossFromNet(parseFloat(pNet.value) || 0, pVat.value);
    });

    function open(existingProduct, prefillBarcode, onSaved) {
        feedback.textContent = '';
        onSavedCallback = onSaved || null;
        editingId = existingProduct ? existingProduct.id : null;
        title.textContent = existingProduct ? 'Árucikk módosítása' : 'Új árucikk';

        pName.value = existingProduct ? existingProduct.name : '';
        pUnit.value = existingProduct ? (existingProduct.unit || 'db') : 'db';
        pGroup.value = existingProduct ? (existingProduct.group_name || '') : '';
        pCikkszam.value = existingProduct ? (existingProduct.cikkszam || '') : '';
        pVtsz.value = existingProduct ? (existingProduct.vtsz || '') : '';
        pCurrency.value = existingProduct ? (existingProduct.currency || 'HUF') : 'HUF';
        pVat.value = existingProduct ? existingProduct.vat_rate : '27';
        pNet.value = existingProduct ? existingProduct.net_price : '';
        pGross.value = existingProduct ? existingProduct.price : '';
        pBarcode.value = existingProduct ? (existingProduct.barcode || '') : (prefillBarcode || '');
        pWeight.value = existingProduct && existingProduct.weight != null ? existingProduct.weight : '';
        if (pVolume) pVolume.value = existingProduct && existingProduct.volume != null ? existingProduct.volume : '';
        pNotes.value = existingProduct ? (existingProduct.notes || '') : '';
        if (pLowStock) pLowStock.value = existingProduct && existingProduct.low_stock_threshold != null ? existingProduct.low_stock_threshold : '';
        ensureSuppliersLoaded().then(() => {
            if (pPreferredSupplier) {
                pPreferredSupplier.value = existingProduct && existingProduct.preferred_supplier_id ? String(existingProduct.preferred_supplier_id) : '';
            }
        });

        pShowPricelist.classList.toggle('on', existingProduct ? !!Number(existingProduct.show_pricelist) : true);
        pShowWebshop.classList.toggle('on', existingProduct ? !!Number(existingProduct.show_webshop) : true);
        if (pDeleted) pDeleted.classList.toggle('on', existingProduct ? !!Number(existingProduct.is_deleted) : false);

        modal.querySelector('.tab-btn[data-tab="tab-main"]').click();
        modal.classList.add('open');
        pName.focus();
        loadPriceHistory(editingId);
    }

    document.getElementById('product-modal-cancel').addEventListener('click', () => {
        modal.classList.remove('open');
    });

    document.getElementById('product-modal-save').addEventListener('click', async () => {
        if (!pName.value.trim()) {
            feedback.textContent = 'A megnevezés kötelező.';
            feedback.className = 'modal-feedback error';
            return;
        }
        if (pNet.value === '' && pGross.value === '') {
            feedback.textContent = 'Adja meg a nettó vagy bruttó árat.';
            feedback.className = 'modal-feedback error';
            return;
        }

        feedback.textContent = 'Mentés...';
        feedback.className = 'modal-feedback';

        const payload = {
            id: editingId,
            name: pName.value.trim(),
            unit: pUnit.value.trim() || 'db',
            group_name: pGroup.value.trim(),
            cikkszam: pCikkszam.value.trim(),
            vtsz: pVtsz.value.trim(),
            currency: pCurrency.value,
            vat_rate: pVat.value,
            net_price: pNet.value !== '' ? parseFloat(pNet.value) : '',
            gross_price: pGross.value !== '' ? parseFloat(pGross.value) : '',
            barcode: pBarcode.value.trim(),
            weight: pWeight.value !== '' ? parseFloat(pWeight.value) : '',
            volume: pVolume && pVolume.value !== '' ? parseFloat(pVolume.value) : '',
            notes: pNotes.value.trim(),
            low_stock_threshold: pLowStock && pLowStock.value.trim() !== '' ? parseInt(pLowStock.value, 10) : '',
            preferred_supplier_id: pPreferredSupplier && pPreferredSupplier.value ? parseInt(pPreferredSupplier.value, 10) : null,
            show_pricelist: pShowPricelist.classList.contains('on'),
            show_webshop: pShowWebshop.classList.contains('on'),
            is_deleted: pDeleted ? pDeleted.classList.contains('on') : false,
        };
        try {
            const staffRaw = localStorage.getItem('sm_current_staff');
            if (staffRaw) payload.staff_id = JSON.parse(staffRaw).id;
        } catch (e) { /* ignore corrupt storage */ }

        try {
            const res = await fetch('/api/product-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (!res.ok) {
                feedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
                feedback.className = 'modal-feedback error';
                return;
            }

            modal.classList.remove('open');
            if (onSavedCallback) onSavedCallback(data.product);
        } catch (err) {
            feedback.textContent = 'Hiba: ' + err.message;
            feedback.className = 'modal-feedback error';
        }
    });

    if (pGenerateBarcodeBtn) {
        pGenerateBarcodeBtn.addEventListener('click', async () => {
            pGenerateBarcodeBtn.disabled = true;
            try {
                const res = await fetch('/api/generate-barcode.php');
                const data = await res.json();
                if (data.barcode) pBarcode.value = data.barcode;
            } catch (e) { /* leave the field as-is on failure */ }
            pGenerateBarcodeBtn.disabled = false;
        });
    }

    const pCopyBarcodeBtn = document.getElementById('p-copy-barcode-btn');
    if (pCopyBarcodeBtn) {
        pCopyBarcodeBtn.addEventListener('click', async () => {
            if (!pBarcode.value.trim()) return;
            try {
                await navigator.clipboard.writeText(pBarcode.value.trim());
                const original = pCopyBarcodeBtn.textContent;
                pCopyBarcodeBtn.textContent = 'Másolva!';
                setTimeout(() => { pCopyBarcodeBtn.textContent = original; }, 1500);
            } catch (e) { /* clipboard access denied — silently do nothing */ }
        });
    }

    if (pPrintLabelBtn) {
        pPrintLabelBtn.addEventListener('click', () => {
            const params = new URLSearchParams({
                name: pName.value.trim(),
                price: pGross.value || '0',
                barcode: pBarcode.value.trim(),
            });
            window.open('label-print.html?' + params.toString(), '_blank', 'width=420,height=520');
        });
    }

    return { open };
})();
