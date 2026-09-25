<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-04 (correctness audit) regresszió — Database::reverseSaleBenefits().
 *
 * A kupon-felhasználás visszaírása skalár MAX(0, times_used - 1)-et
 * használt, ami csak SQLite-ban létezik: MySQL-en minden kuponos eladás
 * teljes visszárúja elbukott. A javítás driver-függő (MySQL: GREATEST).
 * Ez a fájl a futtatható SQLite viselkedést rögzíti (0 alá sosem megy,
 * egyszeri, atomikus) — a MySQL-ág SQL-jét a MysqlDialectRegressionTest
 * ellenőrzi.
 */
final class ReturnCouponReversalTest extends TestCase
{
    /**
     * @return array{db: Database, productId: int, couponId: int, saleId: int}
     */
    private function couponSale(int $timesUsedBeforeSale = 0, int $qty = 1): array
    {
        $db = tests_new_database();
        $productId = $db->saveProduct([
            'name' => 'Kuponos termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'barcode' => null,
        ]);
        $db->incrementStock($productId, 10);
        $couponId = $db->saveCoupon(['code' => 'B04', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);
        for ($i = 0; $i < $timesUsedBeforeSale; $i++) {
            $db->incrementCouponUsage($couponId);
        }

        $db->incrementCouponUsage($couponId); // maga az eladás
        $saleId = $db->insertSale(1270.0 * $qty - 100, 'Készpénz', null, null, 0, 0, $couponId, 100.0, 0.0, null);
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'Kuponos termék', 'qty' => $qty, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($productId, $qty);

        return ['db' => $db, 'productId' => $productId, 'couponId' => $couponId, 'saleId' => $saleId];
    }

    private function returnQty(Database $db, int $saleId, int $productId, int $qty): int
    {
        $sale = $db->getSaleWithItems($saleId);
        return $db->processReturn($saleId, [[
            'sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => $productId,
            'name' => 'Kuponos termék', 'qty' => $qty, 'unit_price' => 1270,
        ]], 'visszáru', null, 1270.0 * $qty, $sale);
    }

    private function timesUsed(Database $db): int
    {
        return (int) $db->findCouponByCode('B04')['times_used'];
    }

    public function testFullReturnDecrementsCouponUsageFromOneToZero(): void
    {
        ['db' => $db, 'productId' => $productId, 'saleId' => $saleId] = $this->couponSale();
        $this->assertSame(1, $this->timesUsed($db));

        $this->returnQty($db, $saleId, $productId, 1);

        $this->assertSame(0, $this->timesUsed($db));
        $this->assertSame(10, (int) $db->findProductById($productId)['stock_qty']);
    }

    public function testFullReturnNeverDropsCouponUsageBelowZero(): void
    {
        ['db' => $db, 'productId' => $productId, 'couponId' => $couponId, 'saleId' => $saleId] = $this->couponSale();
        // Pl. egy admin kézzel nullázta a számlálót az eladás után.
        $db->pdo()->prepare('UPDATE coupons SET times_used = 0 WHERE id = ?')->execute([$couponId]);

        $this->returnQty($db, $saleId, $productId, 1);

        $this->assertSame(0, $this->timesUsed($db));
    }

    public function testRepeatedReturnIsRejectedAndDoesNotDecrementTwice(): void
    {
        ['db' => $db, 'productId' => $productId, 'saleId' => $saleId] = $this->couponSale(1); // 2 felhasználás
        $this->assertSame(2, $this->timesUsed($db));

        $this->returnQty($db, $saleId, $productId, 1);
        $this->assertSame(1, $this->timesUsed($db));

        try {
            $this->returnQty($db, $saleId, $productId, 1);
            $this->fail('A már teljesen visszavett eladás ismételt visszárúját el kell utasítani.');
        } catch (RuntimeException $e) {
            // várt
        }

        $this->assertSame(1, $this->timesUsed($db), 'Az ismételt kérés nem csökkentheti újra a számlálót.');
        $this->assertSame(10, (int) $db->findProductById($productId)['stock_qty']);
        $this->assertCount(1, $db->getReturnsForSale($saleId));
    }

    public function testPartialReturnKeepsCouponUsedUntilTheSaleIsFullyReturned(): void
    {
        ['db' => $db, 'productId' => $productId, 'saleId' => $saleId] = $this->couponSale(0, 2);

        $this->returnQty($db, $saleId, $productId, 1);
        $this->assertSame(1, $this->timesUsed($db), 'Részleges visszárunál a kupon felhasznált marad.');

        $this->returnQty($db, $saleId, $productId, 1);
        $this->assertSame(0, $this->timesUsed($db), 'A teljes visszavételt lezáró visszáru pontosan egyszer csökkent.');
    }

    public function testFailureLaterInTheReturnRollsBackCouponAndEverythingElse(): void
    {
        ['db' => $db, 'productId' => $productId, 'saleId' => $saleId] = $this->couponSaleWithGiftCard();
        $this->assertSame(1, $this->timesUsed($db));

        // A kupon-visszaírás UTÁNI lépés (ajándékkártya-jóváírás naplósora) elbukik.
        $db->pdo()->exec("
            CREATE TRIGGER fail_gift_card_refund BEFORE INSERT ON gift_card_transactions
            WHEN NEW.amount_delta > 0
            BEGIN SELECT RAISE(ABORT, 'szimulált hiba'); END
        ");

        try {
            $this->returnQty($db, $saleId, $productId, 1);
            $this->fail('A visszárunak el kell buknia.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('szimulált hiba', $e->getMessage());
        }

        $this->assertSame(1, $this->timesUsed($db), 'A kupon-visszaírás is visszagördül.');
        $this->assertSame(9, (int) $db->findProductById($productId)['stock_qty'], 'A készlet-visszavét is visszagördül.');
        $this->assertSame([], $db->getReturnsForSale($saleId), 'Nem maradhat félkész visszáru-rekord.');
        $this->assertSame(300.0, (float) $db->findGiftCardByCode('B04CARD')['current_balance']);
        $this->assertFalse($db->pdo()->inTransaction());

        // A hiba megszűnése után ugyanaz a visszáru egyszer, hibátlanul lefut.
        $db->pdo()->exec('DROP TRIGGER fail_gift_card_refund');
        $this->returnQty($db, $saleId, $productId, 1);
        $this->assertSame(0, $this->timesUsed($db));
        $this->assertSame(500.0, (float) $db->findGiftCardByCode('B04CARD')['current_balance']);
    }

    /**
     * @return array{db: Database, productId: int, couponId: int, saleId: int}
     */
    private function couponSaleWithGiftCard(): array
    {
        $db = tests_new_database();
        $giftCardId = $db->issueGiftCard('B04CARD', 500.0, null, null);
        $productId = $db->saveProduct([
            'name' => 'Kuponos termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'barcode' => null,
        ]);
        $db->incrementStock($productId, 10);
        $couponId = $db->saveCoupon(['code' => 'B04', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);
        $db->incrementCouponUsage($couponId);
        $saleId = $db->insertSale(970.0, 'Készpénz', null, null, 0, 0, $couponId, 100.0, 200.0, null);
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'Kuponos termék', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($productId, 1);
        $db->redeemGiftCard($giftCardId, 200.0, $saleId);

        return ['db' => $db, 'productId' => $productId, 'couponId' => $couponId, 'saleId' => $saleId];
    }
}
