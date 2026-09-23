<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Auth.php';
$appSettings = (new Settings(__DIR__ . '/../data/settings.json'))->read();
require_once __DIR__ . '/../src/GeoBlocker.php';
GeoBlocker::enforce($appSettings);
if (!Auth::isLoggedIn($appSettings)) {
    header('Location: login.html?redirect=' . basename($_SERVER['SCRIPT_NAME']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<script>(function(){try{var t=localStorage.getItem("sm_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#22c55e">
<title>FountainTrade — AI Asszisztens</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line></svg></span>
        <h1>AI Asszisztens</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-ai-chat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path></svg>Asszisztens</button>
            <button class="tab-btn" data-tab="tab-ai-history"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"></path><circle cx="12" cy="12" r="9"></circle></svg>Előzmények</button>
            <button class="tab-btn" data-tab="tab-ai-daily"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>Napi intelligencia</button>
        </div>

        <div id="tab-ai-chat" class="tab-panel active">
            <p class="muted" style="margin-top:0;" id="ai-status-line">Állapot ellenőrzése…</p>

            <div id="ai-disabled-notice" class="modal-feedback error" style="display:none;">
                Az AI asszisztens jelenleg ki van kapcsolva — kapcsold be a
                <a href="beallitasok.php">Beállítások → AI asszisztens</a> fülön.
            </div>

            <div id="ai-assistant-form">
                <label for="ai-agent">Agent</label>
                <select id="ai-agent">
                    <option value="copilot">Copilot (általános asszisztens)</option>
                    <option value="inventory">Készlet (Inventory)</option>
                    <option value="sales">Forgalom (Sales)</option>
                    <option value="anomaly">Anomália (Anomaly)</option>
                </select>

                <label for="ai-question" style="margin-top:12px;">Kérdésed</label>
                <textarea id="ai-question" rows="3" placeholder="pl. Melyik termékekből fogunk várhatóan kifogyni?"></textarea>
                <button id="ai-ask-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Kérdezd a FountainTrade-et</button>
                <p id="ai-ask-feedback" class="modal-feedback"></p>

                <div id="ai-answer-box" style="display:none; border-top:1px solid var(--border); margin-top:20px; padding-top:16px;">
                    <strong>Agent:</strong> <span id="ai-answer-agent"></span>
                    <div id="ai-agents-used-box" style="display:none; margin-top:4px;">
                        <strong style="font-size:0.9em;">Felhasznált ügynökök:</strong>
                        <span id="ai-agents-used-text" style="font-size:0.9em; color:var(--muted);"></span>
                    </div>
                    <p><strong>Válasz:</strong></p>
                    <p id="ai-answer-text" style="white-space:pre-wrap;"></p>
                    <div id="ai-tools-used-box" style="display:none;">
                        <strong style="font-size:0.9em;">Használt eszközök:</strong>
                        <ul id="ai-tools-used-list" style="margin:4px 0 0 20px; font-size:0.9em; color:var(--muted);"></ul>
                    </div>
                </div>
            </div>

            <p class="muted" style="margin-top:24px; border-top:1px solid var(--border); padding-top:16px;">
                Az asszisztens kizárólag OLVASÁSRA képes (nem módosít készletet, árat, forgalmat, nem
                hoz létre beszerzést) — minden számadat a FountainTrade tényleges adatbázisából
                származik, a javaslatok pedig javaslatok, nem automatikusan végrehajtott műveletek.
            </p>
        </div>

        <div id="tab-ai-history" class="tab-panel">
            <p class="muted" style="margin-top:0;">A MEGLÉVŐ AI-naplóból (rendszeresemény-napló) épülő, lapozható előzmény-lista — legfeljebb a beállított megőrzési időig visszamenőleg.</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                <select id="ai-history-filter-agent">
                    <option value="">Összes agent</option>
                    <option value="copilot">Copilot</option>
                    <option value="inventory">Készlet</option>
                    <option value="sales">Forgalom</option>
                    <option value="anomaly">Anomália</option>
                </select>
                <select id="ai-history-filter-provider">
                    <option value="">Összes provider</option>
                    <option value="local">Ollama</option>
                    <option value="anthropic">Anthropic</option>
                    <option value="openai">OpenAI</option>
                </select>
                <select id="ai-history-filter-status">
                    <option value="">Összes állapot</option>
                    <option value="success">Sikeres</option>
                    <option value="failure">Sikertelen</option>
                </select>
                <button id="ai-history-filter-btn" class="btn btn-secondary" style="width:auto; padding:8px 14px;" type="button">Szűrés</button>
            </div>
            <table class="sample-table">
                <thead><tr><th>Időpont</th><th>Agent</th><th>Provider</th><th>Modell</th><th>Eszközök</th><th>Időtartam</th><th>Állapot</th></tr></thead>
                <tbody id="ai-history-body"><tr><td colspan="7" class="muted">Betöltés…</td></tr></tbody>
            </table>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                <span class="muted" id="ai-history-summary"></span>
                <div>
                    <button id="ai-history-prev-btn" class="btn btn-secondary" style="width:auto; padding:6px 12px;" type="button">Előző</button>
                    <button id="ai-history-next-btn" class="btn btn-secondary" style="width:auto; padding:6px 12px;" type="button">Következő</button>
                </div>
            </div>

            <div id="ai-history-detail-box" style="display:none; border-top:1px solid var(--border); margin-top:16px; padding-top:16px;">
                <strong>Futás részletei</strong>
                <table class="sample-table" style="margin-top:8px;">
                    <tbody id="ai-history-detail-body"></tbody>
                </table>
                <button id="ai-history-detail-close-btn" class="btn btn-secondary" style="width:auto; padding:6px 12px; margin-top:8px;" type="button">Bezárás</button>
            </div>
        </div>

        <div id="tab-ai-daily" class="tab-panel">
            <p class="muted" style="margin-top:0;">A MEGLÉVŐ Anomália-/Forgalmi-/Készlet-ügynökök determinisztikus eredményeiből naponta (ütemezve) generált, olvasható összefoglaló.</p>
            <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
                <label for="ai-daily-date-select" style="margin:0;">Dátum:</label>
                <select id="ai-daily-date-select" style="width:auto;"></select>
            </div>
            <div id="ai-daily-empty" class="modal-feedback" style="display:none;">Nincs még elkészült napi jelentés.</div>
            <div id="ai-daily-content" style="display:none;">
                <table class="sample-table">
                    <tbody>
                        <tr><td>Dátum</td><td id="ai-daily-date"></td></tr>
                        <tr><td>Elkészült</td><td id="ai-daily-generated-at"></td></tr>
                        <tr><td>Provider / modell</td><td id="ai-daily-provider-model"></td></tr>
                        <tr><td>Állapot</td><td id="ai-daily-status"></td></tr>
                    </tbody>
                </table>
                <div id="ai-daily-findings-box" style="display:none; margin-top:14px;">
                    <strong>Kiemelt megállapítások</strong>
                    <ul id="ai-daily-findings-list" style="margin:6px 0 0 20px;"></ul>
                </div>
                <div style="margin-top:14px;">
                    <strong>Jelentés</strong>
                    <p id="ai-daily-report-text" style="white-space:pre-wrap;"></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="ai-asszisztens.js"></script>
</body>
</html>
