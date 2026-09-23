(function () {
    const statusLine = document.getElementById('ai-status-line');
    const disabledNotice = document.getElementById('ai-disabled-notice');
    const form = document.getElementById('ai-assistant-form');
    const questionInput = document.getElementById('ai-question');
    const askBtn = document.getElementById('ai-ask-btn');
    const askFeedback = document.getElementById('ai-ask-feedback');
    const answerBox = document.getElementById('ai-answer-box');
    const answerText = document.getElementById('ai-answer-text');
    const toolsUsedBox = document.getElementById('ai-tools-used-box');
    const toolsUsedList = document.getElementById('ai-tools-used-list');

    // A providernév a válaszban jön (data.provider, lásd api/ai-health.php)
    // — a feliratok szándékosan providerfüggetlenek, hogy ez az oldal
    // NE tartalmazzon külön kódútvonalat Ollama vs. Anthropic esetén
    // (lásd a kör 10. pontja).
    const STATUS_LABELS = {
        available: '🟢 Elérhető',
        unavailable: '🔴 Nem érhető el',
        model_error: '🟡 A konfigurált modell nem elérhető',
        not_configured: '🟡 Nincs beállítva',
        auth_error: '🔴 Hitelesítési hiba',
    };

    async function loadHealth() {
        try {
            const res = await fetch('/api/ai-health.php');
            const data = await res.json();
            if (!data.enabled) {
                statusLine.textContent = '';
                disabledNotice.style.display = '';
                form.style.display = 'none';
                return;
            }
            disabledNotice.style.display = 'none';
            form.style.display = '';
            const providerLabel = data.provider === 'anthropic' ? 'Anthropic' : 'Ollama';
            statusLine.textContent = providerLabel + ': ' + (STATUS_LABELS[data.status] || data.status) + (data.model ? ' — ' + data.model : '');
            askBtn.disabled = data.status !== 'available';
        } catch (err) {
            statusLine.textContent = 'Nem sikerült lekérdezni az AI állapotát.';
        }
    }

    askBtn.addEventListener('click', async () => {
        const question = questionInput.value.trim();
        if (!question) {
            askFeedback.textContent = 'Adj meg egy kérdést.';
            askFeedback.className = 'modal-feedback error';
            return;
        }
        askBtn.disabled = true;
        answerBox.style.display = 'none';
        askFeedback.textContent = 'Gondolkodom…';
        askFeedback.className = 'modal-feedback';
        try {
            const res = await fetch('/api/ai-inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: question }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Az AI-asszisztens nem tudott válaszolni.');
            }
            askFeedback.textContent = '';
            answerText.textContent = data.answer;
            answerBox.style.display = '';
            if (data.tools_used && data.tools_used.length) {
                toolsUsedList.innerHTML = data.tools_used.map(t => `<li>${t}</li>`).join('');
                toolsUsedBox.style.display = '';
            } else {
                toolsUsedBox.style.display = 'none';
            }
        } catch (err) {
            askFeedback.textContent = 'Hiba: ' + err.message;
            askFeedback.className = 'modal-feedback error';
        } finally {
            askBtn.disabled = false;
        }
    });

    loadHealth();
})();
