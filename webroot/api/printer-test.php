<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/EscPosPrinter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();

// Allow testing with unsaved values from the settings form, before hitting "Mentés".
$ip = trim((string) ($input['printer_ip'] ?? ''));
$port = (int) ($input['printer_port'] ?? 9100);
$paperWidth = (int) ($input['printer_paper_width'] ?? 42);
$encoding = (string) ($input['printer_encoding'] ?? 'cp852');
$includeQrSample = !empty($input['include_qr_sample']);

if ($ip === '') {
    send_json(['error' => 'Add meg a nyomtató IP címét.'], 400);
}
$isValidIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
$isValidHostname = preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $ip) === 1;
if (!$isValidIp && !$isValidHostname) {
    send_json(['error' => 'Érvénytelen nyomtató IP-cím vagy hostname.'], 400);
}
if ($port < 1 || $port > 65535) {
    send_json(['error' => 'Érvénytelen port (1-65535).'], 400);
}
if (!array_key_exists($encoding, EscPosPrinter::CODEPAGES)) {
    $encoding = 'cp852';
}

// Tetszőleges IP:port pár felé indít kapcsolatot, és a válaszból (sikeres/
// elutasított/időtúllépéses) kikövetkeztethető, mi fut az adott címen és
// porton — ez elméletileg belső hálózat feltérképezésére is használható
// lenne. Ugyanaz a "csak vezetői jogszinttel" szabály vonatkozik rá, mint
// a többi, hasonlóan érzékeny Beállítások-műveletre, csak akkor
// kényszerítve, ha egyáltalán van dolgozói PIN-rendszer használatban —
// ez a NORMÁL, admin-konfigurált nyomtató-cél teszteléséhez szükséges
// egyetlen szerveroldali kapu, egy nem-admin session ide sose juthat el
// (require_admin()-jellegű ellenőrzés, lásd _bootstrap.php).
if ($db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
    send_json(['error' => 'A nyomtató tesztjéhez vezetői jogszint szükséges.'], 403);
}

$logoPath = null;
if (!empty($appSettings['receipt_show_logo']) && !empty($appSettings['logo_filename'])) {
    $candidate = __DIR__ . '/../assets/' . $appSettings['logo_filename'];
    if (is_file($candidate)) {
        $logoPath = $candidate;
    }
}

try {
    $printer = new EscPosPrinter($ip, $port, $paperWidth, $encoding);
    $printer->printTestPage($config['shop'], $logoPath, $includeQrSample);
    send_json(['success' => true]);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
}
