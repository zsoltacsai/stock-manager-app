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
if ($settingToDeleted && $db->listStaff(true) && !$db->isStaffAdmin(!empty($p['staff_id']) ? (int) $p['staff_id'] : null)) {
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
    'short_description' => $p['short_description'] ?? null,
    'long_description' => $p['long_description'] ?? null,
    'image_filename' => $p['image_filename'] ?? null,
    'image_alt'      => $p['image_alt'] ?? null,
    'brand'          => $p['brand'] ?? null,
    'sync_to_woocommerce' => $p['sync_to_woocommerce'] ?? true,
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

$savedProduct = $db->findProductById($productId);

$wcPushError = null;
if (!empty($savedProduct['sync_to_woocommerce']) && !empty($savedProduct['wc_product_id'])) {
    try {
        $wc = new WooCommerceClient($config['woocommerce']);
        $brandMapping = is_array($appSettings['brand_mapping'] ?? null) ? $appSettings['brand_mapping'] : [];
        $localBrand = $savedProduct['brand'] ?? '';
        $mappedBrand = $localBrand !== '' && !empty($brandMapping[$localBrand]) ? $brandMapping[$localBrand] : $localBrand;

        $pushFields = [
            'name'  => $savedProduct['name'],
            'price' => $savedProduct['price'],
            'short_description' => $savedProduct['short_description'] ?? '',
            'long_description'  => $savedProduct['long_description'] ?? '',
        ];
        if ($mappedBrand !== '') {
            $pushFields['brand_id'] = $wc->resolveBrandId($mappedBrand);
        }
        $imageChanged = ($existingProduct['image_filename'] ?? null) !== $savedProduct['image_filename']
            || ($existingProduct['image_alt'] ?? null) !== $savedProduct['image_alt'];
        if (!empty($savedProduct['image_filename']) && !empty($appSettings['wc_public_base_url']) && $imageChanged) {
            $pushFields['image_url'] = rtrim($appSettings['wc_public_base_url'], '/')
                . '/assets/products/' . $savedProduct['image_filename'];
            $pushFields['image_alt'] = $savedProduct['image_alt'] ?? '';
            // A WooCommerce oldalán a kép letöltése és többméretű újramintázása
            // jóval tovább tarthat, mint a PHP alapértelmezett 30 másodperces
            // végrehajtási korlátja — enélkül a kérés félbeszakadna, még ha a
            // WooCommerce oldalon a művelet egyébként sikeresen befejeződne is.
            // Csak akkor futtatjuk ezt a lassabb utat, ha a kép ténylegesen
            // változott — egy sima név/ár-módosítás ne várjon emiatt feleslegesen.
            set_time_limit(60);
        }
        $wc->pushProduct((int) $savedProduct['wc_product_id'], $pushFields);
        $db->logSync('push', $productId, 'Termékadatok kiküldve a WooCommerce-nek');
    } catch (Throwable $e) {
        $wcPushError = $e->getMessage();
        $db->logSync('push', $productId, 'FAILED: ' . $e->getMessage());
    }
}

send_json(['product' => $savedProduct, 'wc_push_error' => $wcPushError]);
