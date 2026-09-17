<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/NavClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Ugyanaz a "csak vezetői jogszinttel" szabály, mint a WooCommerce-teszt
// végpontnál (wc-test-connection.php) — a NAV hitelesítő adatok (jelszó,
// aláíró/csere kulcs) érzékenyek, egy sikertelen/sikeres válasz
// megkülönböztetése is potenciálisan kihasználható lenne jogosultság
// nélkül (pl. jelszó-találgatás oracle-ként).
require_admin($db);

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();
$input = json_input();

// A mentés előtti teszteléshez a form éppen begépelt (még el nem mentett)
// értékeit is elfogadja — ugyanaz a minta, mint printer-test.php/
// smtp-test.php/wc-test-connection.php-nál.
$navConfig = [
    'nav_login'        => trim((string) ($input['nav_login'] ?? $current['nav_login'])),
    'nav_password'     => (string) ($input['nav_password'] ?? $current['nav_password']),
    'nav_signer_key'   => (string) ($input['nav_signer_key'] ?? $current['nav_signer_key']),
    'nav_exchange_key' => (string) ($input['nav_exchange_key'] ?? $current['nav_exchange_key']),
    'nav_tax_number'   => trim((string) ($input['nav_tax_number'] ?? $current['nav_tax_number'])),
    'nav_test_mode'    => !empty($input['nav_test_mode'] ?? $current['nav_test_mode']),
];

foreach (['nav_login', 'nav_password', 'nav_signer_key', 'nav_exchange_key', 'nav_tax_number'] as $field) {
    if ($navConfig[$field] === '') {
        send_json(['success' => false, 'error' => 'A NAV technikai felhasználó adatai (login, jelszó, aláíró kulcs, csere kulcs, adószám) mind kötelezők a teszteléshez.']);
    }
}

$retentionDays = system_event_retention_days($current);

try {
    $client = new NavClient($navConfig);
    $result = $client->testConnection();

    if ($result['success']) {
        $settings->save([
            'last_nav_test_at'      => date('c'),
            'last_nav_test_status'  => 'success',
            'last_nav_test_message' => 'A NAV kapcsolat teszt sikeres volt.',
        ]);
        $db->logSystemEvent('nav', 'test_success', 'info', 'success', 'A NAV kapcsolat teszt sikeres volt.', null, $retentionDays);
        send_json(['success' => true]);
    }

    $errorMessage = 'A NAV nem fogadta el a hitelesítést.' . (!empty($result['nav_error_code']) ? ' (' . $result['nav_error_code'] . ')' : '');
    $settings->save([
        'last_nav_test_at'      => date('c'),
        'last_nav_test_status'  => 'failure',
        'last_nav_test_message' => $errorMessage,
    ]);
    $db->logSystemEvent('nav', 'test_failed', 'error', 'failure', $errorMessage, $result['error'] ?? null, $retentionDays);
    send_json(['success' => false, 'error' => $errorMessage]);
} catch (Throwable $e) {
    $settings->save([
        'last_nav_test_at'      => date('c'),
        'last_nav_test_status'  => 'failure',
        'last_nav_test_message' => 'A NAV kapcsolat tesztje váratlan hibával zárult.',
    ]);
    $db->logSystemEvent('nav', 'test_failed', 'error', 'failure', 'A NAV kapcsolat tesztje váratlan hibával zárult.', $e->getMessage(), $retentionDays);
    send_json(['success' => false, 'error' => 'A NAV kapcsolat tesztje váratlan hibával zárult.']);
}