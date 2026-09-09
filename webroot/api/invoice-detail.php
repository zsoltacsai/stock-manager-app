<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$invoice = $id ? $db->getInvoiceById($id) : null;

if (!$invoice) {
    send_json(['error' => 'A számla nem található.'], 404);
}

$sale = $db->getSaleWithItems((int) $invoice['sale_id']);
if ($sale) {
    unset($sale['receipt_token']); // titkos token — sose menjen ki, még bejelentkezett dolgozónak sem (lásd sale-detail.php)
}

send_json(['invoice' => $invoice, 'sale' => $sale]);
