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
<title>Stock Manager — Útmutató</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/sidebarmenu.php'; ?>


<header class="topbar">
    <div class="topbar-brand">
        <span class="topbar-icon-square" id="topbar-icon-square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span>
        <h1>Útmutató</h1>
    </div>
    <div class="topbar-actions">
        <?php require __DIR__ . '/headermenu.php'; ?>
        </div>
</header>

<div class="sync-toast" id="sync-toast"></div>

<div class="import-panel" style="max-width:820px;">
    <div class="import-card">
        <h2 style="margin-top:0;">Első lépések</h2>
        <p>
            Ez az útmutató a program fő munkafolyamatait mutatja be röviden. A telepítés
            első indításkor (<code>install.php</code>) fut le — ha kihagytad, bármikor
            elérheted a Beállításokon keresztül a hiányzó lépéseket (bolt neve, MySQL stb.).
        </p>

        <h2>Kassza (mindennapi eladás)</h2>
        <ul>
            <li>Vonalkód-olvasóval (USB-s vagy a kamera ikonnal) vagy kereséssel add hozzá a termékeket a kosárhoz.</li>
            <li>Ha a raktárban nem szereplő tételt (pl. szolgáltatást) kell felvenni, használd a "+ Kézi tétel hozzáadása" gombot.</li>
            <li>"Vevő számlát kér" bejelölésével a Számlázz.hu-n keresztül automatikusan kiállításra kerül a számla — bejelölés nélkül csak a helyi nyugta készül el.</li>
            <li>Kupon, ajándékutalvány és törzsvásárlói pontok/hűségszint kedvezménye ugyanazon az eladáson belül is kombinálható.</li>
            <li>Eladás után a nyugta nyomtatható, vagy e-mailben elküldhető (utóbbihoz a szervernek kell tudnia leveleket küldeni).</li>
        </ul>

        <h2>Beszerzés</h2>
        <ul>
            <li>Új tétel beérkezésekor itt rögzítheted a beszerzési árat — ez automatikusan növeli a készletet.</li>
            <li>Mentett beszállító kiválasztásával a számlázási adatok automatikusan kitöltődnek.</li>
            <li>Ha egy terméknek nincs még vonalkódja, a termék-szerkesztőben egy kattintással generálható, és rögtön nyomtatható is hozzá címke.</li>
        </ul>

        <h2>Árucikkek</h2>
        <p>
            A teljes terméklista, szűréssel és kereséssel. Egy termékre kattintva megnyílik
            a szerkesztő, ahol az ártörténet is látható, ha korábban változott az ár.
        </p>

        <h2>Napi zárás</h2>
        <p>
            Egy adott nap összes eladásának összesítése fizetési mód és ÁFA-kulcs szerint,
            nyomtatható és CSV-be exportálható formában.
        </p>

        <h2>Hasznos kiegészítő oldalak</h2>
        <ul>
            <li><strong>Beszállítók</strong>, <strong>Vásárlók</strong>, <strong>Kedvezmények</strong> (kupon, ajándékutalvány) — ezek a Kasszán/Beszerzésen belülről, kontextusból nyílnak meg linkként.</li>
            <li><strong>Dolgozók</strong> — PIN-kódos azonosítás a Kasszán, elszámoltathatósághoz. Nem valódi beléptető rendszer, csak nyomon követés.</li>
            <li><strong>Leltározás</strong> — fizikai készletszámlálás rögzítése, eltérés-riporttal.</li>
            <li><strong>Rendszerállapot</strong> — szinkron- és mentés-állapot, bevétel-trend, egy helyen.</li>
            <li><strong>Tevékenységnapló</strong> — ki mit módosított (jelenleg: termék-törlések).</li>
        </ul>

        <h2>Globális kereső</h2>
        <p>
            <strong>Ctrl+K</strong> bármelyik oldalon megnyitja a keresőt, ami egyszerre keres
            termékben, vásárlóban és eladásban.
        </p>

        <h2>Mentés és visszaállítás</h2>
        <p>
            Beállítások → Mentés fülön kézzel is indítható mentés, illetve automatikus
            időzített mentés is beállítható (helyi és/vagy felhő). Visszaállításkor mindig
            készül egy biztonsági mentés a visszaállítás előtti állapotról is.
        </p>

        <h2>Beállítások</h2>
        <p>
            Itt kapcsolható be/ki és állítható testre minden opcionális funkció: WooCommerce
            szinkron, Számlázz.hu, nyomtató, törzsvásárlói pontok és hűségszintek, tevékenységnapló
            megőrzési ideje, megjelenés (sötét/világos), és a nyugta fejléce/lábléce.
        </p>
    </div>
</div>

<script src="topbar.js"></script>
</body>
</html>
