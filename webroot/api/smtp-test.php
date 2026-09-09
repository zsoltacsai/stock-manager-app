<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/MailerService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Ugyanaz a "csak vezetői jogszinttel" szabály, mint a nyomtató-teszt
// végpontnál (printer-test.php) — az SMTP-beállítások (jelszó is)
// érzékenyek, és egy tetszőleges cél email-cím felé küldés (kikémlelt
// SMTP-hitelesítő adatokkal) potenciális visszaélési felület lenne.
if ($db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
    send_json(['error' => 'Az email teszt küldéséhez vezetői jogszint szükséges.'], 403);
}

$input = json_input();
$toEmail = trim((string) ($input['to_email'] ?? ''));
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    send_json(['error' => 'Érvénytelen cél email cím.'], 400);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

// Mentés előtti teszteléshez (a Beállítások "Mentés" gombja előtt is
// kipróbálható legyen) elfogadja a még el nem mentett form-értékeket is —
// ugyanaz a minta, mint printer-test.php-nál. Az üresen hagyott (nem
// beküldött) jelszó mezőt a MÁR elmentett értékkel egészíti ki (soha nem
// jön vissza a kliensnek, lásd webroot/api/settings.php secretResponseFields),
// hogy egy "csak a portot módosítom" teszt ne igényelje a jelszó
// újbóli begépelését.
$smtpConfig = [
    'host'       => trim((string) ($input['smtp_host'] ?? $current['smtp_host'])),
    'port'       => (int) ($input['smtp_port'] ?? $current['smtp_port']),
    'username'   => trim((string) ($input['smtp_username'] ?? $current['smtp_username'])),
    'password'   => (string) ($input['smtp_password'] ?? $current['smtp_password']),
    'encryption' => in_array($input['smtp_encryption'] ?? null, ['none', 'ssl', 'starttls'], true) ? $input['smtp_encryption'] : $current['smtp_encryption'],
    'from_email' => trim((string) ($input['smtp_from_email'] ?? $current['smtp_from_email'])),
    'from_name'  => trim((string) ($input['smtp_from_name'] ?? $current['smtp_from_name'])),
];

if ($smtpConfig['host'] === '') {
    send_json(['error' => 'Add meg az SMTP host címét.'], 400);
}
if (!filter_var($smtpConfig['from_email'], FILTER_VALIDATE_EMAIL)) {
    send_json(['error' => 'Érvénytelen "Feladó email" cím.'], 400);
}

$subject = 'Stock Manager — teszt email';
$html = '<div style="font-family:Arial,sans-serif;"><h2>Stock Manager</h2><p>Ez egy teszt email az SMTP beállítások ellenőrzéséhez.</p><p>Ha ezt megkaptad, az SMTP kapcsolat, hitelesítés és a kézbesítés rendben működik.</p></div>';

$result = MailerService::send($smtpConfig, $toEmail, $subject, $html);

if (!$result['success']) {
    send_json(['error' => $result['error']], 500);
}

send_json(['success' => true]);
