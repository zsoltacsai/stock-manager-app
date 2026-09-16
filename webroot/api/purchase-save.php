<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$lines = $input['items'] ?? [];
$supplier = $input['supplier'] ?? [];

// Kliens által generált, egy adott "beszerzés-leadási kísérlethez" tartozó
// kulcs — PONTOSAN ugyanaz a minta, mint api/sale.php-ban (lásd ott a
// docblockot a teljes indoklásért): dupla kattintás, hálózati
// újrapróbálkozás, vagy egy elveszett válasz utáni manuális újraküldés
// esetén ez zárja ki, hogy ugyanaz a logikai beszerzés kétszer kerüljön
// rögzítésre (duplán megnövelt készlet, duplán kiküldött WooCommerce-push).
// A tényleges atomikus védelmet a purchases.idempotency_key UNIQUE indexe
// adja (lásd Database::recordPurchase()), nem ez az előzetes ellenőrzés
// önmagában.
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
$idempotencyFingerprint = $idempotencyKey !== '' ? build_purchase_fingerprint($input) : null;

if ($idempotencyKey !== '') {
    $existingPurchase = $db->findPurchaseByIdempotencyKey($idempotencyKey);
    if ($existingPurchase) {
        match_or_reject_idempotent_purchase_replay($db, $existingPurchase, $idempotencyFingerprint); // sose tér vissza
    }
}

if (empty($lines)) {
    send_json(['error' => 'A beszerzési tételek listája üres'], 400);
}

$items = [];
foreach ($lines as $line) {
    $product = $db->findProductById((int) $line['product_id']);
    if (!$product) {
        send_json(['error' => "Termék #{$line['product_id']} nem található"], 404);
    }

    $qty = (int) $line['qty'];
    if ($qty <= 0) {
        send_json(['error' => "Érvénytelen mennyiség: {$product['name']}"], 400);
    }

    $vatRate = (string) ($line['vat_rate'] ?? $product['vat_rate']);
    $vatPct  = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;

    $unitCostNet = (float) $line['unit_cost_net'];
    if ($unitCostNet < 0) {
        send_json(['error' => "Érvénytelen beszerzési ár: {$product['name']}"], 400);
    }
    // A bruttó egységárat mindig a nettóból (és az áfából) számítjuk ki
    // szerver-oldalon, nem a kliens által esetlegesen külön beküldött
    // értékből — enélkül egy kézzel összeállított kérés belsőleg
    // inkonzisztens nettó/bruttó párt rögzíthetne.
    $unitCostGross = round($unitCostNet * (1 + $vatPct), 2);

    $items[] = [
        'product_id'      => $product['id'],
        'wc_product_id'   => !empty($product['sync_to_woocommerce']) ? $product['wc_product_id'] : null,
        'name'            => $product['name'],
        'qty'             => $qty,
        'vat_rate'        => $vatRate,
        'unit_cost_net'   => $unitCostNet,
        'unit_cost_gross' => $unitCostGross,
    ];
}

$discountPercent = (float) ($input['discount_percent'] ?? 0);
if ($discountPercent < 0 || $discountPercent > 100) {
    send_json(['error' => 'Érvénytelen kedvezmény százalék.'], 400);
}

$purchase = [
    'supplier_id'         => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
    'supplier_name'       => $supplier['nev'] ?? null,
    'supplier_tax_number' => $supplier['adoszam'] ?? null,
    'supplier_country'    => $supplier['orszag'] ?? null,
    'supplier_zip'        => $supplier['irsz'] ?? null,
    'supplier_city'       => $supplier['telepules'] ?? null,
    'supplier_address'    => $supplier['cim'] ?? null,
    'payment_method'      => $input['payment_method'] ?? 'készpénz',
    'currency'            => $input['currency'] ?? 'HUF',
    'discount_percent'    => $discountPercent,
    'paid'                => $input['paid'] ?? true,
    'note'                => $input['note'] ?? null,
];

try {
    $result = $db->recordPurchase($purchase, $items, $idempotencyKey, $idempotencyFingerprint);
} catch (Throwable $e) {
    // Ha ez éppen az idempotencia-kulcs UNIQUE-ütközése, egy VERSENYHELYZETBEN
    // futó másik kérés (nem egy korábbi, időben eltolt újrapróbálkozás, hanem
    // egy szinte pontosan egyidejű másik kérés ugyanazzal a kulccsal) már
    // megnyerte a beszúrást — lásd api/sale.php ugyanezen mintájának
    // docblockja a teljes indoklásért. A győztes eredményét adjuk vissza,
    // nem hibát.
    if ($idempotencyKey !== '' && str_contains($e->getMessage(), 'idempotency_key')) {
        $winner = $db->findPurchaseByIdempotencyKey($idempotencyKey);
        if ($winner) {
            match_or_reject_idempotent_purchase_replay($db, $winner, $idempotencyFingerprint); // sose tér vissza
        }
    }
    send_generic_error_response($e, 'purchase-save.php beszerzés rögzítése sikertelen');
}

// A WooCommerce-push MÁR beütemezve a recordPurchase() saját tranzakciójában
// (lásd Database::enqueueWcPush()) — a tényleges kiküldés egy külön,
// cron-indított workerben (WcPushQueueWorker) történik, ASZINKRON, hogy egy
// lassú/elérhetetlen WooCommerce szerver se várassa meg a beszerzés
// rögzítését. A 'wc_push_errors' mező visszafelé kompatibilitásból marad.
send_json([
    'purchase_id'      => $result['purchase_id'],
    'total_net'        => $result['total_net'],
    'total_gross'      => $result['total_gross'],
    'updated_products' => array_values($result['updated_products']),
    'wc_push_errors'   => [],
]);

/**
 * Determinisztikus "ujjlenyomat" a kérés üzletileg releváns mezőiről —
 * PONTOSAN ugyanaz az elv, mint api/sale.php build_sale_fingerprint()-jénél
 * (lásd ott a teljes docblockot): csak a szerver-oldali eredményt ténylegesen
 * befolyásoló mezők szerepelnek, stabil (rendezett) formában.
 */
function build_purchase_fingerprint(array $input): string
{
    $lines = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
    $normalizedItems = array_map(static function ($line) {
        $line = is_array($line) ? $line : [];
        return [
            'product_id'    => isset($line['product_id']) ? (int) $line['product_id'] : null,
            'qty'           => isset($line['qty']) ? (int) $line['qty'] : null,
            'unit_cost_net' => isset($line['unit_cost_net']) ? round((float) $line['unit_cost_net'], 2) : null,
            'vat_rate'      => isset($line['vat_rate']) ? (string) $line['vat_rate'] : null,
        ];
    }, $lines);
    usort($normalizedItems, static fn(array $a, array $b): int =>
        [(string) $a['product_id'], $a['qty']] <=> [(string) $b['product_id'], $b['qty']]);

    $supplier = is_array($input['supplier'] ?? null) ? $input['supplier'] : [];
    $supplier = array_map('strval', $supplier);
    ksort($supplier);

    $fingerprintData = [
        'items'             => $normalizedItems,
        'supplier_id'       => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
        'supplier'          => $supplier,
        'payment_method'    => (string) ($input['payment_method'] ?? 'készpénz'),
        'currency'          => (string) ($input['currency'] ?? 'HUF'),
        'discount_percent'  => round((float) ($input['discount_percent'] ?? 0), 2),
        'paid'              => !empty($input['paid']),
        'note'              => (string) ($input['note'] ?? ''),
    ];

    return hash('sha256', json_encode($fingerprintData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Egy korábban (ugyanezzel az idempotencia-kulccsal) már sikeresen rögzített
 * beszerzés visszajátszása — PONTOSAN ugyanaz az elv, mint api/sale.php
 * match_or_reject_idempotent_replay()-jénél. SOSE tér vissza — vagy egy
 * 200-as visszajátszást, vagy egy 409-es hibát küld.
 */
function match_or_reject_idempotent_purchase_replay(Database $db, array $existingPurchase, ?string $requestFingerprint): void
{
    $storedFingerprint = $existingPurchase['idempotency_fingerprint'] ?? null;
    if ($storedFingerprint !== null && $storedFingerprint !== '' && $storedFingerprint !== $requestFingerprint) {
        send_json([
            'error' => 'Ugyanaz az idempotencia-kulcs egy korábbitól eltérő tartalmú kéréssel érkezett — ez a kérés nem dolgozható fel. Töltsd újra az oldalt, és próbáld újra a rögzítést.',
        ], 409);
    }

    send_json([
        'purchase_id'      => (int) $existingPurchase['id'],
        'total_net'        => round((float) $existingPurchase['total_net'], 2),
        'total_gross'      => round((float) $existingPurchase['total_gross'], 2),
        'updated_products' => [],
        'wc_push_errors'   => [],
        'replayed'         => true,
    ]);
}
