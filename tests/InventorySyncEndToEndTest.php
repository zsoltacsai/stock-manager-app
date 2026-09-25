<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-05 + B-08 + B-11 — összefüggő készlet-/szinkron-forgatókönyv. Minden
 * lépés a valódi Database-műveletekkel fut, a WooCommerce egy
 * állapottartó hamis bolt (abszolút készletet állít, mint a valódi API),
 * a push a valódi WcPushQueueWorker-en megy át.
 *
 * A végállapot minden értéke a tárolt adatokból levezethető:
 *   összesített = import abszolút értéke + visszáru − megerősített webes rendelés;
 *   telephelyi  = mozgatás − eladás + visszáru (az import/webes rendelés nem telephelyi);
 *   WooCommerce = összesített − függő webes rendelések.
 */
final class InventorySyncEndToEndTest extends TestCase
{
    public function testFullScenarioEndsInAConsistentExplainableState(): void
    {
        $db = tests_new_database();
        $store = new FakeWooStoreForEndToEndTest();
        $worker = new WcPushQueueWorker($db, $store);
        $wcId = 990;

        // 1) Kiinduló termék, WooCommerce-hez kötve.
        $p = $db->saveProduct(['name' => 'E2E termék', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => '5990000009901']);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([$wcId, $p]);
        $shop = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);

        // 2) Új készlet a boltba.
        $db->transferStock($p, null, $shop, 10, null);
        $worker->processDuePushes(50);
        $this->assertSame([10, 10, 10], $this->state($db, $store, $p, $shop, $wcId));

        // 3) POS-eladás a boltban (sale.php lépései).
        $db->beginTransaction();
        $saleId = $db->insertSale(2540.0, 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, null, $shop);
        $db->insertSaleItem($saleId, ['product_id' => $p, 'name' => 'E2E termék', 'qty' => 2, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($p, 2);
        $db->decrementLocationStock($p, $shop, 2);
        $db->enqueueWcPush($p, $wcId, 'sale', $saleId);
        $db->commit();

        // 4) Webes rendelés 3 db-ra: a WooCommerce azonnal levon, helyben draft (webhook.php).
        $items = [['wc_product_id' => $wcId, 'product_id' => $p, 'name' => 'E2E termék', 'qty' => 3, 'unit_price' => 1270, 'vat_rate' => '27', 'matched' => true]];
        $store->stock[$wcId] -= 3;
        $db->beginTransaction();
        $draftId = $db->insertWebshopOrderDraft(['wc_order_id' => 77001, 'total' => 3810, 'items' => $items]);
        $db->enqueueWcPushForWebOrderItems($items, 'web_order', $draftId);
        $db->commit();

        // 5) Import: külső rendszerből abszolút készlet 12 + új név/ár.
        $db->importUpsertProduct(['name' => 'E2E termék (import)', 'unit' => 'db', 'group_name' => '', 'cikkszam' => '', 'barcode' => '5990000009901', 'currency' => 'HUF', 'vat_rate' => '27', 'net_price' => 1102.36, 'price' => 1400, 'notes' => '', 'stock_qty' => 12, 'purchase_price_net' => 700], 5001);

        // 6) Visszáru: a POS-eladás 2 darabja vissza a boltba.
        $sale = $db->getSaleWithItems($saleId);
        $db->processReturn($saleId, [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => $p, 'name' => 'E2E termék', 'qty' => 2, 'unit_price' => 1270]], 'visszáru', null, 2540.0, $sale);

        // 7) Egy auto-sync pull a push ELŐTT (régi WC név/ár), majd a push, majd még egy pull.
        $db->upsertProductFromWc(['wc_product_id' => $wcId, 'sku' => '5990000009901', 'barcode' => '5990000009901', 'name' => 'E2E termék', 'price' => 1270, 'stock_qty' => $store->stock[$wcId]]);
        $worker->processDuePushes(50);
        $db->upsertProductFromWc(['wc_product_id' => $wcId, 'sku' => '5990000009901', 'barcode' => '5990000009901', 'name' => $store->name[$wcId], 'price' => $store->price[$wcId], 'stock_qty' => $store->stock[$wcId]]);

        // 8–10) Végállapot.
        [$total, $atShop, $wc] = $this->state($db, $store, $p, $shop, $wcId);
        $this->assertSame(14, $total, 'import 12 + visszáru 2');
        $this->assertSame(10, $atShop, 'mozgatás 10 − eladás 2 + visszáru 2 (az import nem telephelyi)');
        $this->assertSame(11, $wc, 'összesített 14 − függő webes rendelés 3');
        $this->assertSame(3, $db->getPendingWebOrderQty($p));
        $product = $db->findProductById($p);
        $this->assertSame(['E2E termék (import)', 1400.0], [$product['name'], (float) $product['price']], 'Az import név/ár nem veszett el.');
        $this->assertSame(['E2E termék (import)', 1400.0], [$store->name[$wcId], (float) $store->price[$wcId]]);

        // A webes rendelés megerősítése: nincs dupla levonás, a WC értéke nem változik.
        $order = $db->getWebshopOrder($draftId);
        $db->beginTransaction();
        $this->assertTrue($db->claimDraftWebshopOrder($draftId));
        $webSaleId = $db->insertSale(3810.0, 'Utánvét');
        $db->insertSaleItem($webSaleId, ['product_id' => $p, 'name' => 'E2E termék', 'qty' => 3, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($p, 3);
        $db->enqueueWcPushForWebOrderItems($order['items'], 'sale', $webSaleId);
        $db->setWebshopOrderSale($draftId, $webSaleId, 'Utánvét');
        $db->commit();
        $worker->processDuePushes(50);

        $this->assertSame([11, 10, 11], $this->state($db, $store, $p, $shop, $wcId));
        $this->assertSame(0, $db->getPendingWebOrderQty($p));
        $this->assertSame(0, (int) $db->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE status != 'done'")->fetchColumn());
    }

    /** @return array{0:int,1:int,2:int} [összesített, telephelyi, WooCommerce] */
    private function state(Database $db, FakeWooStoreForEndToEndTest $store, int $p, int $loc, int $wcId): array
    {
        return [
            (int) $db->findProductById($p)['stock_qty'],
            (int) array_column($db->getLocationStockForProduct($p), 'stock_qty', 'location_id')[$loc],
            $store->stock[$wcId] ?? -1,
        ];
    }
}

class FakeWooStoreForEndToEndTest extends WooCommerceClient
{
    public array $stock = [];
    public array $name = [];
    public array $price = [];

    public function __construct()
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->stock[$wcProductId] = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
        $this->name[$wcProductId] = $fields['name'];
        $this->price[$wcProductId] = $fields['price'];
    }
}
