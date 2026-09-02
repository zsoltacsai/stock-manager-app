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
        'href' => 'termekek.php',
        'title' => 'Árucikkek',
        'icon' => '<rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>',
    ],
    [
        'href' => 'zaras.php',
        'title' => 'Napi zárás',
        'icon' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
    ],
    [
        'href' => 'vasarlok.php',
        'title' => 'Ügyféllista',
        'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    ],
    [
        'href' => 'rendszerallapot.php',
        'title' => 'Rendszerállapot',
        'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>',
    ],
];
?>
<nav class="icon-sidebar">
    <a href="index.php"><img src="assets/logo-default.svg" class="sidebar-logo" alt="Logó" id="sidebar-logo"></a>
<?php foreach ($__smSidebarLinks as $__smLink): ?>
    <a href="<?= $__smLink['href'] ?>" class="sidebar-link<?= $__smLink['href'] === $__smSidebarCurrent ? ' active' : '' ?>" title="<?= $__smLink['title'] ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $__smLink['icon'] ?></svg>
    </a>
<?php endforeach; ?>
    <div class="sidebar-spacer"></div>
    <a href="beallitasok.php" class="sidebar-link<?= $__smSidebarCurrent === 'beallitasok.php' ? ' active' : '' ?>" title="Beállítások">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
    </a>
</nav>
