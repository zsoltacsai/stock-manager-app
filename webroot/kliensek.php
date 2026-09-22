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
<title>FountainTrade — Kliensek</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="8" height="16" rx="1"></rect><rect x="14" y="4" width="8" height="16" rx="1"></rect><line x1="6" y1="8" x2="6" y2="8.01"></line><line x1="18" y1="8" x2="18" y2="8.01"></line></svg></span>
        <h1>Kliensek</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <p class="muted" style="margin-top:0;">
            Egy regisztrált Kliens egy másik gépen futó FountainTrade Kliens-terminál, ami
            ezen a Szerveren keresztül éri el az adatokat — nincs saját adatbázisa. A
            <strong>titok</strong> (client secret) csak a regisztráció/csere pillanatában
            jelenik meg, utána soha többé nem kérhető le — jegyezd fel azonnal, és add meg a
            Kliens gép telepítőjében.
        </p>
        <div class="products-toolbar">
            <span class="muted">&nbsp;</span>
            <button id="new-client-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új kliens regisztrálása</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead><tr><th>Címke</th><th>Client ID</th><th>Állapot</th><th>Utoljára látva</th><th></th></tr></thead>
            <tbody id="clients-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="modal-overlay" id="new-client-modal">
    <div class="modal-card">
        <h2>Új kliens regisztrálása</h2>
        <label for="client-label">Címke *</label>
        <input type="text" id="client-label" placeholder="pl. Pénztár 2 - iroda gép">
        <p id="new-client-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="new-client-cancel" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="new-client-save" style="flex:1;">Regisztrálás</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="secret-modal">
    <div class="modal-card">
        <h2>A kliens titka (csak most jelenik meg)</h2>
        <p class="muted" style="margin-top:-8px;">
            Ezt az értéket <strong>nem tudod később újra lekérni</strong> — másold ki most, és
            add meg a Kliens gép telepítőjében (<code>client_secret</code> mező). Ha elveszett,
            a "Titok cseréje" gombbal generálhatsz újat.
        </p>
        <label for="secret-client-id">Client ID</label>
        <input type="text" id="secret-client-id" readonly>
        <label for="secret-value">Client secret</label>
        <input type="text" id="secret-value" readonly>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-primary" id="secret-modal-close" style="flex:1;">Megjegyeztem, bezárás</button>
        </div>
    </div>
</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="kliensek.js"></script>
</body>
</html>
