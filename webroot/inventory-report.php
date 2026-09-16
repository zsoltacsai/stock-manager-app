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
<title>FountainTrade — Készlet riport</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
        <h1>Készlet riport</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:1100px;">

    <div class="import-card">
        <h2>Áttekintés</h2>
        <div class="stats-grid" id="ir-overview-stats"></div>
    </div>

    <div class="import-card">
        <h2>Készletérték-mutatók</h2>
        <div class="stats-grid" id="ir-valuation-stats"></div>
        <p class="muted" id="ir-valuation-note" style="margin-bottom:0;"></p>
    </div>

    <div class="import-card">
        <h2>Legnagyobb készletértékű termékek</h2>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Készlet</th><th>Beszerzési ár (nettó)</th><th>Készletérték</th></tr></thead>
                <tbody id="ir-top-value-body"></tbody>
            </table>
        </div>
    </div>

    <div class="import-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Alacsony készletű termékek</h2>
            <div class="filter-grid" style="margin:0;">
                <div>
                    <label>Szűrés</label>
                    <select id="ir-filter">
                        <option value="low" selected>Alacsony készlet</option>
                        <option value="out">Csak kifogyott</option>
                    </select>
                </div>
                <a class="toolbar-btn" id="ir-export-csv" href="#" style="text-decoration:none; text-align:center;">CSV export</a>
            </div>
        </div>
        <div class="sample-table-wrap" style="margin-top:12px;">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Csoport</th><th>Készlet</th><th>Minimum</th><th>Javasolt mennyiség</th><th>Előrejelzés</th><th>Beszállító</th></tr></thead>
                <tbody id="ir-low-stock-body"></tbody>
            </table>
        </div>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="inventory-report.js"></script>
</body>
</html>
