<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Ez a végpont állítja be/kapcsolja ki magát az app-jelszót és a
// geo-blokkolást — a legérzékenyebb egyetlen beállítás-csoport az
// alkalmazásban, egy sima pénztáros semmiképp ne érhesse el.
require_admin($db);

$input = json_input();
$settings = new Settings(__DIR__ . '/../../data/settings.json');
$update = [];

$currentMode = (string) ($appSettings['deployment_mode'] ?? 'local');
$targetMode = isset($input['deployment_mode']) ? (string) $input['deployment_mode'] : $currentMode;
if (!in_array($targetMode, ['local', 'network'], true)) {
    send_json(['error' => 'Érvénytelen üzemmód.'], 400);
}

if (!empty($input['new_password'])) {
    $newPassword = (string) $input['new_password'];
    if (strlen($newPassword) < 8) {
        send_json(['error' => 'A jelszó legalább 8 karakter legyen.'], 400);
    }
    if (($input['new_password_confirm'] ?? '') !== $newPassword) {
        send_json(['error' => 'A két jelszó nem egyezik.'], 400);
    }
    $update['app_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
}

// Lesz-e jelszó a mentés UTÁN? (vagy már volt, vagy ebben a kérésben állítjuk be)
$willHavePassword = !empty($update['app_password_hash']) || !empty($appSettings['app_password_hash']);

if (isset($input['app_password_enabled'])) {
    $enabled = !empty($input['app_password_enabled']);
    if ($enabled && !$willHavePassword) {
        send_json(['error' => 'A bekapcsoláshoz előbb állíts be egy jelszót.'], 400);
    }
    $update['app_password_enabled'] = $enabled;
}
$willBeEnabled = $update['app_password_enabled'] ?? !empty($appSettings['app_password_enabled']);

// 'network' üzemmód (nyilvánosan/internetről is elérhető telepítés)
// SOSE léphet érvénybe jelszavas védelem nélkül — se úgy, hogy most
// váltunk 'network'-re jelszó nélkül, se úgy, hogy már 'network'-ben
// vagyunk és valaki megpróbálná kikapcsolni a jelszót. Lásd
// Auth::isEnabled() — ez a szerver-oldali kényszer a döntő, a
// kliens-oldali felület csak segít elkerülni a hibát.
if ($targetMode === 'network' && !$willHavePassword) {
    send_json(['error' => 'Nyilvános ("network") üzemmódhoz előbb állíts be egy jelszót.'], 400);
}
if ($targetMode === 'network' && !$willBeEnabled) {
    send_json(['error' => 'Nyilvános ("network") üzemmódban a jelszavas védelem nem kapcsolható ki.'], 400);
}

if (isset($input['deployment_mode'])) {
    $update['deployment_mode'] = $targetMode;
}

if (isset($input['session_timeout_minutes'])) {
    $update['session_timeout_minutes'] = max(0, (int) $input['session_timeout_minutes']);
}
if (isset($input['login_max_attempts'])) {
    $update['login_max_attempts'] = max(1, (int) $input['login_max_attempts']);
}
if (isset($input['login_lockout_minutes'])) {
    $update['login_lockout_minutes'] = max(1, (int) $input['login_lockout_minutes']);
}

if (isset($input['geo_block_enabled']) || isset($input['geo_block_countries']) || isset($input['geo_block_allow_ips'])) {
    $geoEnabled = isset($input['geo_block_enabled'])
        ? !empty($input['geo_block_enabled'])
        : !empty($appSettings['geo_block_enabled']);
    $geoCountries = isset($input['geo_block_countries'])
        ? strtoupper(trim((string) $input['geo_block_countries']))
        : (string) ($appSettings['geo_block_countries'] ?? '');
    $geoAllowIps = isset($input['geo_block_allow_ips'])
        ? trim((string) $input['geo_block_allow_ips'])
        : (string) ($appSettings['geo_block_allow_ips'] ?? '');

    if ($geoEnabled) {
        require_once __DIR__ . '/../../src/GeoBlocker.php';
        $selfCheck = GeoBlocker::check([
            'geo_block_enabled'   => true,
            'geo_block_countries' => $geoCountries,
            'geo_block_allow_ips' => $geoAllowIps,
        ]);
        if (!$selfCheck['allowed']) {
            send_json([
                'error' => 'A mentés meghiúsult: ezzel a beállítással a jelenlegi IP-címed'
                    . ' (' . $selfCheck['ip'] . ($selfCheck['country'] ? ', ' . $selfCheck['country'] : '') . ')'
                    . ' ki lenne zárva a rendszerből. Vedd fel az országodat vagy IP-címedet a listára,'
                    . ' mielőtt bekapcsolod.',
            ], 400);
        }
    }

    $update['geo_block_enabled'] = $geoEnabled;
    $update['geo_block_countries'] = $geoCountries;
    $update['geo_block_allow_ips'] = $geoAllowIps;
}

$data = $settings->save($update);
unset($data['app_password_hash']); // a hash sose menjen vissza a kliensnek

send_json($data);
