<?php
declare(strict_types=1);

$__smHeaderCurrent = basename($_SERVER['SCRIPT_NAME']);

$__smHeaderLinks = [
    ['href' => 'index.php', 'label' => 'Kassza'],
    ['href' => 'beszerzes.php', 'label' => 'Új beszerzés'],
    ['href' => 'beszerzesek.php', 'label' => 'Beszerzések'],
    ['href' => 'eladasok.php', 'label' => 'Eladások'],
    ['href' => 'termekek.php', 'label' => 'Árucikkek'],
    ['href' => 'zaras.php', 'label' => 'Napi zárás'],
];
?>
<nav class="nav-track">
<?php foreach ($__smHeaderLinks as $__smLink): if ($__smLink['href'] === $__smHeaderCurrent) continue; ?>
        <a href="<?= $__smLink['href'] ?>" class="nav-link"><?= $__smLink['label'] ?></a>
<?php endforeach; ?>
        </nav>
<?php if ($__smHeaderCurrent !== 'beallitasok.php'): ?>
        <a href="beallitasok.php" id="settings-btn" class="icon-btn" title="Beállítások">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        </a>
<?php endif; ?>
        <button id="sync-icon-btn" class="icon-btn" title="Szinkronizálás WooCommerce-ből">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
            <span class="badge-dot"></span>
        </button>
        <button id="header-logout-btn" class="icon-btn hidden" title="Kijelentkezés">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </button>
