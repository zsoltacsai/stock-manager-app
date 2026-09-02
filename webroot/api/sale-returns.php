<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$saleId = (int) ($_GET['sale_id'] ?? 0);
if (!$saleId) {
    send_json(['error' => 'Hiányzó sale_id.'], 400);
}

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}
unset($sale['receipt_token']); // titkos token — sose menjen ki, még bejelentkezett dolgozónak sem

send_json([
    'sale' => $sale,
    'returned_quantities' => $db->getReturnedQuantitiesForSale($saleId),
    'returns' => $db->getReturnsForSale($saleId),
]);
