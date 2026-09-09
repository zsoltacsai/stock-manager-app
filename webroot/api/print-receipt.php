<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/ReceiptPrinter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$saleId = (int) ($input['sale_id'] ?? 0);

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

$result = ReceiptPrinter::printForSale($s, $sale, __DIR__ . '/../assets');

if (!$result['success']) {
    send_json(['error' => $result['error']], 400);
}

send_json(['success' => true]);
