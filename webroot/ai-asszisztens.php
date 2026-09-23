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

<div class="import-panel" style="max-width:800px;">
    <div class="import-card">
        <p class="muted" style="margin-top:0;" id="ai-status-line">Állapot ellenőrzése…</p>

        <div id="ai-disabled-notice" class="modal-feedback error" style="display:none;">
            Az AI asszisztens jelenleg ki van kapcsolva — kapcsold be a
            <a href="beallitasok.php">Beállítások → AI asszisztens</a> fülön.
        </div>

        <div id="ai-assistant-form">
            <label for="ai-agent">Agent</label>
            <select id="ai-agent">
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
</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="ai-asszisztens.js"></script>
</body>
</html>
