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

<div class="import-panel" style="max-width:1000px;">

    <!-- 1. Fejléc: mai dátum + névnap / rendszerállapot -->
    <div class="import-card" id="dash-header-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
            <div>
                <h2 id="dash-date" style="margin:0 0 4px;">Betöltés...</h2>
                <p class="muted" id="dash-nameday" style="margin:0;"></p>
            </div>
            <div id="dash-system-status"></div>
        </div>
    </div>

    <!-- 1.5. Rendszer állapota (kompakt — lásd rendszerallapot.php a teljes nézetért) -->
    <div class="import-card" id="dash-health-card">
        <h2>Rendszer állapota</h2>
        <div id="dash-health-list"></div>
        <p class="muted" style="margin:10px 0 0;"><a href="rendszerallapot.php">Rendszerállapot megtekintése →</a></p>
    </div>

    <!-- 2. Mai KPI-k -->
    <div class="import-card">
        <h2>Ma</h2>
        <div class="stats-grid" id="dash-kpi-stats"></div>
    </div>

    <!-- 3. Figyelmet igényel -->
    <div class="import-card" id="dash-attention-card">
        <h2>Figyelmet igényel</h2>
        <div id="dash-attention-list"></div>
    </div>

    <!-- 4. Mai napi állapotok -->
    <div class="import-card">
        <h2>Napi állapotok</h2>
        <div id="dash-today-status"></div>
    </div>

    <!-- 4b. Fázis 7 — AI napi intelligencia kompakt kártya -->
    <div class="import-card" id="dash-ai-daily-card" style="display:none;">
        <h2>Mai AI összefoglaló</h2>
        <div id="dash-ai-daily-status"></div>
        <!-- Fázis 9 — Copilot/asszisztens használati összesítő (provider/
             modell/mai futásszám/tokenek/becsült költség), UGYANABBÓL a
             dashboard-summary.php hívásból, extra kérés nélkül. -->
        <div id="dash-ai-usage-status" style="margin-top:10px; padding-top:10px; border-top:1px solid var(--border); display:none;"></div>
    </div>

    <!-- 5. 7 napos forgalmi trend -->
    <div class="import-card">
        <h2>Forgalom — utolsó 7 nap</h2>
        <div id="dash-revenue-chart"></div>
    </div>

    <!-- 6. Top termékek + fizetési módok -->
    <div class="import-card">
        <h2>Mai top termékek</h2>
        <div id="dash-top-products"></div>
        <p class="muted" style="margin:10px 0 0;"><a href="sales-report.php">Teljes forgalmi riport megtekintése</a></p>
    </div>

    <div class="import-card">
        <h2>Mai fizetési módok</h2>
        <div id="dash-payment-methods"></div>
    </div>

    <div class="import-card">
        <h2>Riportok</h2>
        <p class="muted" style="margin-bottom:0;">
            <a href="sales-report.php">Forgalmi riport</a> ·
            <a href="inventory-report.php">Készlet riport</a> ·
            <a href="stock-movements.php">Készletmozgások</a> ·
            <a href="beszerzesi-javaslat.php">Beszerzési javaslat</a> ·
            <a href="woocommerce-sync.php">WooCommerce szinkron</a> ·
            <a href="kimeno-szamlak.php">Kimenő számlák</a>
        </p>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="dashboard.js"></script>
</body>
</html>
