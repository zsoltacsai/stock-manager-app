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
<title>FountainTrade — Kassza-riport</title>
<link rel="stylesheet" href="style.css">
<style>
    @media print {
        .sidebar, .topbar, .filter-grid, .products-toolbar { display: none !important; }
    }
</style>
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle></svg></span>
        <h1>Kassza-riport</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">

    <div class="import-card">
        <p class="muted" style="margin-top:0;"><a href="penztargepek.php">Pénztárgépek kezelése</a></p>
        <div class="filter-grid">
            <div>
                <label for="f-date-from">Dátumtól</label>
                <input type="date" id="f-date-from">
            </div>
            <div>
                <label for="f-date-to">Dátumig</label>
                <input type="date" id="f-date-to">
            </div>
            <div>
                <label for="f-location">Telephely</label>
                <select id="f-location"><option value="">Mind</option></select>
            </div>
            <div>
                <label for="f-register">Pénztárgép</label>
                <select id="f-register"><option value="">Mind</option></select>
            </div>
            <div>
                <label for="f-status">Állapot</label>
                <select id="f-status">
                    <option value="">Mind</option>
                    <option value="open">Nyitva</option>
                    <option value="closed">Zárva</option>
                </select>
            </div>
            <div>
                <label class="gt-hidden-label">Műveletek</label>
                <div style="display:flex; gap:8px;">
                    <button id="print-btn" class="btn btn-secondary toolbar-btn" style="flex:1;">Nyomtatás</button>
                    <button id="export-csv-btn" class="btn btn-secondary toolbar-btn" style="flex:1;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV</button>
                </div>
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
                <tr><th>#</th><th>Telephely</th><th>Pénztárgép</th><th>Kasszás</th><th>Állapot</th><th>Nyitás</th><th>Zárás</th><th>Nyitó</th><th>Számolt</th><th>Várható</th><th>Eltérés</th></tr>
            </thead>
            <tbody id="results-body"></tbody>
        </table>
</div>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="kassza-riport.js"></script>
</body>
</html>
