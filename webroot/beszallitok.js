let allSuppliers = [];

const searchInput = document.getElementById('search-input');
const suppliersBody = document.getElementById('suppliers-body');
const newSupplierBtn = document.getElementById('new-supplier-btn');

const modal = document.getElementById('supplier-modal');
const modalTitle = document.getElementById('supplier-modal-title');
const modalFeedback = document.getElementById('supplier-modal-feedback');
const closeBtn = document.getElementById('supplier-modal-close');
const saveBtn = document.getElementById('supplier-modal-save');
const deletedToggle = document.getElementById('s-deleted');

const fields = ['id', 'name', 'contact', 'phone', 'email', 'zip', 'city', 'address', 'country', 'taxnum', 'terms', 'notes'];
const el = {};
fields.forEach(f => { el[f] = document.getElementById('s-' + f); });

async function loadSuppliers() {
    const res = await fetch('/api/suppliers-list.php?include_deleted=1');
    const data = await res.json();
    allSuppliers = data.suppliers || [];
    renderTable();
}

function renderTable() {
    const q = searchInput.value.trim().toLowerCase();
    const filtered = allSuppliers.filter(s =>
        !q || s.name.toLowerCase().includes(q) || (s.phone || '').includes(q) || (s.email || '').toLowerCase().includes(q)
    );

    suppliersBody.innerHTML = filtered.length ? filtered.map(s => `
        <tr class="clickable-row ${Number(s.is_deleted) ? 'deleted-row' : ''}" data-id="${s.id}">
            <td>${escapeHtml(s.name)}${Number(s.is_deleted) ? ' <span class="muted">(törölve)</span>' : ''}</td>
            <td>${escapeHtml(s.contact_name || '—')}</td>
            <td>${escapeHtml(s.phone || '—')}</td>
            <td>${escapeHtml(s.email || '—')}</td>
            <td>${escapeHtml(s.payment_terms || '—')}</td>
            <td><button class="edit-btn" data-id="${s.id}">Módosítás</button></td>
        </tr>
    `).join('') : '<tr><td colspan="6" class="muted" style="text-align:center; padding:24px;">Nincs a szűrésnek megfelelő beszállító.</td></tr>';

    suppliersBody.querySelectorAll('.clickable-row, .edit-btn').forEach(elm => {
        elm.addEventListener('click', (e) => {
            e.stopPropagation();
            openEdit(Number(elm.dataset.id));
        });
    });
}

function openEdit(id) {
    const supplier = id ? allSuppliers.find(s => s.id === id) : null;
    modalTitle.textContent = supplier ? 'Beszállító módosítása' : 'Új beszállító';
    el.id.value = supplier ? supplier.id : '';
    el.name.value = supplier ? supplier.name : '';
    el.contact.value = supplier ? (supplier.contact_name || '') : '';
    el.phone.value = supplier ? (supplier.phone || '') : '';
    el.email.value = supplier ? (supplier.email || '') : '';
    el.zip.value = supplier ? (supplier.zip || '') : '';
    el.city.value = supplier ? (supplier.city || '') : '';
    el.address.value = supplier ? (supplier.address || '') : '';
    el.country.value = supplier ? (supplier.country || 'Magyarország') : 'Magyarország';
    el.taxnum.value = supplier ? (supplier.tax_number || '') : '';
    el.terms.value = supplier ? (supplier.payment_terms || '') : '';
    el.notes.value = supplier ? (supplier.notes || '') : '';
    deletedToggle.classList.toggle('on', supplier ? !!Number(supplier.is_deleted) : false);
    modalFeedback.textContent = '';
    modal.classList.add('open');
}

deletedToggle.addEventListener('click', () => deletedToggle.classList.toggle('on'));
closeBtn.addEventListener('click', () => modal.classList.remove('open'));
newSupplierBtn.addEventListener('click', () => openEdit(null));
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
        contact_name: el.contact.value.trim(),
        phone: el.phone.value.trim(),
        email: el.email.value.trim(),
        zip: el.zip.value.trim(),
        city: el.city.value.trim(),
        address: el.address.value.trim(),
        country: el.country.value.trim(),
        tax_number: el.taxnum.value.trim(),
        payment_terms: el.terms.value.trim(),
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
        const res = await fetch('/api/supplier-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        modal.classList.remove('open');
        loadSuppliers();
    } catch (err) {
        modalFeedback.textContent = 'Hiba: ' + err.message;
        modalFeedback.className = 'modal-feedback error';
    }
});

loadSuppliers();
