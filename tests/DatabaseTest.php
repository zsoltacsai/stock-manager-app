<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private function sampleProduct(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Teszt termék',
            'unit' => 'db',
            'vat_rate' => '27',
            'net_price' => 1000,
            'price' => 1270,
            'barcode' => null,
        ], $overrides);
    }

    public function testSaveProductThenInsertSaleDecrementsStock(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 10);

        $product = $db->findProductById($productId);
        $this->assertSame(10, (int) $product['stock_qty']);

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, [
            'product_id' => $productId, 'name' => 'Teszt termék', 'qty' => 3,
            'unit_price' => 1270, 'vat_rate' => '27',
        ]);
        $db->decrementStock($productId, 3);

        $updated = $db->findProductById($productId);
        $this->assertSame(7, (int) $updated['stock_qty']);

        $sale = $db->getSaleWithItems($saleId);
        $this->assertCount(1, $sale['items']);
        $this->assertSame(3, (int) $sale['items'][0]['qty']);
    }

    public function testDecrementStockAllowsNegative(): void
    {
        // Az app szándékosan engedi a túlértékesítést (lásd README) — a
        // készlet mehet negatívba, nem dob hibát.
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->decrementStock($productId, 5);

        $product = $db->findProductById($productId);
        $this->assertSame(-5, (int) $product['stock_qty']);
    }

    public function testFindProductByBarcode(): void
    {
        $db = tests_new_database();
        $db->saveProduct($this->sampleProduct(['barcode' => '1234567890123']));

        $found = $db->findProductByBarcode('1234567890123');
        $this->assertNotNull($found);
        $this->assertSame('Teszt termék', $found['name']);

        $this->assertNull($db->findProductByBarcode('0000000000000'));
    }

    public function testGenerateUniqueBarcodeProducesValidEan13(): void
    {
        $db = tests_new_database();
        $barcode = $db->generateUniqueBarcode();

        $this->assertSame(13, strlen($barcode));
        $this->assertMatchesRegularExpression('/^\d{13}$/', $barcode);

        // EAN-13 ellenőrzőszám-validálás a generált kódra.
        $digits = array_map('intval', str_split($barcode));
        $checkDigit = array_pop($digits);
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * ($i % 2 === 0 ? 1 : 3);
        }
        $expectedCheck = (10 - ($sum % 10)) % 10;
        $this->assertSame($expectedCheck, $checkDigit);
    }

    public function testValidateCouponPercentDiscount(): void
    {
        $db = tests_new_database();
        $db->saveCoupon(['code' => 'NYAR20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);

        $result = $db->validateCoupon('nyar20', 1000);
        $this->assertTrue($result['ok']);
        $this->assertSame(200.0, $result['discount']);
    }

    public function testValidateCouponRejectsUnknownCode(): void
    {
        $db = tests_new_database();
        $result = $db->validateCoupon('NOPE', 1000);
        $this->assertFalse($result['ok']);
    }

    public function testValidateCouponRejectsInactive(): void
    {
        $db = tests_new_database();
        $db->saveCoupon(['code' => 'OFF', 'type' => 'fixed', 'value' => 500, 'is_active' => false]);
        $result = $db->validateCoupon('OFF', 1000);
        $this->assertFalse($result['ok']);
    }

    public function testValidateCouponEnforcesUsageLimit(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'ONECE', 'type' => 'fixed', 'value' => 100, 'is_active' => true, 'usage_limit' => 1]);
        $db->incrementCouponUsage($couponId);

        $result = $db->validateCoupon('ONECE', 1000);
        $this->assertFalse($result['ok']);
    }

    public function testValidateCouponEnforcesMinPurchase(): void
    {
        $db = tests_new_database();
        $db->saveCoupon(['code' => 'BIG', 'type' => 'fixed', 'value' => 100, 'is_active' => true, 'min_purchase' => 5000]);
        $result = $db->validateCoupon('BIG', 1000);
        $this->assertFalse($result['ok']);
    }

    public function testIncrementCouponUsageEnforcesLimitAtomically(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'LAST1', 'type' => 'fixed', 'value' => 100, 'is_active' => true, 'usage_limit' => 1]);

        $this->assertTrue($db->incrementCouponUsage($couponId), 'Az első felhasználásnak sikeresnek kell lennie.');
        $this->assertFalse($db->incrementCouponUsage($couponId), 'A limit elérése után a második felhasználás ne sikerüljön.');

        $coupon = $db->findCouponByCode('LAST1');
        $this->assertSame(1, (int) $coupon['times_used'], 'A times_used nem léphet túl a limiten.');
    }

    public function testIncrementCouponUsageAllowsUnlimitedWhenNoLimitSet(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'ENDLESS', 'type' => 'percent', 'value' => 10, 'is_active' => true]);

        $this->assertTrue($db->incrementCouponUsage($couponId));
        $this->assertTrue($db->incrementCouponUsage($couponId));
        $this->assertTrue($db->incrementCouponUsage($couponId));
    }

    public function testRedeemGiftCardCannotGoBelowZeroEvenIfCalledTwice(): void
    {
        $db = tests_new_database();
        $giftCardId = $db->issueGiftCard('WELCOME', 1000.0, null, null);

        $firstBalance = $db->redeemGiftCard($giftCardId, 700.0, null);
        $this->assertSame(300.0, $firstBalance);

        // Egy második, a ténylegesnél nagyobb beváltási kísérlet (pl. egy
        // versenyhelyzetben induló második kérés) nem mehet negatívba —
        // az atomikus WHERE current_balance >= :amount ezt megakadályozza,
        // a metódus a változatlan egyenleget adja vissza.
        $secondBalance = $db->redeemGiftCard($giftCardId, 700.0, null);
        $this->assertSame(300.0, $secondBalance, 'A beváltás ne menjen negatívba — az egyenleg maradjon változatlan.');
    }

    public function testFullReturnReversesLoyaltyCouponAndGiftCardBenefits(): void
    {
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Teszt Vevő']);
        $couponId = $db->saveCoupon(['code' => 'RET10', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $db->incrementCouponUsage($couponId);
        $giftCardId = $db->issueGiftCard('RETCARD', 1000.0, null, null);

        $db->applyLoyaltyPoints($customerId, 50, null, 'setup: kezdő egyenleg a beváltáshoz');

        $saleId = $db->insertSale(
            720.0, 'Készpénz', 'Teszt Vevő', $customerId,
            72,   // loyalty_points_earned
            10,   // loyalty_points_redeemed
            $couponId, 80.0, 200.0, // couponId, couponDiscount, giftCardRedeemed
            null
        );
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Szolgáltatás', 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => '27']);
        $db->applyLoyaltyPoints($customerId, -10, $saleId, 'beváltva'); // a 10 pont levonása a vásárláskor, mint sale.php-ban
        $db->applyLoyaltyPoints($customerId, 72, $saleId, 'jóváírva');
        // A kártya beváltása a valós sale.php sorrendjét követi: a sale_id
        // már létezik, amikor a beváltás megtörténik, hogy a visszáru
        // (gift_card_transactions.sale_id alapján) meg tudja találni.
        $db->redeemGiftCard($giftCardId, 200.0, $saleId);
        // 50 induló - 10 beváltott + 72 jóváírt = 112 pont várható a visszáru előtt.

        $sale = $db->getSaleWithItems($saleId);
        $saleItemId = (int) $sale['items'][0]['id'];

        $db->processReturn(
            $saleId,
            [['sale_item_id' => $saleItemId, 'product_id' => null, 'name' => 'Szolgáltatás', 'qty' => 1, 'unit_price' => 1000]],
            'teszt teljes visszáru',
            null,
            720.0,
            $sale
        );

        $customer = $db->findCustomerById($customerId);
        // 112 - 72 (jóváírás visszavonva) + 10 (beváltás visszaadva) = 50.
        $this->assertSame(50, (int) $customer['loyalty_points'], 'A hűségpontoknak vissza kell állniuk a beváltás előtti szintre.');

        $coupon = $db->findCouponByCode('RET10');
        $this->assertSame(0, (int) $coupon['times_used'], 'A kupon-felhasználásnak vissza kell állnia.');

        $giftCard = $db->findGiftCardByCode('RETCARD');
        $this->assertSame(1000.0, (float) $giftCard['current_balance'], 'Az ajándékutalvány-egyenlegnek teljesen vissza kell állnia.');
    }

    public function testPartialReturnDoesNotReverseLoyaltyOrCoupon(): void
    {
        // Szándékos korlát (lásd Database::processReturn docblock): részleges
        // visszárunál a kupon/hűségpont NEM módosul.
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Teszt Vevő 2']);
        $saleId = $db->insertSale(2000.0, 'Készpénz', null, $customerId, 200, 0, null, 0, 0, null);
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel A', 'qty' => 2, 'unit_price' => 1000, 'vat_rate' => '27']);
        $db->applyLoyaltyPoints($customerId, 200, $saleId, 'jóváírva');

        $sale = $db->getSaleWithItems($saleId);
        $saleItemId = (int) $sale['items'][0]['id'];

        // Csak 1-et veszünk vissza a 2-ből — a rendelés nincs teljesen visszavéve.
        $db->processReturn(
            $saleId,
            [['sale_item_id' => $saleItemId, 'product_id' => null, 'name' => 'Tétel A', 'qty' => 1, 'unit_price' => 1000]],
            'részleges visszáru',
            null,
            1000.0,
            $sale
        );

        $customer = $db->findCustomerById($customerId);
        $this->assertSame(200, (int) $customer['loyalty_points'], 'Részleges visszárunál a pontok ne változzanak.');
    }

    public function testStaffAdminRoleCheck(): void
    {
        $db = tests_new_database();
        $adminId = $db->saveStaff(['name' => 'Admin Ede', 'pin' => '1234', 'role' => 'admin']);
        $cashierId = $db->saveStaff(['name' => 'Kasszás Kata', 'pin' => '5678', 'role' => 'cashier']);

        $this->assertTrue($db->isStaffAdmin($adminId));
        $this->assertFalse($db->isStaffAdmin($cashierId));
        $this->assertFalse($db->isStaffAdmin(null));
    }

    public function testVerifyStaffPinMatchesCorrectStaffOnly(): void
    {
        $db = tests_new_database();
        $db->saveStaff(['name' => 'Admin Ede', 'pin' => '1234', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Kasszás Kata', 'pin' => '5678', 'role' => 'cashier']);

        $match = $db->verifyStaffPin('5678');
        $this->assertNotNull($match);
        $this->assertSame('Kasszás Kata', $match['name']);
        $this->assertArrayNotHasKey('pin_hash', $match);

        $this->assertNull($db->verifyStaffPin('0000'));
    }
}
