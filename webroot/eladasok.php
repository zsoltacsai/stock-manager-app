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
<title>Stock Manager — Eladások</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></span>
        <h1>Eladások</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">

    <div class="import-card">
        <div class="filter-grid">
            <div>
                <label for="f-date">Dátum</label>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <input type="date" id="f-date" style="flex:1;">
                    <button type="button" id="f-date-today-btn" class="btn btn-secondary" style="width:auto; padding:0 12px;">Ma</button>
                </div>
            </div>
            <div>
                <label for="f-id">Azonosító</label>
                <input type="text" id="f-id" placeholder="#">
            </div>
            <div>
                <label for="f-query">Név / Cégnév</label>
                <input type="text" id="f-query" placeholder="Keresés...">
            </div>
            <div>
                <label class="gt-hidden-label">Szűrők törlése</label>
                <button id="clear-filters-btn" class="btn btn-secondary toolbar-btn" style="width:100%;">Szűrők törlése</button>
            </div>
            <div>
                <label class="gt-hidden-label">Export</label>
                <button id="export-csv-btn" class="btn btn-secondary toolbar-btn" style="width:100%;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV</button>
            </div>
        </div>
    </div>

    <div class="import-card">
        <div class="products-toolbar">
            <span id="results-count" class="muted"></span>
        </div>
        <div class="sample-table-wrap">
<table class="sample-table">
            <thead>
                <tr><th>#</th><th>Dátum</th><th>Vevő</th><th>Összeg</th><th>Fizetés</th><th>Számla</th></tr>
            </thead>
            <tbody id="results-body"></tbody>
        </table>
</div>
    </div>

</div>

<div class="modal-overlay" id="detail-modal">
    <div class="modal-card" style="max-width:640px;">
        <h2>Eladás részletei</h2>
        <div id="detail-content"></div>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="detail-modal-close" style="flex:1;">Bezárás</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="eladasok.js"></script>
</body>
</html>
