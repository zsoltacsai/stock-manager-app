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
<title>Stock Manager — Beérkező eladások</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg></span>
        <h1>Beérkező eladások</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">

    <div class="import-card">
        <p class="muted" style="margin-top:0;">
            A WooCommerce webshopból fizetett állapotba került rendelések itt jelennek meg
            piszkozatként — a készlet csak akkor csökken, ha ellenőrzés után "leadod" a rendelést.
        </p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary wo-tab-btn active" data-status="draft">Piszkozatok<span class="wo-tab-count"></span></button>
            <button type="button" class="btn btn-secondary wo-tab-btn" data-status="confirmed">Leadva</button>
            <button type="button" class="btn btn-secondary wo-tab-btn" data-status="rejected">Elutasítva</button>
            <button type="button" class="btn btn-secondary wo-tab-btn" data-status="">Mind</button>
        </div>
    </div>

    <div class="import-card">
        <div class="products-toolbar">
            <span id="results-count" class="muted"></span>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>#</th><th>Dátum</th><th>Vevő</th><th>Összeg</th><th>Fizetés</th><th>Állapot</th><th>Tételek</th></tr>
            </thead>
            <tbody id="results-body"></tbody>
        </table>
</div>
    </div>

</div>

<div class="modal-overlay" id="detail-modal">
    <div class="modal-card" style="max-width:640px;">
        <h2>Rendelés részletei</h2>
        <div id="detail-content"></div>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="detail-modal-close" style="flex:1;">Bezárás</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="beerkezo-eladasok.js"></script>
</body>
</html>
