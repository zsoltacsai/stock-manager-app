function statBox(value, label, tone) {
    const cls = tone ? ` ${tone}` : '';
    return `<div class="stat-box${cls}"><div class="value">${value}</div><div class="label">${label}</div></div>`;
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso.replace(' ', 'T')).toLocaleString('hu-HU');
}

async function loadStatus() {
    const statsBox = document.getElementById('wcs-stats');
    const failedBody = document.getElementById('wcs-failed-body');
    try {
        const data = await fetchJson('/api/woocommerce-sync-status.php');
        document.getElementById('wcs-not-configured').classList.toggle('hidden', data.configured);

        const c = data.queue.counts;
        statsBox.innerHTML = [
            statBox(c.queued, 'Várakozó'),
            statBox(c.processing, 'Feldolgozás alatt'),
            statBox(c.done, 'Sikeres'),
            statBox(c.failed, 'Sikertelen', c.failed > 0 ? 'warn' : ''),
            statBox(c.dead_letter, 'Véglegesen meghiúsult', c.dead_letter > 0 ? 'danger' : ''),
        ].join('');

        const failed = data.queue.recent_failed;
        failedBody.innerHTML = failed.length
            ? failed.map(row => `
                <tr>
                    <td>${escapeHtml(row.product_name || ('#' + row.product_id))}</td>
                    <td>${escapeHtml(row.trigger_type)}#${row.trigger_id}</td>
                    <td>${row.attempts}</td>
                    <td class="muted">${escapeHtml(row.last_error || '')}</td>
                    <td>${formatDate(row.updated_at)}</td>
                    <td><button class="toolbar-btn wcs-retry-btn" data-id="${row.id}">Újrapróbálás</button></td>
                </tr>
            `).join('')
            : '<tr><td colspan="6" class="muted" style="text-align:center; padding:16px;">Nincs sikertelen push.</td></tr>';

        failedBody.querySelectorAll('.wcs-retry-btn').forEach(btn => {
            btn.addEventListener('click', () => retryPush(btn));
        });
    } catch (err) {
        statsBox.innerHTML = `<p class="feedback error">A szinkron állapot betöltése sikertelen: ${escapeHtml(err.message)}</p>`;
    }
}

async function retryPush(btn) {
    const feedback = document.getElementById('wcs-retry-feedback');
    btn.disabled = true;
    feedback.textContent = 'Újraütemezés...';
    feedback.className = 'feedback';
    try {
        await fetchJson('/api/woocommerce-sync-retry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(btn.dataset.id) }),
        });
        feedback.textContent = 'Újraütemezve — a háttér-worker a következő futáskor feldolgozza.';
        feedback.className = 'feedback';
        loadStatus();
    } catch (err) {
        feedback.textContent = 'Hiba: ' + err.message;
        feedback.className = 'feedback error';
        btn.disabled = false;
    }
}

loadStatus();
