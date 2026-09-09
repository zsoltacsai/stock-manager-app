<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// A NAV-számla queue kézi újrapróbálkozása érzékeny — csak terminális
// (failed/dead_letter/uncertain) állapotú sorra engedélyezett, és
// vezetői jogszintet igényel (lásd require_admin() docblockja), ugyanúgy,
// mint a Beállítások bármely más módosítása. A CSRF-védelem a normál,
// nem-cron végpontokra vonatkozó általános szabály szerint automatikusan
// érvényesül (lásd _bootstrap.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen id.'], 400);
}

$invoice = $db->getInvoiceById($id);
if (!$invoice || $invoice['provider'] !== 'nav') {
    send_json(['error' => 'A NAV-számla queue-bejegyzés nem található.'], 404);
}

$reset = $db->resetInvoiceForManualRetry($id);
if (!$reset) {
    send_json(['error' => 'Csak sikertelen (failed/dead_letter/uncertain) állapotú bejegyzés próbálható újra manuálisan — ez a bejegyzés jelenleg "' . $invoice['status'] . '" állapotban van.'], 409);
}

send_json(['ok' => true, 'invoice' => $db->getInvoiceById($id)]);
