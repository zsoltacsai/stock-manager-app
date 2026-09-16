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
<title>FountainTrade — Készletmozgások</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg></span>
        <h1>Készletmozgások</h1>
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
                <select id="sm-period">
                    <option value="today">Ma</option>
                    <option value="last_7_days">Utolsó 7 nap</option>
                    <option value="last_30_days" selected>Utolsó 30 nap</option>
                    <option value="this_month">Aktuális hónap</option>
                    <option value="last_month">Előző hónap</option>
                    <option value="custom">Egyedi...</option>
                </select>
            </div>
            <div id="sm-custom-from" class="hidden">
                <label>Ettől</label>
                <input type="date" id="sm-date-from">
            </div>
            <div id="sm-custom-to" class="hidden">
                <label>Eddig</label>
                <input type="date" id="sm-date-to">
            </div>
            <div>
                <label>Típus</label>
                <select id="sm-type">
                    <option value="">Összes</option>
                    <option value="sale">Eladás</option>
                    <option value="purchase">Beszerzés</option>
                    <option value="return">Visszáru</option>
                    <option value="stock_take">Leltár</option>
                    <option value="transfer">Készlet hozzáadás</option>
                </select>
            </div>
            <div>
                <label>Termék</label>
                <select id="sm-product"><option value="">Összes termék</option></select>
            </div>
            <button class="toolbar-btn" id="sm-apply">Szűrés</button>
            <a class="toolbar-btn" id="sm-export-csv" href="#" style="text-decoration:none; text-align:center;">CSV export</a>
        </div>
    </div>

    <div class="import-card">
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Dátum</th><th>Termék</th><th>Típus</th><th>Mennyiség változás</th><th>Hivatkozás</th></tr></thead>
                <tbody id="sm-body"></tbody>
            </table>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
            <span class="muted" id="sm-count"></span>
            <div>
                <button class="toolbar-btn" id="sm-prev" disabled>Előző</button>
                <button class="toolbar-btn" id="sm-next" disabled>Következő</button>
            </div>
        </div>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="stock-movements.js"></script>
</body>
</html>
