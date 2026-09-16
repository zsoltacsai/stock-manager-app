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
<title>FountainTrade — WooCommerce szinkron</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg></span>
        <h1>WooCommerce szinkron</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:1000px;">

    <div class="import-card hidden" id="wcs-not-configured">
        <p class="muted" style="margin:0;">A WooCommerce szinkron nincs beállítva ezen a telepítésen (Beállítások → WooCommerce).</p>
    </div>

    <div class="import-card">
        <h2>Queue állapot</h2>
        <div class="stats-grid" id="wcs-stats"></div>
    </div>

    <div class="import-card">
        <h2>Sikertelen / véglegesen meghiúsult push-ok</h2>
        <p class="muted" style="margin-top:-6px;">Csak terminális (sikertelen) állapotú sorra engedélyezett a kézi újrapróbálkozás, vezetői jogszint szükséges hozzá.</p>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Termék</th><th>Kiváltó esemény</th><th>Próbálkozások</th><th>Utolsó hiba</th><th>Frissítve</th><th></th></tr></thead>
                <tbody id="wcs-failed-body"></tbody>
            </table>
        </div>
        <p class="feedback" id="wcs-retry-feedback"></p>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="woocommerce-sync.js"></script>
</body>
</html>
