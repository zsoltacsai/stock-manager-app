<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N-2 / N-3 adatbázis-szintű regresszió: az idempotencia-kulcs a mozgatás /
 * visszáru SAJÁT sorában, UGYANABBAN a tranzakcióban, mint minden
 * mellékhatása. Egy második, ugyanazzal a kulccsal érkező végrehajtás a
 * kulcs-ütközésnél elbukik, MIELŐTT bármilyen mellékhatás megmaradna;
 * visszagörgetés után nem marad kulcs. A többfolyamatos (valódi párhuzamos)
 * bizonyítás: ConcurrencyLifecycleHttpTest.
 */
final class TransferAndReturnIdempotencyTest extends TestCase
{
    private function linkedProduct(Database $db, int $stock): array
    {
        $wcId = random_int(100000, 999999);
        $p = $db->saveProduct(['name' => 'Idem ' . bin2hex(random_bytes(2)), 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
        if ($stock > 0) {
            $db->incrementStock($p, $stock);
        }
        $db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([$wcId, $p]);
        return [$p, $wcId];
    }

    private function scalar(Database $db, string $sql, array $params = []): int
    {
        $stmt = $db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function locStock(Database $db, int $p, int $loc): int
    {
        return (int) (array_column($db->getLocationStockForProduct($p), 'stock_qty', 'location_id')[$loc] ?? 0);
    }

    // ------------------------------------------------------------------
    // N-2
    // ------------------------------------------------------------------

    public function testNewStockTransferWithAKeyExecutesOnceAndTheSecondAttemptChangesNothing(): void
    {
        $db = tests_new_database();
        [$p] = $this->linkedProduct($db, 0);
        $loc = $db->saveLocation(['name' => 'N2 cél']);

        $id = $db->transferStock($p, null, $loc, 10, null, 'n2-db-key', 'fp');
        $this->assertSame($id, (int) $db->findStockTransferByIdempotencyKey('n2-db-key')['id']);

        try {
            $db->transferStock($p, null, $loc, 10, null, 'n2-db-key', 'fp');
            $this->fail('Ugyanaz a kulcs nem hajthat végre második mozgatást.');
        } catch (PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertSame(10, (int) $db->findProductById($p)['stock_qty'], 'Nem 20.');
        $this->assertSame(10, $this->locStock($db, $p, $loc));
        $this->assertSame(1, $this->scalar($db, 'SELECT COUNT(*) FROM stock_transfers WHERE product_id = ?', [$p]));
        $this->assertSame(1, $this->scalar($db, "SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ? AND trigger_type = 'transfer'", [$p]), 'Pontosan egy WooCommerce-push.');
    }

    public function testLocationToLocationTransferWithAKeyExecutesOnceAndQueuesNothing(): void
    {
        $db = tests_new_database();
        [$p] = $this->linkedProduct($db, 0);
        $a = $db->saveLocation(['name' => 'N2 A']);
        $b = $db->saveLocation(['name' => 'N2 B']);
        $db->transferStock($p, null, $a, 10, null);
        $queuedBefore = $this->scalar($db, 'SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ?', [$p]);

        $db->transferStock($p, $a, $b, 4, null, 'n2-l2l', 'fp');
        try {
            $db->transferStock($p, $a, $b, 4, null, 'n2-l2l', 'fp');
            $this->fail('Második mozgatás ugyanazzal a kulccsal.');
        } catch (PDOException $e) {
        }
        $this->assertSame([6, 4, 10], [$this->locStock($db, $p, $a), $this->locStock($db, $p, $b), (int) $db->findProductById($p)['stock_qty']]);
        $this->assertSame($queuedBefore, $this->scalar($db, 'SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ?', [$p]), 'Telephelyek közötti mozgatás nem változtat az összesítetten — nincs push.');
    }

    public function testRolledBackTransferLeavesNoKeyAndTheSameKeyCanExecuteLater(): void
    {
        $db = tests_new_database();
        [$p] = $this->linkedProduct($db, 0);
        $a = $db->saveLocation(['name' => 'N2 rb A']);
        $b = $db->saveLocation(['name' => 'N2 rb B']);
        $db->transferStock($p, null, $a, 2, null);

        try {
            $db->transferStock($p, $a, $b, 5, null, 'n2-rb', 'fp');
            $this->fail('Nincs elég forrás-készlet.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(PDOException::class, $e);
        }
        $this->assertNull($db->findStockTransferByIdempotencyKey('n2-rb'), 'Visszagörgetés után nem maradhat kulcs.');
        $this->assertSame([2, 0], [$this->locStock($db, $p, $a), $this->locStock($db, $p, $b)]);

        $db->transferStock($p, null, $a, 3, null);
        $db->transferStock($p, $a, $b, 5, null, 'n2-rb', 'fp');
        $this->assertSame([0, 5], [$this->locStock($db, $p, $a), $this->locStock($db, $p, $b)]);
    }

    public function testTransfersWithoutAKeyKeepTheOldBehaviour(): void
    {
        $db = tests_new_database();
        [$p] = $this->linkedProduct($db, 0);
        $loc = $db->saveLocation(['name' => 'N2 kulcs nélkül']);
        $db->transferStock($p, null, $loc, 1, null);
        $db->transferStock($p, null, $loc, 1, null, '', null);
        $this->assertSame(2, (int) $db->findProductById($p)['stock_qty']);
        $this->assertNull($db->findStockTransferByIdempotencyKey(''));
    }

    // ------------------------------------------------------------------
    // N-3
    // ------------------------------------------------------------------

    /** Teljes visszáruval minden mellékhatás megmozdul: készlet, telephely, hűségpont, költés, kupon, utalvány, WooCommerce-queue. */
    private function saleWithEverySideEffect(Database $db): array
    {
        [$p] = $this->linkedProduct($db, 5);
        $loc = $db->saveLocation(['name' => 'N3 bolt']);
        $customer = $db->saveCustomer(['name' => 'N3 Vevő']);
        $card = $db->issueGiftCard('N3' . strtoupper(bin2hex(random_bytes(3))), 1000.0, null, null);
        $db->pdo()->exec("INSERT INTO coupons (code, type, value, is_active, times_used, created_at) VALUES ('N3" . bin2hex(random_bytes(3)) . "', 'percent', 10, 1, 1, '2026-01-01 00:00:00')");
        $coupon = (int) $db->pdo()->lastInsertId();

        $saleId = $db->insertSale(1540.0, 'Készpénz', null, $customer, 25, 0, $coupon, 0.0, 1000.0, null, null, null, null, $loc);
        $db->insertSaleItem($saleId, ['product_id' => $p, 'name' => 'N3', 'qty' => 2, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($p, 2);
        $db->applyLoyaltyPoints($customer, 25, $saleId, 'N3 eladás');
        $db->addCustomerSpend($customer, 2540.0);
        $db->redeemGiftCard($card, 1000.0, $saleId);

        return compact('p', 'loc', 'customer', 'card', 'coupon', 'saleId');
    }

    private function sideEffectState(Database $db, array $f): array
    {
        $c = $db->findCustomerById($f['customer']);
        return [
            'stock'    => (int) $db->findProductById($f['p'])['stock_qty'],
            'returns'  => $this->scalar($db, 'SELECT COUNT(*) FROM returns WHERE sale_id = ?', [$f['saleId']]),
            'items'    => $this->scalar($db, 'SELECT COALESCE(SUM(ri.qty), 0) FROM return_items ri JOIN returns r ON r.id = ri.return_id WHERE r.sale_id = ?', [$f['saleId']]),
            'points'   => (int) $c['loyalty_points'],
            'spent'    => (float) $c['total_spent'],
            'coupon'   => $this->scalar($db, 'SELECT times_used FROM coupons WHERE id = ?', [$f['coupon']]),
            'card'     => (float) $db->pdo()->query('SELECT current_balance FROM gift_cards WHERE id = ' . $f['card'])->fetchColumn(),
            'card_tx'  => $this->scalar($db, 'SELECT COUNT(*) FROM gift_card_transactions WHERE sale_id = ? AND amount_delta > 0', [$f['saleId']]),
            'wc_queue' => $this->scalar($db, "SELECT COUNT(*) FROM wc_push_queue WHERE product_id = ? AND trigger_type = 'return'", [$f['p']]),
        ];
    }

    private function fullReturn(Database $db, array $f, ?string $key): int
    {
        $sale = $db->getSaleWithItems($f['saleId']);
        $si = $sale['items'][0];
        return $db->processReturn($f['saleId'], [['sale_item_id' => (int) $si['id'], 'product_id' => $f['p'], 'name' => 'N3', 'qty' => 2, 'unit_price' => 1270.0]], 'N3', null, 1540.0, $sale, null, $key, $key !== null ? 'fp-' . $key : null);
    }

    public function testSameKeyReturnHappensOnceWithEverySideEffectExactlyOnce(): void
    {
        $db = tests_new_database();
        $f = $this->saleWithEverySideEffect($db);

        $returnId = $this->fullReturn($db, $f, 'n3-db-key');
        $after = $this->sideEffectState($db, $f);
        $this->assertSame(['stock' => 5, 'returns' => 1, 'items' => 2, 'points' => 0, 'spent' => 0.0, 'coupon' => 0, 'card' => 1000.0, 'card_tx' => 1, 'wc_queue' => 1], $after);
        $this->assertSame($returnId, (int) $db->findReturnByIdempotencyKey('n3-db-key')['id']);

        try {
            $this->fullReturn($db, $f, 'n3-db-key');
            $this->fail('Ugyanaz a kulcs nem hajthat végre második visszárut.');
        } catch (RuntimeException $e) {
            // A kulcsos returns-sor az ELSŐ írás: a UNIQUE-ütközés a mennyiségi
            // védelem (F-03) előtt állítja meg — a végpont a győztest játssza vissza.
            $this->assertInstanceOf(PDOException::class, $e);
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertSame($after, $this->sideEffectState($db, $f), 'A második kísérlet semmilyen mellékhatást nem hagyott.');
    }

    public function testSameKeyPartialReturnIsStoppedByTheKeyNotByTheQuantityRule(): void
    {
        $db = tests_new_database();
        $f = $this->saleWithEverySideEffect($db);
        $sale = $db->getSaleWithItems($f['saleId']);
        $item = [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => $f['p'], 'name' => 'N3', 'qty' => 1, 'unit_price' => 1270.0]];

        $db->processReturn($f['saleId'], $item, 'N3 részleges', null, 1270.0, $sale, null, 'n3-part', 'fp');
        $after = $this->sideEffectState($db, $f);
        try {
            // A mennyiségi szabály még engedne egy darabot — csak a kulcs állítja meg.
            $db->processReturn($f['saleId'], $item, 'N3 részleges', null, 1270.0, $sale, null, 'n3-part', 'fp');
            $this->fail('Ugyanaz a kulcs.');
        } catch (PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertSame($after, $this->sideEffectState($db, $f));
        $this->assertSame(['stock' => 4, 'returns' => 1, 'points' => 25, 'coupon' => 1, 'card' => 0.0], array_intersect_key($after, array_flip(['stock', 'returns', 'points', 'coupon', 'card'])), 'Részleges visszáru nem pörgeti vissza a kedvezményeket.');
    }

    public function testRolledBackReturnLeavesNoKeyAndNoHalfState(): void
    {
        $db = tests_new_database();
        $f = $this->saleWithEverySideEffect($db);
        $this->fullReturn($db, $f, null); // mindent visszavettek
        $before = $this->sideEffectState($db, $f);

        try {
            $this->fullReturn($db, $f, 'n3-rb');
            $this->fail('A mennyiségi védelem (F-03) elutasít.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(PDOException::class, $e);
        }
        $this->assertNull($db->findReturnByIdempotencyKey('n3-rb'));
        $this->assertSame($before, $this->sideEffectState($db, $f));
    }

    public function testDistinctKeysAreDecidedByTheQuantityRule(): void
    {
        $db = tests_new_database();
        $f = $this->saleWithEverySideEffect($db);
        $this->fullReturn($db, $f, 'n3-k1');
        try {
            $this->fullReturn($db, $f, 'n3-k2');
            $this->fail('Más kulccsal sem vihető vissza többször ugyanaz a mennyiség.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vihető vissza', $e->getMessage());
        }
        $this->assertSame(1, $this->sideEffectState($db, $f)['returns']);
    }
}
