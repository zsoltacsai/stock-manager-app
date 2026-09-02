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
<title>Stock Manager — Leltározás</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg></span>
        <h1>Leltározás</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" id="leltar-list-view">
    <div class="import-card">
        <div class="products-toolbar">
            <span class="muted">Fizikai készletszámlálás rögzítése, eltérés-riporttal.</span>
            <button id="new-stock-take-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új leltár indítása</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>Indítva</th><th>Lezárva</th><th>Megjegyzés</th><th></th></tr>
            </thead>
            <tbody id="stock-takes-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="import-panel hidden" id="leltar-active-view" style="max-width:900px;">
    <div class="import-card">
        <div class="products-toolbar">
            <input type="text" id="leltar-search-input" placeholder="Keresés termék neve alapján..." style="max-width:320px; margin-bottom:0;">
            <button id="leltar-back-btn" class="btn btn-secondary" style="width:auto; padding:10px 18px;">Vissza a listához</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>Termék</th><th>Rendszer szerint</th><th>Megszámolt</th><th>Eltérés</th></tr>
            </thead>
            <tbody id="leltar-items-body"></tbody>
        </table>
</div>
        <div class="modal-actions" style="margin-top:16px; flex-wrap:wrap;">
            <label class="checkbox-line" style="flex:1 1 260px;">
                <input type="checkbox" id="leltar-apply-corrections">
                A megszámolt mennyiségek felülírják a rendszer szerinti készletet lezáráskor
            </label>
            <button id="leltar-complete-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Leltár lezárása</button>
        </div>
        <p class="muted" style="margin-top:8px;">
            Ha több telephelyet is használsz: a leltár és a korrekció csak az összesített
            készletet kezeli, a telephelyenkénti bontást nem — utána érdemes lehet a
            Telephelyek oldalon manuálisan összhangba hozni a megoszlást.
        </p>
        <p id="leltar-feedback" class="modal-feedback"></p>
    </div>
</div>

<script src="topbar.js"></script>
<script src="leltar.js"></script>
</body>
</html>
