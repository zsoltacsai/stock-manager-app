<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Kézi WooCommerce push-újrapróbálkozás — UGYANAZ a mintázat, mint
// nav-invoice-retry.php (lásd ott): csak terminális (failed/dead_letter)
// sorra engedélyezett, vezetői jogszintet igényel, a tényleges reset a
// MEGLÉVŐ (1.1.1-ben bevezetett) Database::resetWcPushForManualRetry()-t
// használja — nincs új állapotgép, ez a kör 12. pontja szerinti "backend-
// controlled" gomb.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen id.'], 400);
}

$row = $db->getWcPushQueueRowById($id);
if (!$row) {
    send_json(['error' => 'A WooCommerce queue-bejegyzés nem található.'], 404);
}

$reset = $db->resetWcPushForManualRetry($id);
if (!$reset) {
    send_json(['error' => 'Csak sikertelen (failed/dead_letter) állapotú bejegyzés próbálható újra manuálisan — ez a bejegyzés jelenleg "' . $row['status'] . '" állapotban van.'], 409);
}

send_json(['ok' => true, 'row' => $db->getWcPushQueueRowById($id)]);
