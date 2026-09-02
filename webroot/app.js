const cart = new Map();
let allProducts = [];
let manualItemIdCounter = 1;
const manualItems = [];

const barcodeInput   = document.getElementById('barcode-input');
const scanFeedback   = document.getElementById('scan-feedback');
const searchInput    = document.getElementById('search-input');
const searchResults  = document.getElementById('search-results');
const cartBody        = document.getElementById('cart-body');
const cartTotalValue = document.getElementById('cart-total-value');
const checkoutBtn    = document.getElementById('checkout-btn');
const checkoutFeedback = document.getElementById('checkout-feedback');

const invoiceRequested = document.getElementById('invoice-requested');
const buyerFields       = document.getElementById('buyer-fields');
const buyerIsPrivate    = document.getElementById('buyer-is-private');
const buyerTaxnumWrap   = document.getElementById('buyer-taxnum-wrap');
const buyerName    = document.getElementById('buyer-name');
const buyerCountry = document.getElementById('buyer-country');
const buyerZip     = document.getElementById('buyer-zip');
const buyerCity    = document.getElementById('buyer-city');
const buyerAddress = document.getElementById('buyer-address');
const buyerTaxnum  = document.getElementById('buyer-taxnum');
const buyerNote    = document.getElementById('buyer-note');
const buyerLanguage = document.getElementById('buyer-language');
const paymentMethodSelect = document.getElementById('payment-method');

// --- Fizetési mód: egyedi (ikonos) legördülő, natív <select>-tel nem
// lehetne opciónként más ikont mutatni böngészőkön átívelően. A tényleges
// érték a rejtett #payment-method mezőben marad, hogy a checkout-kód
// (paymentMethodSelect.value) változatlanul működjön. ---
const PAYMENT_METHODS = [
    { value: 'Készpénz', color: '#16a34a', icon: '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path>' },
    { value: 'Átutalás', color: '#a855f7', icon: '<polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>' },
    { value: 'Bankkártya', color: '#3b82f6', icon: '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line>' },
    { value: 'PayPal', color: '#14b8a6', icon: '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>' },
    { value: 'Utánvét', color: '#f97316', icon: '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>' },
];

function pmSvg(method) {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="${method.color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${method.icon}</svg>`;
}

let setPaymentMethod = (value) => { paymentMethodSelect.value = value; };

(function initPaymentMethodSelect() {
    const wrap = document.getElementById('pm-select');
    const trigger = document.getElementById('payment-method-btn');
    const list = document.getElementById('payment-method-list');
    if (!wrap || !trigger || !list) return;

    const triggerIcon = trigger.querySelector('.pm-select-icon');
    const triggerLabel = document.getElementById('payment-method-label');

    list.innerHTML = PAYMENT_METHODS.map(m => `
        <button type="button" class="pm-select-option" data-value="${m.value}" role="option">
            <span class="pm-select-icon">${pmSvg(m)}</span>
            <span>${m.value}</span>
        </button>
    `).join('');

    setPaymentMethod = function (value) {
        const method = PAYMENT_METHODS.find(m => m.value === value) || PAYMENT_METHODS[0];
        paymentMethodSelect.value = method.value;
        triggerIcon.innerHTML = pmSvg(method);
        triggerLabel.textContent = method.value;
        list.querySelectorAll('.pm-select-option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.value === method.value);
        });
    };

    function closeList() {
        list.classList.add('hidden');
        trigger.setAttribute('aria-expanded', 'false');
    }
    function openList() {
        list.classList.remove('hidden');
        trigger.setAttribute('aria-expanded', 'true');
    }

    trigger.addEventListener('click', () => {
        list.classList.contains('hidden') ? openList() : closeList();
    });
    list.addEventListener('click', (e) => {
        const opt = e.target.closest('.pm-select-option');
        if (!opt) return;
        setPaymentMethod(opt.dataset.value);
        closeList();
    });
    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) closeList();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeList();
    });

    setPaymentMethod(paymentMethodSelect.value || 'Készpénz');
})();
const receiptLinkWrap = document.getElementById('receipt-link-wrap');
const viewReceiptBtn = document.getElementById('view-receipt-btn');
const receiptEmailInput = document.getElementById('receipt-email-input');
const sendReceiptEmailBtn = document.getElementById('send-receipt-email-btn');
const receiptEmailFeedback = document.getElementById('receipt-email-feedback');
const undoSaleBtn = document.getElementById('undo-sale-btn');
const undoCountdownEl = document.getElementById('undo-countdown');
const undoSaleFeedback = document.getElementById('undo-sale-feedback');
let undoTimer = null;
const buyerTaxlookupWrap = document.getElementById('buyer-taxlookup-wrap');
const buyerTaxlookupInput = document.getElementById('buyer-taxlookup');
const buyerTaxlookupBtn = document.getElementById('buyer-taxlookup-btn');
const buyerTaxlookupFeedback = document.getElementById('buyer-taxlookup-feedback');
let lastSaleId = null;
let lastReceiptToken = null;

// --- Dolgozó / PIN bejelentkezés a Kasszánál ---
const staffBadgeBtn = document.getElementById('staff-badge-btn');
const staffBadgeName = document.getElementById('staff-badge-name');
const staffLoginModal = document.getElementById('staff-login-modal');
const staffSelect = document.getElementById('staff-select');
const staffPinInput = document.getElementById('staff-pin-input');
const staffLoginFeedback = document.getElementById('staff-login-feedback');
const staffLoginBtn = document.getElementById('staff-login-btn');
const staffLogoutBtn = document.getElementById('staff-logout-btn');

let currentStaff = null;

function loadStaffFromStorage() {
    try {
        const raw = localStorage.getItem('sm_current_staff');
        if (raw) currentStaff = JSON.parse(raw);
    } catch (e) { /* ignore corrupt storage */ }
    updateStaffBadge();
}

function updateStaffBadge() {
    staffBadgeName.textContent = currentStaff ? currentStaff.name : 'Nincs bejelentkezve';
}

async function openStaffLoginModal() {
    staffLoginFeedback.textContent = '';
    staffPinInput.value = '';
    try {
        const res = await fetch('/api/staff-list.php');
        const data = await res.json();
        const staffList = data.staff || [];
        staffSelect.innerHTML = staffList.length
            ? staffList.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('')
            : '<option value="">— Nincs felvéve dolgozó (Beállítások → Dolgozók) —</option>';
    } catch (e) {
        staffSelect.innerHTML = '<option value="">Hiba a lista betöltésekor</option>';
    }
    staffLoginModal.classList.add('open');
    staffPinInput.focus();
}

staffBadgeBtn.addEventListener('click', openStaffLoginModal);

staffLoginBtn.addEventListener('click', async () => {
    const pin = staffPinInput.value.trim();
    if (!pin) {
        staffLoginFeedback.textContent = 'Add meg a PIN-kódot.';
        staffLoginFeedback.className = 'modal-feedback error';
        return;
    }
    try {
        const res = await fetch('/api/staff-login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pin }),
        });
        const data = await res.json();
        if (!data.ok) {
            staffLoginFeedback.textContent = data.error || 'Hibás PIN-kód.';
            staffLoginFeedback.className = 'modal-feedback error';
            return;
        }
        currentStaff = data.staff;
        localStorage.setItem('sm_current_staff', JSON.stringify(currentStaff));
        updateStaffBadge();
        staffLoginModal.classList.remove('open');
    } catch (err) {
        staffLoginFeedback.textContent = 'Hiba: ' + err.message;
        staffLoginFeedback.className = 'modal-feedback error';
    }
});

staffLogoutBtn.addEventListener('click', () => {
    currentStaff = null;
    localStorage.removeItem('sm_current_staff');
    updateStaffBadge();
    staffLoginModal.classList.remove('open');
});

staffPinInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') staffLoginBtn.click();
});

loadStaffFromStorage();

// --- Telephely-választó ---
// Csak akkor jelenik meg, ha ténylegesen van felvéve legalább egy
// telephely (Telephelyek oldal) — egytelephelyes boltnál a select rejtve
// marad, és a Kassza pontosan úgy viselkedik, mint korábban.
const locationSelector = document.getElementById('location-selector');

async function initLocationSelector() {
    try {
        const res = await fetch('/api/locations-list.php');
        const data = await res.json();
        const locations = data.locations || [];
        if (!locations.length) return;

        locationSelector.innerHTML = locations.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
        locationSelector.classList.remove('hidden');

        const saved = localStorage.getItem('sm_current_location_id');
        const defaultLoc = locations.find(l => Number(l.is_default)) || locations[0];
        locationSelector.value = (saved && locations.some(l => String(l.id) === saved)) ? saved : String(defaultLoc.id);
        localStorage.setItem('sm_current_location_id', locationSelector.value);

        locationSelector.addEventListener('change', () => {
            localStorage.setItem('sm_current_location_id', locationSelector.value);
        });
    } catch (e) { /* selector just stays hidden on failure */ }
}
initLocationSelector();

// --- Vásárlói törzs: autocomplete + teljes választó/szerkesztő modal a "Név / Cégnév" mezőnél ---
const buyerNameResults = document.getElementById('buyer-name-results');
const buyerPickerBtn = document.getElementById('buyer-picker-btn');
const customerPickerModal = document.getElementById('customer-picker-modal');
const customerPickerListView = document.getElementById('customer-picker-list-view');
const customerPickerEditView = document.getElementById('customer-picker-edit-view');
const customerPickerSearch = document.getElementById('customer-picker-search');
const customerPickerBody = document.getElementById('customer-picker-body');
const customerPickerClose = document.getElementById('customer-picker-close');
const customerPickerNewBtn = document.getElementById('customer-picker-new-btn');
const customerPickerEditTitle = document.getElementById('customer-picker-edit-title');
const customerPickerEditBack = document.getElementById('customer-picker-edit-back');
const customerPickerEditSave = document.getElementById('customer-picker-edit-save');
const customerPickerEditFeedback = document.getElementById('customer-picker-edit-feedback');
const cpId = document.getElementById('cp-id');
const cpName = document.getElementById('cp-name');
const cpPhone = document.getElementById('cp-phone');
const cpEmail = document.getElementById('cp-email');
const cpTaxnum = document.getElementById('cp-taxnum');
const cpZip = document.getElementById('cp-zip');
const cpCity = document.getElementById('cp-city');
const cpAddress = document.getElementById('cp-address');
const cpCountry = document.getElementById('cp-country');

function fillBuyerFieldsFromCustomer(customer) {
    buyerName.value = customer.name || '';
    if (customer.zip) buyerZip.value = customer.zip;
    if (customer.city) buyerCity.value = customer.city;
    if (customer.address) buyerAddress.value = customer.address;
    if (customer.country) buyerCountry.value = customer.country;
    if (customer.tax_number) {
        buyerIsPrivate.checked = false;
        buyerIsPrivate.dispatchEvent(new Event('change'));
        buyerTaxnum.value = customer.tax_number;
    }
    buyerNameResults.innerHTML = '';
}

// Élő javaslatok gépelés közben
buyerName.addEventListener('input', async () => {
    const q = buyerName.value.trim();
    if (q.length < 2) {
        buyerNameResults.innerHTML = '';
        return;
    }
    try {
        const res = await fetch('/api/customer-search.php?query=' + encodeURIComponent(q));
        const data = await res.json();
        const matches = data.customers || [];
        buyerNameResults.innerHTML = matches.map(c => `
            <div class="search-result-item" data-id="${c.id}">
                <span>${escapeHtml(c.name)}</span>
                <span>${escapeHtml(c.city || c.phone || '')}</span>
            </div>
        `).join('');
        buyerNameResults.querySelectorAll('.search-result-item').forEach(row => {
            row.addEventListener('click', () => {
                const customer = matches.find(c => String(c.id) === row.dataset.id);
                if (customer) fillBuyerFieldsFromCustomer(customer);
            });
        });
    } catch (e) { /* autocomplete hiccup — just show nothing */ }
});
document.addEventListener('click', (e) => {
    if (!e.target.closest('#buyer-name') && !e.target.closest('#buyer-name-results')) {
        buyerNameResults.innerHTML = '';
    }
});

// Teljes lista/szerkesztő modal a névmező melletti ikonra kattintva
let allPickerCustomers = [];

async function openCustomerPicker() {
    customerPickerModal.classList.add('open');
    showPickerListView();
    await loadPickerCustomers();
}

async function loadPickerCustomers() {
    try {
        const res = await fetch('/api/customers-list.php');
        const data = await res.json();
        allPickerCustomers = data.customers || [];
        renderPickerList();
    } catch (e) {
        customerPickerBody.innerHTML = `<tr><td colspan="4" class="feedback error">Hiba: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderPickerList() {
    const q = customerPickerSearch.value.trim().toLowerCase();
    const filtered = allPickerCustomers.filter(c =>
        !q || c.name.toLowerCase().includes(q) || (c.phone || '').includes(q) || (c.email || '').toLowerCase().includes(q)
    );
    customerPickerBody.innerHTML = filtered.length ? filtered.map(c => `
        <tr>
            <td>${escapeHtml(c.name)}</td>
            <td>${escapeHtml(c.phone || '—')}</td>
            <td>${escapeHtml(c.city || '—')}</td>
            <td>
                <div class="row-actions">
                    <button class="edit-btn select-customer-btn" data-id="${c.id}">Kiválasztás</button>
                    <button class="edit-btn edit-customer-btn" data-id="${c.id}">Szerkesztés</button>
                </div>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Nincs a szűrésnek megfelelő vásárló.</td></tr>';

    customerPickerBody.querySelectorAll('.select-customer-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const customer = allPickerCustomers.find(c => String(c.id) === btn.dataset.id);
            if (customer) {
                fillBuyerFieldsFromCustomer(customer);
                customerPickerModal.classList.remove('open');
            }
        });
    });
    customerPickerBody.querySelectorAll('.edit-customer-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const customer = allPickerCustomers.find(c => String(c.id) === btn.dataset.id);
            openPickerEditView(customer || null);
        });
    });
}

function showPickerListView() {
    customerPickerListView.classList.remove('hidden');
    customerPickerEditView.classList.add('hidden');
}

function openPickerEditView(customer) {
    customerPickerEditTitle.textContent = customer ? 'Vásárló szerkesztése' : 'Új vásárló';
    cpId.value = customer ? customer.id : '';
    cpName.value = customer ? customer.name : buyerName.value.trim();
    cpPhone.value = customer ? (customer.phone || '') : '';
    cpEmail.value = customer ? (customer.email || '') : '';
    cpTaxnum.value = customer ? (customer.tax_number || '') : '';
    cpZip.value = customer ? (customer.zip || '') : '';
    cpCity.value = customer ? (customer.city || '') : '';
    cpAddress.value = customer ? (customer.address || '') : '';
    cpCountry.value = customer ? (customer.country || 'Magyarország') : 'Magyarország';
    customerPickerEditFeedback.textContent = '';
    customerPickerListView.classList.add('hidden');
    customerPickerEditView.classList.remove('hidden');
}

buyerPickerBtn.addEventListener('click', openCustomerPicker);
customerPickerClose.addEventListener('click', () => customerPickerModal.classList.remove('open'));
customerPickerSearch.addEventListener('input', renderPickerList);
customerPickerNewBtn.addEventListener('click', () => openPickerEditView(null));
customerPickerEditBack.addEventListener('click', showPickerListView);

customerPickerEditSave.addEventListener('click', async () => {
    if (!cpName.value.trim()) {
        customerPickerEditFeedback.textContent = 'A név megadása kötelező.';
        customerPickerEditFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: cpId.value ? parseInt(cpId.value, 10) : undefined,
        name: cpName.value.trim(),
        phone: cpPhone.value.trim(),
        email: cpEmail.value.trim(),
        tax_number: cpTaxnum.value.trim(),
        zip: cpZip.value.trim(),
        city: cpCity.value.trim(),
        address: cpAddress.value.trim(),
        country: cpCountry.value.trim(),
    };
    customerPickerEditFeedback.textContent = 'Mentés...';
    customerPickerEditFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/customer-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        await loadPickerCustomers();
        // Ha épp egy új vásárlót vettünk fel a névmezőből kiindulva, töltsük ki rögtön a számla-adatokat is.
        if (!payload.id && data.customer) {
            fillBuyerFieldsFromCustomer(data.customer);
            customerPickerModal.classList.remove('open');
        } else {
            showPickerListView();
        }
    } catch (err) {
        customerPickerEditFeedback.textContent = 'Hiba: ' + err.message;
        customerPickerEditFeedback.className = 'modal-feedback error';
    }
});

// --- Törzsvásárlói / hűségpont ---
const loyaltySection = document.getElementById('loyalty-section');
const customerSearch = document.getElementById('customer-search');
const customerSearchResults = document.getElementById('customer-search-results');
const selectedCustomerBox = document.getElementById('selected-customer-box');
const selectedCustomerName = document.getElementById('selected-customer-name');
const selectedCustomerPoints = document.getElementById('selected-customer-points');
const clearCustomerBtn = document.getElementById('clear-customer-btn');
const redeemPointsInput = document.getElementById('redeem-points');
const redeemValueHint = document.getElementById('redeem-value-hint');

let selectedCustomer = null;
let loyaltyPointValueHuf = 0;

async function initLoyalty() {
    try {
        const data = await window.smSettingsPromise;
        if (data.loyalty_enabled) {
            loyaltySection.classList.remove('hidden');
            loyaltyPointValueHuf = Number(data.loyalty_point_value_huf || 0);
        }
    } catch (e) { /* loyalty section just stays hidden */ }
}
initLoyalty();

function selectCustomer(customer) {
    selectedCustomer = customer;
    customerSearch.value = '';
    customerSearchResults.innerHTML = '';
    selectedCustomerBox.classList.remove('hidden');
    selectedCustomerName.textContent = customer.name + (customer.phone ? ` (${customer.phone})` : '');
    selectedCustomerPoints.textContent = customer.loyalty_points;
    redeemPointsInput.value = '';
    updateRedeemHint();
}

function clearSelectedCustomer() {
    selectedCustomer = null;
    selectedCustomerBox.classList.add('hidden');
    redeemPointsInput.value = '';
    updateGrandTotalDisplay();
}

function updateRedeemHint() {
    const points = parseInt(redeemPointsInput.value, 10) || 0;
    if (points > 0 && loyaltyPointValueHuf > 0) {
        redeemValueHint.textContent = `Ez ${fmt(points * loyaltyPointValueHuf)} kedvezményt jelent.`;
    } else {
        redeemValueHint.textContent = '';
    }
    updateGrandTotalDisplay();
}

customerSearch.addEventListener('input', async () => {
    const q = customerSearch.value.trim();
    if (!q) {
        customerSearchResults.innerHTML = '';
        return;
    }
    try {
        const res = await fetch('/api/customer-search.php?query=' + encodeURIComponent(q));
        const data = await res.json();
        const customers = data.customers || [];
        customerSearchResults.innerHTML = customers.map(c => `
            <div class="search-result-item" data-id="${c.id}">
                <span>${escapeHtml(c.name)}${c.phone ? ' — ' + escapeHtml(c.phone) : ''}</span>
                <span>${c.loyalty_points} pont</span>
            </div>
        `).join('');
        customerSearchResults.querySelectorAll('.search-result-item').forEach(row => {
            row.addEventListener('click', () => {
                const customer = customers.find(c => String(c.id) === row.dataset.id);
                if (customer) selectCustomer(customer);
            });
        });
    } catch (e) { /* search hiccup — just show nothing */ }
});

clearCustomerBtn.addEventListener('click', clearSelectedCustomer);
redeemPointsInput.addEventListener('input', updateRedeemHint);

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

invoiceRequested.addEventListener('change', () => {
    buyerFields.classList.toggle('hidden', !invoiceRequested.checked);
});
buyerIsPrivate.addEventListener('change', () => {
    const isCompany = !buyerIsPrivate.checked;
    buyerTaxnumWrap.style.display = isCompany ? 'block' : 'none';
    buyerTaxlookupWrap.style.display = isCompany ? 'block' : 'none';
});
buyerTaxnumWrap.style.display = buyerIsPrivate.checked ? 'none' : 'block';
buyerTaxlookupWrap.style.display = buyerIsPrivate.checked ? 'none' : 'block';

// --- Ir.szám → Település automatikus kitöltés ---
buyerZip.addEventListener('blur', async () => {
    const irsz = buyerZip.value.trim();
    if (!/^\d{4}$/.test(irsz)) return;
    try {
        const res = await fetch('/api/irsz-lookup.php?irsz=' + irsz);
        const data = await res.json();
        if (data.found && !buyerCity.value.trim()) {
            buyerCity.value = data.telepules;
        }
    } catch (e) { /* best-effort — don't block the form on a lookup failure */ }
});

// --- Cégadatok lekérdezése adószám alapján (NAV — igényel technikai felhasználót, lásd README) ---
buyerTaxlookupBtn.addEventListener('click', async () => {
    const taxNumber = buyerTaxlookupInput.value.trim();
    if (!taxNumber) return;
    buyerTaxlookupFeedback.textContent = 'Lekérdezés...';
    buyerTaxlookupFeedback.className = 'feedback';
    try {
        const res = await fetch('/api/company-lookup.php?tax_number=' + encodeURIComponent(taxNumber));
        const data = await res.json();
        if (!res.ok || !data.found) {
            buyerTaxlookupFeedback.textContent = data.error || 'Nem található cég ezzel az adószámmal.';
            buyerTaxlookupFeedback.className = 'feedback error';
            return;
        }
        buyerName.value = data.name || buyerName.value;
        buyerZip.value = data.zip || buyerZip.value;
        buyerCity.value = data.city || buyerCity.value;
        buyerAddress.value = data.address || buyerAddress.value;
        buyerTaxnum.value = taxNumber;
        buyerTaxlookupFeedback.textContent = 'Cégadatok kitöltve.';
        buyerTaxlookupFeedback.className = 'feedback ok';
    } catch (err) {
        buyerTaxlookupFeedback.textContent = 'Hiba: ' + err.message;
        buyerTaxlookupFeedback.className = 'feedback error';
    }
});

function collectBuyerInput() {
    if (!invoiceRequested.checked) {
        return { buyer: null, language: null };
    }

    const name = buyerName.value.trim();
    const zip = buyerZip.value.trim();
    const city = buyerCity.value.trim();
    const address = buyerAddress.value.trim();

    if (!name || !zip || !city || !address) {
        return { error: 'Számlához kötelező: Név, Ir.szám, Település, Cím.' };
    }

    const buyer = {
        nev: name,
        irsz: zip,
        telepules: city,
        cim: address,
    };
    if (buyerCountry.value.trim()) buyer.orszag = buyerCountry.value.trim();
    if (buyerNote.value.trim()) buyer.megjegyzes = buyerNote.value.trim();
    if (!buyerIsPrivate.checked && buyerTaxnum.value.trim()) {
        buyer.adoszam = buyerTaxnum.value.trim();
    }

    return { buyer, language: buyerLanguage.value };
}

function resetBuyerForm() {
    invoiceRequested.checked = false;
    buyerFields.classList.add('hidden');
    buyerIsPrivate.checked = true;
    buyerTaxnumWrap.style.display = 'none';
    buyerTaxlookupWrap.style.display = 'none';
    buyerTaxlookupInput.value = '';
    buyerTaxlookupFeedback.textContent = '';
    buyerName.value = '';
    buyerNameResults.innerHTML = '';
    buyerZip.value = '';
    buyerCity.value = '';
    buyerAddress.value = '';
    buyerTaxnum.value = '';
    buyerNote.value = '';
    buyerLanguage.value = 'hu';
    buyerCountry.value = 'Magyarország';
    setPaymentMethod('Készpénz');
}

async function loadProducts() {
    const res = await fetch('/api/products.php');
    const data = await res.json();
    allProducts = data.products || [];
}
loadProducts();
loadFrequentProducts();

// --- Gyakran vásárolt gyorsgombok ---
async function loadFrequentProducts() {
    try {
        const res = await fetch('/api/top-selling-products.php');
        const data = await res.json();
        const products = data.products || [];
        if (!products.length) return;

        const box = document.getElementById('frequent-products-box');
        const row = document.getElementById('frequent-products-row');
        row.innerHTML = products.map(p => `<button type="button" class="quick-chip" data-id="${p.id}">${escapeHtml(p.name)}</button>`).join('');
        row.querySelectorAll('.quick-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                const product = allProducts.find(p => p.id === Number(btn.dataset.id));
                if (product) {
                    addToCart(product);
                    showScanFeedback(`Hozzáadva: ${product.name}`, false);
                }
            });
        });
        box.classList.remove('hidden');
    } catch (e) { /* the row just stays hidden on failure */ }
}

barcodeInput.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    const code = barcodeInput.value.trim();
    barcodeInput.value = '';
    if (!code) return;

    try {
        const res = await fetch('/api/barcode.php?code=' + encodeURIComponent(code));
        const data = await res.json();
        if (!data.found) {
            showScanFeedback(`Nincs találat: ${code}`, true);
            return;
        }
        addToCart(data.product);
        showScanFeedback(`Hozzáadva: ${data.product.name}`, false);
    } catch (err) {
        showScanFeedback('Hiba a keresés közben: ' + err.message, true);
    }
});

function showScanFeedback(msg, isError) {
    scanFeedback.textContent = msg;
    scanFeedback.className = 'feedback ' + (isError ? 'error' : 'ok');
}

searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim().toLowerCase();
    searchResults.innerHTML = '';
    if (!q) return;

    const matches = allProducts
        .filter(p => p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q))
        .slice(0, 20);

    for (const p of matches) {
        const row = document.createElement('div');
        row.className = 'search-result-item';
        const stockLabel = p.stock_qty <= 0
            ? `<span class="stock-warning">nincs készleten (${p.stock_qty} db)</span>`
            : `${p.stock_qty} db`;
        row.innerHTML = `<span>${escapeHtml(p.name)}</span><span>${fmt(p.price)} (${stockLabel})</span>`;
        row.addEventListener('click', () => {
            addToCart(p);
            searchInput.value = '';
            searchResults.innerHTML = '';
        });
        searchResults.appendChild(row);
    }
});

// --- Kosár ---
// --- Rövid "pitty" hang sikeres beolvasásnál (Web Audio API, nincs fájl) ---
function playBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
        osc.start();
        osc.stop(ctx.currentTime + 0.12);
    } catch (e) { /* audio not available — silently skip, never block the scan */ }
}

function addToCart(product) {
    const existing = cart.get(product.id);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.set(product.id, { product, qty: 1 });
    }
    renderCart();
    playBeep();
    flashCartRow(product.id);
}

function flashCartRow(productId) {
    requestAnimationFrame(() => {
        const row = cartBody.querySelector(`tr[data-product-id="${productId}"]`);
        if (row) {
            row.classList.remove('flash-added');
            void row.offsetWidth;
            row.classList.add('flash-added');
        }
    });
}

function renderCart() {
    cartBody.innerHTML = '';

    for (const { product, qty } of cart.values()) {
        const lineTotal = product.price * qty;

        const stockAfter = product.stock_qty - qty;
        const tr = document.createElement('tr');
        tr.dataset.productId = product.id;
        if (stockAfter < 0) tr.classList.add('row-negative-stock');

        tr.innerHTML = `
            <td>${escapeHtml(product.name)}${stockAfter < 0 ? ` <span class="stock-warning">mínuszba megy (${stockAfter} db)</span>` : ''}</td>
            <td>
                <div class="qty-stepper">
                    <button type="button" class="qty-step-btn qty-step-down" data-id="${product.id}">−</button>
                    <input type="number" min="1" value="${qty}" data-id="${product.id}" class="qty-input">
                    <button type="button" class="qty-step-btn qty-step-up" data-id="${product.id}">+</button>
                </div>
            </td>
            <td>${fmt(product.price)}</td>
            <td>${fmt(lineTotal)}</td>
            <td><button class="remove-btn" data-id="${product.id}" title="Törlés"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button></td>
        `;
        cartBody.appendChild(tr);
    }

    for (const item of manualItems) {
        const lineTotal = item.unit_price * item.qty;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(item.name)} <span class="stock-warning" style="color:var(--muted);">kézi tétel</span></td>
            <td><input type="number" min="1" value="${item.qty}" data-manual-id="${item.id}" class="qty-input manual-qty-input"></td>
            <td>${fmt(item.unit_price)}</td>
            <td>${fmt(lineTotal)}</td>
            <td><button class="remove-btn manual-remove-btn" data-manual-id="${item.id}" title="Törlés"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button></td>
        `;
        cartBody.appendChild(tr);
    }

    updateGrandTotalDisplay();
    checkoutBtn.disabled = cart.size === 0 && manualItems.length === 0;

    cartBody.querySelectorAll('.qty-input:not(.manual-qty-input)').forEach(input => {
        input.addEventListener('change', (e) => {
            const id = Number(e.target.dataset.id);
            const qty = Math.max(1, parseInt(e.target.value, 10) || 1);
            cart.get(id).qty = qty;
            renderCart();
        });
    });
    cartBody.querySelectorAll('.qty-step-down:not([data-manual-id])').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = Number(btn.dataset.id);
            const line = cart.get(id);
            if (line) line.qty = Math.max(1, line.qty - 1);
            renderCart();
        });
    });
    cartBody.querySelectorAll('.qty-step-up:not([data-manual-id])').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = Number(btn.dataset.id);
            const line = cart.get(id);
            if (line) line.qty += 1;
            renderCart();
        });
    });
    cartBody.querySelectorAll('.manual-qty-input').forEach(input => {
        input.addEventListener('change', (e) => {
            const id = Number(e.target.dataset.manualId);
            const item = manualItems.find(m => m.id === id);
            if (item) item.qty = Math.max(1, parseInt(e.target.value, 10) || 1);
            renderCart();
        });
    });

    cartBody.querySelectorAll('.remove-btn:not(.manual-remove-btn)').forEach(btn => {
        btn.addEventListener('click', (e) => {
            cart.delete(Number(e.target.closest('button').dataset.id));
            renderCart();
        });
    });
    cartBody.querySelectorAll('.manual-remove-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = Number(e.target.closest('button').dataset.manualId);
            const idx = manualItems.findIndex(m => m.id === id);
            if (idx !== -1) manualItems.splice(idx, 1);
            renderCart();
        });
    });
}

// --- Kézi tétel hozzáadása (nincs raktárkészlete, csak a nyugtán/számlán jelenik meg) ---
const manualItemToggleBtn = document.getElementById('manual-item-toggle-btn');
const manualItemForm = document.getElementById('manual-item-form');
const manualItemName = document.getElementById('manual-item-name');
const manualItemQty = document.getElementById('manual-item-qty');
const manualItemPrice = document.getElementById('manual-item-price');
const manualItemVat = document.getElementById('manual-item-vat');
const manualItemFeedback = document.getElementById('manual-item-feedback');
const manualItemAddBtn = document.getElementById('manual-item-add-btn');

manualItemToggleBtn.addEventListener('click', () => {
    manualItemForm.classList.toggle('hidden');
    if (!manualItemForm.classList.contains('hidden')) {
        manualItemName.focus();
    }
});

manualItemAddBtn.addEventListener('click', () => {
    const name = manualItemName.value.trim();
    const qty = Math.max(1, parseInt(manualItemQty.value, 10) || 1);
    const unitPrice = parseFloat(manualItemPrice.value.replace(',', '.'));

    if (!name) {
        manualItemFeedback.textContent = 'A megnevezés megadása kötelező.';
        manualItemFeedback.className = 'feedback error';
        return;
    }
    if (isNaN(unitPrice) || unitPrice < 0) {
        manualItemFeedback.textContent = 'Adj meg egy érvényes egységárat.';
        manualItemFeedback.className = 'feedback error';
        return;
    }

    manualItems.push({
        id: manualItemIdCounter++,
        name, qty, unit_price: unitPrice, vat_rate: manualItemVat.value,
    });

    manualItemName.value = '';
    manualItemQty.value = '1';
    manualItemPrice.value = '';
    manualItemFeedback.textContent = '';
    manualItemForm.classList.add('hidden');
    renderCart();
});

// --- Kedvezménykód / ajándékutalvány ---
const couponCodeInput = document.getElementById('coupon-code');
const couponApplyBtn = document.getElementById('coupon-apply-btn');
const giftCardCodeInput = document.getElementById('gift-card-code');
const giftCardApplyBtn = document.getElementById('gift-card-apply-btn');
const appliedDiscountsBox = document.getElementById('applied-discounts');

let appliedCoupon = null;
let appliedGiftCard = null;

function cartSubtotal() {
    let sum = 0;
    for (const { product, qty } of cart.values()) sum += product.price * qty;
    for (const item of manualItems) sum += item.unit_price * item.qty;
    return sum;
}

function computeGrandTotal() {
    let total = cartSubtotal();
    if (appliedCoupon) total = Math.max(0, total - appliedCoupon.discount);
    if (selectedCustomer && loyaltyPointValueHuf > 0) {
        const points = parseInt(redeemPointsInput.value, 10) || 0;
        if (points > 0) total = Math.max(0, total - points * loyaltyPointValueHuf);
    }
    if (appliedGiftCard) total = Math.max(0, total - appliedGiftCard.redeemable);
    return total;
}

function updateGrandTotalDisplay() {
    cartTotalValue.textContent = fmt(computeGrandTotal());
}

function renderAppliedDiscounts() {
    const rows = [];
    if (appliedCoupon) {
        rows.push(`
            <div class="search-result-item">
                <span>Kupon: ${escapeHtml(appliedCoupon.code)} (−${fmt(appliedCoupon.discount)})</span>
                <button type="button" class="remove-btn" id="remove-coupon-btn" title="Eltávolítás"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
        `);
    }
    if (appliedGiftCard) {
        rows.push(`
            <div class="search-result-item">
                <span>Utalvány: ${escapeHtml(appliedGiftCard.code)} (−${fmt(appliedGiftCard.redeemable)}, becsült)</span>
                <button type="button" class="remove-btn" id="remove-gift-card-btn" title="Eltávolítás"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
        `);
    }
    appliedDiscountsBox.innerHTML = rows.join('');

    const removeCouponBtn = document.getElementById('remove-coupon-btn');
    if (removeCouponBtn) removeCouponBtn.addEventListener('click', () => { appliedCoupon = null; renderAppliedDiscounts(); updateGrandTotalDisplay(); });
    const removeGiftCardBtn = document.getElementById('remove-gift-card-btn');
    if (removeGiftCardBtn) removeGiftCardBtn.addEventListener('click', () => { appliedGiftCard = null; renderAppliedDiscounts(); updateGrandTotalDisplay(); });
}

couponApplyBtn.addEventListener('click', async () => {
    const code = couponCodeInput.value.trim();
    if (!code) return;
    try {
        const res = await fetch('/api/coupon-validate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, total: cartSubtotal() }),
        });
        const data = await res.json();
        if (!data.ok) {
            checkoutFeedback.textContent = data.error || 'A kupon nem érvényes.';
            checkoutFeedback.className = 'feedback error';
            return;
        }
        appliedCoupon = { code: data.coupon.code, discount: data.discount };
        couponCodeInput.value = '';
        checkoutFeedback.textContent = '';
        renderAppliedDiscounts();
        updateGrandTotalDisplay();
    } catch (err) {
        checkoutFeedback.textContent = 'Hiba: ' + err.message;
        checkoutFeedback.className = 'feedback error';
    }
});

giftCardApplyBtn.addEventListener('click', async () => {
    const code = giftCardCodeInput.value.trim();
    if (!code) return;
    try {
        const res = await fetch('/api/gift-card-validate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, total: cartSubtotal() - (appliedCoupon ? appliedCoupon.discount : 0) }),
        });
        const data = await res.json();
        if (!data.ok) {
            checkoutFeedback.textContent = data.error || 'Az utalvány nem érvényes.';
            checkoutFeedback.className = 'feedback error';
            return;
        }
        appliedGiftCard = { code: data.gift_card.code, redeemable: data.redeemable };
        giftCardCodeInput.value = '';
        checkoutFeedback.textContent = '';
        renderAppliedDiscounts();
        updateGrandTotalDisplay();
    } catch (err) {
        checkoutFeedback.textContent = 'Hiba: ' + err.message;
        checkoutFeedback.className = 'feedback error';
    }
});

checkoutBtn.addEventListener('click', async () => {
    const buyerInput = collectBuyerInput();
    if (buyerInput.error) {
        checkoutFeedback.textContent = buyerInput.error;
        checkoutFeedback.className = 'feedback error';
        return;
    }

    checkoutBtn.disabled = true;
    checkoutFeedback.textContent = 'Feldolgozás...';
    checkoutFeedback.className = 'feedback';

    const items = [
        ...Array.from(cart.values()).map(({ product, qty }) => ({ product_id: product.id, qty })),
        ...manualItems.map(({ name, qty, unit_price, vat_rate }) => ({ manual: true, name, qty, unit_price, vat_rate })),
    ];

    const payload = { items, payment_method: paymentMethodSelect.value };
    if (appliedCoupon) payload.coupon_code = appliedCoupon.code;
    if (currentStaff) payload.staff_id = currentStaff.id;
    if (!locationSelector.classList.contains('hidden') && locationSelector.value) {
        payload.location_id = parseInt(locationSelector.value, 10);
    }
    if (appliedGiftCard) payload.gift_card_code = appliedGiftCard.code;
    if (buyerInput.buyer) {
        payload.buyer = buyerInput.buyer;
        payload.invoice_language = buyerInput.language;
    }
    if (selectedCustomer) {
        payload.customer_id = selectedCustomer.id;
        payload.redeem_points = parseInt(redeemPointsInput.value, 10) || 0;
    }

    try {
        const res = await fetch('/api/sale.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok) {
            checkoutFeedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            checkoutFeedback.className = 'feedback error';
            checkoutBtn.disabled = false;
            return;
        }

        let msg = `Eladás rögzítve (#${data.sale_id}), összesen ${fmt(data.total)}.`;
        if (data.invoice && data.invoice.success) {
            msg += ' Számla kiállítva' + (data.invoice.invoice_number ? `: ${data.invoice.invoice_number}` : '.') + '.';
        } else if (data.invoice) {
            msg += ' Figyelem: a számla kiállítása nem sikerült (' + (data.invoice.error || '?') + ').';
        }
        if (data.wc_push_errors && data.wc_push_errors.length) {
            msg += ' WooCommerce sync hiba: ' + data.wc_push_errors.join('; ');
        }
        if (data.oversold_items && data.oversold_items.length) {
            msg += ' ⚠ Mínuszos készlet: ' + data.oversold_items.map(i => `${i.name} (${i.new_stock} db)`).join(', ') + '.';
        }
        if (data.loyalty) {
            msg += ` Törzsvásárlói pont: +${data.loyalty.points_earned}`;
            if (data.loyalty.points_redeemed > 0) {
                msg += `, -${data.loyalty.points_redeemed} beváltva (${fmt(data.loyalty.redeem_discount)} kedvezmény)`;
            }
            msg += `. Új egyenleg: ${data.loyalty.new_balance} pont.`;
            if (data.loyalty.tier_discount > 0) {
                const tierLabel = data.loyalty.tier === 'gold' ? 'Arany' : (data.loyalty.tier === 'silver' ? 'Ezüst' : data.loyalty.tier);
                msg += ` ${tierLabel} szintű hűségkedvezmény: −${fmt(data.loyalty.tier_discount)}.`;
            }
        }
        if (data.coupon) {
            msg += ` Kupon (${data.coupon.code}): −${fmt(data.coupon.discount)}.`;
        }
        if (data.gift_card) {
            msg += ` Utalvány (${data.gift_card.code}): −${fmt(data.gift_card.redeemed)}, új egyenleg: ${fmt(data.gift_card.new_balance)}.`;
        }

        checkoutFeedback.textContent = msg;
        checkoutFeedback.className = 'feedback ok';

        lastSaleId = data.sale_id;
        lastReceiptToken = data.receipt_token;
        receiptLinkWrap.classList.remove('hidden');
        receiptEmailInput.value = (selectedCustomer && selectedCustomer.email) ? selectedCustomer.email : '';
        receiptEmailFeedback.textContent = '';
        startUndoCountdown(data.sale_id);

        cart.clear();
        manualItems.length = 0;
        appliedCoupon = null;
        appliedGiftCard = null;
        renderAppliedDiscounts();
        renderCart();
        resetBuyerForm();
        clearSelectedCustomer();
        loadProducts();
        barcodeInput.focus();
    } catch (err) {
        checkoutFeedback.textContent = 'Hiba: ' + err.message;
        checkoutFeedback.className = 'feedback error';
    } finally {
        checkoutBtn.disabled = cart.size === 0;
    }
});

document.addEventListener('stockmanager:synced', () => loadProducts());

viewReceiptBtn.addEventListener('click', () => {
    if (lastSaleId) {
        window.open('receipt.html?sale_id=' + lastSaleId + '&token=' + encodeURIComponent(lastReceiptToken || ''), '_blank');
    }
});

// --- Legutóbbi eladás visszavonása (60 másodpercig elérhető) ---
function startUndoCountdown(saleId) {
    if (undoTimer) clearInterval(undoTimer);
    let secondsLeft = 60;
    undoCountdownEl.textContent = String(secondsLeft);
    undoSaleFeedback.textContent = '';
    undoSaleBtn.classList.remove('hidden');
    undoSaleBtn.disabled = false;
    undoSaleBtn.dataset.saleId = saleId;

    undoTimer = setInterval(() => {
        secondsLeft -= 1;
        if (secondsLeft <= 0) {
            clearInterval(undoTimer);
            undoSaleBtn.classList.add('hidden');
            return;
        }
        undoCountdownEl.textContent = String(secondsLeft);
    }, 1000);
}

undoSaleBtn.addEventListener('click', async () => {
    const saleId = Number(undoSaleBtn.dataset.saleId);
    if (!saleId) return;
    const confirmed = confirm('Biztosan visszavonod ezt az eladást? A készlet visszaáll, a tétel(ek) visszáruként rögzülnek.');
    if (!confirmed) return;

    undoSaleBtn.disabled = true;
    undoSaleFeedback.textContent = 'Visszavonás...';
    undoSaleFeedback.className = 'feedback';
    try {
        const detailRes = await fetch('/api/sale-detail.php?sale_id=' + saleId);
        const detailData = await detailRes.json();
        if (!detailRes.ok) throw new Error(detailData.error || 'Az eladás adatai nem elérhetők.');

        const items = detailData.sale.items.map(item => ({ sale_item_id: item.id, qty: item.qty }));

        let staffId = null;
        try {
            const staffRaw = localStorage.getItem('sm_current_staff');
            if (staffRaw) staffId = JSON.parse(staffRaw).id;
        } catch (e) { /* ignore */ }

        const res = await fetch('/api/return-create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sale_id: saleId, items, reason: 'Azonnali visszavonás a Kasszáról', staff_id: staffId }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');

        if (undoTimer) clearInterval(undoTimer);
        undoSaleBtn.classList.add('hidden');
        undoSaleFeedback.textContent = `Az eladás visszavonva. Visszatérítendő összeg: ${fmt(data.total_refund)}.`;
        undoSaleFeedback.className = 'feedback ok';
        loadProducts(); // a visszavont készlet frissen jelenjen meg a keresésben
    } catch (err) {
        undoSaleFeedback.textContent = 'Hiba: ' + err.message;
        undoSaleFeedback.className = 'feedback error';
        undoSaleBtn.disabled = false;
    }
});

sendReceiptEmailBtn.addEventListener('click', async () => {
    const email = receiptEmailInput.value.trim();
    if (!lastSaleId) return;
    if (!email) {
        receiptEmailFeedback.textContent = 'Add meg az email címet.';
        receiptEmailFeedback.className = 'feedback error';
        return;
    }
    receiptEmailFeedback.textContent = 'Küldés...';
    receiptEmailFeedback.className = 'feedback';
    try {
        const res = await fetch('/api/send-receipt-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sale_id: lastSaleId, email }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        receiptEmailFeedback.textContent = 'Elküldve.';
        receiptEmailFeedback.className = 'feedback ok';
    } catch (err) {
        receiptEmailFeedback.textContent = 'Hiba: ' + err.message;
        receiptEmailFeedback.className = 'feedback error';
    }
});

renderCart();
