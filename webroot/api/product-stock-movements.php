<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// A termék-részletező "Készletmozgások" fülének adatforrása (lásd a kör
// 9. pontja) — a getStockMovements() UGYANAZON logikáját használja, csak
// egyetlen termékre szűkítve. Alapértelmezett tartomány az utolsó 365 nap
// (nem "minden idők"), hogy egy régóta futó boltnál se váljon egyetlen
// lekérdezés korlátlanná — a felhasználó a date_from/date_to paraméterekkel
// tetszőlegesen visszamehet.

$productId = (int) ($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    send_json(['error' => 'Érvénytelen product_id.'], 400);
}
if (!$db->findProductById($productId)) {
    send_json(['error' => 'A termék nem található.'], 404);
}

$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-365 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) {
    send_json(['error' => 'Érvénytelen dátumtartomány.'], 400);
}

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

send_json($db->getStockMovements([
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
    'product_id' => $productId,
], $limit, $offset));
