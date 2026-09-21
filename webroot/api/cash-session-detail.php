<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}

$cashPaymentMethods = [];
foreach (($appSettings['payment_methods'] ?? []) as $method) {
    if (!empty($method['is_cash']) && !empty($method['value'])) {
        $cashPaymentMethods[] = $method['value'];
    }
}

try {
    $breakdown = $db->getCashSessionBreakdown($id, $cashPaymentMethods);
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 404);
}

send_json($breakdown);
