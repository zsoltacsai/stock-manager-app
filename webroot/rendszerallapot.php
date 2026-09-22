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
<title>FountainTrade — Rendszerállapot</title>
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

<div class="import-panel" style="max-width:1000px;">

    <!-- 1.4.0 — összesített jelző + "Figyelmet igényel" a rendszerállapot
         tetején, ugyanabból a HealthMonitor-forrásból, mint a Dashboard
         (lásd a kör 11. pontja: "egy igazságforrás"). -->
    <div class="import-card" id="health-overview-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h2 style="margin:0;">Áttekintés</h2>
            <div id="health-overall-status"></div>
        </div>
        <div class="stats-grid" id="overview-stats" style="margin-top:14px;"></div>
        <p class="muted" style="margin-bottom:0;">
            <a href="beszerzesi-javaslat.php">Beszerzési javaslat megtekintése</a> —
            az alacsony készletű termékek beszállító szerint csoportosítva.
        </p>
    </div>

    <div class="import-card" id="health-attention-card" style="display:none;">
        <h2>Figyelmet igényel</h2>
        <div id="health-attention-list"></div>
    </div>

    <!-- Rendszer komponensek — csak a ténylegesen konfigurált integrációk
         jelennek meg (lásd a kör 4. pontja), az Adatbázis mindig. Minden
         sor: státusz, utolsó ellenőrzés/sikeres futás, rövid üzenet, és —
         ahol értelmezhető — "Kapcsolat tesztelése" gomb (lásd 9. pont) és
         link a részletekhez (lásd 10. pont: queue-összesítők). A "Következő
         futás" oszlop SZÁNDÉKOSAN nincs itt — a FountainTrade nem tudja
         megbízhatóan tudni, mikor fut le legközelebb egy Windows Task
         Scheduler feladat (lásd a kör 6. pontja: "ne legyen kitalált
         next run adat"), csak azt, mikor futott le utoljára ténylegesen.
    -->
    <div class="import-card">
        <h2>Rendszer komponensek</h2>
        <div id="health-components-list"></div>
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

    <!-- Eseménynapló — a rendszeresemény-napló (lásd a kör 2-3. pontja),
         KÜLÖN az alatta lévő, régi "szinkron-napló"-tól, ami csak a
         WooCommerce termékszinkron alacsony szintű üzeneteit mutatja. -->
    <div class="import-card">
        <h2>Eseménynapló</h2>
        <div class="filter-grid" style="margin-bottom:12px;">
            <div>
                <label for="events-category-filter">Kategória</label>
                <select id="events-category-filter">
                    <option value="">— Mind —</option>
                    <option value="backup">Backup</option>
                    <option value="woocommerce">WooCommerce</option>
                    <option value="nav">NAV</option>
                    <option value="updater">Frissítés</option>
                    <option value="printer">Nyomtató</option>
                    <option value="smtp">Email (SMTP)</option>
                    <option value="auth">Belépés</option>
                    <option value="database">Adatbázis</option>
                    <option value="ai">AI asszisztens</option>
                </select>
            </div>
            <div>
                <label for="events-severity-filter">Súlyosság</label>
                <select id="events-severity-filter">
                    <option value="">— Mind —</option>
                    <option value="error">Hiba</option>
                    <option value="warning">Figyelmeztetés</option>
                    <option value="info">Info</option>
                </select>
            </div>
        </div>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Idő</th><th>Kategória</th><th>Esemény</th><th>Állapot</th></tr></thead>
                <tbody id="events-log-body"></tbody>
            </table>
        </div>
    </div>

    <div class="import-card">
        <h2>WooCommerce termékszinkron-napló</h2>
        <p class="muted" style="margin-top:-8px;">A "FAILED" kezdetű bejegyzések hibát jeleznek — termékenkénti részlet, lásd fent az összesítőt a "Rendszer komponensek" alatt.</p>
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead><tr><th>Időpont</th><th>Irány</th><th>Termék</th><th>Üzenet</th></tr></thead>
                <tbody id="sync-log-body"></tbody>
            </table>
        </div>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="rendszerallapot.js"></script>
</body>
</html>