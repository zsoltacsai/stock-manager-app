<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Auth.php';
$appSettings = (new Settings(__DIR__ . '/../data/settings.json'))->read();
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
<title>Stock Manager — Dolgozók</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
        <h1>Dolgozók</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">
    <div class="import-card">
        <div class="products-toolbar">
            <input type="text" id="search-input" placeholder="Keresés név alapján..." style="max-width:320px; margin-bottom:0;">
            <button id="new-staff-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új dolgozó</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>Név</th><th>Jogszint</th><th>Állapot</th><th></th></tr>
            </thead>
            <tbody id="staff-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="modal-overlay" id="staff-modal">
    <div class="modal-card">
        <h2 id="staff-modal-title">Új dolgozó</h2>
        <input type="hidden" id="stf-id">
        <label for="stf-name">Név *</label>
        <input type="text" id="stf-name">
        <label for="stf-pin">PIN-kód (4-8 számjegy) — meglévőnél üresen hagyva nem változik</label>
        <input type="text" id="stf-pin" inputmode="numeric" placeholder="pl. 1234">
        <label for="stf-role">Jogszint</label>
        <select id="stf-role">
            <option value="cashier">Eladó — Kassza, Beszerzés, Eladások</option>
            <option value="admin">Vezető — mindent lát/szerkeszthet, beleértve a Beállításokat</option>
        </select>
        <p class="muted" style="margin-top:-6px;">
            Ez egy elszámoltathatósági funkció, nem valódi beléptető rendszer — bárki választhat
            másik nevet, ha ismeri a PIN-kódot. A jogszint azt szabályozza, mely oldalak/műveletek
            érhetők el az adott dolgozó bejelentkezésekor.
        </p>
        <label class="checkbox-line">
            <input type="checkbox" id="stf-active" checked>
            Aktív
        </label>
        <p id="staff-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="staff-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="staff-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="staff.js"></script>
</body>
</html>
