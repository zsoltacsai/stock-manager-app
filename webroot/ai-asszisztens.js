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
            if (agentsUsedBox && agentsUsedText) {
                if (data.agents_used && data.agents_used.length) {
                    agentsUsedText.textContent = data.agents_used.map(a => AGENT_LABELS[a] || a).join(', ');
                    agentsUsedBox.style.display = '';
                } else {
                    agentsUsedBox.style.display = 'none';
                }
            }
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
                ['Időtartam', fmtDuration(e.duration_ms)],
                ['Iterációk', e.iterations ?? '—'],
                ['Eszközök', (e.tools_used || []).join(', ') || '(nincs)'],
                ['Résztvevő ügynökök', e.agents_used ? e.agents_used.join(', ') : '—'],
                ['Állapot', STATUS_TEXT[e.status] || e.status],
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

    const PROPOSAL_TYPE_LABELS = {
        inventory_review: 'Készlet-felülvizsgálat',
        reorder_draft: 'Utánrendelés-vizsgálat',
        sales_review: 'Eladás-felülvizsgálat',
    };
    const PROPOSAL_STATUS_LABELS = {
        pending: '⏳ Függőben',
        approved: '✓ Jóváhagyva',
        rejected: '✗ Elutasítva',
        expired: '⌛ Lejárt',
        stale: '⚠ Elavult',
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
        proposalDetailActions.style.display = p.status === 'pending' ? '' : 'none';
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
                        <td>${p.status === 'pending' ? 'Megnyitás →' : '—'}</td>
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

    async function loadProposalDetail(id) {
        currentProposalId = id;
        proposalDetailFeedback.textContent = '';
        proposalDetailFeedback.className = 'modal-feedback';
        proposalRejectReason.value = '';
        try {
            const res = await fetch('/api/ai-action-proposal-detail.php?id=' + encodeURIComponent(id));
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || 'ismeretlen hiba');
            currentProposalData = data.proposal;
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
    if (proposalsBody) {
        loadProposals();
    }
})();
