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

    public function testDailySummaryVatBreakdownAccountsForOrderLevelDiscount(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        // 1000 Ft-os tétel, 27% áfával, de az eladás sales.total-ja csak
        // 900 Ft (mintha egy 10%-os kupon érvényesült volna) — a
        // sale_items.unit_price a kedvezmény ELŐTTI árat tárolja, ahogy
        // az api/sale.php-ban is történik.
        $saleId = $db->insertSale(900.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => '27']);

        $summary = $db->getDailySummary($today);

        $this->assertSame(900.0, $summary['total_gross']);
        // Nettó + ÁFA összegének a kedvezményes (tényleges) bruttóval kell
        // egyeznie, NEM a kedvezmény előtti tétel-összeggel (1000 Ft) —
        // ez volt a hiba: korábban itt 1000 Ft körüli összeg jött volna ki.
        $this->assertEqualsWithDelta(900.0, round($summary['total_net'] + $summary['total_vat'], 2), 0.02);

        $vatRow = $summary['by_vat_rate']['27'];
        $this->assertEqualsWithDelta(900.0, $vatRow['gross'], 0.02);
    }

    public function testSaveProductPreservesPreferredSupplierIdOnUpdate(): void
    {
        $db = tests_new_database();
        $supplierId = $db->saveSupplier(['name' => 'Teszt Beszállító']);
        $productId = $db->saveProduct($this->sampleProduct(['preferred_supplier_id' => $supplierId]));

        $product = $db->findProductById($productId);
        $this->assertSame($supplierId, (int) $product['preferred_supplier_id']);

        // Egy újabb mentés (mintha a szerkesztő modalból jönne) ugyanazt a
        // preferred_supplier_id-t küldi újra — ennek meg kell maradnia.
        $db->saveProduct($this->sampleProduct(['id' => $productId, 'preferred_supplier_id' => $supplierId]));
        $product = $db->findProductById($productId);
        $this->assertSame($supplierId, (int) $product['preferred_supplier_id'], 'A preferred_supplier_id-nak meg kell maradnia mentés után.');
    }

    public function testImportUpsertProductPreservesExistingPreferredSupplierId(): void
    {
        $db = tests_new_database();
        $supplierId = $db->saveSupplier(['name' => 'Teszt Beszállító 2']);
        $productId = $db->saveProduct($this->sampleProduct(['barcode' => '1112223334445', 'preferred_supplier_id' => $supplierId]));

        // Egy importált sor ugyanazzal a vonalkóddal (import fájlok nem
        // ismerik/küldik a preferred_supplier_id-t) — a meglévő
        // hozzárendelésnek meg kell maradnia, nem szabad kinullázódnia.
        $db->importUpsertProduct([
            'name' => 'Teszt termék (frissítve)', 'unit' => 'db', 'group_name' => '', 'cikkszam' => '',
            'barcode' => '1112223334445', 'currency' => 'HUF', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'notes' => '', 'stock_qty' => 5, 'purchase_price_net' => 800,
        ]);

        $product = $db->findProductById($productId);
        $this->assertSame($supplierId, (int) $product['preferred_supplier_id'], 'Import után is meg kell maradnia a preferred_supplier_id-nak.');
        $this->assertSame('Teszt termék (frissítve)', $product['name']);
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

    public function testTryClaimLoyaltyPointsCannotGoBelowZeroEvenIfCalledTwice(): void
    {
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Pontgyűjtő Pál']);
        $db->applyLoyaltyPoints($customerId, 100, null, 'setup');

        $this->assertTrue($db->tryClaimLoyaltyPoints($customerId, 70), 'Az első lefoglalásnak sikeresnek kell lennie.');
        // Egy második, versenyhelyzetben induló kérés ugyanarra a (már
        // majdnem elfogyott) egyenlegre nem mehet negatívba.
        $this->assertFalse($db->tryClaimLoyaltyPoints($customerId, 70), 'A fedezet nélküli második lefoglalás ne sikerüljön.');

        $customer = $db->findCustomerById($customerId);
        $this->assertSame(30, (int) $customer['loyalty_points'], 'Az egyenleg csak az elsőnek induló foglalással csökkenjen.');
    }

    public function testClaimAndRecordGiftCardRedemptionMatchesBalanceAndWritesLedgerRow(): void
    {
        $db = tests_new_database();
        $giftCardId = $db->issueGiftCard('SPLIT1', 1000.0, null, null);

        // A sale.php-ban használt két lépéses minta: előbb a foglalás (még
        // az eladás rögzítése előtt, sale_id nélkül), utána a könyvelési
        // sor rögzítése, miután az eladás-azonosító már ismert.
        $this->assertTrue($db->tryClaimGiftCardBalance($giftCardId, 400.0));
        $saleId = $db->insertSale(600.0, 'Készpénz');
        $newBalance = $db->recordGiftCardRedemption($giftCardId, 400.0, $saleId);

        $this->assertSame(600.0, $newBalance);
        $giftCard = $db->findGiftCardByCode('SPLIT1');
        $this->assertSame(600.0, (float) $giftCard['current_balance']);

        $history = $db->getGiftCardHistory($giftCardId);
        $redemptionRow = current(array_filter($history, fn($row) => (int) ($row['sale_id'] ?? 0) === $saleId));
        $this->assertNotFalse($redemptionRow, 'A beváltásnak saját, az eladáshoz kötött könyvelési sort kell írnia.');
        $this->assertSame(-400.0, (float) $redemptionRow['amount_delta']);
    }

    public function testProcessReturnRejectsReturningMoreThanRemainsAfterAnEarlierReturn(): void
    {
        // Ez a védelem zárja ki, hogy két majdnem egyidejű visszáru-kérés
        // (mindkettő a "még semmi nincs visszavéve" állapotot látva) együtt
        // többet vigyen vissza, mint amennyi ténylegesen eladásra került —
        // ami emellett a hűségpontok/kupon/utalvány kétszeri visszapörgetését
        // is okozná egy teljesen lefedő visszárunál.
        $db = tests_new_database();
        $saleId = $db->insertSale(1000.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => '27']);

        $sale = $db->getSaleWithItems($saleId);
        $saleItemId = (int) $sale['items'][0]['id'];
        $returnItem = ['sale_item_id' => $saleItemId, 'product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1000];

        // Az első visszáru a teljes (1 db) mennyiséget visszaveszi.
        $db->processReturn($saleId, [$returnItem], 'első visszáru', null, 1000.0, $sale);

        // Egy második kérés, ami — mint egy versenyhelyzetben — még a
        // "semmi nincs visszavéve" előzetes állapotra épül, és megint az
        // egész (1 db) mennyiséget próbálná visszavenni: ezt a friss,
        // tranzakción belüli ellenőrzésnek el kell utasítania.
        $this->expectException(RuntimeException::class);
        $db->processReturn($saleId, [$returnItem], 'második (versenyhelyzetes) visszáru', null, 1000.0, $sale);
    }

    public function testGetDailySummarySubtractsFullyReturnedSaleFromTotals(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);

        $sale = $db->getSaleWithItems($saleId);
        $saleItemId = (int) $sale['items'][0]['id'];
        $db->processReturn(
            $saleId,
            [['sale_item_id' => $saleItemId, 'product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1270]],
            'teszt visszáru',
            null,
            1270.0,
            $sale
        );

        $summary = $db->getDailySummary($today);

        // Az eredeti eladás-rekord (sales.total) változatlanul megmarad —
        // enélkül a napi zárás a visszáru után is a teljes eredeti összeget
        // mutatná a valós (nettó nulla) forgalom helyett.
        $this->assertSame(1270.0, $summary['total_returns']);
        $this->assertSame(0.0, $summary['total_gross'], 'A visszáru után a bruttó forgalomnak nullára kell netóznia.');
        $this->assertEqualsWithDelta(0.0, $summary['total_net'] + $summary['total_vat'], 0.02);
    }

    public function testTransferStockFromNewStockAlsoIncreasesGlobalStock(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $locationId = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);

        // "Új készlet" — nincs forrás telephely (pl. utólag rögzített
        // beszerzés). Enélkül a globális stock_qty (amit a WooCommerce-
        // szinkron és a kassza is olvas) sose látná ezt a mennyiséget.
        $db->transferStock($productId, null, $locationId, 20, null);

        $product = $db->findProductById($productId);
        $this->assertSame(20, (int) $product['stock_qty'], 'Az "Új készlet" mozgatásnak a globális készletet is növelnie kell.');

        $locationStock = $db->getLocationStockForProduct($productId);
        $row = current(array_filter($locationStock, fn($r) => (int) $r['location_id'] === $locationId));
        $this->assertSame(20, (int) $row['stock_qty']);
    }

    public function testTransferStockRejectsWhenSourceLacksEnoughStock(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $locationA = $db->saveLocation(['name' => 'A telephely']);
        $locationB = $db->saveLocation(['name' => 'B telephely']);

        $db->transferStock($productId, null, $locationA, 5, null); // A-n 5 db "új készlet"-ként

        // Egy második, majdnem egyidejű mozgatás ugyanarról a forrásról
        // többet próbál elvinni, mint ami ott ténylegesen van — ezt a
        // tranzakción belüli, atomikus ellenőrzésnek el kell utasítania,
        // nem szabad negatívba engednie a forrás telephely készletét.
        $this->expectException(RuntimeException::class);
        $db->transferStock($productId, $locationA, $locationB, 10, null);
    }

    public function testAnonymizeCustomerAlsoScrubsSalesBuyerName(): void
    {
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Törlendő Tamás']);
        $saleId = $db->insertSale(1000.0, 'Készpénz', 'Törlendő Tamás', $customerId);

        $db->anonymizeCustomer($customerId);

        $sale = $db->getSaleWithItems($saleId);
        $this->assertStringNotContainsString('Törlendő Tamás', (string) $sale['buyer_name'], 'A GDPR-törlés után a korábbi eladásokon se maradhasson olvasható a név.');
        $this->assertStringContainsString('GDPR', (string) $sale['buyer_name']);
    }
}
