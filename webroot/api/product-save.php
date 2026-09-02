<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$p = json_input();

if (empty($p['name'])) {
    send_json(['error' => 'Megnevezés kötelező'], 400);
}

$vatRate = (string) ($p['vat_rate'] ?? $config['szamlazz']['default_vat_rate']);
$vatPct  = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;

$net = isset($p['net_price']) && $p['net_price'] !== '' ? (float) $p['net_price'] : null;
$gross = isset($p['gross_price']) && $p['gross_price'] !== '' ? (float) $p['gross_price'] : null;

if ($net === null && $gross === null) {
    send_json(['error' => 'Adja meg a nettó vagy bruttó árat'], 400);
}
if ($net === null) {
    $net = round($gross / (1 + $vatPct), 2);
}
if ($gross === null) {
    $gross = round($net * (1 + $vatPct), 2);
}

if (!empty($p['barcode'])) {
    $existing = $db->findProductByBarcode($p['barcode']);
    if ($existing && (empty($p['id']) || (int) $existing['id'] !== (int) $p['id'])) {
        send_json(['error' => "A vonalkód ({$p['barcode']}) már egy másik termékhez tartozik: {$existing['name']}"], 409);
    }
}

// Árucikk törléséhez "vezető" (admin) jogszint kell — de csak akkor, ha
// tényleg be van jelentkezve egy dolgozó a kasszán; ha a dolgozói PIN
// funkció egyáltalán nincs használatban, ez engedékeny marad, nem zár ki
// senkit.
$wasDeleted = false;
if (!empty($p['id'])) {
    $existingProduct = $db->findProductById((int) $p['id']);
    $wasDeleted = $existingProduct && !empty($existingProduct['is_deleted']);
}
$settingToDeleted = !empty($p['is_deleted']) && !$wasDeleted;
if ($settingToDeleted && !empty($p['staff_id']) && !$db->isStaffAdmin((int) $p['staff_id'])) {
    send_json(['error' => 'Termék törléséhez vezetői jogszint szükséges.'], 403);
}

$productId = $db->saveProduct([
    'id'             => $p['id'] ?? null,
    'name'           => $p['name'],
    'unit'           => $p['unit'] ?? 'db',
    'group_name'     => $p['group_name'] ?? null,
    'cikkszam'       => $p['cikkszam'] ?? null,
    'vtsz'           => $p['vtsz'] ?? null,
    'barcode'        => $p['barcode'] ?? null,
    'currency'       => $p['currency'] ?? 'HUF',
    'vat_rate'       => $vatRate,
    'net_price'      => $net,
    'price'          => $gross,
    'weight'         => $p['weight'] ?? null,
    'volume'         => $p['volume'] ?? null,
    'notes'          => $p['notes'] ?? null,
    'show_pricelist' => $p['show_pricelist'] ?? true,
    'show_webshop'   => $p['show_webshop'] ?? true,
    'is_deleted'     => $p['is_deleted'] ?? false,
    'low_stock_threshold' => $p['low_stock_threshold'] ?? '',
]);

if ($settingToDeleted) {
    $db->logAudit(
        !empty($p['staff_id']) ? (int) $p['staff_id'] : null,
        'product_delete',
        'product',
        $productId,
        $p['name'],
        (int) ($appSettings['audit_log_retention_days'] ?? 30)
    );
}

send_json(['product' => $db->findProductById($productId)]);
