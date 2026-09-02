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
<title>Stock Manager — Telephelyek</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path></svg></span>
        <h1>Telephelyek</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <p class="muted" style="margin-top:0;">
            Egytelephelyes boltnál ez a funkció figyelmen kívül hagyható — minden változatlanul
            működik. A készlet-riasztás, a WooCommerce szinkron és minden más továbbra is az
            összesített (minden telephelyen lévő) mennyiséget használja; ez a lista csak a
            telephelyenkénti bontást és a köztük történő mozgatást teszi lehetővé.
        </p>
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-locations">Telephelyek</button>
            <button class="tab-btn" data-tab="tab-transfer">Készletmozgatás</button>
            <button class="tab-btn" data-tab="tab-transfer-history">Mozgatási előzmények</button>
        </div>

        <div id="tab-locations" class="tab-panel active">
            <div class="products-toolbar">
                <span class="muted">A csillaggal jelölt az alapértelmezett telephely.</span>
                <button id="new-location-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">+ Új telephely</button>
            </div>
            <div class="sample-table-wrap">
<table class="sample-table">
                <thead><tr><th>Név</th><th>Cím</th><th>Alapértelmezett</th><th></th></tr></thead>
                <tbody id="locations-body"></tbody>
            </table>
</div>
        </div>

        <div id="tab-transfer" class="tab-panel">
            <label for="transfer-product-search">Termék keresése</label>
            <input type="text" id="transfer-product-search" placeholder="Termék neve vagy vonalkódja...">
            <div id="transfer-product-results" class="search-results"></div>
            <div id="transfer-form" class="hidden">
                <p><strong id="transfer-product-name"></strong></p>
                <div id="transfer-current-stock" class="muted" style="margin-bottom:10px;"></div>
                <div class="field-row">
                    <div>
                        <label for="transfer-from">Honnan</label>
                        <select id="transfer-from"><option value="">— Új készlet (pl. beszerzés) —</option></select>
                    </div>
                    <div>
                        <label for="transfer-to">Hová *</label>
                        <select id="transfer-to"></select>
                    </div>
                </div>
                <label for="transfer-qty">Mennyiség *</label>
                <input type="text" id="transfer-qty" placeholder="pl. 10">
                <p id="transfer-feedback" class="modal-feedback"></p>
                <button id="transfer-submit-btn" class="btn btn-primary" style="width:100%;">Mozgatás</button>
            </div>
        </div>

        <div id="tab-transfer-history" class="tab-panel">
            <div class="sample-table-wrap">
<table class="sample-table">
                <thead><tr><th>Időpont</th><th>Termék</th><th>Honnan</th><th>Hová</th><th>Menny.</th></tr></thead>
                <tbody id="transfer-history-body"></tbody>
            </table>
</div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="location-modal">
    <div class="modal-card">
        <h2>Új telephely</h2>
        <input type="hidden" id="loc-id">
        <label for="loc-name">Név *</label>
        <input type="text" id="loc-name">
        <label for="loc-address">Cím</label>
        <input type="text" id="loc-address">
        <label class="checkbox-line">
            <input type="checkbox" id="loc-default">
            Ez az alapértelmezett telephely
        </label>
        <p id="location-modal-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:20px;">
            <button class="btn btn-secondary" id="location-modal-close" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="location-modal-save" style="flex:1;">Mentés</button>
        </div>
    </div>
</div>

<script src="topbar.js"></script>
<script src="telephelyek.js"></script>
</body>
</html>
