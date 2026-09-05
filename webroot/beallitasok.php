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
<title>Stock Manager — Beállítások</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></span>
        <h1>Beállítások</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:900px;">
    <div class="import-card">
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-sync-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>Szinkronizálás</button>
            <button class="tab-btn" data-tab="tab-logo-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>Logó</button>
            <button class="tab-btn" data-tab="tab-printer-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Nyomtató</button>
            <button class="tab-btn" data-tab="tab-backup-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>Mentés</button>
            <button class="tab-btn" data-tab="tab-szamlazz-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>Számlázz.hu</button>
            <button class="tab-btn" data-tab="tab-wc-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>WooCommerce</button>
            <button class="tab-btn" data-tab="tab-lowstock-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>Készlet riasztás</button>
            <button class="tab-btn" data-tab="tab-appearance-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line></svg>Megjelenés</button>
            <button class="tab-btn" data-tab="tab-receipt-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2-3 2z"></path><line x1="8" y1="8" x2="16" y2="8"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>Nyugta</button>
            <button class="tab-btn" data-tab="tab-loyalty-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>Törzsvásárlói pontok</button>
            <button class="tab-btn" data-tab="tab-audit-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"></path></svg>Tevékenységnapló</button>
            <button class="tab-btn" data-tab="tab-import-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Importálás</button>
            <button class="tab-btn" data-tab="tab-security-settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>Biztonság</button>
        </div>

        <div id="tab-sync-settings" class="tab-panel active">
            <label class="checkbox-line">
                <input type="checkbox" id="auto-sync-enabled">
                Automatikus WooCommerce szinkron bekapcsolása
            </label>
            <label for="auto-sync-interval">Gyakoriság</label>
            <select id="auto-sync-interval">
                <option value="5">5 percenként</option>
                <option value="15" selected>15 percenként</option>
                <option value="30">30 percenként</option>
                <option value="60">óránként</option>
            </select>
            <p class="muted" id="last-auto-sync"></p>
            <p class="muted">
                Az automatikus futtatáshoz egy rendszer cron bejegyzés szükséges, ami percenként
                meghívja ezt a végpontot (a végpont maga dönti el, hogy esedékes-e a szinkron a
                fenti gyakoriság alapján, tehát a percenkénti hívás ártalmatlan):
            </p>
            <p class="muted" style="font-family: monospace; background: var(--panel-light); padding: 8px 10px; border-radius: 6px;">
                * * * * * curl -s http://localhost:8000/api/auto-sync-run.php &gt; /dev/null
            </p>
            <button id="settings-save-sync-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-sync-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-logo-settings" class="tab-panel">
            <div class="logo-preview-box">
                <img id="logo-preview" src="assets/logo-default.svg" alt="Logó előnézet">
                <div style="flex:1;">
                    <input type="file" id="logo-file-input" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                </div>
            </div>
            <div class="modal-actions">
                <button id="logo-reset-btn" class="btn btn-secondary" style="flex:1;">Alaplogóra visszaállítás</button>
                <button id="logo-upload-btn" class="btn btn-primary">Feltöltés</button>
            </div>
            <p id="settings-logo-feedback" class="modal-feedback"></p>

            <div style="border-top:1px solid var(--border); margin:20px 0 16px; padding-top:16px;">
                <h3 style="margin:0 0 4px;">Nyomtatási logó</h3>
                <p class="muted" style="margin-top:0;">
                    Külön logó a nyugtán (böngésző-nyomtatás) — hasznos, ha a fenti,
                    felületen használt logó színes/részletes és nem nyomtatna jól.
                    Ha nincs beállítva, a nyugta a fenti logót használja.
                </p>
                <div class="logo-preview-box">
                    <img id="print-logo-preview" src="assets/logo-default.svg" alt="Nyomtatási logó előnézet">
                    <div style="flex:1;">
                        <input type="file" id="print-logo-file-input" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                    </div>
                </div>
                <div class="modal-actions">
                    <button id="print-logo-reset-btn" class="btn btn-secondary" style="flex:1;">Alaplogóra visszaállítás</button>
                    <button id="print-logo-upload-btn" class="btn btn-primary">Feltöltés</button>
                </div>
                <p id="settings-print-logo-feedback" class="modal-feedback"></p>
            </div>
        </div>

        <div id="tab-printer-settings" class="tab-panel">
            <label class="checkbox-line">
                <input type="checkbox" id="printer-enabled">
                Hálózati nyugtanyomtató engedélyezése
            </label>
            <label for="printer-ip">Nyomtató IP címe</label>
            <input type="text" id="printer-ip" placeholder="192.168.1.50">
            <div class="field-row">
                <div>
                    <label for="printer-port">Port</label>
                    <input type="text" id="printer-port" value="9100">
                </div>
                <div>
                    <label for="printer-paper-width">Papír</label>
                    <select id="printer-paper-width">
                        <option value="32">58 mm (32 kar.)</option>
                        <option value="42" selected>80 mm (42 kar.)</option>
                    </select>
                </div>
            </div>
            <p class="muted">
                ESC/POS hálózati (Ethernet/WiFi) hőnyomtatókhoz, "raw" / 9100-as port
                módban. Ha USB-s vagy nem ESC/POS nyomtatód van, használd helyette a
                nyugtán a "Nyomtatás böngészőből" gombot — az bármilyen, a géphez
                telepített nyomtatóval működik.
            </p>
            <div class="modal-actions">
                <button id="printer-test-btn" class="btn btn-secondary" style="flex:1;">Teszt nyomtatás</button>
                <button id="settings-save-printer-btn" class="btn btn-primary">Mentés</button>
            </div>
            <p id="settings-printer-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-backup-settings" class="tab-panel">
            <label class="checkbox-line">
                <input type="checkbox" id="backup-enabled">
                Automatikus napi mentés bekapcsolása
            </label>
            <div class="field-row">
                <div>
                    <label for="backup-time">Időpont</label>
                    <input type="text" id="backup-time" value="23:30" placeholder="23:30">
                </div>
                <div>
                    <label for="backup-retention">Megőrzött mentések</label>
                    <input type="text" id="backup-retention" value="7">
                </div>
            </div>
            <p class="muted" id="last-backup"></p>
            <p class="muted">
                Az automatikus futtatáshoz egy rendszer cron bejegyzés szükséges (pl. 15
                percenként), ami maga dönti el, hogy esedékes-e a mentés a fenti időpont
                alapján, naponta legfeljebb egyszer:
            </p>
            <p class="muted" style="font-family: monospace; background: var(--panel-light); padding: 8px 10px; border-radius: 6px;">
                */15 * * * * curl -s http://localhost:8000/api/auto-backup-run.php &gt; /dev/null
            </p>

            <label for="backup-provider">Felhő szinkronizálás</label>
            <select id="backup-provider">
                <option value="none" selected>Nincs (csak helyi mentés)</option>
                <option value="dropbox">Dropbox</option>
                <option value="googledrive">Google Drive</option>
            </select>

            <div id="backup-dropbox-fields" class="hidden">
                <label for="dropbox-access-token">Dropbox access token</label>
                <input type="text" id="dropbox-access-token" placeholder="sl.xxxxxxxx...">
                <label for="dropbox-folder">Mappa</label>
                <input type="text" id="dropbox-folder" placeholder="/StockManagerBackups">
                <p class="muted">
                    Dropbox App Console → alkalmazásod → "Generate access token". Ez a
                    legegyszerűbb mód, nem igényel böngészős bejelentkezési folyamatot.
                </p>
            </div>

            <div id="backup-google-fields" class="hidden">
                <label for="google-client-id">Google OAuth Client ID</label>
                <input type="text" id="google-client-id" placeholder="xxxxx.apps.googleusercontent.com">
                <label for="google-client-secret">Google OAuth Client Secret</label>
                <input type="text" id="google-client-secret" placeholder="GOCSPX-...">
                <label for="google-refresh-token">Refresh token</label>
                <input type="text" id="google-refresh-token" placeholder="1//...">
                <label for="google-folder-id">Mappa ID (opcionális)</label>
                <input type="text" id="google-folder-id" placeholder="Üresen hagyva a Drive gyökerébe ment">
                <p class="muted">
                    A Google Drive-hoz nincs egyszerű "generálj egy tokent" opció — egyszeri
                    OAuth beállítás kell (lásd README). Miután megvan a három érték, ide
                    beillesztve minden további mentés automatikus.
                </p>
            </div>

            <div class="modal-actions">
                <button id="backup-now-btn" class="btn btn-secondary" style="flex:1;">Mentés most</button>
                <button id="settings-save-backup-btn" class="btn btn-primary">Beállítások mentése</button>
            </div>
            <p id="settings-backup-feedback" class="modal-feedback"></p>

            <p class="muted" style="margin-top:14px;">Helyi mentések:</p>
            <div id="backup-list"></div>

            <div style="margin-top:18px; border-top:1px solid var(--border); padding-top:14px;">
                <label style="color:var(--danger);">Visszaállítás fájlból</label>
                <p class="muted" style="margin-top:-8px;">
                    Egy korábban letöltött vagy máshonnan kapott mentési fájl visszatöltése.
                    A visszaállítás előtt automatikusan készül egy biztonsági mentés a jelenlegi
                    állapotról, hogy tévedés esetén vissza lehessen lépni.
                </p>
                <input type="file" id="restore-file-input" accept=".sqlite,.sql">
                <button id="restore-file-btn" class="btn btn-secondary" style="width:auto; padding:10px 18px; border-color:var(--danger); color:var(--danger);">Visszaállítás a fájlból</button>
                <p id="restore-feedback" class="modal-feedback"></p>
            </div>
        </div>

        <div id="tab-szamlazz-settings" class="tab-panel">
            <strong>Fizetési módok</strong>
            <p class="muted" style="margin-top:4px;">
                A kasszán és a beérkező webshop-rendelések leadásakor választható
                fizetési módok listája — bővítsd ki pl. "Stripe"-pal, ha a
                webáruházban ezt is elfogadod.
            </p>
            <div id="payment-methods-list" style="margin-top:8px;"></div>
            <div class="field-row" style="margin-top:10px;">
                <input type="text" id="payment-methods-add-input" placeholder="Új fizetési mód neve (pl. Stripe)">
                <button type="button" id="payment-methods-add-btn" class="btn btn-secondary" style="width:auto; padding:0 16px;">Hozzáadás</button>
            </div>
            <button id="settings-save-payment-methods-btn" class="btn btn-primary" style="width:auto; padding:10px 18px; margin-top:10px;">Mentés</button>
            <p id="settings-payment-methods-feedback" class="modal-feedback"></p>

            <label for="szamlazz-agent-key" style="margin-top:20px; display:block; border-top:1px solid var(--border); padding-top:14px;">Számla Agent kulcs</label>
            <input type="text" id="szamlazz-agent-key" placeholder="A Számlázz.hu Beállítások → Számla Agent oldaláról">
            <div class="field-row">
                <div>
                    <label for="szamlazz-default-payment">Alapértelmezett fizetési mód</label>
                    <select id="szamlazz-default-payment"></select>
                </div>
                <div>
                    <label for="szamlazz-default-vat">Alapértelmezett áfakulcs</label>
                    <select id="szamlazz-default-vat">
                        <option value="27">27%</option>
                        <option value="18">18%</option>
                        <option value="5">5%</option>
                        <option value="0">0%</option>
                        <option value="AAM">AAM</option>
                        <option value="TAM">TAM</option>
                    </select>
                </div>
            </div>
            <label class="checkbox-line">
                <input type="checkbox" id="szamlazz-send-email">
                Számla e-mailben küldése a vevőnek (ha van e-mail címe)
            </label>
            <button id="settings-save-szamlazz-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-szamlazz-feedback" class="modal-feedback"></p>

            <p class="muted" style="margin-top:20px; border-top:1px solid var(--border); padding-top:14px;">
                <strong>NAV cégadat lekérdezés</strong> (adószám alapján automatikus kitöltés a "Vevő számlát kér" résznél)
            </p>
            <label for="nav-login">NAV technikai felhasználó — login</label>
            <input type="text" id="nav-login">
            <label for="nav-password">Jelszó</label>
            <input type="password" id="nav-password">
            <label for="nav-signer-key">Aláíró kulcs (signer key)</label>
            <input type="text" id="nav-signer-key">
            <label for="nav-exchange-key">Csere kulcs (exchange key)</label>
            <input type="text" id="nav-exchange-key">
            <label for="nav-tax-number">Saját adószám (8 jegyű)</label>
            <input type="text" id="nav-tax-number" placeholder="12345678">
            <label class="checkbox-line">
                <input type="checkbox" id="nav-test-mode">
                Teszt rendszer használata (api-test.onlineszamla.nav.gov.hu)
            </label>
            <p class="muted">
                Ehhez egy ingyenes "technikai felhasználó" regisztráció szükséges a NAV Online
                Számla portálján (onlineszamla.nav.gov.hu). Lásd a README-t a pontos lépésekért.
                Ez a funkció NAV élesben nem lett tesztelve — érdemes előbb a teszt rendszerrel
                ellenőrizni.
            </p>
            <button id="settings-save-nav-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-nav-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-wc-settings" class="tab-panel">
            <label for="wc-store-url">Áruház URL</label>
            <input type="text" id="wc-store-url" placeholder="https://a-boltod.hu">
            <div class="field-row">
                <div>
                    <label for="wc-consumer-key">Consumer key</label>
                    <input type="text" id="wc-consumer-key" placeholder="ck_...">
                </div>
                <div>
                    <label for="wc-consumer-secret">Consumer secret</label>
                    <input type="text" id="wc-consumer-secret" placeholder="cs_...">
                </div>
            </div>
            <button type="button" id="wc-test-connection-btn" class="btn btn-secondary" style="width:auto; padding:8px 14px;">Kapcsolat tesztelése</button>
            <p id="wc-test-connection-feedback" class="modal-feedback"></p>
            <label for="wc-barcode-source">Vonalkód forrása a WooCommerce oldalon</label>
            <select id="wc-barcode-source">
                <option value="sku">SKU mező</option>
                <option value="meta">Egyéni meta mező</option>
            </select>
            <div id="wc-barcode-meta-wrap" class="hidden">
                <label for="wc-barcode-meta-key">Meta mező kulcsa</label>
                <input type="text" id="wc-barcode-meta-key" placeholder="_barcode">
            </div>
            <label for="wc-webhook-secret">Webhook titkos kulcs</label>
            <input type="text" id="wc-webhook-secret">

            <label for="wc-public-base-url">Kívülről elérhető alap URL (a termékkép-szinkronhoz)</label>
            <input type="text" id="wc-public-base-url" placeholder="https://kassza.pelda.hu">
            <p class="muted" style="margin-top:-6px;">
                Opcionális. Csak akkor kell kitölteni, ha szeretnéd, hogy a termékekhez
                feltöltött kép is kiküldésre kerüljön a WooCommerce felé — a
                WooCommerce szerverének el kell tudnia érnie ezt a címet, hogy
                letöltse a képet. Ha üresen hagyod, minden más mező (név, ár,
                leírás, márka) továbbra is szinkronizál, csak a kép nem.
                A WordPress alapból csak a 80, 443 és 8080 portokról fogad el
                ilyen letöltést — ha a kassza-szerver más porton fut, és nincs
                előtte reverse proxy (lásd a távoli szerveres telepítési
                útmutatót), a WooCommerce oldalon ezt engedélyezni kell
                (`http_allowed_safe_ports` szűrő).
            </p>

            <label for="product-image-size">Termékkép mérete (px, négyzet alakú)</label>
            <input type="text" id="product-image-size" placeholder="1200">
            <p class="muted" style="margin-top:-6px;">
                Ekkora négyzet méretre vágja/skálázza a szerver a termékekhez
                feltöltött képet (200–4000 px között). A már feltöltött
                képeket ez a beállítás nem alakítja át visszamenőleg, csak
                az ezután feltöltötteket.
            </p>

            <button id="settings-save-wc-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-wc-feedback" class="modal-feedback"></p>

            <div style="border-top:1px solid var(--border); margin-top:20px; padding-top:14px;">
                <strong>Márka-megfeleltetés</strong>
                <p class="muted" style="margin-top:4px;">
                    Ha egy helyi márkanév nem pontosan egyezik a WooCommerce-ben
                    már meglévő márka nevével (pl. elírás, más írásmód), itt
                    rendelheted hozzá a helyeset — így szinkron-kiküldéskor nem
                    jön létre felesleges, duplikált márka a webshopban. Amit itt
                    nem feleltetsz meg, az a helyi néven kerül kiküldésre
                    (a WooCommerce automatikusan létrehozza, ha még nem létezik).
                </p>
                <button type="button" id="brand-mapping-reload-btn" class="btn btn-secondary" style="width:auto; padding:8px 14px;">WooCommerce márkák betöltése</button>
                <div id="brand-mapping-list" style="margin-top:12px;"></div>
                <button id="settings-save-brand-mapping-btn" class="btn btn-primary" style="width:auto; padding:10px 18px; margin-top:10px;">Megfeleltetés mentése</button>
                <p id="settings-brand-mapping-feedback" class="modal-feedback"></p>
            </div>

            <div class="muted" style="margin-top:20px; border-top:1px solid var(--border); padding-top:14px; line-height:1.6;">
                <strong>Hogyan működik a szinkron?</strong><br>
                <strong>Pull (behúzás)</strong> — a Beszerzés oldal "Sync WooCommerce-ből" gombjával,
                vagy automatikusan (lásd Szinkronizálás fül): a WooCommerce termékei (név, ár,
                vonalkód/SKU, készlet) felülírják a helyi adatokat a vonalkód/SKU alapján párosítva.<br><br>
                <strong>Push (kiküldés)</strong> — minden kassza-eladás és beszerzés után automatikusan:
                az itt módosult készletmennyiség kiküldésre kerül a WooCommerce-be a hozzá tartozó
                terméknél, hogy a webáruház is naprakész maradjon.<br><br>
                <strong>Webhook (valós idejű)</strong> — ha a WooCommerce webhookot állítasz be
                (Woo → Beállítások → Speciális → Webhookok) a "Rendelés frissítve" eseményre, a
                webshopon történt (fizetett) rendelések a <strong>Beérkező eladások</strong> menüpontban
                jelennek meg piszkozatként (piros jelzéssel + felugró értesítéssel) — a készlet csak
                akkor csökken, ha ott ellenőrzés után leadod a rendelést.
            </div>
        </div>

        <div id="tab-lowstock-settings" class="tab-panel">
            <label for="low-stock-default">Alapértelmezett riasztási küszöb (db)</label>
            <input type="text" id="low-stock-default" value="5">
            <p class="muted">
                Termékenként felülírható (Árucikkek → adott termék módosítása). Ha egy eladás után
                egy termék készlete a küszöb alá vagy arra csökken, az Árucikkek listában sárga
                jelzést kap, és — ha be van állítva — riasztás megy ki.
            </p>
            <label for="low-stock-webhook">Webhook URL (opcionális)</label>
            <input type="text" id="low-stock-webhook" placeholder="https://pl-slack-vagy-sajat-szerver/webhook">
            <label for="low-stock-email">E-mail cím (opcionális)</label>
            <input type="text" id="low-stock-email" placeholder="riasztas@bolt.hu">
            <button id="settings-save-lowstock-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-lowstock-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-appearance-settings" class="tab-panel">
            <label>Sablon</label>
            <div class="theme-picker">
                <label class="theme-option">
                    <input type="radio" name="theme-choice" value="dark" id="theme-dark">
                    <span class="theme-swatch theme-swatch-dark"></span>
                    Sötét
                </label>
                <label class="theme-option">
                    <input type="radio" name="theme-choice" value="light" id="theme-light">
                    <span class="theme-swatch theme-swatch-light"></span>
                    Világos
                </label>
            </div>
            <p class="muted">A választás azonnal érvényesül, és minden oldalon megmarad.</p>
            <p id="settings-appearance-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-receipt-settings" class="tab-panel">
            <p class="muted" style="margin-top:0;">
                A nyugta (böngészős és hálózati nyomtatós is) tetején és alján megjelenő
                szöveg szabadon szerkeszthető — soronként egy sor. Üresen hagyva nincs
                fejléc/lábléc szöveg.
            </p>

            <label for="receipt-header-lines">Fejléc szövege</label>
            <textarea id="receipt-header-lines" rows="4" placeholder="pl. Boltod neve&#10;Cím, telefonszám"></textarea>

            <label class="checkbox-line" style="margin-top:10px;">
                <input type="checkbox" id="receipt-show-logo">
                Logó megjelenítése a nyugta tetején (a Logó fülön beállított kép)
            </label>
            <p class="muted" style="margin-top:-6px;">
                A böngészős nyugtán mindig megjelenik, ha be van kapcsolva. Hálózati ESC/POS
                nyomtatón is kinyomtatásra kerül képként (raszterizálva), ha a nyomtató és a
                PHP GD kiterjesztés ezt támogatja — SVG logóból ez nem lehetséges, csak
                PNG/JPG/WEBP formátumból.
            </p>

            <label for="receipt-footer-lines">Lábléc szövege</label>
            <textarea id="receipt-footer-lines" rows="3" placeholder="pl. Köszönjük a vásárlást!"></textarea>

            <button id="settings-save-receipt-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-receipt-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-loyalty-settings" class="tab-panel">
            <label class="checkbox-line">
                <input type="checkbox" id="loyalty-enabled">
                Törzsvásárlói / hűségpont rendszer bekapcsolása
            </label>
            <p class="muted">
                Bekapcsolva a Kasszán megjelenik egy vásárló-kereső mező — a rögzített
                vásárlók pontokat gyűjtenek minden eladás után, és a felgyűlt pontokat
                beválthatják kedvezményként egy következő vásárláskor.
            </p>
            <div class="field-row">
                <div>
                    <label for="loyalty-huf-per-point">Ft / pont (gyűjtés)</label>
                    <input type="text" id="loyalty-huf-per-point" value="100">
                </div>
                <div>
                    <label for="loyalty-point-value">1 pont értéke beváltáskor (Ft)</label>
                    <input type="text" id="loyalty-point-value" value="5">
                </div>
            </div>
            <p class="muted" style="margin-top:-6px;">
                Alapértelmezett példa: minden 100 Ft költés után 1 pont jár, és 1 pont 5 Ft
                kedvezményt ér — ez az arány tetszőlegesen módosítható.
            </p>
            <p class="muted">
                <a href="vasarlok.php" target="_blank">Vásárlók kezelése</a> — új vásárló
                felvétele, pontegyenlegek és -előzmények megtekintése.
            </p>

            <label style="margin-top:16px;">Hűségszintek (élettartam-elköltés alapján)</label>
            <p class="muted" style="margin-top:-6px;">
                A törzsvásárló elköltésének összege alapján automatikus kedvezményt kap a Kasszán,
                a hűségpont-beváltástól függetlenül. Bronz szinten (a küszöb alatt) nincs extra kedvezmény.
            </p>
            <div class="field-row">
                <div>
                    <label for="tier-silver-threshold">Ezüst szint küszöbe (Ft)</label>
                    <input type="text" id="tier-silver-threshold" value="50000">
                </div>
                <div>
                    <label for="tier-silver-discount">Ezüst szint kedvezménye (%)</label>
                    <input type="text" id="tier-silver-discount" value="5">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label for="tier-gold-threshold">Arany szint küszöbe (Ft)</label>
                    <input type="text" id="tier-gold-threshold" value="150000">
                </div>
                <div>
                    <label for="tier-gold-discount">Arany szint kedvezménye (%)</label>
                    <input type="text" id="tier-gold-discount" value="10">
                </div>
            </div>

            <button id="settings-save-loyalty-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-loyalty-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-audit-settings" class="tab-panel">
            <label for="audit-retention-days">Tevékenységnapló megőrzési ideje (nap)</label>
            <input type="text" id="audit-retention-days" value="30">
            <p class="muted" style="margin-top:-6px;">
                Az ennél régebbi naplóbejegyzések automatikusan törlődnek. A napló jelenleg
                a termék-törléseket rögzíti; a jövőben bővíthető más műveletekkel is.
            </p>
            <p class="muted">
                <a href="audit-log.php" target="_blank">Tevékenységnapló megtekintése</a>
            </p>
            <button id="settings-save-audit-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-audit-feedback" class="modal-feedback"></p>
        </div>

        <div id="tab-import-settings" class="tab-panel">
            <div class="import-card">
                <h2>1. Forrás program és fájl</h2>
                <p class="muted" style="margin-top:-8px;">
                    Válaszd ki, honnan érkezik az árulista, majd töltsd fel az onnan exportált fájlt.
                    Elfogad közvetlenül .xls / .xlsx fájlt is (automatikusan CSV-vé alakítja a szerver),
                    vagy CSV-t, ha már abban a formátumban van.
                </p>

                <label for="profile-select">Forrás program</label>
                <select id="profile-select"></select>

                <label for="file-input" id="file-drop" class="file-drop">
                    <span id="file-drop-label">Kattints ide a fájl kiválasztásához, vagy húzd ide</span>
                </label>
                <input type="file" id="file-input" accept=".csv,.xls,.xlsx" style="display:none;">

                <button id="preview-btn" class="btn btn-primary" disabled>Előnézet</button>
                <p id="preview-feedback" class="feedback"></p>
            </div>

            <div class="import-card hidden" id="preview-card">
                <h2>2. Előnézet</h2>

                <div class="stats-grid" id="stats-grid"></div>

                <div id="warnings"></div>

                <p class="muted">Első néhány sor a feltöltött fájlból, a rendszer által értelmezett adatokkal:</p>
                <div class="sample-table-wrap">
                    <table class="sample-table">
                        <thead>
                            <tr>
                                <th>Megnevezés</th><th>Cikkszám</th><th>Csoport</th><th>Vonalkód</th>
                                <th>Készlet</th><th>Nettó Besz.</th><th>Nettó Elad.</th><th>Bruttó Elad.</th><th>Áfa</th>
                            </tr>
                        </thead>
                        <tbody id="sample-body"></tbody>
                    </table>
                </div>

                <button id="commit-btn" class="btn btn-primary">Importálás indítása</button>
                <p id="commit-feedback" class="feedback"></p>
            </div>

            <div class="import-card">
                <h2>Bővíthetőség</h2>
                <p class="muted" style="margin-top:-8px; margin-bottom:0;">
                    Más program (pl. Jutasoft) importja később hozzáadható: elég egy új profilt
                    felvenni a szerver <code>src/ImportProfiles.php</code> fájljában (oszlop-hozzárendelés
                    a program exportjához) — ez az oldal és a mögötte lévő logika mindent automatikusan kezel.
                </p>
            </div>
        </div>

        <div id="tab-security-settings" class="tab-panel">
            <div class="import-card" style="background:var(--panel-light); margin-bottom:16px;">
                <p style="margin:0;">
                    <strong>A valódi védelem az, hogy minden adat és minden művelet kizárólag
                    az API-n keresztül érhető el.</strong> Az alábbi jelszó egy kényelmi/hozzáférési
                    réteg — de akkor is, ha valaki megkerülné (pl. közvetlenül megnyitná egy oldal
                    HTML-jét), a szerver minden egyes API-hívást elutasít bejelentkezés nélkül, így
                    tényleges adathoz vagy funkcióhoz nem fér hozzá.
                </p>
            </div>

            <div class="toggle-line">
                <span>Jelszavas védelem bekapcsolva</span>
                <button type="button" class="toggle-switch" id="security-password-enabled"></button>
            </div>
            <p class="muted" style="margin-top:-6px;">
                Ha bekapcsolod, minden oldal betöltésekor bejelentkezést kér — a Kasszától a
                Beállításokig mindenhol. Ez különbözik a dolgozói PIN-kódtól: az csak elszámoltat
                (ki dolgozott a Kasszánál), ez itt tényleges belépési jelszó az egész programhoz.
            </p>

            <label for="security-new-password">Új jelszó beállítása / módosítása</label>
            <input type="password" id="security-new-password" placeholder="legalább 8 karakter" autocomplete="new-password">
            <label for="security-new-password-confirm">Jelszó megerősítése</label>
            <input type="password" id="security-new-password-confirm" autocomplete="new-password">
            <p class="muted" style="margin-top:-6px;">
                Ha üresen hagyod mindkettőt, a meglévő jelszó változatlan marad — csak a
                be/kikapcsolt állapot módosul.
            </p>

            <div class="field-row">
                <div>
                    <label for="security-session-timeout">Automatikus kijelentkezés (perc inaktivitás után)</label>
                    <input type="text" id="security-session-timeout" value="240">
                </div>
                <div>
                    <label for="security-max-attempts">Sikertelen próbálkozások limitje zárolás előtt</label>
                    <input type="text" id="security-max-attempts" value="5">
                </div>
            </div>
            <label for="security-lockout-minutes">Zárolás időtartama (perc)</label>
            <input type="text" id="security-lockout-minutes" value="15">

            <p class="muted">
                A nyomtatott nyugtákon lévő <strong>QR-kód</strong> a jelszavas védelem
                bekapcsolása után is bejelentkezés nélkül megtekinthető marad — minden
                eladáshoz egy titkos, kitalálhatatlan token tartozik, ami a QR-kódba be van
                ágyazva, így nem kell az eladás-sorszámra (ami kitalálható lenne) hagyatkozni.
            </p>

            <button id="settings-save-security-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
            <p id="settings-security-feedback" class="modal-feedback"></p>

            <div style="border-top:1px solid var(--border); margin-top:24px; padding-top:16px;">
                <strong>IP-cím / ország alapú védelem</strong>
                <p class="muted" style="margin-top:4px;">
                    Bekapcsolva a rendszer csak az alább felsorolt országokból (és a
                    mindig-engedélyezett IP-címekről) éri el a valódi adatokat és
                    funkciókat — mind IPv4, mind IPv6 címeket kezel. A felismerés egy
                    külső, kulcs nélküli szolgáltatáson (ip-api.com) keresztül történik,
                    az eredményt a szerver helyben, néhány hétig gyorsítótárazza, így
                    ugyanaz a látogató nem terheli minden kéréssel újra a lekérdezést. A
                    helyi hálózatról (pl. ugyanarról a gépről) érkező kéréseket ez a
                    korlátozás sosem érinti.
                </p>

                <div class="toggle-line">
                    <span>Bekapcsolva</span>
                    <button type="button" class="toggle-switch" id="geo-block-enabled"></button>
                </div>

                <p class="muted" id="geo-current-info" style="margin-top:-6px;">Jelenlegi IP-cím lekérdezése...</p>

                <label for="geo-block-countries">Engedélyezett országok (ISO kód, vesszővel elválasztva)</label>
                <input type="text" id="geo-block-countries" placeholder="pl. HU,AT,DE,RO">
                <p class="muted" style="margin-top:-6px;">
                    Ha üresen hagyod, a bekapcsolt korlátozás ellenére mindenkit átenged
                    — előbb töltsd ki a listát.
                </p>

                <label for="geo-block-allow-ips">Mindig engedélyezett IP-címek / tartományok (opcionális)</label>
                <div class="input-icon-row">
                    <input type="text" id="geo-block-allow-ips" placeholder="pl. 89.132.1.5, 2001:db8::/32">
                    <button type="button" id="geo-add-own-ip-btn" class="btn btn-secondary" style="width:auto; padding:0 14px; white-space:nowrap;">Saját IP hozzáadása</button>
                </div>
                <p class="muted" style="margin-top:10px;">
                    Ide vessző-elválasztva vehetsz fel egyedi IP-címeket vagy CIDR-tartományokat
                    (pl. az üzlet fix IP-je), amik az ország-listától függetlenül mindig átjutnak.
                </p>

                <p class="muted">
                    A bekapcsolás előtt a mentés automatikusan leellenőrzi, hogy a jelenlegi
                    IP-címed/országod szerepel-e a listán — ha nem, a mentést elutasítja,
                    hogy véletlenül se zárd ki magad a rendszerből. A statikus bejelentkező
                    oldal (login.html) kerete emiatt továbbra is betölt tiltott helyről is,
                    de mögötte minden valódi funkció és adat az API-n keresztül ugyanúgy le
                    van tiltva, mint bárhol máshol a programban.
                </p>

                <button id="settings-save-geo-btn" class="btn btn-primary" style="width:auto; padding:10px 18px;">Mentés</button>
                <p id="settings-geo-feedback" class="modal-feedback"></p>
            </div>

            <div style="border-top:1px solid var(--border); margin-top:24px; padding-top:16px;">
                <label>Kijelentkezés minden eszközről</label>
                <p class="muted" style="margin-top:-6px;">
                    Új jelszó beállítása minden korábbi munkamenetet is érvénytelenít — de ha csak
                    a jelenlegi böngészőből szeretnél kijelentkezni, itt is megteheted.
                </p>
                <button id="security-logout-btn" class="btn btn-secondary" style="width:auto; padding:10px 18px;">Kijelentkezés</button>
            </div>
        </div>

    </div>
</div>

<script src="topbar.js"></script>
<script src="import.js"></script>
<script src="beallitasok.js"></script>
</body>
</html>
