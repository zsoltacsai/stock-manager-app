<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$webhookSecret = (string) $config['woocommerce']['webhook_secret'];

// Ha a titkos kulcs üres vagy a config.php gyári placeholder értékén
// maradt, a HMAC-ellenőrzés önmagában kitalálható/reprodukálható lenne
// bárki számára — ilyenkor inkább egyértelműen elutasítjuk a webhookot,
// minthogy hamis bejövő rendeléseket engedjünk be a piszkozat-sorba.
if ($webhookSecret === '' || $webhookSecret === 'change-me-webhook-secret') {
    send_json(['error' => 'Webhook secret not configured'], 401);
}

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';

$expected = base64_encode(hash_hmac('sha256', $rawBody, $webhookSecret, true));

if (!$signature || !hash_equals($expected, $signature)) {
    send_json(['error' => 'Invalid webhook signature'], 401);
}

$order = json_decode($rawBody, true);

// P-D: egy KORÁBBAN piszkozatként importált rendelés WooCommerce-beli
// lemondása/visszatérítése/sikertelensége — a foglalás (B-08: a draft maga
// a foglalás) felszabadul, egy már leadott (megerősített) rendelésnél a
// helyi eladás/készlet NEM áll vissza vakon. Idempotens: duplikált/
// ismételt webhook no-op. Lásd Database::applyWebOrderTermination().
//
// Törlés: a WooCommerce "order.deleted" webhookja (téma-fejléc) csak a
// rendelés azonosítóját küldi, státusz és tételek nélkül — ezt, és a
// kukába helyezést ('trash' státusz) ugyanígy lezárásként kezeljük
// ('deleted'). Egy még nem ismert rendelésnél sírkő-sor rögzül, így egy
// később érkező, elavult 'processing' webhook sem foglalhat újra.
$webhookTopic = strtolower((string) ($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? ''));
if (is_array($order) && !empty($order['id']) && (
    $webhookTopic === 'order.deleted'
    || (!array_key_exists('status', $order) && empty($order['line_items']))
)) {
    $order['status'] = 'deleted';
}
if (is_array($order) && in_array((string) ($order['status'] ?? ''), Database::WEB_ORDER_TERMINAL_WC_STATUSES, true)) {
    $terminatedId = (int) ($order['id'] ?? 0);
    if (!$terminatedId) {
        send_json(['ignored' => true, 'reason' => 'missing order id']);
    }
    $terminatedBilling = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    $terminatedName = trim(($terminatedBilling['company'] ?? '') !== ''
        ? (string) $terminatedBilling['company']
        : trim(($terminatedBilling['first_name'] ?? '') . ' ' . ($terminatedBilling['last_name'] ?? '')));
    $termination = $db->applyWebOrderTermination($terminatedId, (string) $order['status'], $terminatedName);
    if ($termination['outcome'] === 'released') {
        $db->logSync('webhook', null, "Rendelés #$terminatedId a WooCommerce-ben {$order['status']} — a piszkozat elutasítva, a foglalás felszabadult.");
    } elseif ($termination['outcome'] === 'unknown') {
        $db->logSync('webhook', null, "Rendelés #$terminatedId a WooCommerce-ben {$order['status']}, helyben még ismeretlen — sírkő rögzítve, egy később érkező régi webhook nem foglalhat rá.");
    } elseif ($termination['outcome'] === 'confirmed_kept') {
        $db->logSync('webhook', null, "Rendelés #$terminatedId a WooCommerce-ben {$order['status']}, de már le volt adva (eladás #{$termination['sale_id']}) — a helyi eladás megmaradt, szükség esetén visszáruval rendezd.");
    }
    send_json(['ok' => true, 'status' => $order['status'], 'outcome' => $termination['outcome'], 'order_id' => $terminatedId]);
}

if (!is_array($order) || empty($order['line_items'])) {
    send_json(['ignored' => true]);
}

$status = $order['status'] ?? '';
$relevant = in_array($status, ['processing', 'completed'], true);

if (!$relevant) {
    send_json(['ignored' => true, 'status' => $status]);
}

$wcOrderId = (int) ($order['id'] ?? 0);
if (!$wcOrderId) {
    send_json(['ignored' => true, 'reason' => 'missing order id']);
}

// A rendelés NEM csökkenti azonnal a készletet — ehelyett piszkozatként
// bekerül a "Beérkező eladások" listába, ahol egy ember ellenőrzi (a
// tételek termékpárosítását, a fizetési módot), majd "leadja". Ez védi ki,
// hogy egy hibás/duplikált/gyanús webhook-hívás észrevétlenül módosítsa a
// helyi készletet — lásd api/webshop-order-confirm.php.
$billing = $order['billing'] ?? [];
$buyerName = trim(($billing['company'] ?? '') !== ''
    ? (string) $billing['company']
    : trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));

// A SzamlazzClient buyer-tömbjével megegyező alakban tároljuk, hogy a
// számla egy kattintással, átalakítás nélkül kiállítható legyen belőle.
$euVatOrTaxNumber = '';
foreach (($order['meta_data'] ?? []) as $meta) {
    $key = strtolower((string) ($meta['key'] ?? ''));
    if (in_array($key, ['_billing_vat_number', '_billing_eu_vat_number', 'vat_number', '_billing_tax_number'], true) && !empty($meta['value'])) {
        $euVatOrTaxNumber = (string) $meta['value'];
        break;
    }
}
$buyer = [
    'nev'        => $buyerName,
    'orszag'     => $billing['country'] ?? '',
    'irsz'       => $billing['postcode'] ?? '',
    'telepules'  => $billing['city'] ?? '',
    'cim'        => trim(($billing['address_1'] ?? '') . ' ' . ($billing['address_2'] ?? '')),
    'email'      => $billing['email'] ?? '',
    'adoszam'    => $euVatOrTaxNumber,
    'megjegyzes' => 'WooCommerce rendelés #' . ($order['number'] ?? $wcOrderId),
];

$items = [];
foreach ($order['line_items'] as $li) {
    $wcProductId = (int) ($li['product_id'] ?? 0);
    $qty = (int) ($li['quantity'] ?? 0);
    if ($qty <= 0) {
        continue;
    }

    $product = $wcProductId ? $db->findProductByWcId($wcProductId) : null;
    $lineTotal = (float) ($li['total'] ?? 0) + (float) ($li['total_tax'] ?? 0);
    $unitPrice = $qty > 0 ? round($lineTotal / $qty, 2) : (float) ($li['price'] ?? 0);

    $items[] = [
        'wc_product_id' => $wcProductId ?: null,
        'product_id'    => $product['id'] ?? null,
        'name'          => (string) ($li['name'] ?? ''),
        'sku'           => (string) ($li['sku'] ?? ''),
        'qty'           => $qty,
        'unit_price'    => $unitPrice,
        'vat_rate'      => $product['vat_rate'] ?? ($appSettings['szamlazz_default_vat'] ?? '27'),
        'matched'       => $product !== null,
    ];
}

if (!$items) {
    send_json(['ignored' => true, 'reason' => 'no usable line items']);
}

$total = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $items));

// B-08: a draft (a helyi foglalás) és a WooCommerce-push beütemezése EGY
// tranzakcióban — a WooCommerce a rendeléskor már levonta a darabokat, a
// push (stock_qty − függő webes rendelések) egy közben lefutott, a draftot
// még nem ismerő korábbi pusht korrigál. Duplikált webhooknál (UNIQUE
// wc_order_id) se draft, se push nem keletkezik.
$db->beginTransaction();
try {
    $draftId = $db->insertWebshopOrderDraft([
        'wc_order_id'    => $wcOrderId,
        'order_number'   => (string) ($order['number'] ?? $wcOrderId),
        'wc_status'      => $status,
        'customer_name'  => $buyerName,
        'customer_email' => $billing['email'] ?? '',
        'billing'        => $buyer,
        'payment_method' => (string) ($order['payment_method_title'] ?? $order['payment_method'] ?? ''),
        'currency'       => (string) ($order['currency'] ?? 'HUF'),
        'total'          => round($total, 2),
        'items'          => $items,
        'customer_note'  => (string) ($order['customer_note'] ?? ''),
    ]);
    if ($draftId !== null) {
        $db->enqueueWcPushForWebOrderItems($items, 'web_order', $draftId);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e; // 5xx — a WooCommerce újraküldi a webhookot
}

if ($draftId === null) {
    send_json(['ok' => true, 'ignored' => true, 'reason' => 'already imported', 'order_id' => $wcOrderId]);
}

$db->logSync('webhook', null, "Rendelés #{$wcOrderId} piszkozatként importálva (Beérkező eladások, #$draftId)");

send_json(['ok' => true, 'draft_id' => $draftId, 'order_id' => $wcOrderId]);
