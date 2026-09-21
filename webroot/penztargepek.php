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
<title>FountainTrade — Pénztárgépek</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle></svg></span>
        <h1>Pénztárgépek</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <p class="muted" style="margin-top:0;">
            A pénztárgépek a kasszanyitás/kasszazárás alapegységei — minden pénztárgéphez
            legfeljebb egy nyitott műszak tartozhat egyszerre. Egy telephelyen több pénztárgép
            is felvehető. Csak vezetői jogszinttel hozható létre/szerkeszthető.
            — <a href="kassza-riport.php">Kassza-riport megnyitása</a>
        </p>
        <div class="products-toolbar">
            <span class="muted">&nbsp;</span>
            <button id="new-register-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új pénztárgép</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead><tr><th>Név</th><th>Kód</th><th>Telephely</th><th>Aktív</th><th></th></tr></thead>
            <tbody id="registers-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="modal-overlay" id="register-modal">
    <div class="modal-card">
        <h2 id="register-modal-title">Új pénztárgép</h2>
        <input type="hidden" id="reg-id">
        <label for="reg-name">Név *</label>
        <input type="text" id="reg-name" placeholder="pl. Kassza 1">
        <label for="reg-code">Kód *</label>
        <input type="text" id="reg-code" placeholder="pl. K1">
        <label for="reg-location">Telephely *</label>
        <select id="reg-location"></select>
        <label class="checkbox-line">
            <input type="checkbox" id="reg-active" checked>
            Aktív
        </label>
        <p id="register-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="register-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="register-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="penztargepek.js"></script>
</body>
</html>
