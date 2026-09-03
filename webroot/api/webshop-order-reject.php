<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);

$order = $id ? $db->getWebshopOrder($id) : null;
if (!$order) {
    send_json(['error' => 'A rendelés nem található.'], 404);
}
if ($order['status'] !== 'draft') {
    send_json(['error' => 'Ez a rendelés már fel lett dolgozva (' . $order['status'] . ').'], 400);
}

// Atomikus feltételes frissítés — lásd Database::claimDraftWebshopOrder.
if (!$db->claimAndRejectDraftWebshopOrder($id)) {
    send_json(['error' => 'Ezt a rendelést időközben már feldolgozták.'], 409);
}
$db->logSync('webhook', null, "Beérkező rendelés #{$order['wc_order_id']} elutasítva");

send_json(['ok' => true]);
