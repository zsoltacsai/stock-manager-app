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
// B-08: az elutasítás felszabadítja a draft foglalását — a push ettől
// kezdve a teljes helyi készletet adja vissza a webshopnak. Az állapotváltás
// és a push beütemezése egy tranzakcióban.
$db->beginTransaction();
try {
    if (!$db->claimAndRejectDraftWebshopOrder($id)) {
        $db->rollBack();
        send_json(['error' => 'Ezt a rendelést időközben már feldolgozták.'], 409);
    }
    $db->enqueueWcPushForWebOrderItems($order['items'], 'web_reject', $id);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    send_generic_error_response($e, 'webshop-order-reject.php elutasítás sikertelen');
}
$db->logSync('webhook', null, "Beérkező rendelés #{$order['wc_order_id']} elutasítva");

send_json(['ok' => true]);
