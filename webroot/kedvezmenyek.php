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
<title>Stock Manager — Kedvezmények</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg></span>
        <h1>Kedvezmények</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-coupons">Kuponok</button>
            <button class="tab-btn" data-tab="tab-gift-cards">Ajándékutalványok</button>
        </div>

        <div id="tab-coupons" class="tab-panel active">
            <div class="products-toolbar">
                <span class="muted">Kedvezménykódok, amiket a Kasszán a vevő megadhat.</span>
                <button id="new-coupon-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új kupon</button>
            </div>
            <div class="sample-table-wrap">
<table class="sample-table">
                <thead>
                    <tr><th>Kód</th><th>Típus</th><th>Érték</th><th>Felhasználva</th><th>Lejárat</th><th>Állapot</th><th></th></tr>
                </thead>
                <tbody id="coupons-body"></tbody>
            </table>
</div>
        </div>

        <div id="tab-gift-cards" class="tab-panel">
            <div class="products-toolbar">
                <span class="muted">Egyenleggel rendelkező utalványok, amik több eladásban is elkölthetők.</span>
                <button id="new-gift-card-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új utalvány kiállítása</button>
            </div>
            <div class="sample-table-wrap">
<table class="sample-table">
                <thead>
                    <tr><th>Kód</th><th>Kezdő egyenleg</th><th>Jelenlegi egyenleg</th><th>Lejárat</th><th>Állapot</th><th></th></tr>
                </thead>
                <tbody id="gift-cards-body"></tbody>
            </table>
</div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="coupon-modal">
    <div class="modal-card">
        <h2 id="coupon-modal-title">Új kupon</h2>
        <input type="hidden" id="cpn-id">
        <label for="cpn-code">Kód *</label>
        <input type="text" id="cpn-code" style="text-transform:uppercase;" placeholder="pl. NYAR20">
        <div class="field-row">
            <div>
                <label for="cpn-type">Típus</label>
                <select id="cpn-type">
                    <option value="percent">Százalékos</option>
                    <option value="fixed">Fix összegű (Ft)</option>
                </select>
            </div>
            <div>
                <label for="cpn-value">Érték *</label>
                <input type="text" id="cpn-value" placeholder="pl. 20">
            </div>
        </div>
        <div class="field-row">
            <div>
                <label for="cpn-expiry">Lejárat (opcionális)</label>
                <input type="date" id="cpn-expiry">
            </div>
            <div>
                <label for="cpn-usage-limit">Felhasználási korlát (opcionális)</label>
                <input type="text" id="cpn-usage-limit" placeholder="korlátlan, ha üres">
            </div>
        </div>
        <label for="cpn-min-purchase">Minimum vásárlási összeg (Ft)</label>
        <input type="text" id="cpn-min-purchase" value="0">
        <label for="cpn-notes">Megjegyzés</label>
        <input type="text" id="cpn-notes">
        <label class="checkbox-line">
            <input type="checkbox" id="cpn-active" checked>
            Aktív
        </label>
        <p id="coupon-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="coupon-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="coupon-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="gift-card-modal">
    <div class="modal-card">
        <h2>Új ajándékutalvány kiállítása</h2>
        <label for="gc-code">Kód *</label>
        <input type="text" id="gc-code" style="text-transform:uppercase;" placeholder="pl. AJANDEK-1001">
        <label for="gc-balance">Kezdő egyenleg (Ft) *</label>
        <input type="text" id="gc-balance" placeholder="pl. 10000">
        <label for="gc-expiry">Lejárat (opcionális)</label>
        <input type="date" id="gc-expiry">
        <label for="gc-notes">Megjegyzés</label>
        <input type="text" id="gc-notes">
        <p id="gift-card-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="gift-card-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="gift-card-modal-save" style="flex:1;">Kiállítás</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="gift-card-history-modal">
    <div class="modal-card">
        <h2>Utalvány előzményei</h2>
        <div id="gift-card-history-body"></div>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="gift-card-history-close" style="flex:1;">Bezárás</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="kedvezmenyek.js"></script>
</body>
</html>
