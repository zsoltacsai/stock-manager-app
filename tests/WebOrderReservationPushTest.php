<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-08 (correctness audit) regresszió — a WooCommerce felé küldött
 * abszolút készlet nem tartalmazhatja a WooCommerce által már lefoglalt,
 * helyben még csak draftként létező webes rendelés darabjait.
 *
 * Választott modell (a legkevesebb új állapottal): a draft státusz maga a
 * foglalás; a push = stock_qty − függő (draft) webes rendelések
 * mennyisége (Database::getPendingWebOrderQty()). A végpontok DB-lépéseit
 * (webhook.php, webshop-order-confirm.php, webshop-order-reject.php) itt
 * pontosan úgy hajtjuk végre, ahogy a végpontok; a HTTP-szintű bekötést
 * az InventorySyncEndToEndHttpTest ellenőrzi.
 */
final class WebOrderReservationPushTest extends TestCase
{
    private function product(Database $db, int $stock, int $wcId = 900, bool $sync = true): int
    {
        $id = $db->saveProduct(['name' => 'Webes termék ' . $wcId, 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
        $db->incrementStock($id, $stock);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = ? WHERE id = ?')->execute([$wcId, $sync ? 1 : 0, $id]);
        return $id;
    }

    /** webhook.php: draft + push-ütemezés egy tranzakcióban. */
    private function webOrder(Database $db, int $wcOrderId, array $lines): ?int
    {
        $items = array_map(fn ($l) => ['wc_product_id' => $l['wc'] ?? null, 'product_id' => $l['product_id'], 'name' => 'Webes tétel', 'qty' => $l['qty'], 'unit_price' => 1270, 'vat_rate' => '27', 'matched' => $l['product_id'] !== null], $lines);
        $db->beginTransaction();
        $draftId = $db->insertWebshopOrderDraft(['wc_order_id' => $wcOrderId, 'total' => 1270 * array_sum(array_column($lines, 'qty')), 'items' => $items]);
        if ($draftId !== null) {
            $db->enqueueWcPushForWebOrderItems($items, 'web_order', $draftId);
        }
        $db->commit();
        return $draftId;
    }

    /** webshop-order-confirm.php DB-lépései. */
    private function confirm(Database $db, int $draftId): int
    {
        $order = $db->getWebshopOrder($draftId);
        $db->beginTransaction();
        $this->assertTrue($db->claimDraftWebshopOrder($draftId));
        $saleId = $db->insertSale((float) $order['total'], 'Készpénz');
        $lines = [];
        foreach ($order['items'] as $item) {
            $line = ['product_id' => $item['product_id'], 'name' => $item['name'], 'qty' => (int) $item['qty'], 'unit_price' => (float) $item['unit_price'], 'vat_rate' => $item['vat_rate']];
            $db->insertSaleItem($saleId, $line);
            if ($item['product_id']) {
                $db->decrementStock((int) $item['product_id'], (int) $item['qty']);
            }
            $lines[] = $line;
        }
        $db->enqueueWcPushForWebOrderItems($lines, 'sale', $saleId);
        $db->setWebshopOrderSale($draftId, $saleId, 'Készpénz');
        $db->commit();
        return $saleId;
    }

    /** webshop-order-reject.php DB-lépései. */
    private function reject(Database $db, int $draftId): void
    {
        $order = $db->getWebshopOrder($draftId);
        $db->beginTransaction();
        $this->assertTrue($db->claimAndRejectDraftWebshopOrder($draftId));
        $db->enqueueWcPushForWebOrderItems($order['items'], 'web_reject', $draftId);
        $db->commit();
    }

    /** sale.php: POS-eladás + push-ütemezés. */
    private function posSale(Database $db, int $productId, int $qty, int $wcId = 900): void
    {
        $db->beginTransaction();
        $saleId = $db->insertSale(1270.0 * $qty, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'x', 'qty' => $qty, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($productId, $qty);
        $db->enqueueWcPush($productId, $wcId, 'sale', $saleId);
        $db->commit();
    }

    private function runWorker(Database $db, FakeWooStoreForReservationTest $store): array
    {
        return (new WcPushQueueWorker($db, $store))->processDuePushes(50);
    }

    public function testMandatoryScenarioPendingWebOrderIsNotGivenBackToWooCommerce(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForReservationTest([900 => 5]);

        $this->webOrder($db, 5001, [['product_id' => $p, 'qty' => 2]]);
        $store->stock[900] = 3; // a WooCommerce a rendeléskor maga levonja
        $this->posSale($db, $p, 1);
        $this->assertSame(4, (int) $db->findProductById($p)['stock_qty']);

        $this->runWorker($db, $store);

        $this->assertNotSame(4, $store->stock[900], 'A hibás (audit) érték 4 volt.');
        $this->assertSame(2, $store->stock[900], '5 − 2 (webes rendelés) − 1 (POS) = 2 ténylegesen eladható.');
    }

    public function testMultiplePendingOrdersAreAllReserved(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 10);
        $store = new FakeWooStoreForReservationTest([900 => 10]);
        $this->webOrder($db, 6001, [['product_id' => $p, 'qty' => 2]]);
        $this->webOrder($db, 6002, [['product_id' => $p, 'qty' => 3]]);
        $this->posSale($db, $p, 1);

        $this->runWorker($db, $store);
        $this->assertSame(4, $store->stock[900]);
        $this->assertSame(5, $db->getPendingWebOrderQty($p));
    }

    public function testConfirmationDoesNotDeductTwice(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForReservationTest([900 => 5]);
        $draftId = $this->webOrder($db, 7001, [['product_id' => $p, 'qty' => 2]]);
        $this->runWorker($db, $store);
        $this->assertSame(3, $store->stock[900]);

        $this->confirm($db, $draftId);
        $this->assertSame(3, (int) $db->findProductById($p)['stock_qty']);
        $this->assertSame(0, $db->getPendingWebOrderQty($p));
        $this->runWorker($db, $store);
        $this->assertSame(3, $store->stock[900], 'Megerősítés után sem vonódik le még egyszer.');
    }

    public function testRejectionReleasesTheReservation(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForReservationTest([900 => 3]);
        $draftId = $this->webOrder($db, 7101, [['product_id' => $p, 'qty' => 2]]);
        $this->runWorker($db, $store);
        $this->assertSame(3, $store->stock[900]);

        $this->reject($db, $draftId);
        $this->runWorker($db, $store);
        $this->assertSame(5, $store->stock[900]);
        $this->assertSame(5, (int) $db->findProductById($p)['stock_qty'], 'Elutasításkor a helyi készlet nem változik.');
    }

    public function testDuplicateWebhookIsReservedAndQueuedOnce(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $first = $this->webOrder($db, 8001, [['product_id' => $p, 'qty' => 2]]);
        $second = $this->webOrder($db, 8001, [['product_id' => $p, 'qty' => 2]]);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(2, $db->getPendingWebOrderQty($p));
        $this->assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'web_order'")->fetchColumn());
    }

    public function testConfirmingTheSameOrderTwiceIsImpossible(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $draftId = $this->webOrder($db, 8101, [['product_id' => $p, 'qty' => 2]]);
        $this->confirm($db, $draftId);

        $this->assertFalse($db->claimDraftWebshopOrder($draftId));
        $this->assertFalse($db->claimAndRejectDraftWebshopOrder($draftId));
        $this->assertSame(3, (int) $db->findProductById($p)['stock_qty']);
    }

    public function testWorkerRetryPushesFreshValueNotTheOriginalSnapshot(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForReservationTest([900 => 5]);
        $store->failures = [new WooCommerceRequestException('timeout', retryable: true)];

        $this->posSale($db, $p, 1);
        $summary = $this->runWorker($db, $store);
        $this->assertSame(1, $summary['retried']);
        $this->assertSame(5, $store->stock[900], 'A sikertelen kísérlet nem módosított.');

        // Közben webes rendelés érkezik és egy újabb POS-eladás.
        $this->webOrder($db, 9001, [['product_id' => $p, 'qty' => 2]]);
        $store->stock[900] = 2;
        $this->posSale($db, $p, 1);
        $db->pdo()->exec("UPDATE wc_push_queue SET next_attempt_at = NULL");

        $this->runWorker($db, $store);
        $this->assertSame(1, $store->stock[900], '5 − 1 − 1 (POS) − 2 (függő webes) = 1');
    }

    public function testPermanentQueueFailureThenManualRetryIsStillCorrect(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForReservationTest([900 => 3]);
        $store->failures = [new WooCommerceRequestException('bad request', retryable: false)];
        $this->webOrder($db, 9101, [['product_id' => $p, 'qty' => 2]]);

        $this->assertSame(1, $this->runWorker($db, $store)['permanent_failures']);
        $rowId = (int) $db->pdo()->query("SELECT id FROM wc_push_queue WHERE status = 'failed'")->fetchColumn();
        $this->assertTrue($db->resetWcPushForManualRetry($rowId));
        $this->posSale($db, $p, 1);
        $this->runWorker($db, $store);
        $this->assertSame(2, $store->stock[900]);
    }

    public function testMultipleLocationsDoNotAffectTheWooCommerceValue(): void
    {
        // A WooCommerce-szinkron telephely-független (összesített készlet).
        $db = tests_new_database();
        $p = $this->product($db, 0);
        $a = $db->saveLocation(['name' => 'A']);
        $b = $db->saveLocation(['name' => 'B']);
        $db->transferStock($p, null, $a, 3, null);
        $db->transferStock($p, null, $b, 4, null);
        $store = new FakeWooStoreForReservationTest([900 => 7]);
        $this->webOrder($db, 9201, [['product_id' => $p, 'qty' => 2]]);
        $this->posSale($db, $p, 1);

        $this->runWorker($db, $store);
        $this->assertSame(4, $store->stock[900]);
    }

    public function testUnmatchedAndUnsyncedLinesAreIgnored(): void
    {
        $db = tests_new_database();
        $synced = $this->product($db, 5, 900);
        $unsynced = $this->product($db, 5, 901, false);
        $this->webOrder($db, 9301, [
            ['product_id' => $synced, 'qty' => 1],
            ['product_id' => $unsynced, 'qty' => 1],
            ['product_id' => null, 'qty' => 4],
        ]);

        $this->assertSame(1, $db->getPendingWebOrderQty($synced));
        $queued = $db->pdo()->query("SELECT product_id FROM wc_push_queue WHERE trigger_type = 'web_order'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([$synced], array_map('intval', $queued));
    }

    public function testWebhookTransactionRollbackLeavesNoDraftAndNoPush(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $db->pdo()->exec("CREATE TRIGGER fail_push BEFORE INSERT ON wc_push_queue BEGIN SELECT RAISE(ABORT, 'szimulált hiba'); END");
        try {
            $this->webOrder($db, 9401, [['product_id' => $p, 'qty' => 2]]);
            $this->fail('A webhooknak el kell buknia.');
        } catch (PDOException $e) {
            $db->rollBack();
        }
        $this->assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM webshop_orders')->fetchColumn(), 'A WooCommerce újraküldi; nincs félkész draft.');
        $this->assertSame(0, $db->getPendingWebOrderQty($p));
    }
}

/**
 * Állapottartó hamis WooCommerce-bolt: updateStock() ABSZOLÚT értéket
 * állít (mint a valódi API), $failures sorban dobott kivételek.
 */
class FakeWooStoreForReservationTest extends WooCommerceClient
{
    public array $failures = [];
    public array $fieldPushes = [];

    public function __construct(public array $stock)
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $failure = array_shift($this->failures);
        if ($failure instanceof Throwable) {
            throw $failure;
        }
        $this->stock[$wcProductId] = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
        $this->fieldPushes[] = ['wc_product_id' => $wcProductId] + $fields;
    }
}
