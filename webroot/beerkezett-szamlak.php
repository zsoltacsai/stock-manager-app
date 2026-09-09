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
<title>Stock Manager — Beérkezett számlák</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 12 18 15 15"></polyline><line x1="12" y1="11" x2="12" y2="18"></line></svg></span>
        <h1>Beérkezett számlák</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel">

    <div class="import-card">
        <div class="products-toolbar">
            <span id="sync-status-text" class="muted">Sync állapot betöltése...</span>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <select id="sync-period" style="width:auto; margin-bottom:0;">
                    <option value="7d">Utolsó 7 nap</option>
                    <option value="30d">Utolsó 30 nap</option>
                    <option value="custom">Egyedi dátumtól</option>
                </select>
                <input type="date" id="sync-custom-from" style="display:none; width:auto; margin-bottom:0;">
                <button id="sync-now-btn" class="btn btn-primary toolbar-btn" style="width:auto; flex:0 0 auto;">Számlák frissítése</button>
            </div>
        </div>
        <p id="sync-feedback" class="modal-feedback"></p>
    </div>

    <div class="import-card">
        <div class="filter-grid">
            <div>
                <label for="f-date-from">Dátumtartomány (kiállítás)</label>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <input type="date" id="f-date-from" style="flex:1;">
                    <input type="date" id="f-date-to" style="flex:1;">
                </div>
            </div>
            <div>
                <label for="f-supplier">Szállító (név / adószám)</label>
                <input type="text" id="f-supplier" placeholder="Keresés...">
            </div>
            <div>
                <label for="f-invoice-number">Számlaszám</label>
                <input type="text" id="f-invoice-number" placeholder="#">
            </div>
            <div>
                <label for="f-operation">Típus</label>
                <select id="f-operation">
                    <option value="">Mind</option>
                    <option value="CREATE">Eredeti</option>
                    <option value="MODIFY">Módosító</option>
                    <option value="STORNO">Sztornó</option>
                </select>
            </div>
            <div>
                <label for="f-currency">Pénznem</label>
                <select id="f-currency">
                    <option value="">Mind</option>
                    <option value="HUF">HUF</option>
                    <option value="EUR">EUR</option>
                </select>
            </div>
            <div>
                <label class="gt-hidden-label">Szűrők törlése</label>
                <button id="clear-filters-btn" class="btn btn-secondary toolbar-btn" style="width:100%;">Szűrők törlése</button>
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
                <tr><th>Számlaszám</th><th>Szállító</th><th>Adószám</th><th>Teljesítés</th><th>Kiállítás</th><th>Fiz. határidő</th><th>Nettó</th><th>ÁFA</th><th>Bruttó</th><th>Típus</th></tr>
            </thead>
            <tbody id="results-body"></tbody>
        </table>
</div>
    </div>

</div>

<div class="modal-overlay" id="detail-modal">
    <div class="modal-card" style="max-width:640px;">
        <h2>Beérkezett számla részletei</h2>
        <div id="detail-content"></div>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="detail-modal-close" style="flex:1;">Bezárás</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="beerkezett-szamlak.js"></script>
</body>
</html>
