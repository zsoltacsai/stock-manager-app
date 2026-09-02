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
<title>Stock Manager — Kassza</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></span>
        <h1>Kassza</h1>
    </div>
    <div class="topbar-actions">
        <select id="location-selector" class="hidden" style="width:auto; margin-bottom:0; padding:8px 30px 8px 12px; font-size:16px;" title="Telephely"></select></select>
        <button id="staff-badge-btn" class="icon-btn" style="width:auto; padding:0 12px; gap:6px; display:flex; align-items:center;" title="Dolgozó váltása">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <span id="staff-badge-name">Nincs bejelentkezve</span>
        </button>
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<main class="layout">

    <section class="scan-panel">
        <label for="barcode-input">Vonalkód</label>
        <div class="input-icon-row">
            <input id="barcode-input" type="text" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" placeholder="Húzd le a vonalkódot..." autofocus>
            <button type="button" class="camera-scan-btn" data-target="barcode-input" title="Kamerás vonalkód-olvasás">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
            </button>
        </div>
        <p id="scan-feedback" class="feedback"></p>

        <div id="frequent-products-box" class="hidden">
            <label style="margin-top:14px;">Gyakran vásárolt</label>
            <div id="frequent-products-row" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;"></div>
        </div>

        <div class="manual-lookup">
            <input id="search-input" type="text" placeholder="Keresés név / cikkszám alapján...">
            <div id="search-results" class="search-results"></div>
        </div>

        <button type="button" id="manual-item-toggle-btn" class="btn btn-secondary" style="width:100%; margin-top:10px;">
            + Kézi tétel hozzáadása
        </button>
        <div id="manual-item-form" class="hidden" style="margin-top:10px; background:var(--panel-light); border-radius:8px; padding:12px;">
            <p class="muted" style="margin-top:0;">
                Olyan tételhez, ami nincs a raktárkészletben (pl. szolgáltatás, egyedi termék) —
                bekerül a nyugtára/számlára, de nem érinti a készletet.
            </p>
            <label for="manual-item-name">Megnevezés</label>
            <input type="text" id="manual-item-name" placeholder="pl. Kiszállítási díj">
            <div class="field-row">
                <div>
                    <label for="manual-item-qty">Mennyiség</label>
                    <input type="text" id="manual-item-qty" value="1">
                </div>
                <div>
                    <label for="manual-item-price">Bruttó egységár (Ft)</label>
                    <input type="text" id="manual-item-price" placeholder="0">
                </div>
            </div>
            <label for="manual-item-vat">Áfakulcs</label>
            <select id="manual-item-vat">
                <option value="27" selected>27%</option>
                <option value="18">18%</option>
                <option value="5">5%</option>
                <option value="0">0%</option>
                <option value="AAM">AAM</option>
                <option value="TAM">TAM</option>
            </select>
            <p id="manual-item-feedback" class="feedback"></p>
            <button type="button" id="manual-item-add-btn" class="btn btn-primary" style="width:100%;">Hozzáadás a kosárhoz</button>
        </div>
    </section>

    <section class="cart-panel">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Termék</th>
                    <th>Menny.</th>
                    <th>Egységár</th>
                    <th>Össz.</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cart-body"></tbody>
        </table>

        <div class="cart-total">
            <span>Fizetendő</span>
            <span id="cart-total-value">0 Ft</span>
        </div>

        <div class="field-row">
            <div>
                <label for="coupon-code">Kedvezménykód (opcionális)</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input type="text" id="coupon-code" placeholder="pl. NYAR20" style="flex:1 1 100px; text-transform:uppercase;">
                    <button type="button" id="coupon-apply-btn" class="btn btn-secondary inline-field-btn">OK</button>
                </div>
            </div>
            <div>
                <label for="gift-card-code">Ajándékutalvány (opcionális)</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input type="text" id="gift-card-code" placeholder="kód" style="flex:1 1 100px; text-transform:uppercase;">
                    <button type="button" id="gift-card-apply-btn" class="btn btn-secondary inline-field-btn">OK</button>
                </div>
            </div>
        </div>
        <div id="applied-discounts"></div>
        <p class="muted" style="margin-top:-6px;"><a href="kedvezmenyek.php" target="_blank">Kuponok / utalványok kezelése</a></p>

        <div id="loyalty-section" class="hidden">
            <label for="customer-search">Törzsvásárló (opcionális) — <a href="vasarlok.php" target="_blank">kezelés</a></label>
            <div class="input-icon-row">
                <input type="text" id="customer-search" placeholder="Keresés név vagy telefonszám alapján...">
            </div>
            <div id="customer-search-results" class="search-results"></div>
            <div id="selected-customer-box" class="hidden" style="background:var(--panel-light); border-radius:8px; padding:10px 12px; margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong id="selected-customer-name"></strong>
                    <button type="button" id="clear-customer-btn" class="remove-btn" title="Törlés">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <p class="muted" style="margin:4px 0 0;">Jelenlegi pontegyenleg: <span id="selected-customer-points">0</span> pont</p>
                <label for="redeem-points" style="margin-top:8px;">Beváltandó pontok (opcionális)</label>
                <input type="text" id="redeem-points" placeholder="0" style="margin-bottom:0;">
                <p class="muted" id="redeem-value-hint" style="margin-top:4px;"></p>
            </div>
        </div>

        <label for="payment-method-btn">Fizetési mód</label>
        <div class="pm-select" id="pm-select">
            <button type="button" class="pm-select-trigger" id="payment-method-btn" aria-haspopup="listbox" aria-expanded="false">
                <span class="pm-select-icon"></span>
                <span class="pm-select-label" id="payment-method-label">Készpénz</span>
                <svg class="pm-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="pm-select-list hidden" id="payment-method-list" role="listbox"></div>
        </div>
        <input type="hidden" id="payment-method" value="Készpénz">

        <label class="checkbox-line">
            <input type="checkbox" id="invoice-requested">
            Vevő számlát kér (adatok megadása)
        </label>

        <div id="buyer-fields" class="buyer-fields hidden">
            <label class="checkbox-line">
                <input type="checkbox" id="buyer-is-private" checked>
                Magánszemély
            </label>

            <label for="buyer-name">Név / Cégnév</label>
            <div class="input-icon-row">
                <input type="text" id="buyer-name" placeholder="Vevő neve vagy cégnév" autocomplete="off">
                <button type="button" id="buyer-picker-btn" class="camera-scan-btn" title="Mentett vásárló kiválasztása / szerkesztése">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </button>
            </div>
            <div id="buyer-name-results" class="search-results"></div>

            <div id="buyer-taxlookup-wrap" style="display:none;">
                <label for="buyer-taxlookup">Cégadatok automatikus kitöltése adószám alapján</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input type="text" id="buyer-taxlookup" placeholder="12345678-1-42" style="flex:1 1 160px;">
                    <button type="button" id="buyer-taxlookup-btn" class="btn btn-secondary inline-field-btn">Lekérdezés</button>
                </div>
                <p id="buyer-taxlookup-feedback" class="feedback"></p>
            </div>

            <div class="field-row">
                <div>
                    <label for="buyer-country">Ország</label>
                    <select id="buyer-country">
                        <option value="Magyarország" selected>Magyarország</option>
                        <option value="">Egyéb / külföld</option>
                    </select>
                </div>
                <div>
                    <label for="buyer-zip">Ir.szám</label>
                    <input type="text" id="buyer-zip" placeholder="1234">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label for="buyer-city">Település</label>
                    <input type="text" id="buyer-city" placeholder="Szeged">
                </div>
                <div>
                    <label for="buyer-address">Utca, házszám</label>
                    <input type="text" id="buyer-address" placeholder="Fő utca 1.">
                </div>
            </div>

            <div id="buyer-taxnum-wrap">
                <label for="buyer-taxnum">Adószám</label>
                <input type="text" id="buyer-taxnum" placeholder="12345678-1-42">
            </div>

            <label for="buyer-note">Egyéb</label>
            <input type="text" id="buyer-note" placeholder="Megjegyzés (opcionális)">

            <label for="buyer-language">Nyelv</label>
            <select id="buyer-language">
                <option value="hu" selected>magyar</option>
                <option value="en">angol</option>
                <option value="de">német</option>
            </select>
        </div>

        <button id="checkout-btn" class="btn btn-primary" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-4px;margin-right:6px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            Eladás rögzítése + Számla
        </button>
        <p id="checkout-feedback" class="feedback"></p>
        <div id="receipt-link-wrap" class="hidden">
            <button id="view-receipt-btn" class="btn btn-secondary" style="width:100%; margin-top:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Nyugta megtekintése / nyomtatása
            </button>
            <div style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;">
                <input type="text" id="receipt-email-input" placeholder="email@cim.hu" style="flex:1 1 160px; margin-bottom:0;">
                <button id="send-receipt-email-btn" class="btn btn-secondary inline-field-btn">E-mail küldése</button>
            </div>
            <p id="receipt-email-feedback" class="feedback"></p>
            <button id="undo-sale-btn" class="btn btn-secondary hidden" style="width:100%; margin-top:8px; border-color:var(--danger); color:var(--danger);">
                Legutóbbi eladás visszavonása (<span id="undo-countdown">60</span> mp)
            </button>
            <p id="undo-sale-feedback" class="feedback"></p>
        </div>
    </section>

</main>

<div class="modal-overlay" id="staff-login-modal">
    <div class="modal-card" style="max-width:360px;">
        <h2>Dolgozó bejelentkezés</h2>
        <p class="muted" style="margin-top:-8px;">Ki dolgozik most a Kasszánál? Ez minden eladásnál rögzítésre kerül. — <a href="staff.php" target="_blank">Dolgozók kezelése</a></p>
        <label for="staff-select">Dolgozó</label>
        <select id="staff-select"></select>
        <label for="staff-pin-input">PIN-kód</label>
        <input type="password" id="staff-pin-input" inputmode="numeric" placeholder="••••">
        <p id="staff-login-feedback" class="modal-feedback"></p>
        <div class="modal-actions" style="margin-top:16px;">
            <button class="btn btn-secondary" id="staff-logout-btn" style="flex:1;">Kijelentkezés</button>
            <button class="btn btn-primary" id="staff-login-btn" style="flex:1;">Bejelentkezés</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="customer-picker-modal">
    <div class="modal-card" style="max-width:640px;">
        <div id="customer-picker-list-view">
            <h2>Vásárlói törzs</h2>
            <input type="text" id="customer-picker-search" placeholder="Keresés név, telefon, email alapján...">
            <div class="sample-table-wrap" style="max-height:320px; overflow-y:auto;">
                <table class="sample-table">
                    <thead><tr><th>Név</th><th>Telefon</th><th>Település</th><th></th></tr></thead>
                    <tbody id="customer-picker-body"></tbody>
                </table>
            </div>
            <div class="modal-actions" style="margin-top:16px;">
                <button class="btn btn-secondary" id="customer-picker-close" style="flex:1;">Bezárás</button>
                <button class="btn btn-primary" id="customer-picker-new-btn" style="flex:1;">+ Új vásárló</button>
            </div>
        </div>

        <div id="customer-picker-edit-view" class="hidden">
            <h2 id="customer-picker-edit-title">Új vásárló</h2>
            <input type="hidden" id="cp-id">
            <label for="cp-name">Név / Cégnév *</label>
            <input type="text" id="cp-name">
            <div class="field-row">
                <div>
                    <label for="cp-phone">Telefon</label>
                    <input type="text" id="cp-phone">
                </div>
                <div>
                    <label for="cp-email">Email</label>
                    <input type="text" id="cp-email">
                </div>
            </div>
            <label for="cp-taxnum">Adószám (céges vásárlóknál)</label>
            <input type="text" id="cp-taxnum">
            <div class="field-row">
                <div>
                    <label for="cp-zip">Ir.szám</label>
                    <input type="text" id="cp-zip">
                </div>
                <div>
                    <label for="cp-city">Település</label>
                    <input type="text" id="cp-city">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label for="cp-address">Cím (utca, házszám)</label>
                    <input type="text" id="cp-address">
                </div>
                <div>
                    <label for="cp-country">Ország</label>
                    <input type="text" id="cp-country" value="Magyarország">
                </div>
            </div>
            <p id="customer-picker-edit-feedback" class="modal-feedback"></p>
            <div class="modal-actions" style="margin-top:16px;">
                <button class="btn btn-secondary" id="customer-picker-edit-back" style="flex:1;">Vissza a listához</button>
                <button class="btn btn-primary" id="customer-picker-edit-save" style="flex:1;">Mentés</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="camera-scanner-modal">
    <div class="modal-card" style="max-width:480px;">
        <h2>Kamerás vonalkód-olvasás</h2>
        <div class="camera-scanner-video-wrap">
            <video id="camera-scanner-video" autoplay playsinline muted></video>
            <div class="camera-scanner-frame"></div>
        </div>
        <p id="camera-scanner-status" class="muted"></p>
        <button class="btn btn-secondary" id="camera-scanner-close" style="width:100%;">Bezárás</button>
    </div>
</div>

<script src="topbar.js"></script>
<script src="barcode-scanner.js"></script>
<script src="app.js"></script>
</body>
</html>
