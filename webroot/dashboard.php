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
<title>FountainTrade — Dashboard</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg></span>
        <h1>Dashboard</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:1100px;">

    <div class="import-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Időszak</h2>
            <select id="dashboard-period-select" style="width:auto; margin-bottom:0;">
                <option value="today" selected>Ma</option>
                <option value="yesterday">Tegnap</option>
                <option value="last_7_days">Utolsó 7 nap</option>
                <option value="last_30_days">Utolsó 30 nap</option>
                <option value="this_month">Aktuális hónap</option>
                <option value="last_month">Előző hónap</option>
                <option value="custom">Egyedi...</option>
            </select>
        </div>
        <div id="dashboard-custom-range" class="filter-grid hidden" style="margin-top:12px;">
            <div>
                <label>Ettől</label>
                <input type="date" id="dashboard-date-from">
            </div>
            <div>
                <label>Eddig</label>
                <input type="date" id="dashboard-date-to">
            </div>
            <button class="toolbar-btn" id="dashboard-custom-apply">Alkalmaz</button>
        </div>
    </div>

    <div class="import-card">
        <h2>Ma</h2>
        <div class="stats-grid" id="dashboard-today-stats"></div>
    </div>

    <div class="import-card">
        <h2 id="dashboard-period-title">Kiválasztott időszak</h2>
        <div class="stats-grid" id="dashboard-period-stats"></div>
    </div>

    <div class="import-card">
        <h2>Készlet</h2>
        <div class="stats-grid" id="dashboard-inventory-stats"></div>
        <p class="muted" style="margin-bottom:0;">
            <a href="inventory-report.php">Készletriport megtekintése</a> ·
            <a href="beszerzesi-javaslat.php">Beszerzési javaslat</a>
        </p>
    </div>

    <div class="import-card">
        <h2>WooCommerce szinkron</h2>
        <div id="dashboard-wc-status"></div>
        <p class="muted" style="margin-bottom:0;"><a href="woocommerce-sync.php">Részletes szinkron-nézet megtekintése</a></p>
    </div>

    <div class="import-card">
        <h2>NAV számla queue</h2>
        <div id="dashboard-nav-status"></div>
        <p class="muted" style="margin-bottom:0;"><a href="kimeno-szamlak.php">Kimenő számlák megtekintése</a></p>
    </div>

    <div class="import-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Bevétel trend</h2>
        </div>
        <div id="dashboard-revenue-chart" style="margin-top:14px;"></div>
    </div>

    <div class="import-card">
        <h2>Riportok</h2>
        <p class="muted" style="margin-bottom:0;">
            <a href="sales-report.php">Forgalmi riport</a> ·
            <a href="inventory-report.php">Készlet riport</a> ·
            <a href="stock-movements.php">Készletmozgások</a> ·
            <a href="woocommerce-sync.php">WooCommerce szinkron</a>
        </p>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="dashboard.js"></script>
</body>
</html>
