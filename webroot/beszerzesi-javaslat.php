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
<title>FountainTrade — Beszerzési javaslat</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="4" rx="1"></rect><path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4"></path><path d="M9 14l2 2 4-4"></path></svg></span>
        <h1>Beszerzési javaslat</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:1100px;">

    <div class="import-card">
        <p class="muted" style="margin-top:0; margin-bottom:14px;">
            Az alacsony készletű termékek listája, a fogyási ütem alapján számolt sürgősséggel és
            javasolt beszerzési mennyiséggel. A pontos képletek: <a href="#suggestion-legend">lásd lent</a>.
        </p>
        <div class="filter-grid">
            <div class="pm-select" style="margin:0;">
                <div id="bj-tabs" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="toolbar-btn bj-tab active" data-urgency="">Minden (<span id="bj-count-all">0</span>)</button>
                    <button type="button" class="toolbar-btn bj-tab" data-urgency="urgent">Sürgős (<span id="bj-count-urgent">0</span>)</button>
                    <button type="button" class="toolbar-btn bj-tab" data-urgency="soon">Hamarosan elfogy (<span id="bj-count-soon">0</span>)</button>
                    <button type="button" class="toolbar-btn bj-tab" data-urgency="low">Alacsony készlet (<span id="bj-count-low">0</span>)</button>
                </div>
            </div>
            <button type="button" class="btn btn-primary" id="bj-start-purchase-btn" style="width:auto; padding:10px 18px;" disabled>
                Beszerzés indítása a kijelöltekkel (<span id="bj-selected-count">0</span>)
            </button>
        </div>
    </div>

    <div class="import-card">
        <div class="sample-table-wrap">
            <table class="sample-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="bj-select-all"></th>
                        <th>Termék</th>
                        <th>Készlet</th>
                        <th>Napi fogyás</th>
                        <th>Kifogyás</th>
                        <th>Javasolt mennyiség</th>
                        <th>Indok</th>
                    </tr>
                </thead>
                <tbody id="bj-body"></tbody>
            </table>
        </div>
    </div>

    <div class="import-card" id="suggestion-legend">
        <h2>Hogyan számolódik a javaslat?</h2>
        <p class="muted" style="margin-top:0;">
            <strong>Biztonsági készlet</strong> = a termékhez beállított (vagy alapértelmezett) riasztási küszöb.<br>
            <strong>Rendelési pont</strong> = biztonsági készlet + (napi fogyás × 7 nap) — eddig a szintig lecsökkenve érdemes rendelni.<br>
            <strong>Javasolt mennyiség</strong> = a biztonsági készlet + (napi fogyás × 14 nap) célszint és a jelenlegi készlet különbsége.<br>
            Ha egy termékhez nincs elég eladási előzmény a napi fogyás megbízható becsléséhez, a javaslat a régebbi, egyszerű
            "küszöb duplájára tölt fel" ökölszabályra esik vissza — ez a lista "Alacsony készlet" (nem "Sürgős"/"Hamarosan elfogy")
            fülén jelenik meg, jelezve, hogy a becslés kevésbé pontos.
        </p>
    </div>

</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="beszerzesi-javaslat.js"></script>
</body>
</html>
