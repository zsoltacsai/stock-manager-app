function fmtFt(n) {
    return Math.round(Number(n) || 0).toLocaleString('hu-HU') + ' Ft';
}

const params = new URLSearchParams(location.search);
const sessionId = params.get('id') ? parseInt(params.get('id'), 10) : null;

const noSessionCard = document.getElementById('close-no-session');
const closePanel = document.getElementById('close-panel');
const closeResult = document.getElementById('close-result');
const closeSessionMeta = document.getElementById('close-session-meta');
const closeCountedAmount = document.getElementById('close-counted-amount');
const closeFeedback = document.getElementById('close-feedback');
const closeSubmitBtn = document.getElementById('close-submit-btn');

let breakdown = null;

async function loadBreakdown() {
    if (!sessionId) {
        noSessionCard.classList.remove('hidden');
        return;
    }
    try {
        const data = await fetchJson('/api/cash-session-detail.php?id=' + sessionId);
        breakdown = data;
        if (breakdown.session.status !== 'open') {
            closeFeedback.textContent = 'Ez a műszak már le van zárva.';
            closeFeedback.className = 'modal-feedback error';
            closeSubmitBtn.disabled = true;
        }
        renderBreakdown();
        noSessionCard.classList.add('hidden');
        closePanel.classList.remove('hidden');
    } catch (err) {
        noSessionCard.classList.remove('hidden');
        noSessionCard.querySelector('p').textContent = 'Hiba a műszak betöltésekor: ' + err.message;
    }
}

function renderBreakdown() {
    closeSessionMeta.textContent = `#${breakdown.session.id} műszak — nyitva: ${breakdown.session.opened_at}`;
    document.getElementById('close-row-opening').textContent = fmtFt(breakdown.opening_amount);
    document.getElementById('close-row-sales').textContent = fmtFt(breakdown.cash_sales);
    document.getElementById('close-row-refunds').textContent = '-' + fmtFt(breakdown.cash_refunds);
    document.getElementById('close-row-in').textContent = '+' + fmtFt(breakdown.cash_in);
    document.getElementById('close-row-out').textContent = '-' + fmtFt(breakdown.cash_out);
    document.getElementById('close-row-expected').innerHTML = `<strong>${fmtFt(breakdown.expected_amount)}</strong>`;

    const body = document.getElementById('close-movements-body');
    const movements = breakdown.movements || [];
    document.getElementById('close-movements-wrap').classList.toggle('hidden', movements.length === 0);
    body.innerHTML = movements.map(m => `
        <tr>
            <td>${m.created_at}</td>
            <td>${m.type === 'cash_in' ? 'Pénzbevét' : 'Pénzkiadás'}</td>
            <td>${fmtFt(m.amount)}</td>
            <td>${escapeHtml(m.reason)}</td>
        </tr>
    `).join('');
}

closeSubmitBtn.addEventListener('click', async () => {
    const amount = parseFloat(closeCountedAmount.value);
    if (isNaN(amount) || amount < 0) {
        closeFeedback.textContent = 'Add meg a megszámolt összeget.';
        closeFeedback.className = 'modal-feedback error';
        return;
    }
    closeSubmitBtn.disabled = true;
    closeFeedback.textContent = 'Zárás folyamatban...';
    closeFeedback.className = 'modal-feedback';
    try {
        const res = await fetch('/api/cash-session-close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: sessionId, counted_amount: amount }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'ismeretlen hiba');

        closePanel.classList.add('hidden');
        closeResult.classList.remove('hidden');
        const variance = Number(data.variance);
        const varianceText = variance === 0
            ? 'Nincs eltérés — a megszámolt összeg pontosan egyezik a várhatóval.'
            : (variance > 0
                ? `Többlet: +${fmtFt(variance)} (a megszámolt összeg ennyivel több a várhatónál).`
                : `Hiány: ${fmtFt(variance)} (a megszámolt összeg ennyivel kevesebb a várhatónál).`);
        document.getElementById('close-result-text').innerHTML =
            `Várható összeg: <strong>${fmtFt(data.expected_amount)}</strong><br>${escapeHtml(varianceText)}`;
    } catch (err) {
        closeFeedback.textContent = 'Hiba: ' + err.message;
        closeFeedback.className = 'modal-feedback error';
        closeSubmitBtn.disabled = false;
    }
});

loadBreakdown();
