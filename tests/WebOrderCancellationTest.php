<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * P-D regresszió — a WooCommerce-ben lemondott/visszatérített/sikertelen
 * webes rendelés foglalása nem marad örökre (B-08: a draft maga a foglalás).
 *
 * Meglévő státuszok (nincs új): webshop_orders.status draft = foglalás,
 * confirmed = helyi eladás lett, rejected = nem teljesül; wc_status = a
 * WooCommerce utolsó ismert státusza. A webhook a 'cancelled' / 'refunded' /
 * 'failed' státuszra Database::applyWebOrderTermination()-t hív: draft →
 * rejected (atomikus, ugyanaz a claim, mint a kézi elutasításé), confirmed →
 * a helyi eladás/készlet marad (nincs vak visszatöltés), rejected → no-op.
 * A WooCommerce egy állapottartó hamis bolt (abszolút készletet állít), a
 * push a valódi WcPushQueueWorker-en megy.
 */
final class WebOrderCancellationTest extends TestCase
{
    private const WC_ID = 4400;

    private function product(Database $db, int $stock): int
    {
        $id = $db->saveProduct(['name' => 'PD termék', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
        $db->incrementStock($id, $stock);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([self::WC_ID, $id]);
        return $id;
    }

    /** webhook.php 'processing' ága. */
    private function webOrder(Database $db, int $wcOrderId, int $productId, int $qty): ?int
    {
        $items = [['wc_product_id' => self::WC_ID, 'product_id' => $productId, 'name' => 'PD', 'qty' => $qty, 'unit_price' => 1270, 'vat_rate' => '27', 'matched' => true]];
        $db->beginTransaction();
        $draftId = $db->insertWebshopOrderDraft(['wc_order_id' => $wcOrderId, 'wc_status' => 'processing', 'total' => 1270 * $qty, 'items' => $items]);
        if ($draftId !== null) {
            $db->enqueueWcPushForWebOrderItems($items, 'web_order', $draftId);
        }
        $db->commit();
        return $draftId;
    }

    /** webshop-order-confirm.php DB-lépései. */
    private function confirm(Database $db, int $draftId): bool
    {
        $order = $db->getWebshopOrder($draftId);
        $db->beginTransaction();
        if (!$db->claimDraftWebshopOrder($draftId)) {
            $db->rollBack();
            return false;
        }
        $saleId = $db->insertSale((float) $order['total'], 'Utánvét');
        foreach ($order['items'] as $item) {
            $db->insertSaleItem($saleId, ['product_id' => $item['product_id'], 'name' => $item['name'], 'qty' => (int) $item['qty'], 'unit_price' => (float) $item['unit_price'], 'vat_rate' => $item['vat_rate']]);
            $db->decrementStock((int) $item['product_id'], (int) $item['qty']);
        }
        $db->enqueueWcPushForWebOrderItems($order['items'], 'sale', $saleId);
        $db->setWebshopOrderSale($draftId, $saleId, 'Utánvét');
        $db->commit();
        return true;
    }

    private function posSale(Database $db, int $productId, int $qty): void
    {
        $db->beginTransaction();
        $saleId = $db->insertSale(1270.0 * $qty, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'PD', 'qty' => $qty, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($productId, $qty);
        $db->enqueueWcPush($productId, self::WC_ID, 'sale', $saleId);
        $db->commit();
    }

    private function push(Database $db, FakeWooStoreForCancellationTest $store): void
    {
        (new WcPushQueueWorker($db, $store))->processDuePushes(50);
    }

    private function local(Database $db, int $p): int
    {
        return (int) $db->findProductById($p)['stock_qty'];
    }

    // ------------------------------------------------------------------
    // A spec E2E-forgatókönyve
    // ------------------------------------------------------------------

    public function testEndToEndCancellationReleasesTheReservationExactlyOnce(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForCancellationTest(5);

        $draftId = $this->webOrder($db, 91001, $p, 2);          // 1–2.
        $store->stock -= 2;                                     // a WooCommerce levon
        $this->assertSame(2, $db->getPendingWebOrderQty($p));   // 3. foglalás
        $this->posSale($db, $p, 1);                             // 4.
        $this->assertSame(2, $this->local($db, $p) - $db->getPendingWebOrderQty($p)); // 5.
        $this->push($db, $store);
        $this->assertSame(2, $store->stock);                    // 6.

        $store->stock += 2;                                     // 7. a WooCommerce lemondáskor visszatölt
        $result = $db->applyWebOrderTermination(91001, 'cancelled');
        $this->assertSame('released', $result['outcome']);
        $this->assertSame(0, $db->getPendingWebOrderQty($p));   // 8.
        $this->push($db, $store);                               // 9.

        $this->assertSame([4, 4], [$this->local($db, $p), $store->stock], '10. konzisztens végállapot');
        $order = $db->getWebshopOrder($draftId);
        $this->assertSame(['rejected', 'cancelled'], [$order['status'], $order['wc_status']]);

        // Duplikált lemondás-webhook: nincs dupla felszabadítás, nincs új push.
        $queueBefore = (int) $db->pdo()->query('SELECT COUNT(*) FROM wc_push_queue')->fetchColumn();
        $this->assertSame('already_released', $db->applyWebOrderTermination(91001, 'cancelled')['outcome']);
        $this->assertSame($queueBefore, (int) $db->pdo()->query('SELECT COUNT(*) FROM wc_push_queue')->fetchColumn());
        $this->assertSame([4, 0], [$this->local($db, $p), $db->getPendingWebOrderQty($p)]);
        $this->assertFalse($this->confirm($db, $draftId), 'Lemondott rendelés nem adható le.');
    }

    public function testEndToEndConfirmationVariant(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForCancellationTest(5);
        $draftId = $this->webOrder($db, 91002, $p, 2);
        $store->stock -= 2;
        $this->posSale($db, $p, 1);
        $this->push($db, $store);
        $this->assertSame(2, $store->stock);

        $this->assertTrue($this->confirm($db, $draftId));
        $this->push($db, $store);
        $this->assertSame([2, 2, 0], [$this->local($db, $p), $store->stock, $db->getPendingWebOrderQty($p)]);
    }

    public function testEndToEndCancellationAfterConfirmationDoesNotBlindlyRestoreLocalStock(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $store = new FakeWooStoreForCancellationTest(5);
        $draftId = $this->webOrder($db, 91003, $p, 2);
        $store->stock -= 2;
        $this->posSale($db, $p, 1);
        $this->assertTrue($this->confirm($db, $draftId));
        $this->push($db, $store);
        $this->assertSame([2, 2], [$this->local($db, $p), $store->stock]);
        $saleId = (int) $db->getWebshopOrder($draftId)['sale_id'];

        $store->stock += 2; // a WooCommerce a lemondáskor maga visszatölt
        $result = $db->applyWebOrderTermination(91003, 'cancelled');
        $this->assertSame(['confirmed_kept', $saleId], [$result['outcome'], $result['sale_id']]);
        $this->push($db, $store);

        $this->assertSame(2, $this->local($db, $p), 'Nincs vak helyi visszatöltés — ez visszáru-teendő.');
        $this->assertSame(2, $store->stock, 'A WooCommerce a helyi (irányadó) készletre korrigálódik — nincs túladás.');
        $this->assertNotNull($db->getSaleWithItems($saleId), 'A helyi eladás megmarad.');
        $order = $db->getWebshopOrder($draftId);
        $this->assertSame(['confirmed', 'cancelled'], [$order['status'], $order['wc_status']]);
    }

    // ------------------------------------------------------------------
    // Egyedi esetek
    // ------------------------------------------------------------------

    public function testRefundAfterConfirmationKeepsTheSale(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $draftId = $this->webOrder($db, 92001, $p, 1);
        $this->confirm($db, $draftId);

        $this->assertSame('confirmed_kept', $db->applyWebOrderTermination(92001, 'refunded')['outcome']);
        $this->assertSame(4, $this->local($db, $p));
    }

    public function testFailedPaymentBeforeConfirmationReleases(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $this->webOrder($db, 92002, $p, 3);

        $this->assertSame('released', $db->applyWebOrderTermination(92002, 'failed')['outcome']);
        $this->assertSame([5, 0], [$this->local($db, $p), $db->getPendingWebOrderQty($p)]);
    }

    public function testWebhookReplayOfTheOriginalOrderAfterCancellationDoesNotReserveAgain(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $this->webOrder($db, 92003, $p, 2);
        $db->applyWebOrderTermination(92003, 'cancelled');

        $this->assertNull($this->webOrder($db, 92003, $p, 2), 'Ugyanaz a rendelés nem foglal kétszer.');
        $this->assertSame(0, $db->getPendingWebOrderQty($p));
    }

    public function testCancellationOfAnUnknownOrderChangesNothing(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $this->assertSame('unknown', $db->applyWebOrderTermination(99999, 'cancelled')['outcome']);
        $this->assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM wc_push_queue')->fetchColumn());
        $this->assertSame(5, $this->local($db, $p));
    }

    public function testOnlyTheCancelledOrderIsReleased(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 10);
        $this->webOrder($db, 92004, $p, 2);
        $this->webOrder($db, 92005, $p, 3);
        $db->applyWebOrderTermination(92004, 'cancelled');
        $this->assertSame(3, $db->getPendingWebOrderQty($p));
    }

    public function testTerminationRollsBackCompletelyOnFailure(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $draftId = $this->webOrder($db, 92006, $p, 2);
        $db->pdo()->exec("CREATE TRIGGER pd_fail BEFORE INSERT ON wc_push_queue WHEN NEW.trigger_type = 'web_cancel' BEGIN SELECT RAISE(ABORT, 'szimulált hiba'); END");

        try {
            $db->applyWebOrderTermination(92006, 'cancelled');
            $this->fail('A lemondásnak el kell buknia.');
        } catch (PDOException $e) {
            // várt — a webhook 5xx-et ad, a WooCommerce újraküldi
        }
        $order = $db->getWebshopOrder($draftId);
        $this->assertSame(['draft', 'processing'], [$order['status'], $order['wc_status']], 'Se státusz, se wc_status nem változott félig.');
        $this->assertSame(2, $db->getPendingWebOrderQty($p));

        $db->pdo()->exec('DROP TRIGGER pd_fail');
        $this->assertSame('released', $db->applyWebOrderTermination(92006, 'cancelled')['outcome'], 'Az újraküldött webhook rendben lefut.');
    }

    public function testConfirmationCancellationRaceAcrossRealProcessesHasExactlyOneWinner(): void
    {
        $db = tests_new_database();
        $p = $this->product($db, 5);
        $draftId = $this->webOrder($db, 93001, $p, 2);
        $prop = new ReflectionProperty(Database::class, 'dbConfig');
        $prop->setAccessible(true);
        $path = $prop->getValue($db)['sqlite']['path'];

        $script = sys_get_temp_dir() . '/sm_pd_race_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';
$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');
[$_, $path, $mode, $draftId, $productId] = $argv;
for ($attempt = 0; $attempt < 50; $attempt++) {
    try {
        if ($mode === "cancel") {
            echo $db->applyWebOrderTermination(93001, "cancelled")["outcome"]; exit;
        }
        $order = $db->getWebshopOrder((int) $draftId);
        $db->beginTransaction();
        if (!$db->claimDraftWebshopOrder((int) $draftId)) { $db->rollBack(); echo "confirm_lost"; exit; }
        $saleId = $db->insertSale(2540.0, "Utánvét");
        $db->insertSaleItem($saleId, ["product_id" => (int) $productId, "name" => "PD", "qty" => 2, "unit_price" => 1270, "vat_rate" => "27"]);
        $db->decrementStock((int) $productId, 2);
        $db->setWebshopOrderSale((int) $draftId, $saleId, "Utánvét");
        $db->commit();
        echo "confirmed"; exit;
    } catch (Throwable $e) { $db->rollBack(); usleep(20000); }
}
echo "E";');

        $procs = [];
        $pipes = [];
        foreach (['confirm', 'cancel', 'confirm', 'cancel', 'confirm', 'cancel'] as $i => $mode) {
            $procs[$i] = proc_open([PHP_BINARY, $script, $path, $mode, (string) $draftId, (string) $p], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $proc) {
            $out[] = trim(stream_get_contents($pipes[$i][1]));
            proc_close($proc);
        }
        @unlink($script);

        $this->assertNotContains('E', $out, implode(',', $out));
        $winners = count(array_keys($out, 'confirmed', true)) + count(array_keys($out, 'released', true));
        $this->assertSame(1, $winners, 'Pontosan egy leadás VAGY egy felszabadítás nyer: ' . implode(',', $out));
        $order = $db->getWebshopOrder($draftId);
        if ($order['status'] === 'confirmed') {
            $this->assertSame(3, $this->local($db, $p), 'Leadás nyert: pontosan egyszer vonódott le.');
        } else {
            $this->assertSame(['rejected', 5], [$order['status'], $this->local($db, $p)], 'Lemondás nyert: nincs levonás.');
        }
        $this->assertSame(0, $db->getPendingWebOrderQty($p), 'Nincs beragadt vagy negatív foglalás.');
    }
}

class FakeWooStoreForCancellationTest extends WooCommerceClient
{
    public function __construct(public int $stock)
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->stock = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
    }
}
