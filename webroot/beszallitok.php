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
<title>Stock Manager — Beszállítók</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
        <h1>Beszállítók</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">
    <div class="import-card">
        <div class="products-toolbar">
            <input type="text" id="search-input" placeholder="Keresés név, telefon, email alapján..." style="max-width:320px; margin-bottom:0;">
            <button id="new-supplier-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új beszállító</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>Név</th><th>Kapcsolattartó</th><th>Telefon</th><th>Email</th><th>Fizetési feltételek</th><th></th></tr>
            </thead>
            <tbody id="suppliers-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="modal-overlay" id="supplier-modal">
    <div class="modal-card">
        <h2 id="supplier-modal-title">Új beszállító</h2>
        <input type="hidden" id="s-id">
        <label for="s-name">Név *</label>
        <input type="text" id="s-name">
        <div class="field-row">
            <div>
                <label for="s-contact">Kapcsolattartó</label>
                <input type="text" id="s-contact">
            </div>
            <div>
                <label for="s-phone">Telefon</label>
                <input type="text" id="s-phone">
            </div>
        </div>
        <label for="s-email">Email</label>
        <input type="text" id="s-email">
        <div class="field-row">
            <div>
                <label for="s-zip">Ir.szám</label>
                <input type="text" id="s-zip">
            </div>
            <div>
                <label for="s-city">Település</label>
                <input type="text" id="s-city">
            </div>
        </div>
        <label for="s-address">Cím</label>
        <input type="text" id="s-address">
        <div class="field-row">
            <div>
                <label for="s-country">Ország</label>
                <input type="text" id="s-country" value="Magyarország">
            </div>
            <div>
                <label for="s-taxnum">Adószám</label>
                <input type="text" id="s-taxnum">
            </div>
        </div>
        <label for="s-terms">Fizetési feltételek</label>
        <input type="text" id="s-terms" placeholder="pl. 30 napos átutalás">
        <label for="s-notes">Megjegyzés</label>
        <input type="text" id="s-notes">
        <div class="toggle-line" style="margin-top:14px; border-bottom:none;">
            <span>Beszállító törölve</span>
            <button type="button" class="toggle-switch" id="s-deleted"></button>
        </div>
        <p id="supplier-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="supplier-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="supplier-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="beszallitok.js"></script>
</body>
</html>
