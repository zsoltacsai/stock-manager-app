let allClients = [];

const clientsBody = document.getElementById('clients-body');
const newClientBtn = document.getElementById('new-client-btn');
const newClientModal = document.getElementById('new-client-modal');
const newClientFeedback = document.getElementById('new-client-feedback');
const newClientCancel = document.getElementById('new-client-cancel');
const newClientSave = document.getElementById('new-client-save');
const clientLabelInput = document.getElementById('client-label');

const secretModal = document.getElementById('secret-modal');
const secretClientIdInput = document.getElementById('secret-client-id');
const secretValueInput = document.getElementById('secret-value');
const secretModalClose = document.getElementById('secret-modal-close');

async function loadClients() {
    try {
        const data = await fetchJson('/api/clients-list.php');
        allClients = data.clients || [];
        renderClients();
    } catch (err) {
        clientsBody.innerHTML = `<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Hiba a kliensek betöltésekor: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function statusBadge(c) {
    if (c.revoked_at) return '<span class="stock-badge">Visszavonva</span>';
    if (!Number(c.is_active)) return '<span class="stock-badge warn">Letiltva</span>';
    return '<span class="stock-badge ok">Aktív</span>';
}

function renderClients() {
    clientsBody.innerHTML = allClients.length ? allClients.map(c => `
        <tr>
            <td>${escapeHtml(c.label)}</td>
            <td><code>${escapeHtml(c.client_id)}</code></td>
            <td>${statusBadge(c)}</td>
            <td>${c.last_seen_at ? escapeHtml(c.last_seen_at) : '<span class="muted">még sose</span>'}</td>
            <td class="actions-cell">${renderActions(c)}</td>
        </tr>
    `).join('') : '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Még nincs regisztrálva kliens.</td></tr>';

    clientsBody.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => handleAction(Number(btn.dataset.id), btn.dataset.action));
    });
}

function renderActions(c) {
    if (c.revoked_at) {
        return '<span class="muted">—</span>';
    }
    const isActive = Number(c.is_active);
    return `
        <button class="edit-btn" data-action="rotate" data-id="${c.id}">Titok cseréje</button>
        ${isActive
            ? `<button class="edit-btn" data-action="disable" data-id="${c.id}">Letiltás</button>`
            : `<button class="edit-btn" data-action="enable" data-id="${c.id}">Engedélyezés</button>`}
        <button class="edit-btn" data-action="revoke" data-id="${c.id}" style="color:var(--danger);">Visszavonás</button>
    `;
}

async function handleAction(id, action) {
    if (action === 'revoke' && !confirm('Biztosan véglegesen visszavonod ezt a klienst? Ez nem vonható vissza, és a client ID később nem hasznosítható újra.')) {
        return;
    }
    if (action === 'disable' && !confirm('Ideiglenesen letiltod ezt a klienst? Később újra engedélyezhető.')) {
        return;
    }
    try {
        const res = await fetch(`/api/client-${action}.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        if (action === 'rotate') {
            showSecret(data.client_secret, allClients.find(c => c.id === id)?.client_id || '');
        }
        loadClients();
    } catch (err) {
        alert('Hiba: ' + err.message);
    }
}

function showSecret(secret, clientId) {
    secretClientIdInput.value = clientId;
    secretValueInput.value = secret;
    secretModal.classList.add('open');
}

newClientBtn.addEventListener('click', () => {
    clientLabelInput.value = '';
    newClientFeedback.textContent = '';
    newClientModal.classList.add('open');
});
newClientCancel.addEventListener('click', () => newClientModal.classList.remove('open'));
newClientSave.addEventListener('click', async () => {
    const label = clientLabelInput.value.trim();
    if (!label) {
        newClientFeedback.textContent = 'A címke megadása kötelező.';
        newClientFeedback.className = 'modal-feedback error';
        return;
    }
    try {
        const res = await fetch('/api/client-register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ label }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');
        newClientModal.classList.remove('open');
        showSecret(data.client_secret, data.client_id);
        loadClients();
    } catch (err) {
        newClientFeedback.textContent = 'Hiba: ' + err.message;
        newClientFeedback.className = 'modal-feedback error';
    }
});

secretModalClose.addEventListener('click', () => secretModal.classList.remove('open'));

loadClients();
