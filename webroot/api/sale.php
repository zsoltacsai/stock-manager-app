<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/LowStockNotifier.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$cart  = $input['items'] ?? [];
$buyer = $input['buyer'] ?? null; // when null, "Vevő számlát kér" wasn't checked — no Szamlazz.hu call happens at all in that case
$paymentMethod = $input['payment_method'] ?? 'Készpénz';
$customerId = !empty($input['customer_id']) ? (int) $input['customer_id'] : null;
$redeemPoints = max(0, (int) ($input['redeem_points'] ?? 0));
$locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;

if (empty($cart)) {
    send_json(['error' => 'Cart is empty'], 400);
}

if ($buyer !== null) {
    foreach (['nev', 'irsz', 'telepules', 'cim'] as $required) {
        if (empty($buyer[$required])) {
            send_json(['error' => "Missing buyer field: $required"], 400);
        }
    }
}

$customer = null;
if ($customerId && !empty($appSettings['loyalty_enabled'])) {
    $customer = $db->findCustomerById($customerId);
    if (!$customer) {
        send_json(['error' => 'A kiválasztott vásárló nem található.'], 404);
    }
    if ($redeemPoints > (int) $customer['loyalty_points']) {
        send_json(['error' => 'A vásárlónak nincs elég pontja (' . $customer['loyalty_points'] . ' pont van).'], 400);
    }
}

$couponCode = trim((string) ($input['coupon_code'] ?? ''));
$giftCardCode = trim((string) ($input['gift_card_code'] ?? ''));

// 1. Tételsorok összeállítása — a készlet itt NINCS letiltva: a bolt
//    szeretné tudni túladni a készleten (a készlet negatívba megy,
//    a következő beszerzésnél korrigálódik). A kasszafelület már ez
//    előtt figyelmezteti az eladót; itt csak megjelöljük, mely sorok
//    mennek negatívba, hogy a válasz jelezni tudja.
// Egy kosártétel lehet kézzel beírt is (nincs product_id) — olyan
// szolgáltatáshoz vagy egyedi tételhez, amit a vevő rá akar tetetni a
// nyugtára/számlára, de egyáltalán nincs nyilvántartva a készletben. Ez
// teljesen kihagyja a készlet/WooCommerce/alacsony-készlet kezelést, de
// az eladáson elmentődik és a számlán is szerepel, mint bármelyik más sor.
$lineItems = [];
$total = 0.0;
$oversoldItems = [];

foreach ($cart as $line) {
    if (!empty($line['manual'])) {
        $name = trim((string) ($line['name'] ?? ''));
        $qty = (int) ($line['qty'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $vatRate = (string) ($line['vat_rate'] ?? '27');

        if ($name === '') {
            send_json(['error' => 'A kézi tétel neve kötelező.'], 400);
        }
        if ($qty <= 0) {
            send_json(['error' => "Érvénytelen mennyiség: $name"], 400);
        }
        if ($unitPrice < 0) {
            send_json(['error' => "Érvénytelen egységár: $name"], 400);
        }

        $lineItems[] = [
            'product_id'   => null,
            'wc_product_id' => null,
            'name'         => $name,
            'barcode'      => null,
            'qty'          => $qty,
            'unit_price'   => $unitPrice,
            'vat_rate'     => $vatRate,
            'stock_before' => null,
            'low_stock_threshold' => null,
            'manual'       => true,
        ];
        $total += $qty * $unitPrice;
        continue;
    }

    $product = $db->findProductById((int) $line['product_id']);
    $qty = (int) $line['qty'];

    if (!$product) {
        send_json(['error' => "Product {$line['product_id']} not found"], 404);
    }
    if ($qty <= 0) {
        send_json(['error' => "Invalid quantity for {$product['name']}"], 400);
    }

    $stockAfter = (int) $product['stock_qty'] - $qty;
    if ($stockAfter < 0) {
        $oversoldItems[] = ['name' => $product['name'], 'new_stock' => $stockAfter];
    }

    $lineItems[] = [
        'product_id' => $product['id'],
        'wc_product_id' => $product['wc_product_id'],
        'name'       => $product['name'],
        'barcode'    => $product['barcode'],
        'qty'        => $qty,
        'unit_price' => (float) $product['price'],
        'vat_rate'   => $product['vat_rate'],
        'stock_before' => (int) $product['stock_qty'],
        'low_stock_threshold' => $product['low_stock_threshold'],
    ];
    $total += $qty * (float) $product['price'];
}

$subtotal = $total;

$coupon = null;
$couponDiscount = 0.0;
if ($couponCode !== '') {
    $couponResult = $db->validateCoupon($couponCode, $subtotal);
    if (!$couponResult['ok']) {
        send_json(['error' => $couponResult['error']], 400);
    }
    $coupon = $couponResult['coupon'];
    $couponDiscount = $couponResult['discount'];
    $total = max(0, $total - $couponDiscount);
}

$pointValue = (float) ($appSettings['loyalty_point_value_huf'] ?? 0);
$redeemDiscount = $customer ? round($redeemPoints * $pointValue, 2) : 0.0;
$total = max(0, $total - $redeemDiscount);

// Hűségszint (loyalty tier) kedvezmény — az élettartam-elköltés alapján,
// a pontbeváltástól függetlenül. A kupon és a pontok után, az utalvány
// előtt kerül alkalmazásra.
$tierName = null;
$tierDiscount = 0.0;
if ($customer && !empty($appSettings['loyalty_enabled'])) {
    $goldThreshold = (float) ($appSettings['loyalty_tier_gold_threshold'] ?? 150000);
    $silverThreshold = (float) ($appSettings['loyalty_tier_silver_threshold'] ?? 50000);
    $totalSpent = (float) $customer['total_spent'];

    if ($totalSpent >= $goldThreshold) {
        $tierName = 'gold';
        $tierPct = (float) ($appSettings['loyalty_tier_gold_discount'] ?? 10);
    } elseif ($totalSpent >= $silverThreshold) {
        $tierName = 'silver';
        $tierPct = (float) ($appSettings['loyalty_tier_silver_discount'] ?? 5);
    } else {
        $tierName = 'bronze';
        $tierPct = 0.0;
    }

    if ($tierPct > 0) {
        $tierDiscount = round($total * ($tierPct / 100), 2);
        $total = max(0, $total - $tierDiscount);
    }
}

$pointsEarned = 0;
$loyaltyBasisTotal = $total; // a pontszámítás ÉS a total_spent ugyanerre az alapra épül — lásd lentebb
if ($customer && !empty($appSettings['loyalty_enabled'])) {
    $hufPerPoint = max(1, (int) ($appSettings['loyalty_huf_per_point'] ?? 100));
    $pointsEarned = (int) floor($total / $hufPerPoint);
}

$giftCard = null;
$giftCardRedeemed = 0.0;
if ($giftCardCode !== '') {
    $giftCardResult = $db->validateGiftCard($giftCardCode, $total);
    if (!$giftCardResult['ok']) {
        send_json(['error' => $giftCardResult['error']], 400);
    }
    $giftCard = $giftCardResult['gift_card'];
    $giftCardRedeemed = $giftCardResult['redeemable'];
    // $total csökken innentől a kártyával fedezett résszel, de a
    // $loyaltyBasisTotal (pontszámítás ÉS total_spent alapja) szándékosan
    // NEM — egy ajándékkártyával fizetett rész is "elköltésnek" számít a
    // hűségszint/pontok szempontjából, különben a kártyát használó vásárló
    // pontjai és hűségszintje egymáshoz képest inkonzisztensen alakulna.
    $total = max(0, round($total - $giftCardRedeemed, 2));
}

$db->beginTransaction();
try {
    $saleId = $db->insertSale(
        round($total, 2),
        $paymentMethod,
        $buyer['nev'] ?? null,
        $customer ? $customerId : null,
        $pointsEarned,
        $customer ? $redeemPoints : 0,
        $coupon['id'] ?? null,
        $couponDiscount,
        $giftCardRedeemed,
        !empty($input['staff_id']) ? (int) $input['staff_id'] : null
    );
    foreach ($lineItems as $item) {
        $db->insertSaleItem($saleId, $item);
        if (empty($item['manual'])) {
            $db->decrementStock($item['product_id'], $item['qty']);
            if ($locationId) {
                $db->decrementLocationStock($item['product_id'], $locationId, $item['qty']);
            }
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    send_json(['error' => 'Az eladás rögzítése sikertelen: ' . $e->getMessage()], 500);
}

$newPointsBalance = null;
if ($customer) {
    if ($redeemPoints > 0) {
        $db->applyLoyaltyPoints($customerId, -$redeemPoints, $saleId, "Beváltva eladás #$saleId-nél");
    }
    if ($pointsEarned > 0) {
        $newPointsBalance = $db->applyLoyaltyPoints($customerId, $pointsEarned, $saleId, "Jóváírva eladás #$saleId-nél");
    } else {
        $newPointsBalance = $db->findCustomerById($customerId)['loyalty_points'] ?? null;
    }
    $db->addCustomerSpend($customerId, round($loyaltyBasisTotal, 2));
}
if ($coupon) {
    $db->incrementCouponUsage((int) $coupon['id']);
}
$giftCardNewBalance = null;
if ($giftCard && $giftCardRedeemed > 0) {
    $giftCardNewBalance = $db->redeemGiftCard((int) $giftCard['id'], $giftCardRedeemed, $saleId);
}

$invoiceResult = null;

if ($buyer !== null) {
    $szamlazz = new SzamlazzClient($config['szamlazz']);

    $invoiceItems = array_map(fn($i) => [
        'name'             => $i['name'],
        'qty'              => $i['qty'],
        'unit_price_gross' => $i['unit_price'],
        'vat_rate'         => $i['vat_rate'],
    ], $lineItems);

    $languageOverride = $input['invoice_language'] ?? null;

    try {
        $invoiceResult = $szamlazz->createInvoice($buyer, $invoiceItems, (string) $saleId, $languageOverride, $paymentMethod);
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

$pushErrors = [];
try {
    $wc = new WooCommerceClient($config['woocommerce']);
    foreach ($lineItems as $item) {
        if (empty($item['wc_product_id'])) {
            continue;
        }
        $newStock = $item['stock_before'] - $item['qty'];
        try {
            $wc->updateStock((int) $item['wc_product_id'], $newStock);
            $db->setStock($item['product_id'], $newStock);
            $db->logSync('push', $item['product_id'], 'Stock pushed after sale #' . $saleId);
        } catch (Throwable $e) {
            $pushErrors[] = $item['name'] . ': ' . $e->getMessage();
            $db->logSync('push', $item['product_id'], 'FAILED: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $pushErrors[] = $e->getMessage();
}

$lowStockCrossed = [];
foreach ($lineItems as $item) {
    if (!empty($item['manual'])) {
        continue;
    }
    $threshold = $item['low_stock_threshold'] !== null && $item['low_stock_threshold'] !== ''
        ? (int) $item['low_stock_threshold']
        : (int) $appSettings['low_stock_default_threshold'];
    $stockAfter = $item['stock_before'] - $item['qty'];
    if ($stockAfter <= $threshold && $item['stock_before'] > $threshold) {
        $lowStockCrossed[] = [
            'id' => $item['product_id'], 'name' => $item['name'], 'barcode' => $item['barcode'],
            'stock_qty' => $stockAfter, 'threshold' => $threshold,
        ];
    }
}
if ($lowStockCrossed) {
    try {
        LowStockNotifier::notify($appSettings, $lowStockCrossed);
    } catch (Throwable $e) {
    }
}

send_json([
    'sale_id'        => $saleId,
    'receipt_token'  => $db->getSaleReceiptToken($saleId),
    'total'          => round($total, 2),
    'subtotal'       => round($subtotal, 2),
    'invoice'        => $invoiceResult,
    'wc_push_errors' => $pushErrors,
    'oversold_items' => $oversoldItems,
    'loyalty'        => $customer ? [
        'customer_id'      => $customerId,
        'points_earned'    => $pointsEarned,
        'points_redeemed'  => $redeemPoints,
        'new_balance'      => $newPointsBalance,
        'redeem_discount'  => $redeemDiscount,
        'tier'             => $tierName,
        'tier_discount'    => $tierDiscount,
    ] : null,
    'coupon' => $coupon ? [
        'code'     => $coupon['code'],
        'discount' => $couponDiscount,
    ] : null,
    'gift_card' => $giftCard ? [
        'code'        => $giftCard['code'],
        'redeemed'    => $giftCardRedeemed,
        'new_balance' => $giftCardNewBalance,
    ] : null,
]);
