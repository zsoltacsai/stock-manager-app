<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/NavIncomingInvoiceSync.php';
require_once __DIR__ . '/../../src/NavIncomingInvoiceSyncWorker.php';

// Admin-kezdeményezett manuális sync — a Beállítások bármely más
// módosításával megegyező vezetői jogszint + CSRF-védelem (lásd
// require_admin() és _bootstrap.php CSRF-blokkja).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$period = (string) ($input['period'] ?? '');
if (!in_array($period, ['7d', '30d', 'custom'], true)) {
    send_json(['error' => 'Érvénytelen period — "7d", "30d" vagy "custom" lehet.'], 400);
}

$request = ['period' => $period];
if ($period === 'custom') {
    $dateFrom = trim((string) ($input['date_from'] ?? ''));
    if ($dateFrom === '' || strtotime($dateFrom) === false) {
        send_json(['error' => 'Érvénytelen vagy hiányzó date_from egyedi tartomány esetén.'], 400);
    }
    $request['date_from'] = $dateFrom;
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

if (empty($current['nav_login']) || empty($current['nav_password']) || empty($current['nav_tax_number'])) {
    send_json(['error' => 'A NAV Online Számla integráció nincs beállítva.'], 400);
}

$navConfig = [
    'nav_login' => $current['nav_login'],
    'nav_password' => $current['nav_password'],
    'nav_signer_key' => $current['nav_signer_key'],
    'nav_exchange_key' => $current['nav_exchange_key'],
    'nav_tax_number' => $current['nav_tax_number'],
    'nav_test_mode' => !empty($current['nav_test_mode']),
];

$sync = new NavIncomingInvoiceSync($db, fn () => new NavClient($navConfig));
$worker = new NavIncomingInvoiceSyncWorker($db, $sync);

$result = $worker->triggerManualSync($request, 3);

if ($result['outcome'] === 'already_running') {
    // A cron worker (vagy egy másik admin) már folyamatban lévő syncet
    // tart — SZÁNDÉKOSAN nem indítunk párhuzamos másodikat (lásd Phase 6
    // terv 6. és 12. pontja).
    send_json(['error' => 'A bejövő-számla sync már folyamatban van — próbáld később.'], 409);
}

$settings->save([
    'last_nav_incoming_sync_run_at' => date('c'),
    'last_nav_incoming_sync_run_summary' => 'Manuális sync (' . $period . '): ' . $result['outcome']
        . (isset($result['windows_processed']) ? (', ' . $result['windows_processed'] . ' ablak feldolgozva') : '')
        . (!empty($result['error']) ? (', hiba: ' . $result['error']) : ''),
]);

send_json(['result' => $result, 'sync' => $db->getIncomingInvoiceSyncState('nav')]);
