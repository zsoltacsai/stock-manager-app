const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

function copyIconBtn(text) {
    return `<button type="button" class="copy-code-btn" data-copy="${escapeHtml(text)}" title="Másolás vágólapra"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button>`;
}

function wireCopyButtons(container) {
    container.querySelectorAll('.copy-code-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await navigator.clipboard.writeText(btn.dataset.copy);
                btn.classList.add('copy-code-btn-done');
                setTimeout(() => btn.classList.remove('copy-code-btn-done'), 1200);
            } catch (err) { /* clipboard access denied — silently do nothing */ }
        });
    });
}

// =========================================================================
// KUPONOK
// =========================================================================
let allCoupons = [];
const couponsBody = document.getElementById('coupons-body');
const newCouponBtn = document.getElementById('new-coupon-btn');
const couponModal = document.getElementById('coupon-modal');
const couponModalTitle = document.getElementById('coupon-modal-title');
const couponModalFeedback = document.getElementById('coupon-modal-feedback');
const couponModalClose = document.getElementById('coupon-modal-close');
const couponModalSave = document.getElementById('coupon-modal-save');
const cpnId = document.getElementById('cpn-id');
const cpnCode = document.getElementById('cpn-code');
const cpnType = document.getElementById('cpn-type');
const cpnValue = document.getElementById('cpn-value');
const cpnExpiry = document.getElementById('cpn-expiry');
const cpnUsageLimit = document.getElementById('cpn-usage-limit');
const cpnMinPurchase = document.getElementById('cpn-min-purchase');
const cpnNotes = document.getElementById('cpn-notes');
const cpnActive = document.getElementById('cpn-active');

async function loadCoupons() {
    const res = await fetch('/api/coupons-list.php');
    const data = await res.json();
    allCoupons = data.coupons || [];
    renderCoupons();
}

function renderCoupons() {
    couponsBody.innerHTML = allCoupons.length ? allCoupons.map(c => `
        <tr class="clickable-row" data-id="${c.id}">
            <td>${escapeHtml(c.code)} ${copyIconBtn(c.code)}</td>
            <td>${c.type === 'fixed' ? 'Fix' : 'Százalék'}</td>
            <td>${c.type === 'fixed' ? fmt(c.value) : c.value + '%'}</td>
            <td>${c.times_used}${c.usage_limit ? ' / ' + c.usage_limit : ''}</td>
            <td>${c.expiry_date || '—'}</td>
            <td>${Number(c.is_active) ? '<span class="stock-badge ok">aktív</span>' : '<span class="stock-badge zero">inaktív</span>'}</td>
            <td><button class="edit-btn" data-id="${c.id}">Módosítás</button></td>
        </tr>
    `).join('') : '<tr><td colspan="7" class="muted" style="text-align:center; padding:24px;">Még nincs kupon.</td></tr>';

    wireCopyButtons(couponsBody);
    couponsBody.querySelectorAll('.clickable-row, .edit-btn').forEach(elm => {
        elm.addEventListener('click', (e) => {
            e.stopPropagation();
            openCouponEdit(Number(elm.dataset.id));
        });
    });
}

function openCouponEdit(id) {
    const coupon = id ? allCoupons.find(c => c.id === id) : null;
    couponModalTitle.textContent = coupon ? 'Kupon módosítása' : 'Új kupon';
    cpnId.value = coupon ? coupon.id : '';
    cpnCode.value = coupon ? coupon.code : '';
    cpnType.value = coupon ? coupon.type : 'percent';
    cpnValue.value = coupon ? coupon.value : '';
    cpnExpiry.value = coupon ? (coupon.expiry_date || '') : '';
    cpnUsageLimit.value = coupon && coupon.usage_limit !== null ? coupon.usage_limit : '';
    cpnMinPurchase.value = coupon ? coupon.min_purchase : '0';
    cpnNotes.value = coupon ? (coupon.notes || '') : '';
    cpnActive.checked = coupon ? !!Number(coupon.is_active) : true;
    couponModalFeedback.textContent = '';
    couponModal.classList.add('open');
}

newCouponBtn.addEventListener('click', () => openCouponEdit(null));
couponModalClose.addEventListener('click', () => couponModal.classList.remove('open'));
couponModalSave.addEventListener('click', async () => {
    if (!cpnCode.value.trim() || cpnValue.value === '') {
        couponModalFeedback.textContent = 'A kód és az érték megadása kötelező.';
        couponModalFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: cpnId.value ? parseInt(cpnId.value, 10) : undefined,
        code: cpnCode.value.trim().toUpperCase(),
        type: cpnType.value,
        value: parseFloat(cpnValue.value.replace(',', '.')),
        expiry_date: cpnExpiry.value || null,
        usage_limit: cpnUsageLimit.value.trim() !== '' ? parseInt(cpnUsageLimit.value, 10) : '',
        min_purchase: parseFloat(cpnMinPurchase.value.replace(',', '.')) || 0,
        notes: cpnNotes.value.trim(),
        is_active: cpnActive.checked,
    };
    couponModalFeedback.textContent = 'Mentés...';
    couponModalFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/coupon-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        couponModal.classList.remove('open');
        loadCoupons();
    } catch (err) {
        couponModalFeedback.textContent = 'Hiba: ' + err.message;
        couponModalFeedback.className = 'modal-feedback error';
    }
});

// =========================================================================
// AJÁNDÉKUTALVÁNYOK
// =========================================================================
let allGiftCards = [];
const giftCardsBody = document.getElementById('gift-cards-body');
const newGiftCardBtn = document.getElementById('new-gift-card-btn');
const giftCardModal = document.getElementById('gift-card-modal');
const giftCardModalFeedback = document.getElementById('gift-card-modal-feedback');
const giftCardModalClose = document.getElementById('gift-card-modal-close');
const giftCardModalSave = document.getElementById('gift-card-modal-save');
const gcCode = document.getElementById('gc-code');
const gcBalance = document.getElementById('gc-balance');
const gcExpiry = document.getElementById('gc-expiry');
const gcNotes = document.getElementById('gc-notes');

const giftCardHistoryModal = document.getElementById('gift-card-history-modal');
const giftCardHistoryBody = document.getElementById('gift-card-history-body');
const giftCardHistoryClose = document.getElementById('gift-card-history-close');

async function loadGiftCards() {
    const res = await fetch('/api/gift-cards-list.php');
    const data = await res.json();
    allGiftCards = data.gift_cards || [];
    renderGiftCards();
}

function renderGiftCards() {
    giftCardsBody.innerHTML = allGiftCards.length ? allGiftCards.map(g => `
        <tr>
            <td class="clickable-row" data-history-id="${g.id}">${escapeHtml(g.code)} ${copyIconBtn(g.code)}</td>
            <td>${fmt(g.initial_balance)}</td>
            <td>${fmt(g.current_balance)}</td>
            <td>${g.expiry_date || '—'}</td>
            <td>${Number(g.is_active) ? '<span class="stock-badge ok">aktív</span>' : '<span class="stock-badge zero">inaktív</span>'}</td>
            <td><button class="edit-btn toggle-gc-btn" data-id="${g.id}" data-active="${Number(g.is_active)}">${Number(g.is_active) ? 'Inaktiválás' : 'Aktiválás'}</button></td>
        </tr>
    `).join('') : '<tr><td colspan="6" class="muted" style="text-align:center; padding:24px;">Még nincs kiállított utalvány.</td></tr>';

    wireCopyButtons(giftCardsBody);
    giftCardsBody.querySelectorAll('[data-history-id]').forEach(elm => {
        elm.addEventListener('click', () => openGiftCardHistory(Number(elm.dataset.historyId)));
    });
    giftCardsBody.querySelectorAll('.toggle-gc-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const isActive = btn.dataset.active === '1';
            await fetch('/api/gift-card-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ toggle_id: Number(btn.dataset.id), active: !isActive }),
            });
            loadGiftCards();
        });
    });
}

async function openGiftCardHistory(id) {
    giftCardHistoryBody.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
    giftCardHistoryModal.classList.add('open');
    try {
        const res = await fetch('/api/gift-card-detail.php?id=' + id);
        const data = await res.json();
        const card = data.gift_card;
        const rows = (card.history || []).map(h => `
            <div class="muted">${h.created_at} — ${h.amount_delta > 0 ? '+' : ''}${fmt(h.amount_delta)} ${h.note ? '(' + escapeHtml(h.note) + ')' : ''}</div>
        `).join('') || '<div class="muted">Nincs még mozgás.</div>';
        giftCardHistoryBody.innerHTML = `
            <p><strong>${escapeHtml(card.code)}</strong> — jelenlegi egyenleg: ${fmt(card.current_balance)} (kezdő: ${fmt(card.initial_balance)})</p>
            ${rows}
        `;
    } catch (err) {
        giftCardHistoryBody.innerHTML = `<p class="feedback error">Hiba: ${escapeHtml(err.message)}</p>`;
    }
}

newGiftCardBtn.addEventListener('click', () => {
    gcCode.value = '';
    gcBalance.value = '';
    gcExpiry.value = '';
    gcNotes.value = '';
    giftCardModalFeedback.textContent = '';
    giftCardModal.classList.add('open');
});
giftCardModalClose.addEventListener('click', () => giftCardModal.classList.remove('open'));
giftCardHistoryClose.addEventListener('click', () => giftCardHistoryModal.classList.remove('open'));

giftCardModalSave.addEventListener('click', async () => {
    const balance = parseFloat(gcBalance.value.replace(',', '.'));
    if (!gcCode.value.trim() || isNaN(balance) || balance <= 0) {
        giftCardModalFeedback.textContent = 'A kód és egy 0-nál nagyobb egyenleg megadása kötelező.';
        giftCardModalFeedback.className = 'modal-feedback error';
        return;
    }
    giftCardModalFeedback.textContent = 'Kiállítás...';
    giftCardModalFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/gift-card-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: gcCode.value.trim().toUpperCase(),
                balance,
                expiry_date: gcExpiry.value || null,
                notes: gcNotes.value.trim(),
            }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        giftCardModal.classList.remove('open');
        loadGiftCards();
    } catch (err) {
        giftCardModalFeedback.textContent = 'Hiba: ' + err.message;
        giftCardModalFeedback.className = 'modal-feedback error';
    }
});

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

loadCoupons();
loadGiftCards();
