let allStaff = [];

const searchInput = document.getElementById('search-input');
const staffBody = document.getElementById('staff-body');
const newStaffBtn = document.getElementById('new-staff-btn');

const modal = document.getElementById('staff-modal');
const modalTitle = document.getElementById('staff-modal-title');
const modalFeedback = document.getElementById('staff-modal-feedback');
const closeBtn = document.getElementById('staff-modal-close');
const saveBtn = document.getElementById('staff-modal-save');
const stfId = document.getElementById('stf-id');
const stfName = document.getElementById('stf-name');
const stfPin = document.getElementById('stf-pin');
const stfRole = document.getElementById('stf-role');
const stfActive = document.getElementById('stf-active');

async function loadStaff() {
    const res = await fetch('/api/staff-list.php?include_inactive=1');
    const data = await res.json();
    allStaff = data.staff || [];
    renderTable();
}

function renderTable() {
    const q = searchInput.value.trim().toLowerCase();
    const filtered = allStaff.filter(s => !q || s.name.toLowerCase().includes(q));

    staffBody.innerHTML = filtered.length ? filtered.map(s => `
        <tr class="clickable-row" data-id="${s.id}">
            <td>${escapeHtml(s.name)}</td>
            <td>${s.role === 'admin' ? 'Vezető' : 'Eladó'}</td>
            <td>${Number(s.is_active) ? '<span class="stock-badge ok">aktív</span>' : '<span class="stock-badge zero">inaktív</span>'}</td>
            <td><button class="edit-btn" data-id="${s.id}">Módosítás</button></td>
        </tr>
    `).join('') : '<tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">Még nincs felvéve dolgozó.</td></tr>';

    staffBody.querySelectorAll('.clickable-row, .edit-btn').forEach(elm => {
        elm.addEventListener('click', (e) => {
            e.stopPropagation();
            openEdit(Number(elm.dataset.id));
        });
    });
}

function openEdit(id) {
    const staff = id ? allStaff.find(s => s.id === id) : null;
    modalTitle.textContent = staff ? 'Dolgozó módosítása' : 'Új dolgozó';
    stfId.value = staff ? staff.id : '';
    stfName.value = staff ? staff.name : '';
    stfPin.value = '';
    stfRole.value = staff ? (staff.role || 'cashier') : 'cashier';
    stfActive.checked = staff ? !!Number(staff.is_active) : true;
    modalFeedback.textContent = '';
    modal.classList.add('open');
}

newStaffBtn.addEventListener('click', () => openEdit(null));
closeBtn.addEventListener('click', () => modal.classList.remove('open'));
searchInput.addEventListener('input', renderTable);

saveBtn.addEventListener('click', async () => {
    if (!stfName.value.trim()) {
        modalFeedback.textContent = 'A név megadása kötelező.';
        modalFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: stfId.value ? parseInt(stfId.value, 10) : undefined,
        name: stfName.value.trim(),
        role: stfRole.value,
        is_active: stfActive.checked,
    };
    if (stfPin.value.trim()) payload.pin = stfPin.value.trim();
    try {
        const staffRaw = localStorage.getItem('sm_current_staff');
        if (staffRaw) payload.staff_id = JSON.parse(staffRaw).id;
    } catch (e) { /* ignore corrupt storage */ }

    modalFeedback.textContent = 'Mentés...';
    modalFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/staff-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        modal.classList.remove('open');
        loadStaff();
    } catch (err) {
        modalFeedback.textContent = 'Hiba: ' + err.message;
        modalFeedback.className = 'modal-feedback error';
    }
});

loadStaff();
