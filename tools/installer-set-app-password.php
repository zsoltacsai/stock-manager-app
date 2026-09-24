<?php

declare(strict_types=1);

/**
 * A Windows telepítő (install-windows.ps1) Szerver szerepkörű telepítésének
 * alkalmazás-jelszó beállítása. Szerver szerepkörben a közvetlen (nem
 * proxyzott) API-forgalom MINDIG hitelesítést igényel (lásd Auth::isEnabled())
 * — jelszó nélkül a Szerver felülete zárva maradna, ezért a telepítő itt
 * állítja be az első jelszót.
 *
 * A jelszó KIZÁRÓLAG a szabványos bemeneten (stdin) érkezik, SOSE
 * parancssori argumentumként (az a folyamatlistában látható lenne).
 *
 *   php tools/installer-set-app-password.php --action=status
 *     -> {"ok":true,"has_password":bool}
 *   echo <jelszó> | php tools/installer-set-app-password.php --action=set [--force]
 *     -> {"ok":true,"changed":bool}
 *   (--stdin=base64: a stdin a jelszó UTF-8 bájtjainak base64-e — a telepítő ezt használja)
 *
 * --force nélkül egy MÁR beállított jelszót sose ír felül (idempotens
 * újrafuttatás); --force a dokumentált kézi helyreállítási út (elfelejtett
 * jelszó egy Szerver gépen, rendszergazdai parancssorból).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../src/Settings.php';

const MIN_APP_PASSWORD_LENGTH = 8;

function ft_app_password_output(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE), "\n";
    exit($exitCode);
}

$options = getopt('', ['action:', 'force', 'stdin:']);
$action = (string) ($options['action'] ?? '');
$settings = new Settings(__DIR__ . '/../data/settings.json');

try {
    $current = $settings->read();
} catch (Throwable $e) {
    ft_app_password_output(['ok' => false, 'error' => 'A data/settings.json nem olvasható.'], 1);
}
$hasPassword = !empty($current['app_password_hash']);

if ($action === 'status') {
    ft_app_password_output(['ok' => true, 'has_password' => $hasPassword], 0);
}

if ($action !== 'set') {
    ft_app_password_output(['ok' => false, 'error' => 'Ismeretlen --action (status|set).'], 1);
}

if ($hasPassword && !isset($options['force'])) {
    if (empty($current['app_password_enabled'])) {
        $settings->save(['app_password_enabled' => true]);
    }
    ft_app_password_output(['ok' => true, 'changed' => false], 0);
}

$rawInput = (string) stream_get_contents(STDIN);
if (($options['stdin'] ?? '') === 'base64') {
    // A Windows PowerShell 5.1 a natív programnak csövezett szöveget a konzol
    // kódlapjára kódolja (az ékezetes karakterek '?'-lé válnak) és BOM-ot is
    // elé tehet — ezért a telepítő ASCII-biztos base64-ben küldi a jelszót.
    $encoded = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawInput) ?? '');
    $password = base64_decode($encoded, true);
    if ($password === false || !mb_check_encoding($password, 'UTF-8')) {
        ft_app_password_output(['ok' => false, 'error' => 'Érvénytelen base64 jelszó-bemenet.'], 1);
    }
} else {
    $password = rtrim($rawInput, "\r\n");
}
if (mb_strlen($password) < MIN_APP_PASSWORD_LENGTH) {
    ft_app_password_output(['ok' => false, 'error' => 'A jelszónak legalább ' . MIN_APP_PASSWORD_LENGTH . ' karakter hosszúnak kell lennie.'], 1);
}

$settings->save([
    'app_password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'app_password_enabled' => true,
]);
ft_app_password_output(['ok' => true, 'changed' => true], 0);
