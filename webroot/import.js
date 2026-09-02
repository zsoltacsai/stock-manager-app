const profileSelect = document.getElementById('profile-select');
const fileInput = document.getElementById('file-input');
const fileDrop = document.getElementById('file-drop');
const fileDropLabel = document.getElementById('file-drop-label');
const previewBtn = document.getElementById('preview-btn');
const previewFeedback = document.getElementById('preview-feedback');
const previewCard = document.getElementById('preview-card');
const statsGrid = document.getElementById('stats-grid');
const warningsBox = document.getElementById('warnings');
const sampleBody = document.getElementById('sample-body');
const commitBtn = document.getElementById('commit-btn');
const commitFeedback = document.getElementById('commit-feedback');

const fmt = (n) => new Intl.NumberFormat('hu-HU').format(Math.round(Number(n) || 0)) + ' Ft';

let currentToken = null;

async function loadProfiles() {
    const res = await fetch('/api/import-profiles.php');
    const data = await res.json();
    profileSelect.innerHTML = (data.profiles || []).map(p => `
        <option value="${p.key}" ${p.implemented ? '' : 'class="profile-disabled"'}>
            ${p.label}${p.implemented ? '' : ' (hamarosan)'}
        </option>
    `).join('');
}
loadProfiles();

fileDrop.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        fileDropLabel.textContent = fileInput.files[0].name;
        fileDrop.classList.add('has-file');
        previewBtn.disabled = false;
    } else {
        fileDropLabel.textContent = 'Kattints ide a fájl kiválasztásához, vagy húzd ide';
        fileDrop.classList.remove('has-file');
        previewBtn.disabled = true;
    }
});
['dragover', 'dragleave', 'drop'].forEach(evt => {
    fileDrop.addEventListener(evt, (e) => e.preventDefault());
});
fileDrop.addEventListener('drop', (e) => {
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
    }
});

previewBtn.addEventListener('click', async () => {
    if (!fileInput.files.length) return;

    previewBtn.disabled = true;
    previewFeedback.textContent = 'Feldolgozás...';
    previewFeedback.className = 'feedback';
    previewCard.classList.add('hidden');

    const formData = new FormData();
    formData.append('profile', profileSelect.value);
    formData.append('file', fileInput.files[0]);

    try {
        const res = await fetch('/api/import-preview.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (!res.ok) {
            previewFeedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            previewFeedback.className = 'feedback error';
            return;
        }

        previewFeedback.textContent = '';
        currentToken = data.token;
        renderPreview(data);
    } catch (err) {
        previewFeedback.textContent = 'Hiba: ' + err.message;
        previewFeedback.className = 'feedback error';
    } finally {
        previewBtn.disabled = false;
    }
});

function renderPreview(data) {
    const s = data.stats;

    statsGrid.innerHTML = `
        <div class="stat-box"><div class="value">${s.total_rows}</div><div class="label">Sor összesen</div></div>
        <div class="stat-box"><div class="value">${s.will_insert}</div><div class="label">Új termék</div></div>
        <div class="stat-box"><div class="value">${s.will_update}</div><div class="label">Frissülő (vonalkód alapján)</div></div>
        <div class="stat-box ${s.blank_barcode > 0 ? 'warn' : ''}"><div class="value">${s.blank_barcode}</div><div class="label">Vonalkód nélkül</div></div>
        <div class="stat-box ${s.duplicate_barcodes > 0 ? 'warn' : ''}"><div class="value">${s.duplicate_barcodes}</div><div class="label">Duplikált vonalkód a fájlban</div></div>
        <div class="stat-box ${s.missing_name > 0 ? 'danger' : ''}"><div class="value">${s.missing_name}</div><div class="label">Hiányzó megnevezés</div></div>
    `;

    let warnings = '';
    if (s.missing_name > 0) {
        warnings += `<p class="feedback warn">${s.missing_name} sornak nincs megnevezése — ezek importáláskor kimaradnak.</p>`;
    }
    if (s.duplicate_barcodes > 0) {
        warnings += `<p class="feedback warn">${s.duplicate_barcodes} vonalkód többször szerepel a fájlban — importáláskor a fájlban utoljára szereplő érték marad meg.</p>`;
    }
    if (data.unmatched_fields && data.unmatched_fields.length) {
        warnings += `<p class="feedback warn">Nem található oszlop ehhez: ${data.unmatched_fields.join(', ')} — ezek a mezők üresen maradnak.</p>`;
    }
    warningsBox.innerHTML = warnings;

    sampleBody.innerHTML = data.sample.map(row => `
        <tr>
            <td>${escapeHtml(row.name) || '—'}</td>
            <td>${escapeHtml(row.cikkszam || '')}</td>
            <td>${escapeHtml(row.group_name || '')}</td>
            <td>${escapeHtml(row.barcode || '')}</td>
            <td>${row.stock_qty}</td>
            <td>${fmt(row.purchase_price_net)}</td>
            <td>${fmt(row.net_price)}</td>
            <td>${fmt(row.price)}</td>
            <td>${isNaN(parseFloat(row.vat_rate)) ? escapeHtml(String(row.vat_rate)) : row.vat_rate + '%'}</td>
        </tr>
    `).join('');

    commitFeedback.textContent = '';
    previewCard.classList.remove('hidden');
}

commitBtn.addEventListener('click', async () => {
    if (!currentToken) return;

    commitBtn.disabled = true;
    commitFeedback.textContent = 'Importálás folyamatban...';
    commitFeedback.className = 'feedback';

    try {
        const res = await fetch('/api/import-commit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: currentToken, profile: profileSelect.value }),
        });
        const data = await res.json();

        if (!res.ok) {
            commitFeedback.textContent = 'Hiba: ' + (data.error || 'ismeretlen hiba');
            commitFeedback.className = 'feedback error';
            commitBtn.disabled = false;
            return;
        }

        commitFeedback.textContent = `Kész: ${data.inserted} új termék létrehozva, ${data.updated} meglévő frissítve` +
            (data.skipped > 0 ? `, ${data.skipped} sor kihagyva (hiányzó megnevezés).` : '.');
        commitFeedback.className = 'feedback ok';
        currentToken = null;
    } catch (err) {
        commitFeedback.textContent = 'Hiba: ' + err.message;
        commitFeedback.className = 'feedback error';
        commitBtn.disabled = false;
    }
});
