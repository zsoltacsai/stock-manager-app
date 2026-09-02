<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$productId = (int) ($input['product_id'] ?? 0);
$fromLocationId = !empty($input['from_location_id']) ? (int) $input['from_location_id'] : null;
$toLocationId = (int) ($input['to_location_id'] ?? 0);
$qty = (int) ($input['qty'] ?? 0);
$staffId = !empty($input['staff_id']) ? (int) $input['staff_id'] : null;

if (!$productId || !$toLocationId || $qty <= 0) {
    send_json(['error' => 'Hiányzó vagy érvénytelen adatok.'], 400);
}
if ($fromLocationId === $toLocationId) {
    send_json(['error' => 'A forrás és a cél telephely nem lehet ugyanaz.'], 400);
}

if ($fromLocationId) {
    $fromStock = $db->getLocationStockForProduct($productId);
    foreach ($fromStock as $row) {
        if ((int) $row['location_id'] === $fromLocationId && (int) $row['stock_qty'] < $qty) {
            send_json(['error' => 'A forrás telephelyen nincs elég készlet (' . $row['stock_qty'] . ' db van).'], 400);
        }
    }
}

try {
    $db->transferStock($productId, $fromLocationId, $toLocationId, $qty, $staffId);
} catch (Throwable $e) {
    send_json(['error' => 'A készletmozgatás sikertelen: ' . $e->getMessage()], 500);
}

send_json(['ok' => true]);
