<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/NavInvoiceProvider.php';
require_once __DIR__ . '/../../src/NavInvoiceQueueWorker.php';
require_once __DIR__ . '/../../src/NavTokenCache.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

if (empty($current['nav_queue_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

if (empty($current['nav_login']) || empty($current['nav_password']) || empty($current['nav_tax_number'])) {
    send_json(['skipped' => true, 'reason' => 'not_configured']);
}

try {
    $navConfig = [
        'nav_login' => $current['nav_login'],
        'nav_password' => $current['nav_password'],
        'nav_signer_key' => $current['nav_signer_key'],
        'nav_exchange_key' => $current['nav_exchange_key'],
        'nav_tax_number' => $current['nav_tax_number'],
        'nav_test_mode' => !empty($current['nav_test_mode']),
    ];
    $supplierConfig = [
        'tax_number' => $current['nav_tax_number'],
        'name' => $current['nav_supplier_name'] ?? '',
        'zip' => $current['nav_supplier_zip'] ?? '',
        'city' => $current['nav_supplier_city'] ?? '',
        'address' => $current['nav_supplier_address'] ?? '',
        'bank_account' => $current['nav_supplier_bank_account'] ?? null,
    ];
    $tokenCache = new NavTokenCache(__DIR__ . '/../../data/nav-token-cache.json');
    $provider = new NavInvoiceProvider($navConfig, $supplierConfig, $tokenCache);
    $worker = new NavInvoiceQueueWorker($db, $provider);

    // A három kör EGYMÁS UTÁN, egy cron-futáson belül — a NAV tipikus
    // válaszideje <200ms, a limitek (10-10-10) miatt egy teljes futás
    // normál esetben másodperceken belül végez, még ha mindhárom kör
    // talál is feldolgoznivalót.
    $submissions = $worker->processDueSubmissions(10);
    $statusChecks = $worker->processDueStatusChecks(10);
    $uncertainRecovery = $worker->processDueUncertainRecovery(10);

    $summary = sprintf(
        'beküldés: %d claim, %d submitted, %d retry, %d dead_letter, %d uncertain, %d permanent hiba | '
        . 'státusz: %d claim, %d done, %d failed, %d még folyamatban | '
        . 'bizonytalan-egyeztetés: %d claim, %d helyreállítva, %d még bizonytalan, %d feladva',
        $submissions['claimed'], $submissions['submitted'], $submissions['retried'], $submissions['dead_letters'], $submissions['uncertain'], $submissions['permanent_failures'],
        $statusChecks['claimed'], $statusChecks['done'], $statusChecks['failed'], $statusChecks['still_pending'],
        $uncertainRecovery['claimed'], $uncertainRecovery['recovered'], $uncertainRecovery['still_uncertain'], $uncertainRecovery['gave_up']
    );

    $settings->save([
        'last_nav_queue_run_at' => date('c'),
        'last_nav_queue_run_summary' => $summary,
    ]);

    send_json(['ran' => true, 'submissions' => $submissions, 'status_checks' => $statusChecks, 'uncertain_recovery' => $uncertainRecovery]);
} catch (Throwable $e) {
    $settings->save([
        'last_nav_queue_run_at' => date('c'),
        'last_nav_queue_run_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
