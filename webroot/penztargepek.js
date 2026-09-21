let allRegisters = [];
let allRegisterLocations = [];

const registersBody = document.getElementById('registers-body');
const newRegisterBtn = document.getElementById('new-register-btn');
const registerModal = document.getElementById('register-modal');
const registerModalTitle = document.getElementById('register-modal-title');
const registerModalFeedback = document.getElementById('register-modal-feedback');
const registerModalClose = document.getElementById('register-modal-close');
const registerModalSave = document.getElementById('register-modal-save');
const regId = document.getElementById('reg-id');
const regName = document.getElementById('reg-name');
const regCode = document.getElementById('reg-code');
const regLocation = document.getElementById('reg-location');
const regActive = document.getElementById('reg-active');

async function loadRegisters() {
    try {
        const [regData, locData] = await Promise.all([
            fetchJson('/api/cash-registers-list.php?include_inactive=1'),
            fetchJson('/api/locations-list.php'),
        ]);
        allRegisters = regData.registers || [];
        allRegisterLocations = locData.locations || [];
        renderRegisters();
    } catch (err) {
        registersBody.innerHTML = `<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Hiba a pénztárgépek betöltésekor: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderRegisters() {
    registersBody.innerHTML = allRegisters.length ? allRegisters.map(r => `
        <tr>
            <td>${escapeHtml(r.name)}</td>
            <td>${escapeHtml(r.code)}</td>
            <td>${escapeHtml(r.location_name || '—')}</td>
            <td>${Number(r.is_active) ? 'Igen' : 'Nem'}</td>
            <td><button class="edit-btn" data-id="${r.id}">Módosítás</button></td>
        </tr>
    `).join('') : '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Még nincs felvéve pénztárgép.</td></tr>';

    registersBody.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => openRegisterEdit(Number(btn.dataset.id)));
    });
}

function openRegisterEdit(id) {
    const reg = id ? allRegisters.find(r => r.id === id) : null;
    regLocation.innerHTML = allRegisterLocations.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
    registerModalTitle.textContent = reg ? 'Pénztárgép módosítása' : 'Új pénztárgép';
    regId.value = reg ? reg.id : '';
    regName.value = reg ? reg.name : '';
    regCode.value = reg ? reg.code : '';
    if (reg) regLocation.value = reg.location_id;
    regActive.checked = reg ? !!Number(reg.is_active) : true;
    registerModalFeedback.textContent = '';
    registerModal.classList.add('open');
}

newRegisterBtn.addEventListener('click', () => openRegisterEdit(null));
registerModalClose.addEventListener('click', () => registerModal.classList.remove('open'));
registerModalSave.addEventListener('click', async () => {
    if (!regName.value.trim() || !regCode.value.trim()) {
        registerModalFeedback.textContent = 'A név és a kód megadása kötelező.';
        registerModalFeedback.className = 'modal-feedback error';
        return;
    }
    if (!regLocation.value) {
        registerModalFeedback.textContent = 'Válassz telephelyet.';
        registerModalFeedback.className = 'modal-feedback error';
        return;
    }
    const payload = {
        id: regId.value ? parseInt(regId.value, 10) : undefined,
        name: regName.value.trim(),
        code: regCode.value.trim(),
        location_id: parseInt(regLocation.value, 10),
        is_active: regActive.checked,
    };
    try {
        const res = await fetch('/api/cash-register-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        registerModal.classList.remove('open');
        loadRegisters();
    } catch (err) {
        registerModalFeedback.textContent = 'Hiba: ' + err.message;
        registerModalFeedback.className = 'modal-feedback error';
    }
});

loadRegisters();
