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
<title>Stock Manager — Rendszerállapot</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg></span>
        <h1>Rendszerállapot</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">

    <div class="import-card">
        <h2>Áttekintés</h2>
        <div class="stats-grid" id="overview-stats"></div>
        <p class="muted" style="margin-bottom:0;">
            <a href="beszerzesi-javaslat.php">Beszerzési javaslat megtekintése</a> —
            az alacsony készletű termékek beszállító szerint csoportosítva.
        </p>
    </div>

    <div class="import-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Bevétel trend</h2>
            <select id="trend-days-select" style="width:auto; margin-bottom:0;">
                <option value="14">Utolsó 14 nap</option>
                <option value="30" selected>Utolsó 30 nap</option>
                <option value="90">Utolsó 90 nap</option>
            </select>
        </div>
        <div id="revenue-chart" style="margin-top:14px;"></div>
    </div>

    <div class="import-card">
        <h2>WooCommerce szinkron</h2>
        <div id="wc-status"></div>
    </div>

    <div class="import-card">
        <h2>Mentés (backup)</h2>
        <div id="backup-status"></div>
    </div>

    <div class="import-card">
        <h2>Nyomtató és Számlázz.hu</h2>
        <div id="config-status"></div>
    </div>

    <div class="import-card">
        <h2>Legutóbbi szinkron-napló</h2>
        <p class="muted" style="margin-top:-8px;">A "FAILED" kezdetű bejegyzések hibát jeleznek.</p>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Időpont</th><th>Irány</th><th>Termék</th><th>Üzenet</th></tr></thead>
                <tbody id="sync-log-body"></tbody>
            </table>
        </div>
    </div>

</div>

<script src="topbar.js"></script>
<script src="rendszerallapot.js"></script>
</body>
</html>
