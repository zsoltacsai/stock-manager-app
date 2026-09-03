<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/LowStockNotifier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$paymentMethod = trim((string) ($input['payment_method'] ?? ''));
$issueInvoice = !empty($input['issue_invoice']);

$order = $id ? $db->getWebshopOrder($id) : null;
if (!$order) {
    send_json(['error' => 'A rendelés nem található.'], 404);
}
if ($order['status'] !== 'draft') {
    send_json(['error' => 'Ez a rendelés már fel lett dolgozva (' . $order['status'] . ').'], 400);
}
if ($paymentMethod === '') {
    $paymentMethod = $order['payment_method'] ?: 'Készpénz';
}

// A tételek közül csak azok csökkentik a helyi készletet, amik egy
// meglévő helyi termékhez vannak párosítva (product_id) — a
// párosítatlanokat kézi tételként visszük át az eladásba, hogy az
// összeg/számla pontos maradjon, de a készletet nem érintik.
$lineItems = [];
$lowStockCrossed = [];
foreach ($order['items'] as $item) {
    $qty = (int) ($item['qty'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    $productId = !empty($item['product_id']) ? (int) $item['product_id'] : null;
    $product = $productId ? $db->findProductById($productId) : null;

    $lineItems[] = [
        'product_id' => $product ? $product['id'] : null,
        'name'       => $item['name'] ?? ($product['name'] ?? 'Tétel'),
        'qty'        => $qty,
        'unit_price' => (float) ($item['unit_price'] ?? 0),
        'vat_rate'   => (string) ($item['vat_rate'] ?? ($product['vat_rate'] ?? '27')),
    ];

    if ($product) {
        $stockBefore = (int) $product['stock_qty'];
        $stockAfter = $stockBefore - $qty;
        $threshold = $product['low_stock_threshold'] !== null && $product['low_stock_threshold'] !== ''
            ? (int) $product['low_stock_threshold']
            : (int) ($appSettings['low_stock_default_threshold'] ?? 5);
        if ($stockAfter <= $threshold && $stockBefore > $threshold) {
            $lowStockCrossed[] = [
                'id' => $product['id'], 'name' => $product['name'], 'barcode' => $product['barcode'],
                'stock_qty' => $stockAfter, 'threshold' => $threshold,
            ];
        }
    }
}

if (!$lineItems) {
    send_json(['error' => 'A rendelésnek nincs feldolgozható tétele.'], 400);
}

$db->beginTransaction();
try {
    // Atomikus lefoglalás: ha időközben (pl. egy másik dolgozó általi
    // majdnem egyidejű kattintás miatt) már feldolgozásra került, itt
    // derül ki biztonságosan — a fenti korábbi ellenőrzés óta eltelt
    // időben ez elméletileg megváltozhatott.
    if (!$db->claimDraftWebshopOrder($id)) {
        $db->rollBack();
        send_json(['error' => 'Ezt a rendelést időközben már feldolgozták.'], 409);
    }

    $saleId = $db->insertSale(
        round((float) $order['total'], 2),
        $paymentMethod,
        $order['customer_name'] ?: null
    );
    foreach ($lineItems as $item) {
        $db->insertSaleItem($saleId, $item);
        if ($item['product_id']) {
            $db->decrementStock($item['product_id'], $item['qty']);
        }
    }
    $db->setWebshopOrderSale($id, $saleId, $paymentMethod);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    send_json(['error' => 'A rendelés leadása sikertelen: ' . $e->getMessage()], 500);
}

$db->logSync('webhook', null, "Beérkező rendelés #{$order['wc_order_id']} leadva (eladás #$saleId)");

if ($lowStockCrossed) {
    try {
        LowStockNotifier::notify($appSettings, $lowStockCrossed);
    } catch (Throwable $e) {
    }
}

$invoiceResult = null;
$buyer = $order['billing'] ?? [];
if ($issueInvoice) {
    $hasRequiredFields = !empty($buyer['nev']) && !empty($buyer['irsz']) && !empty($buyer['telepules']) && !empty($buyer['cim']);
    if (!$hasRequiredFields) {
        $invoiceResult = ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => 'A rendelés számlázási címe hiányos (név/irányítószám/település/cím szükséges), a számla nem állítható ki automatikusan.'];
    } else {
        $szamlazz = new SzamlazzClient($config['szamlazz']);
        $invoiceItems = array_map(fn($i) => [
            'name'             => $i['name'],
            'qty'              => $i['qty'],
            'unit_price_gross' => $i['unit_price'],
            'vat_rate'         => $i['vat_rate'],
        ], $lineItems);
        try {
            $invoiceResult = $szamlazz->createInvoice($buyer, $invoiceItems, (string) $saleId, null, $paymentMethod);
        } catch (Throwable $e) {
            $invoiceResult = ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage()];
        }
        $db->attachInvoiceToSale(
            $saleId,
            $invoiceResult['invoice_number'] ?? null,
            $invoiceResult['pdf_path'] ?? null,
            $invoiceResult['success'] ? 'completed' : 'invoice_failed'
        );
    }
}

send_json(['ok' => true, 'sale_id' => $saleId, 'invoice' => $invoiceResult]);
