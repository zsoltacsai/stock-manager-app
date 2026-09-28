<?php
declare(strict_types=1);

$__smSidebarCurrent = basename($_SERVER['SCRIPT_NAME']);

$__smSidebarLinks = [
    [
        'href' => 'index.php',
        'title' => 'Kassza',
        'icon' => '<circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>',
    ],
    [
        'href' => 'beszerzes.php',
        'title' => 'Új beszerzés',
        'icon' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line>',
    ],
    [
        'href' => 'beerkezo-eladasok.php',
        'title' => 'Beérkező eladások',
        'icon' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>',
        // A `badge_id` MARAD a desktop ikon-sáv span-jének egyedi id-je
        // (megőrizve a meglévő viselkedést), a `badge_group` egy közös,
        // nem-egyedi jelölés, amit MOST a mobil fiók (lásd lent) ugyanerre
        // a bejegyzésre rajzolt második span-je is felvesz — így a
        // topbar.js badge-frissítő kódja (refreshWebshopOrderBadge())
        // mindkettőt egyszerre tudja mutatni/rejteni, egy `id` kettőzése
        // nélkül (l. Phase 8 responsive remediation, RESP-01).
        'badge_id' => 'sidebar-webshop-badge',
        'badge_group' => 'sidebar-webshop-badge',
    ],
    [
        // UX-09 (Phase 7 audit) — korábban ugyanaz a "dokumentum" alap-alak
        // (mint a "Beérkezett számlák" ikonjáé) + két vízszintes vonal volt
        // itt, vizuálisan nagyon hasonlítva a "Beérkezett számlák" ikonjára,
        // annak ellenére, hogy teljesen más munkafolyamathoz (kiállítás vs.
        // csak-olvasható NAV-beérkezés) tartoznak. A "küldés" (repülő papír)
        // ikon vizuálisan is kifejezi a "kimenő" irányt, és egyik másik
        // navigációs ikon alakjával sem egyezik.
        'href' => 'kimeno-szamlak.php',
        'title' => 'Kimenő számlák',
        'icon' => '<line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>',
    ],
    [
        'href' => 'beerkezett-szamlak.php',
        'title' => 'Beérkezett számlák',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 12 18 15 15"></polyline><line x1="12" y1="11" x2="12" y2="18"></line>',
    ],
    [
        'href' => 'termekek.php',
        'title' => 'Árucikkek',
        'icon' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
    ],
    [
        // UX-03 (Phase 7 audit) — a Leltározás (leltar.php) funkció eddig
        // teljesen elkészült és működött, de sehonnan nem volt elérhető a
        // felületről (sem itt, sem a felső menüben, sem a Dashboardon) —
        // csak a közvetlen URL ismeretében. Az Árucikkek mellé kerül, mert
        // fogalmilag oda tartozik (a teljes katalógus fizikai
        // átszámolása/egyeztetése), ugyanazokkal a jogosultságokkal, mint
        // eddig — ez a bejegyzés csak egy belépési pontot ad hozzá, a
        // leltar.php saját jogosultság-/hozzáférés-kezelését nem érinti.
        'href' => 'leltar.php',
        'title' => 'Leltározás',
        'icon' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path><rect x="9" y="3" width="6" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path>',
    ],
    [
        'href' => 'zaras.php',
        'title' => 'Napi zárás',
        'icon' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
    ],
    [
        'href' => 'kassza-riport.php',
        'title' => 'Kassza-riport',
        'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle>',
    ],
    [
        'href' => 'vasarlok.php',
        'title' => 'Ügyféllista',
        'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    ],
    [
        'href' => 'telephelyek.php',
        'title' => 'Telephelyek',
        'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
    ],
    [
        'href' => 'rendszerallapot.php',
        'title' => 'Rendszerállapot',
        'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>',
    ],
    [
        'href' => 'kliensek.php',
        'title' => 'Kliensek',
        'icon' => '<rect x="2" y="4" width="8" height="16" rx="1"></rect><rect x="14" y="4" width="8" height="16" rx="1"></rect><line x1="6" y1="8" x2="6" y2="8.01"></line><line x1="18" y1="8" x2="18" y2="8.01"></line>',
    ],
    [
        'href' => 'ai-asszisztens.php',
        'title' => 'AI Asszisztens',
        'icon' => '<rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line>',
    ],
];
?>
<nav class="icon-sidebar">
    <a href="dashboard.php" title="Dashboard"><img src="assets/logo-default.svg" class="sidebar-logo" alt="Logó" id="sidebar-logo"></a>
<?php foreach ($__smSidebarLinks as $__smLink): ?>
    <a href="<?= $__smLink['href'] ?>" class="sidebar-link<?= $__smLink['href'] === $__smSidebarCurrent ? ' active' : '' ?>" title="<?= $__smLink['title'] ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $__smLink['icon'] ?></svg>
<?php if (!empty($__smLink['badge_id'])): ?>
        <span class="sidebar-badge-dot hidden" id="<?= $__smLink['badge_id'] ?>" data-badge-group="<?= $__smLink['badge_group'] ?? $__smLink['badge_id'] ?>"></span>
<?php endif; ?>
    </a>
<?php endforeach; ?>
    <div class="sidebar-spacer"></div>
    <a href="beallitasok.php" class="sidebar-link<?= $__smSidebarCurrent === 'beallitasok.php' ? ' active' : '' ?>" title="Beállítások">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
    </a>
</nav>

<?php
// ---------------------------------------------------------------------
// Phase 8 responsive remediation — RESP-01 (HIGH, ui-ux-audit... helyett
// responsive-mobile-audit-phase8-2026-09-28.md).
//
// A fenti `.icon-sidebar` ≤768px szélességnél `display:none`-ra vált
// (style.css) — ez a szándékos, hely-felszabadító viselkedés VÁLTOZATLAN
// marad. A hiányzó helyettesítő navigáció volt a talált hiba, nem maga az
// elrejtés. Az alábbi hamburger-gomb + off-canvas fiók UGYANAZT a 16
// célpontot adja vissza (Dashboard + a fenti $__smSidebarLinks +
// Beállítások — szó szerint ugyanaz a PHP tömb, nincs duplikált,
// karbantartandó linklista), UGYANAZOKKAL a jogosultságokkal, mint eddig
// (a sidebarmenu.php sosem végzett szerepkör-szűrést a linkeken — ez itt
// sem változik). ≥769px szélességnél a `.mobile-nav-toggle` gomb
// `display:none` marad (l. style.css), tehát desktopon ez az egész blokk
// néma/inaktív.
// ---------------------------------------------------------------------
?>
<div id="mobile-nav-backdrop" class="mobile-nav-backdrop"></div>
<nav id="mobile-nav-drawer" class="mobile-nav-drawer" aria-label="Navigáció">
    <div class="mobile-nav-drawer-header">
        <a href="dashboard.php" class="mobile-nav-brand<?= $__smSidebarCurrent === 'dashboard.php' ? ' active' : '' ?>">
            <img src="assets/logo-default.svg" alt="" style="width:26px;height:26px;border-radius:7px;">
            <span>FountainTrade</span>
        </a>
        <button type="button" id="mobile-nav-close" class="mobile-nav-close" aria-label="Navigáció bezárása">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>
    <a href="dashboard.php" class="mobile-nav-link<?= $__smSidebarCurrent === 'dashboard.php' ? ' active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>
        <span>Dashboard</span>
    </a>
<?php foreach ($__smSidebarLinks as $__smLink): ?>
    <a href="<?= $__smLink['href'] ?>" class="mobile-nav-link<?= $__smLink['href'] === $__smSidebarCurrent ? ' active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $__smLink['icon'] ?></svg>
        <span><?= $__smLink['title'] ?></span>
<?php if (!empty($__smLink['badge_id'])): ?>
        <span class="sidebar-badge-dot hidden" data-badge-group="<?= $__smLink['badge_group'] ?? $__smLink['badge_id'] ?>" style="position:static; margin-left:auto; border:none;"></span>
<?php endif; ?>
    </a>
<?php endforeach; ?>
    <a href="beallitasok.php" class="mobile-nav-link<?= $__smSidebarCurrent === 'beallitasok.php' ? ' active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        <span>Beállítások</span>
    </a>
</nav>
