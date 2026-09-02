<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$barcode = trim((string) ($_GET['code'] ?? ''));

if ($barcode === '') {
    send_json(['error' => 'Missing barcode'], 400);
}

$product = $db->findProductByBarcode($barcode);

if (!$product) {
    send_json(['found' => false]);
}

send_json(['found' => true, 'product' => $product]);
