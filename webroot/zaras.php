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
<title>Stock Manager — Napi zárás</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></span>
        <h1>Napi zárás</h1>
    </div>
    <div class="topbar-actions no-print">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">

    <div class="import-card no-print">
        <div class="filter-grid filter-grid-toolbar">
            <label for="zaras-date" class="gt-label">Dátum</label>
            <input type="date" id="zaras-date" class="gt-input" style="width:160px;">
            <button id="zaras-refresh-btn" class="btn btn-secondary toolbar-btn gt-btn1">Frissítés</button>
            <div class="gt-btn2" style="display:flex; gap:8px; justify-self:end; align-items:stretch;">
                <button id="zaras-export-btn" class="btn btn-secondary" style="width:auto; height:41px; box-sizing:border-box; padding:0 18px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export CSV</button>
                <button id="zaras-print-btn" class="btn btn-secondary" style="width:auto; height:41px; box-sizing:border-box; padding:0 18px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Nyomtatás</button>
            </div>
        </div>
    </div>

    <div class="import-card">
        <h2 id="zaras-heading">Forgalmi összesítő</h2>
        <div class="stats-grid" id="zaras-stats"></div>
    </div>

    <div class="import-card">
        <h2>Fizetési módok szerint</h2>
        <div class="sample-table-wrap">
<table class="sample-table" id="zaras-payment-table">
            <thead><tr><th>Fizetési mód</th><th>Darab</th><th>Bruttó összeg</th></tr></thead>
            <tbody></tbody>
        </table>
</div>
    </div>

    <div class="import-card">
        <h2>ÁFA kulcsok szerint</h2>
        <div class="sample-table-wrap">
<table class="sample-table" id="zaras-vat-table">
            <thead><tr><th>Áfakulcs</th><th>Nettó</th><th>ÁFA</th><th>Bruttó</th></tr></thead>
            <tbody></tbody>
        </table>
</div>
    </div>

    <div class="import-card">
        <h2>Tranzakciók</h2>
        <div class="sample-table-wrap">
<table class="sample-table" id="zaras-transactions-table">
            <thead><tr><th>#</th><th>Idő</th><th>Összeg</th><th>Fizetés</th><th>Számla</th><th class="no-print"></th></tr></thead>
            <tbody></tbody>
        </table>
</div>
    </div>

    <div class="import-card no-print">
        <h2>Zárás</h2>
        <p class="muted" id="zaras-closing-status" style="margin-top:-8px;">Ezen a napon még nem történt zárás.</p>
        <button id="zaras-close-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Napi zárás rögzítése</button>
        <p id="zaras-close-feedback" class="feedback"></p>
    </div>

</div>

<script src="topbar.js"></script>
<script src="zaras.js"></script>
</body>
</html>
