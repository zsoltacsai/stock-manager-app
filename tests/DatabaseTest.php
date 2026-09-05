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
