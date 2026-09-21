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
<title>FountainTrade — Kasszazárás</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle></svg></span>
        <h1>Kasszazárás</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:640px;">
    <div class="import-card hidden" id="close-no-session">
        <p class="muted" style="margin-top:0;">
            Nincs megadva lezárandó műszak. A kasszazárást a Kassza oldal fejlécében lévő
            kassza-jelzőre kattintva, "Kasszazárás" gombbal érheted el egy nyitott műszaknál.
        </p>
        <a class="btn btn-primary" href="index.php">Vissza a Kasszához</a>
    </div>

    <div class="import-card hidden" id="close-panel">
        <p class="muted" style="margin-top:0;" id="close-session-meta"></p>

        <table class="sample-table" style="margin-bottom:20px;">
            <tbody>
                <tr><td>Nyitó összeg</td><td id="close-row-opening" style="text-align:right;"></td></tr>
                <tr><td>Készpénzes eladások</td><td id="close-row-sales" style="text-align:right;"></td></tr>
                <tr><td>Készpénzes visszatérítések</td><td id="close-row-refunds" style="text-align:right;"></td></tr>
                <tr><td>Pénzbevét</td><td id="close-row-in" style="text-align:right;"></td></tr>
                <tr><td>Pénzkiadás</td><td id="close-row-out" style="text-align:right;"></td></tr>
                <tr><td><strong>Várható készpénz</strong></td><td id="close-row-expected" style="text-align:right;"><strong></strong></td></tr>
            </tbody>
        </table>

        <div id="close-movements-wrap">
            <h3 style="font-size:14px; margin-bottom:8px;">Pénzmozgások a műszak alatt</h3>
            <div class="sample-table-wrap">
<table class="sample-table">
                <thead><tr><th>Időpont</th><th>Típus</th><th>Összeg</th><th>Indoklás</th></tr></thead>
                <tbody id="close-movements-body"></tbody>
            </table>
</div>
        </div>

        <label for="close-counted-amount" style="margin-top:20px;">Ténylegesen megszámolt összeg (Ft) *</label>
        <input type="number" id="close-counted-amount" min="0" step="1" placeholder="0">
        <p id="close-feedback" class="modal-feedback"></p>
        <button id="close-submit-btn" class="btn btn-primary" style="width:100%; margin-top:8px;">Kasszazárás véglegesítése</button>
    </div>

    <div class="import-card hidden" id="close-result">
        <h2 style="margin-top:0;">Műszak lezárva</h2>
        <p id="close-result-text"></p>
        <a class="btn btn-primary" href="index.php">Vissza a Kasszához</a>
    </div>
</div>

<script src="api.js"></script>
<script src="topbar.js"></script>
<script src="kasszazaras.js"></script>
</body>
</html>
