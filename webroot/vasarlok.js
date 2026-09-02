let allCustomers = [];

const searchInput = document.getElementById('search-input');
const customersBody = document.getElementById('customers-body');
const newCustomerBtn = document.getElementById('new-customer-btn');

const modal = document.getElementById('customer-modal');
const modalTitle = document.getElementById('customer-modal-title');
const modalFeedback = document.getElementById('customer-modal-feedback');
const closeBtn = document.getElementById('customer-modal-close');
const saveBtn = document.getElementById('customer-modal-save');
const deletedToggle = document.getElementById('c-deleted');
const pointsBalance = document.getElementById('c-points-balance');
const pointsHistory = document.getElementById('c-points-history');

const fields = ['id', 'name', 'phone', 'email', 'taxnum', 'zip', 'city', 'address', 'country', 'notes'];
const el = {};
fields.forEach(f => { el[f] = document.getElementById('c-' + f); });

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft';

let tierSettings = { silverThreshold: 50000, goldThreshold: 150000 };

async function loadTierSettings() {
    try {
        const data = await window.smSettingsPromise;
        tierSettings.silverThreshold = Number(data.loyalty_tier_silver_threshold ?? 50000);
        tierSettings.goldThreshold = Number(data.loyalty_tier_gold_threshold ?? 150000);
    } catch (e) { /* keep defaults */ }
}

function tierFor(totalSpent) {
    const spent = Number(totalSpent) || 0;
    if (spent >= tierSettings.goldThreshold) return { label: 'Arany', cls: 'ok' };
    if (spent >= tierSettings.silverThreshold) return { label: 'Ezüst', cls: 'warn' };
    return { label: 'Bronz', cls: 'zero' };
}

async function loadCustomers() {
    const res = await fetch('/api/customers-list.php?include_deleted=1');
    const data = await res.json();
    allCustomers = data.customers || [];
    renderTable();
}

function renderTable() {
    const q = searchInput.value.trim().toLowerCase();
    const filtered = allCustomers.filter(c =>
        !q || c.name.toLowerCase().includes(q) || (c.phone || '').includes(q) || (c.email || '').toLowerCase().includes(q)
    );

    customersBody.innerHTML = filtered.length ? filtered.map(c => {
        const tier = tierFor(c.total_spent);
        return `
        <tr class="clickable-row ${Number(c.is_deleted) ? 'deleted-row' : ''}" data-id="${c.id}">
            <td>${escapeHtml(c.name)}${Number(c.is_deleted) ? ' <span class="muted">(törölve)</span>' : ''}</td>
            <td>${escapeHtml(c.phone || '—')}</td>
            <td>${escapeHtml(c.email || '—')}</td>
            <td><span class="stock-badge ok">${c.loyalty_points} pont</span> <span class="stock-badge ${tier.cls}">${tier.label}</span></td>
            <td>
                <div class="row-actions">
                    <button class="edit-btn" data-id="${c.id}">Módosítás</button>
                    <button class="toggle-delete-btn danger" data-id="${c.id}">${Number(c.is_deleted) ? 'Visszaállítás' : 'Törlés'}</button>
                </div>
            </td>
        </tr>
    `;
    }).join('') : '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő vásárló.</td></tr>';

    customersBody.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('.row-actions')) return;
            openEdit(Number(row.dataset.id));
        });
    });
    customersBody.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openEdit(Number(btn.dataset.id));
        });
    });
    customersBody.querySelectorAll('.toggle-delete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleDeletedCustomer(Number(btn.dataset.id));
        });
    });
}

async function toggleDeletedCustomer(id) {
    const customer = allCustomers.find(c => c.id === id);
    if (!customer) return;

    const nextDeleted = !Number(customer.is_deleted);
    let confirmMsg = nextDeleted
        ? `Törlöd a(z) "${customer.name}" vásárlót?`
        : `Visszaállítod a(z) "${customer.name}" vásárlót?`;
    if (nextDeleted && Number(customer.total_spent) > 0) {
        confirmMsg = `"${customer.name}" vásárlónak már volt korábbi vásárlása (összesen ${fmt(customer.total_spent)}). ` +
            `A törlés a vásárlási előzményeket nem érinti, csak elrejti a vásárlót a Kasszán és a listákban. Biztosan törlöd?`;
    }
    if (!confirm(confirmMsg)) return;

    let staffId;
    try {
        const staffRaw = localStorage.getItem('sm_current_staff');
        if (staffRaw) staffId = JSON.parse(staffRaw).id;
    } catch (e) { /* ignore corrupt storage */ }

    await fetch('/api/customer-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: customer.id,
            name: customer.name,
            phone: customer.phone,
            email: customer.email,
            tax_number: customer.tax_number,
            zip: customer.zip,
            city: customer.city,
            address: customer.address,
            country: customer.country,
            notes: customer.notes,
            is_deleted: nextDeleted,
            staff_id: staffId,
        }),
    });
    loadCustomers();
}

const tabStatsBtn = document.getElementById('c-tab-stats-btn');
const tabItemsBtn = document.getElementById('c-tab-items-btn');
const statsGrid = document.getElementById('c-stats-grid');
const itemsBody = document.getElementById('c-items-body');

function fmtDateTime(s) {
    return s || '—';
}

function resetToDataTab() {
    document.querySelectorAll('#customer-modal-tabs .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#customer-modal .tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector('[data-tab="c-tab-data"]').classList.add('active');
    document.getElementById('c-tab-data').classList.add('active');
}

document.getElementById('customer-modal-tabs').addEventListener('click', (e) => {
    const btn = e.target.closest('.tab-btn');
    if (!btn || btn.classList.contains('hidden')) return;
    document.querySelectorAll('#customer-modal-tabs .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#customer-modal .tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
});

async function openEdit(id) {
    const customer = id ? allCustomers.find(c => c.id === id) : null;
    modalTitle.textContent = customer ? 'Vásárló módosítása' : 'Új vásárló';
    el.id.value = customer ? customer.id : '';
    el.name.value = customer ? customer.name : '';
    el.phone.value = customer ? (customer.phone || '') : '';
    el.email.value = customer ? (customer.email || '') : '';
    el.taxnum.value = customer ? (customer.tax_number || '') : '';
    el.zip.value = customer ? (customer.zip || '') : '';
    el.city.value = customer ? (customer.city || '') : '';
    el.address.value = customer ? (customer.address || '') : '';
    el.country.value = customer ? (customer.country || 'Magyarország') : 'Magyarország';
    el.notes.value = customer ? (customer.notes || '') : '';
    deletedToggle.classList.toggle('on', customer ? !!Number(customer.is_deleted) : false);
    modalFeedback.textContent = '';
    resetToDataTab();

    if (customer) {
        tabStatsBtn.classList.remove('hidden');
        tabItemsBtn.classList.remove('hidden');
        pointsBalance.textContent = customer.loyalty_points;
        pointsHistory.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
        statsGrid.innerHTML = '<div class="spinner-row"><span class="spinner"></span>Betöltés...</div>';
        itemsBody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Betöltés...</td></tr>';
        try {
            const res = await fetch('/api/customer-detail.php?id=' + id);
            const data = await res.json();

            const history = (data.customer && data.customer.loyalty_history) || [];
            pointsHistory.innerHTML = history.length
                ? history.map(h => `<div class="muted">${h.created_at} — ${h.points_delta > 0 ? '+' : ''}${h.points_delta} pont ${h.note ? '(' + escapeHtml(h.note) + ')' : ''}</div>`).join('')
                : '<div class="muted">Nincs még pontmozgás.</div>';

            const stats = data.stats || {};
            const tier = data.tier || 'bronze';
            const tierLabel = tier === 'gold' ? 'Arany' : (tier === 'silver' ? 'Ezüst' : 'Bronz');
            statsGrid.innerHTML = `
                <div class="stat-box"><div class="value">${tierLabel}</div><div class="label">Hűségszint</div></div>
                <div class="stat-box"><div class="value">${fmt(customer.total_spent)}</div><div class="label">Összes elköltés</div></div>
                <div class="stat-box"><div class="value">${stats.purchase_count}</div><div class="label">Vásárlások száma</div></div>
                <div class="stat-box"><div class="value">${fmt(stats.avg_purchase)}</div><div class="label">Átlagos kosárérték</div></div>
                <div class="stat-box"><div class="value" style="font-size:14px;">${fmtDateTime(stats.first_purchase_at)}</div><div class="label">Első vásárlás</div></div>
                <div class="stat-box"><div class="value" style="font-size:14px;">${fmtDateTime(stats.last_purchase_at)}</div><div class="label">Utolsó vásárlás</div></div>
            `;

            const items = data.items || [];
            itemsBody.innerHTML = items.length ? items.map(item => `
                <tr>
                    <td>${item.created_at}</td>
                    <td>${escapeHtml(item.name)}</td>
                    <td>${item.qty}</td>
                    <td>${fmt(item.unit_price)}</td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:16px;">Még nincs vásárlása.</td></tr>';
        } catch (e) {
            pointsHistory.innerHTML = '<div class="muted">Az előzmények betöltése sikertelen.</div>';
            statsGrid.innerHTML = '<p class="feedback error">A statisztika betöltése sikertelen.</p>';
            itemsBody.innerHTML = '<tr><td colspan="4" class="feedback error">A tételek betöltése sikertelen.</td></tr>';
        }
    } else {
        tabStatsBtn.classList.add('hidden');
        tabItemsBtn.classList.add('hidden');
    }

    modal.classList.add('open');
}

deletedToggle.addEventListener('click', () => deletedToggle.classList.toggle('on'));
closeBtn.addEventListener('click', () => modal.classList.remove('open'));
newCustomerBtn.addEventListener('click', () => openEdit(null));
searchInput.addEventListener('input', renderTable);

saveBtn.addEventListener('click', async () => {
    if (!el.name.value.trim()) {
        modalFeedback.textContent = 'A név megadása kötelező.';
        modalFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: el.id.value ? parseInt(el.id.value, 10) : undefined,
        name: el.name.value.trim(),
        phone: el.phone.value.trim(),
        email: el.email.value.trim(),
        tax_number: el.taxnum.value.trim(),
        zip: el.zip.value.trim(),
        city: el.city.value.trim(),
        address: el.address.value.trim(),
        country: el.country.value.trim(),
        notes: el.notes.value.trim(),
        is_deleted: deletedToggle.classList.contains('on'),
    };
    try {
        const staffRaw = localStorage.getItem('sm_current_staff');
        if (staffRaw) payload.staff_id = JSON.parse(staffRaw).id;
    } catch (e) { /* ignore corrupt storage */ }
    modalFeedback.textContent = 'Mentés...';
    modalFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/customer-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        modal.classList.remove('open');
        loadCustomers();
    } catch (err) {
        modalFeedback.textContent = 'Hiba: ' + err.message;
        modalFeedback.className = 'modal-feedback error';
    }
});

loadTierSettings().then(() => { if (allCustomers.length) renderTable(); });
loadCustomers();
