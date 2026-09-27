<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/**
 * PERF-04 — szerveroldali termékkeresés a kasszának és a Beszerzésnek (a
 * teljes katalógus letöltése helyett). Egyszerre egy mód:
 *   ?q=...&limit=20 — név/cikkszám részszöveg (Database::searchProductsForPos())
 *   ?barcode=...    — nem törölt termék pontos vonalkóddal
 *   ?ids=1,2,3      — nem törölt termékek azonosító szerint (legfeljebb 500)
 * A válasz minden módban {"products": [...]} teljes termékrekordokkal.
 */

if (isset($_GET['barcode'])) {
    $barcode = trim((string) $_GET['barcode']);
    $product = $barcode === '' ? null : $db->findActiveProductByBarcode($barcode);
    send_json(['products' => $product ? [$product] : []]);
}

if (isset($_GET['ids'])) {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ids'])), static fn ($id) => $id > 0))), 0, 500);
    $rows = $ids ? $db->findProductsByIds($ids) : [];
    $products = [];
    foreach ($ids as $id) {
        if (isset($rows[$id]) && !(int) $rows[$id]['is_deleted']) {
            $products[] = $rows[$id];
        }
    }
    send_json(['products' => $products]);
}

$limit = (int) ($_GET['limit'] ?? 20);
send_json(['products' => $db->searchProductsForPos((string) ($_GET['q'] ?? ''), $limit)]);
