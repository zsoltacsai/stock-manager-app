(function () {
    const statusLine = document.getElementById('ai-status-line');
    const disabledNotice = document.getElementById('ai-disabled-notice');
    const form = document.getElementById('ai-assistant-form');
    const agentSelect = document.getElementById('ai-agent');
    const questionInput = document.getElementById('ai-question');
    const askBtn = document.getElementById('ai-ask-btn');
    const askFeedback = document.getElementById('ai-ask-feedback');
    const answerBox = document.getElementById('ai-answer-box');
    const answerAgent = document.getElementById('ai-answer-agent');
    const answerText = document.getElementById('ai-answer-text');
    const toolsUsedBox = document.getElementById('ai-tools-used-box');
    const toolsUsedList = document.getElementById('ai-tools-used-list');

    // Fázis 4 (Sales Agent) — egyetlen oldal, egy "Agent" választóval, két
    // KÜLÖN végponttal (lásd a kör 12. pontja: "Keep this minimal. Do NOT
    // redesign the page into a large generic chat application"). A
    // provider-választás (Beállítások fülön) teljesen független ettől —
    // ugyanaz a végpont-pár működik Ollama/Anthropic/OpenAI alatt is,
    // provider-specifikus kódútvonal NÉLKÜL ezen az oldalon.
    const AGENT_ENDPOINTS = { inventory: '/api/ai-inventory.php', sales: '/api/ai-sales.php' };
    const AGENT_LABELS = { inventory: 'Készlet (Inventory)', sales: 'Forgalom (Sales)' };

    // A providernév a válaszban jön (data.provider, lásd api/ai-health.php)
    // — a feliratok szándékosan providerfüggetlenek, hogy ez az oldal
    // NE tartalmazzon külön kódútvonalat Ollama vs. Anthropic vs. OpenAI
    // esetén (lásd a kör 13. pontja: "The Inventory Assistant page must
    // remain provider-neutral").
    const PROVIDER_LABELS = { anthropic: 'Anthropic', openai: 'OpenAI', local: 'Ollama' };
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
            const providerLabel = PROVIDER_LABELS[data.provider] || 'Ollama';
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
        const agent = agentSelect ? agentSelect.value : 'inventory';
        const endpoint = AGENT_ENDPOINTS[agent] || AGENT_ENDPOINTS.inventory;
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: question }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Az AI-asszisztens nem tudott válaszolni.');
            }
            askFeedback.textContent = '';
            if (answerAgent) answerAgent.textContent = AGENT_LABELS[data.agent] || data.agent;
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
