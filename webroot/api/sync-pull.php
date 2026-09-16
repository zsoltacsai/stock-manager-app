<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Egy teljes WooCommerce-katalógus behúzása minden helyi, szinkronra
// kapcsolt terméket felülír — ugyanaz a "vezetői jogszint kell" szabály
// indokolt rá, mint a beállítások/mentés egyéb infrastruktúra-szintű
// műveleteinél.
require_admin($db);

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    $result = $wc->fetchAllProducts();
    $products = $result['products'];

    $imported = 0;
    $skipped  = 0;

    // Regresszió (1.3.1): korábban a TELJES katalógus (akár 5000 tétel) egy
    // EGYETLEN tranzakcióban futott le — SQLite-on ez a teljes szinkron
    // időtartamára fogva tartja az író-zárat, így egy közben induló valódi
    // eladás (sale.php, szintén write tranzakció) SQLITE_BUSY-ba
    // ütközhetett, ha a busy_timeout-on belül nem szabadult fel. 200-as
    // csomagokban commit-olva a zár csak rövid ideig tartós — egy
    // közbeeső hiba esetén a már commit-olt csomagok NEM vesznek el (a
    // WooCommerce-behúzás önmagában idempotens, egy újrafuttatás ugyanoda
    // konvergál), ami ELFOGADHATÓ tradeoff a checkout-blokkolás
    // elkerüléséért.
    foreach (array_chunk($products, 200) as $chunk) {
        $db->beginTransaction();
        try {
            foreach ($chunk as $p) {
                if (empty($p['barcode'])) {
                    $skipped++;
                    $db->logSync('pull', null, "Skipped '{$p['name']}' (no barcode/SKU)");
                    continue;
                }
                $db->upsertProductFromWc($p);
                $imported++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    if ($result['truncated']) {
        $db->logSync('pull', null, 'FIGYELEM: a WooCommerce termékkatalógus nagyobb, mint 5000 tétel — a szinkron nem dolgozta fel a teljeset.');
    }

    send_json([
        'imported' => $imported, 'skipped' => $skipped, 'total_from_wc' => count($products),
        'truncated' => $result['truncated'],
    ]);
} catch (RuntimeException $e) {
    // A WooCommerceClient saját, biztonságosan felhasználó (admin) elé
    // tárható hibaüzenete (pl. "WooCommerce request failed (timeout)")
    // — verifikáltan sose tartalmaz hitelesítő adatot, valódi
    // diagnosztikai értéke van a szinkron-hiba elhárításához.
    $db->rollBack();
    send_json(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    $db->rollBack();
    send_generic_error_response($e, 'sync-pull.php WooCommerce behúzás sikertelen');
}
