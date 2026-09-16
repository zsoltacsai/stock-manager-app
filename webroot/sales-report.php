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
<title>FountainTrade — Forgalmi riport</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></span>
        <h1>Forgalmi riport</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:1100px;">

    <div class="import-card">
        <div class="filter-grid">
            <div>
                <label>Időszak</label>
                <select id="sr-period">
                    <option value="today">Ma</option>
                    <option value="yesterday">Tegnap</option>
                    <option value="last_7_days">Utolsó 7 nap</option>
                    <option value="last_30_days" selected>Utolsó 30 nap</option>
                    <option value="this_month">Aktuális hónap</option>
                    <option value="last_month">Előző hónap</option>
                    <option value="custom">Egyedi...</option>
                </select>
            </div>
            <div id="sr-custom-from" class="hidden">
                <label>Ettől</label>
                <input type="date" id="sr-date-from">
            </div>
            <div id="sr-custom-to" class="hidden">
                <label>Eddig</label>
                <input type="date" id="sr-date-to">
            </div>
            <div>
                <label>Fizetési mód</label>
                <select id="sr-payment-method"><option value="">Összes</option></select>
            </div>
            <button class="toolbar-btn" id="sr-apply">Szűrés</button>
            <a class="toolbar-btn" id="sr-export-csv" href="#" style="text-decoration:none; text-align:center;">CSV export</a>
        </div>
    </div>

    <div class="import-card">
        <h2>Összesítő</h2>
        <div class="stats-grid" id="sr-totals"></div>
    </div>

    <div class="import-card">
        <h2>Árrés</h2>
        <div class="stats-grid" id="sr-margin"></div>
        <p class="muted" id="sr-margin-note" style="margin-bottom:0;"></p>
    </div>

    <div class="import-card">
        <h2>Fizetési mód szerinti bontás</h2>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Fizetési mód</th><th>Darabszám</th><th>Összeg</th><th>Százalék</th></tr></thead>
                <tbody id="sr-payment-body"></tbody>
            </table>
        </div>
    </div>

    <div class="import-card">
        <h2>Napi bontás</h2>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Dátum</th><th>Bruttó</th><th>Nettó</th><th>Eladások</th></tr></thead>
                <tbody id="sr-daily-body"></tbody>
            </table>
        </div>
    </div>

    <div class="import-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Top termékek</h2>
            <div class="filter-grid" style="margin:0;">
                <div>
                    <label>Csoport</label>
                    <select id="sr-top-group"><option value="">Összes</option></select>
                </div>
                <div>
                    <label>Min. darabszám</label>
                    <input type="number" id="sr-top-min-qty" value="0" min="0" style="width:100px;">
                </div>
                <button class="toolbar-btn" id="sr-top-apply">Szűrés</button>
            </div>
        </div>
        <div class="sample-table-wrap" style="margin-top:12px;">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Csoport</th><th>Darabszám</th><th>Forgalom</th><th>Árrés</th><th>Árrés %</th></tr></thead>
                <tbody id="sr-top-body"></tbody>
            </table>
        </div>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="sales-report.js"></script>
</body>
</html>
