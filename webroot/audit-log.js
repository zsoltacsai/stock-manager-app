const ACTION_LABELS = {
    product_delete: 'Termék törölve',
    settings_change: 'Beállítás módosítva',
    staff_create: 'Dolgozó felvéve',
};

async function loadAuditLog() {
    const body = document.getElementById('audit-log-body');
    body.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Betöltés...</td></tr>';
    try {
        const res = await fetch('/api/audit-log.php');
        const data = await res.json();
        const entries = data.entries || [];

        body.innerHTML = entries.length ? entries.map(e => `
            <tr>
                <td>${e.created_at}</td>
                <td>${escapeHtml(e.staff_name || '—')}</td>
                <td>${ACTION_LABELS[e.action] || e.action}</td>
                <td>${e.entity_type ? e.entity_type + (e.entity_id ? ' #' + e.entity_id : '') : '—'}</td>
                <td class="muted">${escapeHtml(e.details || '')}</td>
            </tr>
        `).join('') : '<tr><td colspan="5" class="muted" style="text-align:center; padding:24px;">Még nincs naplóbejegyzés.</td></tr>';
    } catch (e) {
        body.innerHTML = '<tr><td colspan="5" class="feedback error">A napló betöltése sikertelen.</td></tr>';
    }
}

loadAuditLog();
