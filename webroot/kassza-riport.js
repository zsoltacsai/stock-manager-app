function fmtFt(n) {
    return (n === null || n === undefined || n === '') ? '—' : Math.round(Number(n)).toLocaleString('hu-HU') + ' Ft';
}

const fDateFrom = document.getElementById('f-date-from');
const fDateTo = document.getElementById('f-date-to');
const fLocation = document.getElementById('f-location');
const fRegister = document.getElementById('f-register');
const fStatus = document.getElementById('f-status');
const resultsBody = document.getElementById('results-body');
const resultsCount = document.getElementById('results-count');

let allRegistersForFilter = [];

function currentFilters() {
    const filters = {
        date_from: fDateFrom.value || '',
        date_to: fDateTo.value || '',
        location_id: fLocation.value || '',
        cash_register_id: fRegister.value || '',
        status: fStatus.value || '',
    };
    return Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
}

async function loadFilterOptions() {
    try {
        const [locData, regData] = await Promise.all([
            fetchJson('/api/locations-list.php'),
            fetchJson('/api/cash-registers-list.php?include_inactive=1'),
        ]);
        const locations = locData.locations || [];
        allRegistersForFilter = regData.registers || [];
        fLocation.innerHTML = '<option value="">Mind</option>' + locations.map(l => `<option value="${l.id}">${escapeHtml(l.name)}</option>`).join('');
        renderRegisterFilterOptions();
    } catch (e) { /* filters just stay empty on failure */ }
}

function renderRegisterFilterOptions() {
    const locId = fLocation.value;
    const filtered = locId ? allRegistersForFilter.filter(r => String(r.location_id) === locId) : allRegistersForFilter;
    fRegister.innerHTML = '<option value="">Mind</option>' + filtered.map(r => `<option value="${r.id}">${escapeHtml(r.name)} (${escapeHtml(r.code)})</option>`).join('');
}

async function loadReport() {
    resultsBody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Betöltés...</td></tr>';
    try {
        const qs = new URLSearchParams(currentFilters()).toString();
        const data = await fetchJson('/api/cash-report-data.php' + (qs ? '?' + qs : ''));
        const sessions = data.sessions || [];
        resultsCount.textContent = `${sessions.length} találat`;
        resultsBody.innerHTML = sessions.length ? sessions.map(s => {
            const varianceStyle = s.variance == null ? '' : (Number(s.variance) < 0 ? 'color:var(--danger);' : (Number(s.variance) > 0 ? 'color:var(--accent);' : ''));
            return `
            <tr>
                <td>${s.id}</td>
                <td>${escapeHtml(s.location_name || '')}</td>
                <td>${escapeHtml(s.register_name || '')}</td>
                <td>${escapeHtml(s.staff_name || '—')}</td>
                <td>${s.status === 'open' ? '<span class="stock-badge ok">Nyitva</span>' : '<span class="stock-badge">Zárva</span>'}</td>
                <td>${s.opened_at}</td>
                <td>${s.closed_at || '—'}</td>
                <td>${fmtFt(s.opening_amount)}</td>
                <td>${fmtFt(s.closing_amount)}</td>
                <td>${fmtFt(s.expected_amount)}</td>
                <td style="${varianceStyle}">${s.variance == null ? '—' : fmtFt(s.variance)}</td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Nincs találat a megadott szűrőkkel.</td></tr>';
    } catch (err) {
        resultsBody.innerHTML = `<tr><td colspan="11" class="muted" style="text-align:center; padding:24px;">Hiba: ${escapeHtml(err.message)}</td></tr>`;
    }
}

fLocation.addEventListener('change', () => { renderRegisterFilterOptions(); loadReport(); });
[fDateFrom, fDateTo, fRegister, fStatus].forEach(el => el.addEventListener('change', loadReport));

document.getElementById('print-btn').addEventListener('click', () => window.print());
document.getElementById('export-csv-btn').addEventListener('click', () => {
    const qs = new URLSearchParams(currentFilters()).toString();
    window.location.href = '/api/export-cash-sessions-csv.php' + (qs ? '?' + qs : '');
});

loadFilterOptions().then(loadReport);
