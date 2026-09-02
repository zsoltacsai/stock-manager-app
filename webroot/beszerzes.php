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
<title>Stock Manager — Beszerzés</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
        <h1>Beszerzés</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<main class="layout">

    <section class="scan-panel">
        <label for="barcode-input">Vonalkód (beérkező tétel)</label>
        <div class="input-icon-row">
            <input id="barcode-input" type="text" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" placeholder="Húzd le a vonalkódot..." autofocus>
            <button type="button" class="camera-scan-btn" data-target="barcode-input" title="Kamerás vonalkód-olvasás">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
            </button>
        </div>
        <p id="scan-feedback" class="feedback"></p>

        <button id="new-product-btn" class="btn btn-secondary" style="width:100%; margin: 10px 0 20px;">
            + Új termék hozzáadása
        </button>

        <div class="manual-lookup">
            <label for="search-input">Vagy keress meglévő termék között</label>
            <input id="search-input" type="text" placeholder="Keresés...">
            <div id="search-results" class="search-results"></div>
        </div>

        <hr style="border-color: var(--border); margin: 20px 0;">

        <label class="checkbox-line">
            <input type="checkbox" id="supplier-toggle">
            Beszállító adatainak megadása
        </label>

        <div id="supplier-fields" class="buyer-fields hidden">
            <label for="supplier-picker">Mentett beszállító kiválasztása (opcionális)</label>
            <select id="supplier-picker">
                <option value="">— Új / eseti beszállító —</option>
            </select>
            <p class="muted" style="margin-top:-8px;">
                <a href="beszallitok.php" target="_blank">Beszállítók kezelése</a> — itt vehetsz fel újat.
            </p>

            <label for="supplier-name">Név</label>
            <input type="text" id="supplier-name" placeholder="Beszállító neve">

            <div class="field-row">
                <div>
                    <label for="supplier-country">Ország</label>
                    <select id="supplier-country">
                        <option value="Magyarország" selected>Magyarország</option>
                        <option value="">Egyéb / külföld</option>
                    </select>
                </div>
                <div>
                    <label for="supplier-zip">Ir.szám</label>
                    <input type="text" id="supplier-zip" placeholder="1234">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label for="supplier-city">Település</label>
                    <input type="text" id="supplier-city" placeholder="Budapest">
                </div>
                <div>
                    <label for="supplier-address">Utca, házszám</label>
                    <input type="text" id="supplier-address" placeholder="Fő utca 1.">
                </div>
            </div>

            <label for="supplier-taxnum">Adószám</label>
            <input type="text" id="supplier-taxnum" placeholder="12345678-1-42">

            <label for="purchase-payment-method">Fizetési mód</label>
            <select id="purchase-payment-method">
                <option value="készpénz" selected>készpénz</option>
                <option value="átutalás">átutalás</option>
                <option value="bankkártya">bankkártya</option>
            </select>

            <label class="checkbox-line" style="margin-top:10px;">
                <input type="checkbox" id="purchase-paid" checked>
                Fizetve
            </label>

            <label for="purchase-note">Megjegyzés</label>
            <input type="text" id="purchase-note" placeholder="Opcionális">
        </div>
    </section>

    <section class="cart-panel">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>Termék</th>
                    <th>Menny.</th>
                    <th>Áfa</th>
                    <th>Nettó egységár</th>
                    <th>Bruttó egységár</th>
                    <th>Bruttó érték</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cart-body">
                <tr class="empty-row"><td colspan="7">Nincs még felvitt tétel — szkennelj be egy terméket</td></tr>
            </tbody>
        </table>

        <div class="cart-total">
            <span>Végösszeg (bruttó)</span>
            <span id="cart-total-value">0 Ft</span>
        </div>

        <button id="save-btn" class="btn btn-primary" disabled>Beszerzés rögzítése</button>
        <p id="save-feedback" class="feedback"></p>
    </section>

</main>

<!-- New / edit product modal ("Árucikk módosítása") -->
<div class="modal-overlay" id="product-modal">
    <div class="modal-card">
        <h2>Árucikk módosítása</h2>
        <p class="muted">Itt adhatja meg a kiválasztott áruikk adatait.</p>

        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-main">Fő adatok</button>
            <button class="tab-btn" data-tab="tab-prices">Árak</button>
            <button class="tab-btn" data-tab="tab-other">Egyéb</button>
            <button class="tab-btn" data-tab="tab-media">Leírás és kép</button>
        </div>

        <div id="tab-main" class="tab-panel active">
            <label for="p-name">Megnevezés</label>
            <input type="text" id="p-name" placeholder="Termék megnevezése">

            <div class="field-row">
                <div>
                    <label for="p-unit">Mértékegység</label>
                    <input type="text" id="p-unit" value="db">
                </div>
                <div>
                    <label for="p-group">Csoport</label>
                    <input type="text" id="p-group" placeholder="pl. Festékek">
                </div>
            </div>

            <div class="field-row">
                <div>
                    <label for="p-cikkszam">Cikkszám</label>
                    <input type="text" id="p-cikkszam">
                </div>
                <div>
                    <label for="p-vtsz">Vtsz/Szj</label>
                    <input type="text" id="p-vtsz">
                </div>
            </div>
        </div>

        <div id="tab-prices" class="tab-panel">
            <label for="p-currency">Ár tárolási pénznem</label>
            <select id="p-currency">
                <option value="HUF" selected>HUF - magyar forint</option>
                <option value="EUR">EUR - euró</option>
            </select>

            <label for="p-vat">Áfakulcs</label>
            <select id="p-vat">
                <option value="27" selected>27%</option>
                <option value="18">18%</option>
                <option value="5">5%</option>
                <option value="0">0%</option>
                <option value="AAM">AAM (alanyi adómentes)</option>
                <option value="TAM">TAM (tárgyi adómentes)</option>
            </select>

            <div class="field-row">
                <div>
                    <label for="p-net">Nettó (eladási)</label>
                    <input type="text" id="p-net" placeholder="0">
                </div>
                <div>
                    <label for="p-gross">Bruttó (eladási)</label>
                    <input type="text" id="p-gross" placeholder="0">
                </div>
            </div>
        </div>

        <div id="tab-other" class="tab-panel">
            <div class="toggle-line">
                <span>Árucikk feltüntetése az árlistán</span>
                <button type="button" class="toggle-switch on" id="p-show-pricelist"></button>
            </div>
            <div class="toggle-line">
                <span>Árucikk feltüntetése webáruházban</span>
                <button type="button" class="toggle-switch on" id="p-show-webshop"></button>
            </div>

            <label for="p-barcode">Vonalkód</label>
            <div class="input-icon-row">
                <input type="text" id="p-barcode" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
                <button type="button" class="camera-scan-btn" data-target="p-barcode" title="Kamerás vonalkód-olvasás">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                </button>
            </div>
            <div style="display:flex; gap:8px; margin-top:10px; margin-bottom:10px; flex-wrap:wrap;">
                <button type="button" id="p-generate-barcode-btn" class="btn btn-secondary" style="flex:1 1 100px; padding:8px;">Generálás</button>
                <button type="button" id="p-copy-barcode-btn" class="btn btn-secondary" style="flex:1 1 100px; padding:8px;" title="Vonalkód másolása">Másolás</button>
                <button type="button" id="p-print-label-btn" class="btn btn-secondary" style="flex:1 1 100px; padding:8px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px;margin-right:5px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Címke nyomtatása</button>
            </div>

            <div class="field-row" style="margin-top:0;">
                <div>
                    <label for="p-weight">Tömeg (kg/db)</label>
                    <input type="text" id="p-weight">
                </div>
                <div>
                    <label for="p-volume">Térfogat (m³/db)</label>
                    <input type="text" id="p-volume">
                </div>
            </div>

            <label for="p-notes">Megjegyzés</label>
            <input type="text" id="p-notes">

            <label for="p-low-stock">Riasztási küszöb (db) — üresen a globális alapérték érvényes</label>
            <input type="text" id="p-low-stock" placeholder="pl. 5">

            <label for="p-preferred-supplier">Preferált beszállító — a beszerzési javaslathoz</label>
            <select id="p-preferred-supplier">
                <option value="">— Nincs megadva —</option>
            </select>

            <div id="p-price-history-box" class="hidden">
                <label>Ártörténet</label>
                <div id="p-price-history-list" class="muted" style="font-size:12px; max-height:120px; overflow-y:auto; margin-bottom:10px;"></div>
            </div>

            <div class="toggle-line" style="margin-top:14px; border-bottom:none;">
                <span>Árucikk törölve</span>
                <button type="button" class="toggle-switch" id="p-deleted"></button>
            </div>
        </div>

        <div id="tab-media" class="tab-panel">
            <label for="p-short-desc">Rövid leírás</label>
            <textarea id="p-short-desc" rows="2" placeholder="Rövid, egy-két mondatos összefoglaló"></textarea>

            <label for="p-long-desc">Hosszú leírás</label>
            <textarea id="p-long-desc" rows="6" placeholder="Részletes termékleírás"></textarea>

            <label for="p-brand">Márka</label>
            <input type="text" id="p-brand" placeholder="pl. Bosch">

            <label>Termékkép</label>
            <p class="muted" style="margin-top:-6px;">
                A feltöltött kép <span id="p-image-target-size">1200×1200</span> px méretre kerül
                (Beállítások → WooCommerce alatt módosítható), WEBP formátumban — de JPG, JPEG
                és GIF is elfogadott. Ha a feltöltött kép nem négyzet alakú, automatikusan
                középre vágva 1:1-es arányúra alakul.
            </p>
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:10px;">
                <img id="p-image-preview" src="" alt="" class="hidden" style="width:96px; height:96px; object-fit:cover; border-radius:8px; border:1px solid var(--border);">
                <div style="flex:1;">
                    <input type="file" id="p-image-input" accept="image/webp,image/jpeg,image/gif" class="hidden">
                    <button type="button" id="p-image-upload-btn" class="btn btn-secondary" style="width:auto; padding:8px 14px;">Kép feltöltése</button>
                    <button type="button" id="p-image-remove-btn" class="btn btn-secondary hidden" style="width:auto; padding:8px 14px;">Kép eltávolítása</button>
                </div>
            </div>
            <label for="p-image-alt">Kép alt szövege (SEO)</label>
            <input type="text" id="p-image-alt" placeholder="Rövid, leíró szöveg a képhez">
            <p id="p-image-feedback" class="modal-feedback"></p>

            <div class="toggle-line" style="margin-top:14px;">
                <span>Szinkronizáljon a WooCommerce-szel</span>
                <button type="button" class="toggle-switch on" id="p-sync-wc"></button>
            </div>
            <p class="muted" style="margin-top:-6px;">
                Kikapcsolva a termék csak üzletben, helyben marad — a WooCommerce
                sem behúzáskor nem írja felül, sem szinkron-kiküldéskor nem
                frissül/nem csökken a webshopban a készlete.
            </p>
        </div>

        <p id="product-modal-feedback" class="modal-feedback"></p>

        <div class="modal-actions">
            <button class="btn btn-secondary" id="product-modal-cancel" style="flex:1;">Mégse</button>
            <button class="btn btn-primary" id="product-modal-save">Rendben</button>
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
<script src="vendor/tinymce/tinymce.min.js"></script>
<script src="product-modal.js"></script>
<script src="beszerzes.js"></script>
</body>
</html>
