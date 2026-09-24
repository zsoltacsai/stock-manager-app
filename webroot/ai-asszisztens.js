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
    const agentsUsedBox = document.getElementById('ai-agents-used-box');
    const agentsUsedText = document.getElementById('ai-agents-used-text');
    const cancelBtn = document.getElementById('ai-cancel-btn');
    const progressList = document.getElementById('ai-progress-list');
    const usageBox = document.getElementById('ai-usage-box');

    // Fázis 9 — mindkettő a MEGLÉVŐ, admin-only Beállítások-értékből jön
    // (lásd api/ai-health.php), a böngésző SOSE befolyásolja — csak
    // MEGJELENÍTÉSI/útvonal-választási döntés ezen az oldalon.
    let aiStreamingEnabled = true;
    let aiShowUsageCost = true;
    let currentAbortController = null;

    // Fázis 4 (Sales Agent) — egyetlen oldal, egy "Agent" választóval, két
    // KÜLÖN végponttal (lásd a kör 12. pontja: "Keep this minimal. Do NOT
    // redesign the page into a large generic chat application"). A
    // provider-választás (Beállítások fülön) teljesen független ettől —
    // ugyanaz a végpont-pár működik Ollama/Anthropic/OpenAI alatt is,
    // provider-specifikus kódútvonal NÉLKÜL ezen az oldalon.
    const AGENT_ENDPOINTS = { copilot: '/api/ai-copilot.php', inventory: '/api/ai-inventory.php', sales: '/api/ai-sales.php', anomaly: '/api/ai-anomaly.php' };
    const AGENT_LABELS = { copilot: 'Copilot', inventory: 'Készlet (Inventory)', sales: 'Forgalom (Sales)', anomaly: 'Anomália (Anomaly)' };

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
            aiStreamingEnabled = data.streaming_enabled !== false;
            aiShowUsageCost = data.show_usage_cost !== false;
        } catch (err) {
            statusLine.textContent = 'Nem sikerült lekérdezni az AI állapotát.';
        }
    }

    // Fázis 9 — a kör 6/9. pontja: elsődlegesen a streamelő végpontot
    // (ai-agent-stream.php) használjuk, ami MINDEN agent-hez ugyanazt a
    // progresszív (agent/eszköz-életciklus) UX-et adja — MÉG akkor is, ha
    // a ténylegesen konfigurált provider/modell maga nem tud hálózati
    // szinten streamelni (lásd AgentRunner::runStreaming() "transparent
    // fallback" ága: ilyenkor is kapunk agent_started/tool_call_*/final
    // eseményeket, csak a szöveg egyetlen darabban érkezik). A RÉGI,
    // nem-streamelt végpontokra (askSync) KIZÁRÓLAG akkor esünk vissza, ha
    // az admin kifejezetten kikapcsolta (ai_streaming_enabled=false —
    // lásd api/ai-health.php) VAGY a böngésző nem támogatja a
    // ReadableStream-et — SOSE a válasz TARTALMA alapján döntünk.
    askBtn.addEventListener('click', async () => {
        const question = questionInput.value.trim();
        if (!question) {
            askFeedback.textContent = 'Adj meg egy kérdést.';
            askFeedback.className = 'modal-feedback error';
            return;
        }
        const agent = agentSelect ? agentSelect.value : 'inventory';
        const canStream = aiStreamingEnabled && typeof window.ReadableStream !== 'undefined';
        if (canStream) {
            await askStreaming(agent, question);
        } else {
            await askSync(agent, question);
        }
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            if (currentAbortController) {
                currentAbortController.abort();
            }
        });
    }

    const LIMIT_LABELS = {
        tool_call_limit: 'A kérdés megválaszolásához túl sok eszközhívás lett volna szükséges — próbáld egyszerűbben megfogalmazni.',
        cost_limit: 'A beállított költség-korlát elérve — a válasz emiatt megszakadt.',
    };

    function addProgressLine(text) {
        if (!progressList) return;
        const li = document.createElement('li');
        li.textContent = text;
        li.dataset.state = 'active';
        progressList.appendChild(li);
        progressList.style.display = '';
    }

    function markLastProgressDone(success) {
        if (!progressList) return;
        const items = progressList.querySelectorAll('li[data-state="active"]');
        const last = items[items.length - 1];
        if (last) {
            last.dataset.state = 'done';
            last.textContent = (success ? '✓ ' : '✗ ') + last.textContent;
        }
    }

    function renderUsageBox(payload) {
        if (!usageBox) return;
        if (!aiShowUsageCost || !payload || !payload.usage) {
            usageBox.style.display = 'none';
            return;
        }
        const u = payload.usage;
        const parts = [];
        if (u.input_tokens !== null && u.input_tokens !== undefined) parts.push(`bemenet: ${u.input_tokens} token`);
        if (u.output_tokens !== null && u.output_tokens !== undefined) parts.push(`kimenet: ${u.output_tokens} token`);
        if (!parts.length) {
            usageBox.style.display = 'none';
            return;
        }
        parts.push(payload.estimated_cost !== null && payload.estimated_cost !== undefined
            ? `becsült költség: $${Number(payload.estimated_cost).toFixed(4)}`
            : 'becsült költség: nem ismert ehhez a modellhez');
        parts.push(payload.streamed ? 'élő streamelés' : 'egyben érkezett válasz');
        usageBox.textContent = parts.join(' · ');
        usageBox.style.display = '';
    }

    function renderFinalMeta(doneOrData) {
        if (answerAgent) answerAgent.textContent = AGENT_LABELS[doneOrData.agent] || doneOrData.agent;
        const agentsUsed = doneOrData.agents_used || [];
        if (agentsUsedBox && agentsUsedText) {
            if (agentsUsed.length) {
                agentsUsedText.textContent = agentsUsed.map(a => AGENT_LABELS[a] || a).join(', ');
                agentsUsedBox.style.display = '';
            } else {
                agentsUsedBox.style.display = 'none';
            }
        }
        const toolsUsed = doneOrData.tools_used || [];
        if (toolsUsed.length) {
            toolsUsedList.innerHTML = toolsUsed.map(t => `<li>${t}</li>`).join('');
            toolsUsedBox.style.display = '';
        } else {
            toolsUsedBox.style.display = 'none';
        }
    }

    async function askStreaming(agent, question) {
        askBtn.disabled = true;
        if (cancelBtn) cancelBtn.style.display = '';
        answerBox.style.display = 'none';
        if (usageBox) usageBox.style.display = 'none';
        if (progressList) { progressList.innerHTML = ''; progressList.style.display = 'none'; }
        askFeedback.textContent = 'Gondolkodom…';
        askFeedback.className = 'modal-feedback';
        answerText.textContent = '';

        currentAbortController = new AbortController();
        let answerSoFar = '';
        let donePayload = null;

        try {
            const res = await fetch('/api/ai-agent-stream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ agent, message: question }),
                signal: currentAbortController.signal,
            });
            if (!res.ok) {
                let data = {};
                try { data = await res.json(); } catch (e) { /* nem JSON törzs — az általános hibaüzenet marad */ }
                if (res.status === 429 && data.retry_after_seconds) {
                    throw new Error(`Túl gyorsan érkezett a kérés — várj ${data.retry_after_seconds} másodpercet.`);
                }
                throw new Error(data.error || `Az AI-asszisztens nem tudott válaszolni (HTTP ${res.status}).`);
            }
            if (!res.body || !res.body.getReader) {
                // Fázis 9 — a kör 9. pontja "automatic safe fallback": ha a
                // böngésző ténylegesen nem tudja olvasni a törzset
                // darabokban, essünk vissza a régi, teljesen szinkron
                // útvonalra, MIELŐTT bármit megjelenítenénk.
                await askSync(agent, question);
                return;
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let sep;
                while ((sep = buffer.indexOf('\n\n')) !== -1) {
                    const frame = buffer.slice(0, sep);
                    buffer = buffer.slice(sep + 2);
                    const dataLine = frame.split('\n').find(l => l.startsWith('data:'));
                    if (!dataLine) continue;
                    let evt;
                    try { evt = JSON.parse(dataLine.slice(5).trim()); } catch (e) { continue; }
                    const payload = evt.payload || {};
                    switch (evt.type) {
                        case 'agent_started':
                            addProgressLine(payload.label || `${AGENT_LABELS[payload.agent] || payload.agent} elindult…`);
                            break;
                        case 'tool_call_started':
                            addProgressLine(payload.label || `Eszköz: ${payload.name}…`);
                            break;
                        case 'tool_call_completed':
                            markLastProgressDone(payload.success !== false);
                            break;
                        case 'text_delta':
                            answerSoFar += payload.text || '';
                            answerText.textContent = answerSoFar;
                            answerBox.style.display = '';
                            break;
                        case 'final':
                            answerSoFar = payload.answer || answerSoFar;
                            answerText.textContent = answerSoFar;
                            answerBox.style.display = '';
                            break;
                        case 'error':
                            askFeedback.textContent = 'Hiba: ' + (payload.message || 'ismeretlen hiba');
                            askFeedback.className = 'modal-feedback error';
                            break;
                        case 'done':
                            donePayload = payload;
                            break;
                    }
                }
            }

            if (progressList) progressList.style.display = 'none';

            if (donePayload) {
                renderFinalMeta(donePayload);
                renderUsageBox(donePayload);
                if (donePayload.success) {
                    askFeedback.textContent = '';
                } else {
                    askFeedback.textContent = 'Hiba: ' + (donePayload.limit_reached
                        ? (LIMIT_LABELS[donePayload.limit_reached] || donePayload.limit_reached)
                        : 'Az AI-asszisztens nem tudott válaszolni.');
                    askFeedback.className = 'modal-feedback error';
                }
            } else {
                askFeedback.textContent = '';
            }
        } catch (err) {
            if (progressList) progressList.style.display = 'none';
            if (err.name === 'AbortError') {
                askFeedback.textContent = 'Megszakítva.';
                askFeedback.className = 'modal-feedback';
            } else {
                askFeedback.textContent = 'Hiba: ' + err.message;
                askFeedback.className = 'modal-feedback error';
            }
        } finally {
            askBtn.disabled = false;
            if (cancelBtn) cancelBtn.style.display = 'none';
            currentAbortController = null;
        }
    }

    async function askSync(agent, question) {
        askBtn.disabled = true;
        answerBox.style.display = 'none';
        if (usageBox) usageBox.style.display = 'none';
        if (progressList) progressList.style.display = 'none';
        askFeedback.textContent = 'Gondolkodom…';
        askFeedback.className = 'modal-feedback';
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
            answerText.textContent = data.answer;
            answerBox.style.display = '';
            renderFinalMeta(data);
        } catch (err) {
            askFeedback.textContent = 'Hiba: ' + err.message;
            askFeedback.className = 'modal-feedback error';
        } finally {
            askBtn.disabled = false;
        }
    }

    loadHealth();

    // ------------------------------------------------------------------
    // Fülek — ugyanaz az egyszerű minta, mint beallitasok.js (nincs
    // beallitasok.js beillesztve ezen az oldalon, ezért egy sajátja).
    // ------------------------------------------------------------------
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');
        });
    });

    const urlParams = new URLSearchParams(location.search);
    if (urlParams.get('tab') === 'daily') {
        const dailyBtn = document.querySelector('.tab-btn[data-tab="tab-ai-daily"]');
        if (dailyBtn) dailyBtn.click();
    } else if (urlParams.get('tab') === 'history') {
        const historyBtn = document.querySelector('.tab-btn[data-tab="tab-ai-history"]');
        if (historyBtn) historyBtn.click();
    } else if (urlParams.get('tab') === 'proposals') {
        const proposalsBtn = document.querySelector('.tab-btn[data-tab="tab-ai-proposals"]');
        if (proposalsBtn) proposalsBtn.click();
    }

    // ------------------------------------------------------------------
    // Fázis 7 — Előzmények (a kör 2/3/4. pontja)
    // ------------------------------------------------------------------
    const historyBody = document.getElementById('ai-history-body');
    const historySummary = document.getElementById('ai-history-summary');
    const historyPrevBtn = document.getElementById('ai-history-prev-btn');
    const historyNextBtn = document.getElementById('ai-history-next-btn');
    const historyFilterAgent = document.getElementById('ai-history-filter-agent');
    const historyFilterProvider = document.getElementById('ai-history-filter-provider');
    const historyFilterStatus = document.getElementById('ai-history-filter-status');
    const historyFilterBtn = document.getElementById('ai-history-filter-btn');
    const historyDetailBox = document.getElementById('ai-history-detail-box');
    const historyDetailBody = document.getElementById('ai-history-detail-body');
    const historyDetailCloseBtn = document.getElementById('ai-history-detail-close-btn');

    const HISTORY_PAGE_SIZE = 20;
    let historyPage = 1;
    let historyHasMore = false;

    const STATUS_TEXT = { success: '✓ Sikeres', failure: '✗ Sikertelen', started: '… folyamatban' };
    // Fázis 10 — lásd AiProviderException.php a teljes, hivatalos
    // felsorolásért; a UI-ban KIZÁRÓLAG akkor jelenik meg, ha a mögöttes
    // hiba ténylegesen egy provider-kivételből származott.
    const FAILURE_CATEGORY_LABELS = {
        configuration_error: 'Konfigurációs hiba (érvénytelen beállítás)',
        unavailable: 'A provider nem érhető el (hálózati hiba)',
        timeout: 'Időtúllépés',
        auth_error: 'Hitelesítési hiba (API-kulcs)',
        rate_limit: 'Korlátozva a provider által (rate limit)',
        malformed_response: 'Érvénytelen/váratlanul félbeszakadt válasz',
        http_error: 'Egyéb HTTP-hiba',
    };

    function fmtDuration(ms) {
        if (ms === null || ms === undefined) return '—';
        return ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : ms + ' ms';
    }

    async function loadHistory() {
        if (!historyBody) return;
        historyBody.innerHTML = '<tr><td colspan="7" class="muted">Betöltés…</td></tr>';
        const params = new URLSearchParams({ page: String(historyPage), page_size: String(HISTORY_PAGE_SIZE) });
        if (historyFilterAgent.value) params.set('agent', historyFilterAgent.value);
        if (historyFilterProvider.value) params.set('provider', historyFilterProvider.value);
        if (historyFilterStatus.value) params.set('status', historyFilterStatus.value);
        try {
            const res = await fetch('/api/ai-history-list.php?' + params.toString());
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            historyHasMore = !!data.has_more;
            if (!data.entries.length) {
                historyBody.innerHTML = '<tr><td colspan="7" class="muted">Nincs találat.</td></tr>';
            } else {
                historyBody.innerHTML = data.entries.map(e => `
                    <tr style="cursor:pointer;" data-id="${e.id}">
                        <td>${e.created_at || ''}</td>
                        <td>${AGENT_LABELS[e.agent] || e.agent || '—'}</td>
                        <td>${PROVIDER_LABELS[e.provider] || e.provider || '—'}</td>
                        <td>${e.model || '—'}</td>
                        <td>${(e.tools_used || []).length}</td>
                        <td>${fmtDuration(e.duration_ms)}</td>
                        <td>${STATUS_TEXT[e.status] || e.status}</td>
                    </tr>
                `).join('');
                historyBody.querySelectorAll('tr[data-id]').forEach(row => {
                    row.addEventListener('click', () => loadHistoryDetail(row.dataset.id));
                });
            }
            historySummary.textContent = `${data.total} találat — ${historyPage}. oldal`;
            historyPrevBtn.disabled = historyPage <= 1;
            historyNextBtn.disabled = !historyHasMore;
        } catch (err) {
            historyBody.innerHTML = `<tr><td colspan="7" class="muted">Hiba: ${err.message}</td></tr>`;
        }
    }

    async function loadHistoryDetail(id) {
        try {
            const res = await fetch('/api/ai-history-detail.php?id=' + encodeURIComponent(id));
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            const e = data.entry;
            const rows = [
                ['Időpont', e.created_at || '—'],
                ['Agent', AGENT_LABELS[e.agent] || e.agent || '—'],
                ['Provider', PROVIDER_LABELS[e.provider] || e.provider || '—'],
                ['Modell', e.model || '—'],
                ['Szállítás', e.streamed === null || e.streamed === undefined ? '—' : (e.streamed ? 'élő streamelés' : 'egyben érkezett válasz')],
                ['Időtartam', fmtDuration(e.duration_ms)],
                ['Iterációk', e.iterations ?? '—'],
                ['Eszközök', (e.tools_used || []).join(', ') || '(nincs)'],
                ['Résztvevő ügynökök', e.agents_used ? e.agents_used.join(', ') : '—'],
                ['Tokenek (be/ki)', (e.input_tokens ?? e.output_tokens) !== null && (e.input_tokens ?? e.output_tokens) !== undefined
                    ? `${e.input_tokens ?? '?'} / ${e.output_tokens ?? '?'}` + (e.total_tokens !== null && e.total_tokens !== undefined ? ` (össz.: ${e.total_tokens})` : '')
                    : '—'],
                ['Becsült költség', e.estimated_cost !== null && e.estimated_cost !== undefined ? '$' + Number(e.estimated_cost).toFixed(4) : (e.input_tokens !== null && e.input_tokens !== undefined ? 'nem ismert ehhez a modellhez' : '—')],
                ['Kontextus-tömörítés', e.context_compacted === null || e.context_compacted === undefined ? '—' : (e.context_compacted ? 'igen — a beszélgetés túllépte a korlátot' : 'nem')],
                ['Elért korlát', e.limit_reached || '—'],
                ['Állapot', STATUS_TEXT[e.status] || e.status],
                ['Hibakategória', FAILURE_CATEGORY_LABELS[e.failure_category] || e.failure_category || '—'],
                ['Hiba', e.detail_error || '—'],
            ];
            historyDetailBody.innerHTML = rows.map(([k, v]) => `<tr><td>${k}</td><td>${String(v)}</td></tr>`).join('');
            historyDetailBox.style.display = '';
        } catch (err) {
            historyDetailBody.innerHTML = `<tr><td colspan="2" class="muted">Hiba: ${err.message}</td></tr>`;
            historyDetailBox.style.display = '';
        }
    }

    if (historyFilterBtn) {
        historyFilterBtn.addEventListener('click', () => { historyPage = 1; loadHistory(); });
    }
    if (historyPrevBtn) {
        historyPrevBtn.addEventListener('click', () => { if (historyPage > 1) { historyPage--; loadHistory(); } });
    }
    if (historyNextBtn) {
        historyNextBtn.addEventListener('click', () => { if (historyHasMore) { historyPage++; loadHistory(); } });
    }
    if (historyDetailCloseBtn) {
        historyDetailCloseBtn.addEventListener('click', () => { historyDetailBox.style.display = 'none'; });
    }
    if (historyBody) {
        loadHistory();
    }

    // ------------------------------------------------------------------
    // Fázis 7 — Napi intelligencia (a kör 21/22. pontja)
    // ------------------------------------------------------------------
    const dailyDateSelect = document.getElementById('ai-daily-date-select');
    const dailyEmpty = document.getElementById('ai-daily-empty');
    const dailyContent = document.getElementById('ai-daily-content');
    const dailyDate = document.getElementById('ai-daily-date');
    const dailyGeneratedAt = document.getElementById('ai-daily-generated-at');
    const dailyProviderModel = document.getElementById('ai-daily-provider-model');
    const dailyStatus = document.getElementById('ai-daily-status');
    const dailyFindingsBox = document.getElementById('ai-daily-findings-box');
    const dailyFindingsList = document.getElementById('ai-daily-findings-list');
    const dailyReportText = document.getElementById('ai-daily-report-text');

    const SEVERITY_LABELS = { critical: '🔴 kritikus', high: '🟠 magas', medium: '🟡 közepes', low: '⚪ alacsony' };

    function renderDailyReport(report) {
        if (!report) {
            dailyEmpty.style.display = '';
            dailyContent.style.display = 'none';
            return;
        }
        dailyEmpty.style.display = 'none';
        dailyContent.style.display = '';
        dailyDate.textContent = report.report_date;
        dailyGeneratedAt.textContent = report.completed_at || '—';
        dailyProviderModel.textContent = (PROVIDER_LABELS[report.provider] || report.provider || '—') + (report.model ? ' — ' + report.model : '');
        dailyStatus.textContent = report.status === 'completed' ? '✓ Elkészült' : (report.status === 'failed' ? '✗ Sikertelen: ' + (report.error || '') : report.status);
        if (report.findings && report.findings.length) {
            dailyFindingsList.innerHTML = report.findings.map(f => `<li><strong>${SEVERITY_LABELS[f.severity] || f.severity}</strong> — ${f.entity_name || ''} (${f.metric || f.type}${f.change_percent !== undefined && f.change_percent !== null ? ': ' + f.change_percent + '%' : ''})</li>`).join('');
            dailyFindingsBox.style.display = '';
        } else {
            dailyFindingsBox.style.display = 'none';
        }
        dailyReportText.textContent = report.report_text || (report.status === 'completed' ? 'Nincs jelentős megállapítás ma.' : '');
    }

    async function loadDailyReport(date) {
        try {
            const res = await fetch('/api/ai-daily-report.php' + (date ? '?date=' + encodeURIComponent(date) : ''));
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            renderDailyReport(data.report);
        } catch (err) {
            dailyEmpty.textContent = 'Hiba: ' + err.message;
            dailyEmpty.style.display = '';
            dailyContent.style.display = 'none';
        }
    }

    async function loadDailyReportDates() {
        if (!dailyDateSelect) return;
        try {
            const res = await fetch('/api/ai-daily-report.php?list=1');
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            if (!data.reports.length) {
                renderDailyReport(null);
                return;
            }
            dailyDateSelect.innerHTML = data.reports.map(r => `<option value="${r.report_date}">${r.report_date}${r.has_significant_findings ? ' ⚠' : ''}</option>`).join('');
            dailyDateSelect.value = data.reports[0].report_date;
            loadDailyReport(data.reports[0].report_date);
        } catch (err) {
            dailyEmpty.textContent = 'Hiba: ' + err.message;
            dailyEmpty.style.display = '';
        }
    }

    if (dailyDateSelect) {
        dailyDateSelect.addEventListener('change', () => loadDailyReport(dailyDateSelect.value));
        loadDailyReportDates();
    }

    // ------------------------------------------------------------------
    // Fázis 8A — Javaslatok (a kör 21/22. pontja). KRITIKUS: a "Jóváhagyás"
    // gomb KIZÁRÓLAG a javaslat állapotát változtatja — SOSE jelenít meg
    // "készlet módosítva"/"rendelés létrehozva"-jellegű szöveget, mert
    // ilyen üzleti művelet ebben a fázisban SOSE történik.
    // ------------------------------------------------------------------
    const proposalsBody = document.getElementById('ai-proposals-body');
    const proposalsSummary = document.getElementById('ai-proposals-summary');
    const proposalsPrevBtn = document.getElementById('ai-proposals-prev-btn');
    const proposalsNextBtn = document.getElementById('ai-proposals-next-btn');
    const proposalsFilterStatus = document.getElementById('ai-proposals-filter-status');
    const proposalsFilterType = document.getElementById('ai-proposals-filter-type');
    const proposalsFilterBtn = document.getElementById('ai-proposals-filter-btn');
    const proposalDetailBox = document.getElementById('ai-proposal-detail-box');
    const proposalDetailBody = document.getElementById('ai-proposal-detail-body');
    const proposalDetailCloseBtn = document.getElementById('ai-proposal-detail-close-btn');
    const proposalApproveBtn = document.getElementById('ai-proposal-approve-btn');
    const proposalRejectBtn = document.getElementById('ai-proposal-reject-btn');
    const proposalRejectReason = document.getElementById('ai-proposal-reject-reason');
    const proposalDetailFeedback = document.getElementById('ai-proposal-detail-feedback');
    const proposalDetailActions = document.getElementById('ai-proposal-detail-actions');
    const proposalExecuteActions = document.getElementById('ai-proposal-execute-actions');
    const proposalExecuteBtn = document.getElementById('ai-proposal-execute-btn');
    const proposalExecuteState = document.getElementById('ai-proposal-execute-state');
    const proposalExecutionResultBox = document.getElementById('ai-proposal-execution-result-box');
    const proposalExecutionResultText = document.getElementById('ai-proposal-execution-result-text');

    const PROPOSAL_TYPE_LABELS = {
        inventory_review: 'Készlet-felülvizsgálat',
        reorder_draft: 'Utánrendelés-vizsgálat',
        sales_review: 'Eladás-felülvizsgálat',
    };
    // Fázis 8B — a kör 4/19. pontja: a "Jóváhagyva" ÉS a "Végrehajtva"
    // SOSE keverhető össze — külön státusz-szöveg mindegyikre.
    const PROPOSAL_STATUS_LABELS = {
        pending: '⏳ Függőben',
        approved: '✓ Jóváhagyva',
        rejected: '✗ Elutasítva',
        expired: '⌛ Lejárt',
        stale: '⚠ Elavult',
        executing: '⏳ Végrehajtás folyamatban…',
        executed: '✅ Végrehajtva',
        execution_failed: '✗ Végrehajtás sikertelen',
    };

    const PROPOSALS_PAGE_SIZE = 20;
    let proposalsPage = 1;
    let proposalsHasMore = false;
    let currentProposalId = null;
    let currentProposalData = null;

    function renderProposalDetailRows(p) {
        const evidenceLines = Object.entries(p.evidence || {}).map(([k, v]) => `${k}: ${v}`).join(' · ');
        const rows = [
            ['Típus', PROPOSAL_TYPE_LABELS[p.proposal_type] || p.proposal_type],
            ['Forrás (agent)', p.agent],
            ['Entitás', p.entity_name || '—'],
            ['Javasolt lépés', (p.proposed_action && p.proposed_action.summary) || '—'],
            ['Bizonyíték', evidenceLines || '—'],
            ['Létrehozva', p.created_at],
            ['Lejárat', p.expires_at],
            ['Állapot', PROPOSAL_STATUS_LABELS[p.status] || p.status],
            ['Elbírálva', p.reviewed_at || '—'],
            ['Elutasítás indoka', p.rejection_reason || '—'],
        ];
        proposalDetailBody.innerHTML = rows.map(([k, v]) => `<tr><td>${k}</td><td>${String(v)}</td></tr>`).join('');

        // Jóváhagyás/Elutasítás — KIZÁRÓLAG 'pending'-nél.
        proposalDetailActions.style.display = p.status === 'pending' ? '' : 'none';

        // Végrehajtás — a kör 19. pontja: KIZÁRÓLAG végrehajtható TÍPUSÚ
        // javaslatoknál jelenik meg egyáltalán, ÉS csak 'approved'
        // (első végrehajtás) vagy 'execution_failed' (újrapróbálkozás)
        // állapotban aktív gombbal — 'executing'-nél letiltott,
        // "Folyamatban…" szöveggel.
        if (p.is_executable_type && (p.status === 'approved' || p.status === 'execution_failed' || p.status === 'executing')) {
            proposalExecuteActions.style.display = '';
            proposalExecuteBtn.disabled = p.status === 'executing';
            proposalExecuteBtn.textContent = p.status === 'execution_failed' ? 'Újrapróbálás' : 'Végrehajtás';
            proposalExecuteState.textContent = p.status === 'executing' ? 'Folyamatban…' : (p.status === 'execution_failed' ? (p.execution_error || 'Sikertelen.') : '');
        } else {
            proposalExecuteActions.style.display = 'none';
        }

        // Végrehajtás eredménye — csak 'executed'-nél, a kör 20. pontja
        // szerinti strukturált eredményből, EGYÉRTELMŰ, "piszkozat"
        // szóhasználattal (SOSE "elküldve"/"leadva").
        if (p.status === 'executed' && p.execution_result && p.execution_result.action === 'reorder_draft') {
            const r = p.execution_result;
            proposalExecutionResultText.textContent = `Beszerzési rendelés tervezete létrehozva (#${r.reference_id}) — ${r.quantity} db.`;
            proposalExecutionResultBox.style.display = '';
        } else {
            proposalExecutionResultBox.style.display = 'none';
        }
    }

    async function loadProposals() {
        if (!proposalsBody) return;
        proposalsBody.innerHTML = '<tr><td colspan="7" class="muted">Betöltés…</td></tr>';
        const params = new URLSearchParams({ page: String(proposalsPage), page_size: String(PROPOSALS_PAGE_SIZE) });
        if (proposalsFilterStatus.value) params.set('status', proposalsFilterStatus.value);
        if (proposalsFilterType.value) params.set('proposal_type', proposalsFilterType.value);
        try {
            const res = await fetch('/api/ai-action-proposals-list.php?' + params.toString());
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            proposalsHasMore = !!data.has_more;
            if (!data.proposals.length) {
                proposalsBody.innerHTML = '<tr><td colspan="7" class="muted">Nincs találat.</td></tr>';
            } else {
                proposalsBody.innerHTML = data.proposals.map(p => `
                    <tr style="cursor:pointer;" data-id="${p.id}">
                        <td>${PROPOSAL_TYPE_LABELS[p.proposal_type] || p.proposal_type}</td>
                        <td>${p.entity_name || '—'}</td>
                        <td>${SEVERITY_LABELS[(p.evidence && p.evidence.severity) || ''] || (p.evidence && p.evidence.severity) || '—'}</td>
                        <td>${p.created_at || ''}</td>
                        <td>${p.expires_at || ''}</td>
                        <td>${PROPOSAL_STATUS_LABELS[p.status] || p.status}</td>
                        <td>Megnyitás →</td>
                    </tr>
                `).join('');
                proposalsBody.querySelectorAll('tr[data-id]').forEach(row => {
                    row.addEventListener('click', () => loadProposalDetail(row.dataset.id));
                });
            }
            proposalsSummary.textContent = `${data.total} találat — ${proposalsPage}. oldal`;
            proposalsPrevBtn.disabled = proposalsPage <= 1;
            proposalsNextBtn.disabled = !proposalsHasMore;
        } catch (err) {
            proposalsBody.innerHTML = `<tr><td colspan="7" class="muted">Hiba: ${err.message}</td></tr>`;
        }
    }

    async function fetchProposalDetail(id) {
        const res = await fetch('/api/ai-action-proposal-detail.php?id=' + encodeURIComponent(id));
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
        return data.proposal;
    }

    async function loadProposalDetail(id) {
        currentProposalId = id;
        proposalDetailFeedback.textContent = '';
        proposalDetailFeedback.className = 'modal-feedback';
        proposalRejectReason.value = '';
        try {
            currentProposalData = await fetchProposalDetail(id);
            renderProposalDetailRows(currentProposalData);
            proposalDetailBox.style.display = '';
        } catch (err) {
            proposalDetailBody.innerHTML = `<tr><td colspan="2" class="muted">Hiba: ${err.message}</td></tr>`;
            proposalDetailBox.style.display = '';
            proposalDetailActions.style.display = 'none';
        }
    }

    async function reviewProposal(action) {
        if (!currentProposalId) return;
        const btn = action === 'approve' ? proposalApproveBtn : proposalRejectBtn;
        proposalApproveBtn.disabled = true;
        proposalRejectBtn.disabled = true;
        proposalDetailFeedback.textContent = 'Feldolgozás…';
        proposalDetailFeedback.className = 'modal-feedback';
        try {
            const endpoint = action === 'approve' ? '/api/ai-action-proposal-approve.php' : '/api/ai-action-proposal-reject.php';
            const body = action === 'approve' ? { id: currentProposalId } : { id: currentProposalId, reason: proposalRejectReason.value.trim() };
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'A művelet nem sikerült.');
            proposalDetailFeedback.textContent = action === 'approve'
                ? 'A javaslat jóváhagyva. Üzleti művelet nem történt.'
                : 'A javaslat elutasítva.';
            proposalDetailFeedback.className = 'modal-feedback success';
            if (currentProposalData) {
                currentProposalData.status = data.status;
                renderProposalDetailRows(currentProposalData);
            }
            loadProposals();
        } catch (err) {
            proposalDetailFeedback.textContent = 'Hiba: ' + err.message;
            proposalDetailFeedback.className = 'modal-feedback error';
        } finally {
            proposalApproveBtn.disabled = false;
            proposalRejectBtn.disabled = false;
        }
    }

    // Fázis 8B — a kör 13/19. pontja: a "Végrehajtás" EGY KÜLÖN, saját
    // gomb/lépés — SOSE fut le automatikusan a jóváhagyáskor. A gomb a
    // kérés alatt le van tiltva (dupla-kattintás elleni védelem), a
    // válasz UTÁN pedig a friss szervertől kapott ÁLLAPOTOT (SOSE
    // feltételezett sikert) jeleníti meg.
    async function executeProposal() {
        if (!currentProposalId) return;
        proposalExecuteBtn.disabled = true;
        proposalDetailFeedback.textContent = 'Végrehajtás folyamatban…';
        proposalDetailFeedback.className = 'modal-feedback';
        try {
            const res = await fetch('/api/ai-action-proposal-execute.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: currentProposalId }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'A végrehajtás nem sikerült.');
            proposalDetailFeedback.textContent = data.already_executed
                ? 'Ez a javaslat már korábban végrehajtásra került — az eredmény változatlan.'
                : 'Végrehajtás sikeres.';
            proposalDetailFeedback.className = 'modal-feedback success';
            // Friss részletek lekérése a szervertől (SOSE feltételezett
            // állapot) — ez frissíti az "Állapot"/"Végrehajtás eredménye"
            // blokkot, a fenti visszajelző szöveg MEGŐRZÉSÉVEL (nem
            // loadProposalDetail(), ami törölné azt).
            currentProposalData = await fetchProposalDetail(currentProposalId);
            renderProposalDetailRows(currentProposalData);
            loadProposals();
        } catch (err) {
            proposalDetailFeedback.textContent = 'Hiba: ' + err.message;
            proposalDetailFeedback.className = 'modal-feedback error';
            try {
                currentProposalData = await fetchProposalDetail(currentProposalId);
                renderProposalDetailRows(currentProposalData);
            } catch (e2) { /* a fenti hibaüzenet marad látható */ }
        } finally {
            proposalExecuteBtn.disabled = false;
        }
    }

    if (proposalsFilterBtn) {
        proposalsFilterBtn.addEventListener('click', () => { proposalsPage = 1; loadProposals(); });
    }
    if (proposalsPrevBtn) {
        proposalsPrevBtn.addEventListener('click', () => { if (proposalsPage > 1) { proposalsPage--; loadProposals(); } });
    }
    if (proposalsNextBtn) {
        proposalsNextBtn.addEventListener('click', () => { if (proposalsHasMore) { proposalsPage++; loadProposals(); } });
    }
    if (proposalDetailCloseBtn) {
        proposalDetailCloseBtn.addEventListener('click', () => { proposalDetailBox.style.display = 'none'; currentProposalId = null; });
    }
    if (proposalApproveBtn) {
        proposalApproveBtn.addEventListener('click', () => reviewProposal('approve'));
    }
    if (proposalRejectBtn) {
        proposalRejectBtn.addEventListener('click', () => reviewProposal('reject'));
    }
    if (proposalExecuteBtn) {
        proposalExecuteBtn.addEventListener('click', executeProposal);
    }
    if (proposalsBody) {
        loadProposals();
    }
})();
