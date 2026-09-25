<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-11 (correctness audit) regresszió — az import változásai eljutnak a
 * WooCommerce-be, és a pull nem írja vissza őket.
 *
 * Source-of-truth a meglévő kódból (nem új policy):
 *   - készlet: HELYI az irányadó — a pull meglévő terméknél sosem írja
 *     felül (upsertProductFromWc()), minden változás push-sal megy ki.
 *   - név/ár: kétirányú, "az utolsó módosító nyer" — a pull felülírja a
 *     helyit, a kézi szerkesztés (product-save.php) azonnal kiküldi. Az
 *     import eddig egyiket sem tette, ezért a következő pull visszaírta.
 * A javítás: az import (linkelt, szinkronizált termék változásakor) egy
 * 'import' sort tesz a meglévő wc_push_queue-ba; a worker ilyenkor a nevet
 * és az árat is kiküldi a push pillanatában friss helyi értékkel; amíg ez a
 * push célba nem ért, a pull nem írja vissza a nevet/árat.
 */
final class ImportWooCommerceSyncTest extends TestCase
{
    private function linked(Database $db, string $barcode, int $wcId, int $stock = 10, bool $sync = true): int
    {
        $id = $db->saveProduct(['name' => "Régi név $wcId", 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => $barcode]);
        $db->incrementStock($id, $stock);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = ? WHERE id = ?')->execute([$wcId, $sync ? 1 : 0, $id]);
        return $id;
    }

    private function row(string $barcode, string $name, float $price, int $stock): array
    {
        return ['name' => $name, 'unit' => 'db', 'group_name' => '', 'cikkszam' => '', 'barcode' => $barcode, 'currency' => 'HUF',
            'vat_rate' => '27', 'net_price' => round($price / 1.27, 2), 'price' => $price, 'notes' => '', 'stock_qty' => $stock, 'purchase_price_net' => 500];
    }

    private function wcPull(Database $db, int $wcId, string $barcode, string $name, float $price): void
    {
        $db->upsertProductFromWc(['wc_product_id' => $wcId, 'sku' => $barcode, 'barcode' => $barcode, 'name' => $name, 'price' => $price, 'stock_qty' => 999]);
    }

    private function queued(Database $db, string $trigger = 'import'): array
    {
        return $db->pdo()->query("SELECT product_id, status FROM wc_push_queue WHERE trigger_type = '$trigger' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function work(Database $db, FakeWooStoreForImportTest $store): array
    {
        return (new WcPushQueueWorker($db, $store))->processDuePushes(50);
    }

    public function testImportOfExistingLinkedProductQueuesPushOfStockNameAndPrice(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002001', 950);
        $store = new FakeWooStoreForImportTest();

        $result = $db->importUpsertProduct($this->row('5990000002001', 'Új név', 1500.0, 25), 111);
        $this->assertTrue($result['wc_push_queued']);
        $this->assertSame([['product_id' => (string) $p, 'status' => 'queued']], array_map(fn ($r) => ['product_id' => (string) $r['product_id'], 'status' => $r['status']], $this->queued($db)));

        $this->work($db, $store);
        $this->assertSame(25, $store->stock[950]);
        $this->assertSame([['wc_product_id' => 950, 'name' => 'Új név', 'price' => 1500.0]], array_map(fn ($f) => ['wc_product_id' => $f['wc_product_id'], 'name' => $f['name'], 'price' => (float) $f['price']], $store->fieldPushes));
    }

    public function testStockOnlyChangeAlsoQueues(): void
    {
        $db = tests_new_database();
        $this->linked($db, '5990000002002', 951);
        $db->importUpsertProduct($this->row('5990000002002', 'Régi név 951', 1270.0, 3), 112);
        $this->assertCount(1, $this->queued($db));
    }

    public function testUnchangedImportQueuesNothing(): void
    {
        $db = tests_new_database();
        $this->linked($db, '5990000002003', 952);
        $result = $db->importUpsertProduct($this->row('5990000002003', 'Régi név 952', 1270.0, 10), 113);
        $this->assertFalse($result['wc_push_queued']);
        $this->assertSame([], $this->queued($db));
    }

    public function testUnlinkedAndUnsyncedProductsQueueNothing(): void
    {
        $db = tests_new_database();
        $db->saveProduct(['name' => 'Csak helyi', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1, 'price' => 1, 'barcode' => '5990000002004']);
        $this->linked($db, '5990000002005', 953, 10, false);

        $db->importUpsertProduct($this->row('5990000002004', 'Csak helyi 2', 100.0, 7), 114);
        $db->importUpsertProduct($this->row('5990000002005', 'Kikapcsolt', 100.0, 7), 114);
        $this->assertSame([], $this->queued($db));
    }

    public function testImportedNewProductIsNotPushedAndLinkingPullKeepsTheExistingPolicy(): void
    {
        // Egy importtal LÉTREHOZOTT termék még nincs WooCommerce-hez kötve →
        // nincs mit kiküldeni. Ha egy későbbi pull vonalkód alapján összeköti,
        // a meglévő összekötési szabály érvényes (a WC név/ár kerül át, a
        // helyi készlet megmarad) — ezt a javítás nem változtatja.
        $db = tests_new_database();
        $result = $db->importUpsertProduct($this->row('5990000002006', 'Importált', 800.0, 4), 115);
        $this->assertSame('inserted', $result['action']);
        $this->assertSame([], $this->queued($db));

        $this->wcPull($db, 954, '5990000002006', 'WC név', 900.0);
        $product = $db->findProductByBarcode('5990000002006');
        $this->assertSame([954, 'WC név', 4], [(int) $product['wc_product_id'], $product['name'], (int) $product['stock_qty']]);
    }

    public function testPullBeforePushDoesNotRevertImportedNameAndPrice(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002007', 955);
        $db->importUpsertProduct($this->row('5990000002007', 'Importált név', 1990.0, 10), 116);

        // Az auto-sync pull a push ELŐTT fut, még a régi WC-adatokkal.
        $this->wcPull($db, 955, '5990000002007', 'Régi név 955', 1270.0);
        $product = $db->findProductById($p);
        $this->assertSame(['Importált név', 1990.0], [$product['name'], (float) $product['price']], 'Korábban itt visszaállt a régi név/ár.');

        $store = new FakeWooStoreForImportTest();
        $this->work($db, $store);
        $this->assertSame('Importált név', $store->fieldPushes[0]['name']);
        $this->assertSame(1990.0, (float) $store->fieldPushes[0]['price']);
    }

    public function testPushThenPullConvergesAndLaterWooCommerceEditsStillApply(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002008', 956);
        $db->importUpsertProduct($this->row('5990000002008', 'Szinkron név', 2000.0, 10), 117);
        $this->work($db, new FakeWooStoreForImportTest());
        $this->assertFalse($db->hasPendingWcFieldPush($p));

        // A WC most már ugyanazt adja vissza → nincs oda-vissza felülírás.
        $this->wcPull($db, 956, '5990000002008', 'Szinkron név', 2000.0);
        $this->assertSame('Szinkron név', $db->findProductById($p)['name']);
        // Egy később a WooCommerce-ben végzett módosítás a meglévő szabály szerint átjön.
        $this->wcPull($db, 956, '5990000002008', 'Webshopban átírt', 2100.0);
        $this->assertSame(['Webshopban átírt', 2100.0], [$db->findProductById($p)['name'], (float) $db->findProductById($p)['price']]);
        $this->assertSame([], array_filter($this->queued($db), fn ($r) => $r['status'] !== 'done'), 'A pull nem ütemez új pusht — nincs végtelen ciklus.');
    }

    public function testImportFollowedByPosSalePushesFreshStock(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002009', 957);
        $db->importUpsertProduct($this->row('5990000002009', 'Régi név 957', 1270.0, 20), 118);
        $db->beginTransaction();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $p, 'name' => 'x', 'qty' => 3, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($p, 3);
        $db->enqueueWcPush($p, 957, 'sale', $saleId);
        $db->commit();

        $store = new FakeWooStoreForImportTest();
        $this->work($db, $store);
        $this->assertSame(17, $store->stock[957]);
    }

    public function testQueueRetryKeepsProtectionAndPushesTheSameFreshValues(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002010', 958);
        $db->importUpsertProduct($this->row('5990000002010', 'Retry név', 1111.0, 6), 119);
        $store = new FakeWooStoreForImportTest();
        $store->fieldFailures = [new WooCommerceRequestException('timeout', retryable: true)];

        $this->assertSame(1, $this->work($db, $store)['retried']);
        $this->assertArrayNotHasKey(958, $store->stock, 'A mezők hibájakor a készlet sem ment ki félig.');
        $this->assertTrue($db->hasPendingWcFieldPush($p));
        $this->wcPull($db, 958, '5990000002010', 'Régi név 958', 1270.0);
        $this->assertSame('Retry név', $db->findProductById($p)['name']);

        $db->pdo()->exec('UPDATE wc_push_queue SET next_attempt_at = NULL');
        $this->work($db, $store);
        $this->assertSame(['Retry név', 6], [$store->fieldPushes[0]['name'], $store->stock[958]]);
        $this->assertFalse($db->hasPendingWcFieldPush($p));
    }

    public function testRepeatedEnqueueForTheSameImportIsIdempotent(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002011', 959);
        $db->importUpsertProduct($this->row('5990000002011', 'A', 1.0, 1), 120);
        $this->assertNull($db->enqueueWcPush($p, 959, 'import', 120));
        $this->assertCount(1, $this->queued($db));
    }

    public function testMultipleLinkedProductsEachGetOnePush(): void
    {
        $db = tests_new_database();
        foreach ([960, 961, 962] as $i => $wcId) {
            $this->linked($db, '599000000303' . $i, $wcId);
            $db->importUpsertProduct($this->row('599000000303' . $i, "Új $wcId", 100.0 + $i, 5 + $i), 121);
        }
        $store = new FakeWooStoreForImportTest();
        $this->work($db, $store);
        $this->assertSame([960 => 5, 961 => 6, 962 => 7], $store->stock);
        $this->assertCount(3, $store->fieldPushes);
    }

    public function testImportTransactionRollbackLeavesNoQueueRow(): void
    {
        $db = tests_new_database();
        $p = $this->linked($db, '5990000002012', 963);
        $db->beginTransaction();
        $db->importUpsertProduct($this->row('5990000002012', 'Visszagörgetett', 1.0, 1), 122);
        $db->rollBack();

        $this->assertSame([], $this->queued($db));
        $this->assertSame('Régi név 963', $db->findProductById($p)['name']);
    }
}

class FakeWooStoreForImportTest extends WooCommerceClient
{
    public array $stock = [];
    public array $fieldPushes = [];
    public array $fieldFailures = [];

    public function __construct()
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->stock[$wcProductId] = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
        $failure = array_shift($this->fieldFailures);
        if ($failure instanceof Throwable) {
            throw $failure;
        }
        $this->fieldPushes[] = ['wc_product_id' => $wcProductId] + $fields;
    }
}
