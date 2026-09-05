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
<title>Stock Manager — Vásárlók</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
        <h1>Vásárlók</h1>
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
            <span id="customers-selected-count" class="muted"></span>
            <button id="export-customers-csv-btn" class="btn btn-secondary toolbar-btn" style="width:auto;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV</button>
            <button id="export-customers-xls-btn" class="btn btn-secondary toolbar-btn" style="width:auto;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export XLS</button>
            <button id="new-customer-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új vásárló</button>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th style="width:32px;"><input type="checkbox" id="select-all-customers" title="Mind kijelölése"></th><th>Név</th><th>Telefon</th><th>Email</th><th>Pontegyenleg</th><th></th></tr>
            </thead>
            <tbody id="customers-body"></tbody>
        </table>
</div>
    </div>
</div>

<div class="modal-overlay" id="customer-modal">
    <div class="modal-card" style="max-width:640px;">
        <h2 id="customer-modal-title">Új vásárló</h2>

        <div class="tabs" id="customer-modal-tabs">
            <button class="tab-btn active" data-tab="c-tab-data">Adatok</button>
            <button class="tab-btn hidden" data-tab="c-tab-stats" id="c-tab-stats-btn">Statisztika</button>
            <button class="tab-btn hidden" data-tab="c-tab-items" id="c-tab-items-btn">Vásárolt tételek</button>
        </div>

        <div id="c-tab-data" class="tab-panel active">
            <input type="hidden" id="c-id">
            <label for="c-name">Név *</label>
            <input type="text" id="c-name">
            <div class="field-row">
                <div>
                    <label for="c-phone">Telefon</label>
                    <input type="text" id="c-phone">
                </div>
                <div>
                    <label for="c-email">Email</label>
                    <input type="text" id="c-email">
                </div>
            </div>
            <label for="c-taxnum">Adószám (opcionális, céges vásárlóknál)</label>
            <input type="text" id="c-taxnum">
            <div class="field-row">
                <div>
                    <label for="c-zip">Ir.szám</label>
                    <input type="text" id="c-zip">
                </div>
                <div>
                    <label for="c-city">Település</label>
                    <input type="text" id="c-city">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label for="c-address">Cím (utca, házszám)</label>
                    <input type="text" id="c-address">
                </div>
                <div>
                    <label for="c-country">Ország</label>
                    <input type="text" id="c-country" value="Magyarország">
                </div>
            </div>
            <p class="muted" style="margin-top:-6px;">
                A cím megadása opcionális, de ha kitöltöd, a Kasszán a "Vevő számlát kér" résznél
                a névre kattintva ez is automatikusan kitöltődik.
            </p>
            <label for="c-notes">Megjegyzés</label>
            <input type="text" id="c-notes">

            <div class="toggle-line" style="margin-top:14px; border-bottom:none;">
                <span>Vásárló törölve</span>
                <button type="button" class="toggle-switch" id="c-deleted"></button>
            </div>
        </div>

        <div id="c-tab-stats" class="tab-panel">
            <div class="stats-grid" id="c-stats-grid" style="margin-bottom:14px;"></div>
            <p style="margin:0 0 4px;">Jelenlegi pontegyenleg: <strong id="c-points-balance">0</strong> pont</p>
            <label style="margin-top:14px;">Pontelőzmény</label>
            <div id="c-points-history" style="font-size:12px;"></div>
        </div>

        <div id="c-tab-items" class="tab-panel">
            <div class="sample-table-wrap" style="max-height:360px; overflow-y:auto;">
                <table class="sample-table">
                    <thead><tr><th>Dátum</th><th>Termék</th><th>Menny.</th><th>Egységár</th></tr></thead>
                    <tbody id="c-items-body"></tbody>
                </table>
            </div>
        </div>

        <p id="customer-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="customer-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="customer-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="vasarlok.js"></script>
</body>
</html>
