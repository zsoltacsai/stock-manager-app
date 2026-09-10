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

    // ------------------------------------------------------------------
    // P1-3 regresszió: a termék-felvitel/szerkesztő modal dupla kattintás
    // (vagy egy elveszett válasz utáni ismételt beküldés) ellen korábban
    // sem kliens-, sem szerver-oldali védelem nem volt — két külön
    // terméksor jött létre. A kliens-oldali gombletiltást (product-
    // modal.js) itt nem lehet egységtesztelni (nincs böngésző), de a
    // szerver-oldali, tartalom- és időablak-alapú védelmet (lásd
    // Database::saveProduct()/findRecentlyCreatedIdenticalProduct())
    // igen — ez a réteg pontosan a hálózati-újrapróbálkozás jellegű
    // duplikátumot fedi le.
    // ------------------------------------------------------------------

    public function testRapidDuplicateProductCreateReturnsSameProductNotADuplicate(): void
    {
        $db = tests_new_database();
        $payload = $this->sampleProduct(['name' => 'Duplikátum Teszt Termék', 'barcode' => null]);

        $firstId = $db->saveProduct($payload);
        // Ugyanaz a tartalom, SZINTE azonnal újra beküldve -- pontosan
        // egy dupla kattintás vagy egy gyors hálózati újrapróbálkozás
        // szimulációja.
        $secondId = $db->saveProduct($payload);

        $this->assertSame($firstId, $secondId, 'Egy azonnali, tartalmilag azonos ismételt beküldésnek a MEGLÉVŐ terméket kell visszaadnia, nem egy másodikat létrehoznia.');

        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM products WHERE name = ?');
        $stmt->execute(['Duplikátum Teszt Termék']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Pontosan EGY terméksornak szabad létrejönnie, nem kettőnek.');
    }

    public function testProductCreateWithDifferentContentIsNeverTreatedAsDuplicate(): void
    {
        $db = tests_new_database();
        $firstId = $db->saveProduct($this->sampleProduct(['name' => 'Termék A', 'price' => 1270]));
        $secondId = $db->saveProduct($this->sampleProduct(['name' => 'Termék B', 'price' => 1270]));

        $this->assertNotSame($firstId, $secondId, 'Két, TARTALMÁBAN eltérő (más néven) termék sose vonódhat össze.');

        $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testLegitimateSecondIdenticalProductAfterTheDeduplicationWindowIsCreatedSeparately(): void
    {
        $db = tests_new_database();
        $payload = $this->sampleProduct(['name' => 'Ismételt Termék Később', 'barcode' => null]);
        $firstId = $db->saveProduct($payload);

        // Szimuláljuk, hogy ténylegesen eltelt a néhány másodperces
        // dedup-ablak (pl. az operátor tudatosan, később hoz létre egy
        // MÁSODIK, azonos nevű/árú terméket) -- ez NEM lehet örökre
        // letiltva, csak a valóban azonnali duplikátum ellen véd.
        $db->pdo()->exec("UPDATE products SET updated_at = datetime('now', '-1 hour') WHERE id = " . (int) $firstId);

        $secondId = $db->saveProduct($payload);

        $this->assertNotSame($firstId, $secondId, 'A dedup-időablakon TÚL egy tudatos, ismételt létrehozásnak ténylegesen új sort kell eredményeznie.');

        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM products WHERE name = ?');
        $stmt->execute(['Ismételt Termék Később']);
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testProductUpdateIsNeverAffectedByDuplicateCreateGuard(): void
    {
        // A védelem KIZÁRÓLAG új (id nélküli) termékre vonatkozik -- egy
        // meglévő termék normál, ismételt mentése (pl. csak egy mezőt
        // módosítva, majd rögtön újra menteni) sose ütközhet ebbe.
        $db = tests_new_database();
        $id = $db->saveProduct($this->sampleProduct(['name' => 'Szerkesztett Termék']));

        $updatedId1 = $db->saveProduct($this->sampleProduct(['id' => $id, 'name' => 'Szerkesztett Termék', 'notes' => 'v1']));
        $updatedId2 = $db->saveProduct($this->sampleProduct(['id' => $id, 'name' => 'Szerkesztett Termék', 'notes' => 'v2']));

        $this->assertSame($id, $updatedId1);
        $this->assertSame($id, $updatedId2);
        $product = $db->findProductById($id);
        $this->assertSame('v2', $product['notes']);

        $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $this->assertSame(1, $count, 'Egy meglévő termék ismételt mentése sose hozhat létre új sort.');
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

    public function testCompleteStockTakeAppliesRelativeDeltaNotAbsoluteOverwrite(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 50);

        $takeId = $db->startStockTake(null, 'teszt leltár');
        // A leltár indításakor a rendszer 50-et lát (expected_qty=50). A
        // fizikai számlálás 48-at talál (2 db hiány/eltérés).
        $db->updateStockTakeCount($takeId, $productId, 48);

        // KÖZBEN, a leltár lezárása ELŐTT, egy valódi eladás történik —
        // ez a rendszeres, várt eset, amit egy abszolút felülírás
        // csendben eltüntetne.
        $db->decrementStock($productId, 3); // 50 -> 47

        $db->completeStockTake($takeId, true);

        $product = $db->findProductById($productId);
        // Helyes: a JELENLEGI (47) készletre alkalmazva a leltár által
        // felfedezett -2 eltérést kapjuk: 47 - 2 = 45. Egy abszolút
        // felülírás (a hibás, korábbi viselkedés) 48-at adott volna,
        // csendben eltüntetve a közben lezajlott eladás hatását.
        $this->assertSame(45, (int) $product['stock_qty'], 'A leltári korrekciónak relatív eltérésként kell alkalmazódnia, nem abszolút felülírásként.');
    }

    public function testCompleteStockTakeRejectsSecondCompletion(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 10);

        $takeId = $db->startStockTake(null, '');
        $db->updateStockTakeCount($takeId, $productId, 8);
        $db->completeStockTake($takeId, true);

        // Egy második lezárási kísérlet (dupla kattintás, hálózati
        // újrapróbálkozás) enélkül még egyszer alkalmazná a -2 korrekciót.
        $this->expectException(RuntimeException::class);
        $db->completeStockTake($takeId, true);
    }

    public function testStartStockTakeRejectsWhenAnotherIsOpen(): void
    {
        $db = tests_new_database();
        $db->startStockTake(null, 'első leltár');

        $this->expectException(RuntimeException::class);
        $db->startStockTake(null, 'második, átfedő leltár');
    }

    public function testRecordPurchaseAppliesDiscountAndKeepsTotalsConsistentWithLineSum(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());

        $items = [[
            'product_id' => $productId, 'wc_product_id' => null, 'name' => 'Teszt termék',
            'qty' => 3, 'vat_rate' => '27', 'unit_cost_net' => 1000.0, 'unit_cost_gross' => 1270.0,
        ]];
        $result = $db->recordPurchase(['discount_percent' => 10.0], $items);

        // 3 * 1000 = 3000 nettó, 10% kedvezménnyel 2700.
        $this->assertSame(2700.0, $result['total_net'], 'A kedvezménynek ténylegesen csökkentenie kell a végösszeget.');

        $purchase = $db->getPurchaseWithItems($result['purchase_id']);
        $lineSum = array_sum(array_map(static fn($i) => (float) $i['line_net'], $purchase['items']));
        $this->assertEqualsWithDelta($result['total_net'], $lineSum, 0.001, 'A fejléc-összegnek pontosan meg kell egyeznie a tételek összegével.');
    }

    public function testUpsertProductFromWcPreservesLocalFieldsWhenWcSendsEmpty(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct([
            'barcode' => '1231231231231',
            'short_description' => 'Helyben megírt rövid leírás',
            'brand' => 'HelyiMárka',
        ]));
        $db->incrementStock($productId, 10); // ismert, helyi készlet a szinkron előtt

        // Első szinkron: a vonalkód alapján párosítja, és beköti a
        // wc_product_id-t (a helyi termék MÁR LÉTEZIK, csak eddig nem volt
        // hozzá kötve WC-termék).
        $db->upsertProductFromWc([
            'wc_product_id' => 999, 'sku' => 'SKU1', 'barcode' => '1231231231231', 'name' => 'Teszt termék',
            'price' => 1270.0, 'stock_qty' => 5, 'short_description' => 'Helyben megírt rövid leírás', 'long_description' => null, 'brand' => 'HelyiMárka',
        ]);

        // Második szinkron: most már wc_product_id alapján párosít — a WC
        // oldalán nincs vonalkód-meta és nincs kitöltve leírás/márka. Ez
        // NEM jelentheti azt, hogy a helyi, gondosan kitöltött mezőket
        // törölni kellene.
        $db->upsertProductFromWc([
            'wc_product_id' => 999, 'sku' => 'SKU1', 'barcode' => '', 'name' => 'Teszt termék (WC-ről)',
            'price' => 1280.0, 'stock_qty' => 4, 'short_description' => '', 'long_description' => null, 'brand' => '',
        ]);

        $product = $db->findProductById($productId);
        $this->assertSame('1231231231231', $product['barcode'], 'A helyi vonalkód nem törölhető egy üres WC-értékkel.');
        $this->assertSame('Helyben megírt rövid leírás', $product['short_description']);
        $this->assertSame('HelyiMárka', $product['brand']);
        $this->assertSame('Teszt termék (WC-ről)', $product['name'], 'A ténylegesen küldött (nem üres) mezőknek viszont érvényesülniük kell.');
        $this->assertSame(1280.0, (float) $product['price']);
        // A készlet nem WC-forrású mező ebben az appban — minden más helyen
        // (eladás, beszerzés, leltár) a HELYI adatbázis a hiteles forrás, és
        // ez PUSH-olódik ki a WooCommerce felé, sosem fordítva. Ha a
        // pull-szinkron felülírná stock_qty-t egy már ismert (akár csak
        // vonalkód alapján párosított) termékén, egy közben (a WC-lekérdezés
        // és e szinkron írása közötti időben) lezajlott helyi eladás/
        // beszerzés készlethatását törölné el csendben — sem az első
        // (barcode-alapú), sem a második (wc_product_id-alapú) szinkronnak
        // nem szabad módosítania a 10-es kezdőértéket.
        $this->assertSame(10, (int) $product['stock_qty'], 'A pull-szinkron egy már ismert terméknél nem írhatja felül a helyi készletet.');
    }

    public function testUpsertProductFromWcSeedsStockQtyOnlyForBrandNewProduct(): void
    {
        // Ellenpróba az előző teszthez: egy WC-n ismert, de helyben MÉG
        // NEM létező termék első behúzásakor a WC-féle stock_qty-nek
        // továbbra is érvényesülnie kell kezdőértékként — a fenti fix csak
        // a már létező helyi termékek védelmére vonatkozik, nem general
        // tiltás.
        $db = tests_new_database();
        $db->upsertProductFromWc([
            'wc_product_id' => 555, 'sku' => 'NEWSKU', 'barcode' => '5551112223334', 'name' => 'Vadonatúj WC termék',
            'price' => 990.0, 'stock_qty' => 42, 'short_description' => '', 'long_description' => null, 'brand' => '',
        ]);

        $product = $db->findProductByWcId(555);
        $this->assertNotNull($product);
        $this->assertSame(42, (int) $product['stock_qty'], 'Egy új (helyben eddig ismeretlen) terméknél a WC-féle kezdő készletnek érvényesülnie kell.');
    }

    public function testInsertSaleEnforcesUniqueIdempotencyKey(): void
    {
        $db = tests_new_database();
        $firstId = $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'idem-key-1');
        $this->assertGreaterThan(0, $firstId);

        // Egy második insertSale ugyanazzal a kulccsal az adatbázis szintjén
        // (UNIQUE INDEX), nem csak alkalmazás-szinten kell hogy elbukjon —
        // ez a tényleges, versenyhelyzet-mentes védelem (lásd
        // insertSale()/api/sale.php docblockjei).
        $this->expectException(PDOException::class);
        $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'idem-key-1');
    }

    public function testInsertSaleAllowsMultipleNullIdempotencyKeys(): void
    {
        // A régi hívók (vagy egy kulcs nélküli kérés) NULL-t küldenek —
        // ennek több sorra is engedélyezettnek kell maradnia, különben
        // minden kulcs nélküli eladás a második után elbukna.
        $db = tests_new_database();
        $id1 = $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, null);
        $id2 = $db->insertSale(2000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, null);
        $this->assertNotSame($id1, $id2);
    }

    public function testFindSaleByIdempotencyKeyReturnsTheOriginalSale(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'idem-key-2');

        $found = $db->findSaleByIdempotencyKey('idem-key-2');
        $this->assertNotNull($found);
        $this->assertSame($saleId, (int) $found['id']);

        $this->assertNull($db->findSaleByIdempotencyKey('does-not-exist'));
        $this->assertNull($db->findSaleByIdempotencyKey(''));
    }

    public function testInsertSalePersistsIdempotencyFingerprintAlongsideKey(): void
    {
        // DB-szintű bizonyíték a C7 javításhoz: az insertSale() új,
        // trailing paraméterként kapott ujjlenyomat ténylegesen elmentődik
        // és visszaolvasható — a sale.php-beli tényleges egyezés/eltérés-
        // ellenőrzést (build_sale_fingerprint()/match_or_reject_idempotent_replay())
        // a tests/HttpSecurityTest.php fedi le HTTP-szinten (valódi sale.php
        // hívásokkal), mert az a kérés-fingerprint-számítás logikája (mi
        // számít bele) sale.php-ban, nem a Database osztályban él.
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'idem-key-fp', 'fp-hash-abc123');

        $found = $db->findSaleByIdempotencyKey('idem-key-fp');
        $this->assertNotNull($found);
        $this->assertSame($saleId, (int) $found['id']);
        $this->assertSame('fp-hash-abc123', $found['idempotency_fingerprint']);
    }

    public function testInsertSaleAllowsNullFingerprintForBackwardCompatibility(): void
    {
        // Egy régi hívó (vagy egy hiányzó ujjlenyomat) NULL-t hagy a
        // mezőben — ennek nem szabad hibát okoznia, és a sale.php-beli
        // match_or_reject_idempotent_replay() logikájának ezt "nincs mit
        // összehasonlítani, engedjük a visszajátszást" esetként kell
        // kezelnie (lásd Database::migrateV18SaleIdempotencyFingerprint()
        // docblockja).
        $db = tests_new_database();
        $saleId = $db->insertSale(500.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'idem-key-no-fp');

        $found = $db->findSaleByIdempotencyKey('idem-key-no-fp');
        $this->assertNotNull($found);
        $this->assertSame($saleId, (int) $found['id']);
        $this->assertNull($found['idempotency_fingerprint']);
    }

    public function testTryClaimInvoiceIssuanceIsExclusiveUntilReleased(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1000.0, 'Készpénz');

        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId), 'Az első foglalásnak sikeresnek kell lennie.');
        // Egy második, még a "folyamatban" ablakon belüli próbálkozás
        // (pl. egy majdnem egyidejű másik kérés) nem szerezheti meg a
        // foglalást — ez zárja ki, hogy két kérés egyszerre hívja a
        // Számlázz.hu-t ugyanarra az eladásra.
        $this->assertFalse($db->tryClaimInvoiceIssuance($saleId), 'A már folyamatban lévő foglalás ne legyen újra megszerezhető.');

        // attachInvoiceToSale() (akár sikeres, akár sikertelen kimenettel)
        // felszabadítja a foglalást — utána azonnal újra megszerezhető.
        $db->attachInvoiceToSale($saleId, null, null, 'invoice_failed');
        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId), 'Egy sikertelen kísérlet utáni felszabadítás után azonnal újra foglalhatónak kell lennie.');
    }

    public function testTryClaimInvoiceIssuanceRejectedOnceInvoiceNumberIsSet(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1000.0, 'Készpénz');

        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId));
        $db->attachInvoiceToSale($saleId, 'SZ-2026-001', null, 'completed');

        // Miután egyszer sikeresen kiállított számla van rögzítve, TÖBBÉ
        // sose szerezhető meg újra a foglalás erre az eladásra — ez zárja
        // ki, hogy egy késve érkező, ugyanerre az eladásra vonatkozó
        // ismételt kérés második számlát is kiállítson.
        $this->assertFalse($db->tryClaimInvoiceIssuance($saleId), 'Már kiállított számlájú eladásra ne legyen újra foglalható a kiállítás joga.');
    }

    public function testApplyLoyaltyPointsAddsRelativelyNotAbsolutely(): void
    {
        // DB-szintű bizonyíték, hogy a jóváírás relatív (loyalty_points +
        // delta), nem egy korábban kiolvasott érték felülírása — két
        // egymást követő hívás összeadódik, nem "utoljára nyer" módon
        // felülíródik.
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Atomikus Teszt Vevő']);

        $afterFirst = $db->applyLoyaltyPoints($customerId, 10, null, 'első jóváírás');
        $this->assertSame(10, $afterFirst);

        $afterSecond = $db->applyLoyaltyPoints($customerId, 5, null, 'második jóváírás');
        $this->assertSame(15, $afterSecond, 'A második jóváírásnak az ELSŐ eredményéhez kell hozzáadódnia, nem felülírnia azt.');
    }

    public function testApplyLoyaltyPointsClampsAtZeroWithoutGoingNegative(): void
    {
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Korlátozás Teszt Vevő']);
        $db->applyLoyaltyPoints($customerId, 5, null, 'kezdő egyenleg');

        $newBalance = $db->applyLoyaltyPoints($customerId, -20, null, 'visszavonás nagyobb, mint az egyenleg');
        $this->assertSame(0, $newBalance, 'Az egyenleg sose menjen negatívba, még akkor sem, ha a visszavonás nagyobb az aktuális egyenlegnél.');

        $customer = $db->findCustomerById($customerId);
        $this->assertSame(0, (int) $customer['loyalty_points']);
    }

    /**
     * VALÓDI, több különálló PHP-folyamat közötti konkurrencia-teszt (nem
     * csak ugyanazon a PHP-objektumon belüli, szekvenciális hívás) — ez
     * pontosan azt a forgatókönyvet reprodukálja, amit a piros csapat
     * auditja aggályosnak talált: két egyidejű, KÜLÖNÁLLÓ PHP-FPM worker
     * (itt: külön OS-folyamat) próbál egyszerre jóváírni ugyanannak a
     * vásárlónak. A korábbi (olvasd ki → PHP-ben számolj → írd vissza az
     * abszolút értéket) mintázat ezt elveszthette volna; a mostani,
     * atomikus relatív UPDATE nem.
     *
     * Ha a proc_open() nem elérhető ezen a rendszeren, a teszt kihagyásra
     * kerül a "DB-szintű atomicitás bizonyítva, de valódi többfolyamatos
     * integrációs teszt nem futott le" eset explicit jelzésével — ez a
     * korlát ITT, a teszt saját szkip-üzenetében van dokumentálva.
     */
    public function testApplyLoyaltyPointsIsAtomicAcrossRealConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — a DB-szintű atomicitás (lásd testApplyLoyaltyPointsAddsRelativelyNotAbsolutely) bizonyított, de VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $customerId = $setupDb->saveCustomer(['name' => 'Konkurrencia Teszt Vevő']);
        unset($setupDb); // a PDO-kapcsolat elengedése, mielőtt külön folyamatok nyitnák meg ugyanazt a fájlt

        $childScriptPath = sys_get_temp_dir() . '/sm_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $db->applyLoyaltyPoints((int) $argv[3], 1, null, 'concurrent-test');
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $processCount = 12;
        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $customerId],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }

        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                proc_close($handle);
            }
        }
        @unlink($devNull);

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $customer = $verifyDb->findCustomerById($customerId);
        $this->assertSame(
            $processCount,
            (int) $customer['loyalty_points'],
            "Mind a $processCount, KÜLÖNÁLLÓ folyamatból induló +1 jóváírásnak meg kell jelennie az egyenlegben — egy elveszett jóváírás azt jelentené, hogy a lost-update versenyhelyzet visszatért."
        );
    }

    /**
     * A NAV invoice queue Phase 5B-beli VALÓDI, több-folyamatos
     * konkurrencia-bizonyítéka — ugyanaz a proc_open-alapú minta, mint
     * testApplyLoyaltyPointsIsAtomicAcrossRealConcurrentProcesses()
     * (lásd feljebb), de a Database::claimQueuedInvoiceForSubmission()
     * atomikus claim-jére alkalmazva: 16 KÜLÖNÁLLÓ folyamat próbál
     * egyszerre 16 db 'queued' sort claim-elni. Bizonyítandó: minden sort
     * PONTOSAN EGY folyamat claim-el (nincs két folyamat, ami ugyanazt a
     * sort kapja — ami duplikált manageInvoice CREATE-et jelentene élesben),
     * ÉS egyetlen sor se marad claim-elés nélkül.
     */
    public function testNavInvoiceQueueClaimIsAtomicAcrossRealConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_nav_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $processCount = 16;

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $queuedIds = [];
        for ($i = 0; $i < $processCount; $i++) {
            $saleId = $setupDb->insertSale(1000.0, 'Készpénz');
            $row = $setupDb->insertQueuedInvoice($saleId, 'nav', 787.4, 212.6, 1000.0, 'HUF', ['buyer' => ['nev' => 'x'], 'items' => [], 'payment_method' => 'Készpénz', 'supplier' => []]);
            $queuedIds[] = (int) $row['id'];
        }
        // Egy MÁR lezárt ('done') sor is — bizonyítandó, hogy ezt egyetlen
        // folyamat se claim-eli újra.
        $doneSaleId = $setupDb->insertSale(500.0, 'Készpénz');
        $doneRow = $setupDb->insertQueuedInvoice($doneSaleId, 'nav', 393.7, 106.3, 500.0, 'HUF', ['buyer' => ['nev' => 'x'], 'items' => [], 'payment_method' => 'Készpénz', 'supplier' => []]);
        $setupDb->markInvoiceDone((int) $doneRow['id'], date('Y-m-d H:i:s'));
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/sm_nav_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_nav_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $row = $db->claimQueuedInvoiceForSubmission('nav', 600);
            if ($row !== null) {
                $db->markInvoiceDone((int) $row['id'], date('Y-m-d H:i:s'));
                file_put_contents($argv[3], $row['id'] . "\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_nav_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        // Több folyamat (processCount) indul, mint ahány TÉNYLEGESEN
        // claim-elhető 'queued' sor van (processCount is) — ez
        // SZÁNDÉKOS: a queue mérete pontosan lefedi a versenyhelyzetet
        // (minden sorért ténylegesen verseng valaki), miközben a "done"
        // sor is jelen van a táblában a fenti kontroll-eset miatt.
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                proc_close($handle);
            }
        }
        @unlink($devNull);

        $claimedIds = array_filter(array_map('intval', explode("\n", trim((string) @file_get_contents($resultFile)))));

        $this->assertCount(
            $processCount,
            $claimedIds,
            "Mind a $processCount 'queued' sort pontosan egy folyamatnak kellett volna claim-elnie — egy eltérő szám azt jelentené, hogy vagy ütközés (duplikált claim), vagy egy sor kimaradt."
        );
        $this->assertCount(
            $processCount,
            array_unique($claimedIds),
            'Két KÜLÖNÁLLÓ folyamat NEM claim-elhette ugyanazt a sort — ez élesben duplikált manageInvoice CREATE-et (duplikált NAV-számlát) jelentene.'
        );
        sort($queuedIds);
        $sortedClaimed = $claimedIds;
        sort($sortedClaimed);
        $this->assertSame($queuedIds, $sortedClaimed, 'A claim-elt id-knak pontosan az eredetileg queue-ba helyezett sorokkal kell megegyezniük.');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        foreach ($queuedIds as $id) {
            $row = $verifyDb->getInvoiceById($id);
            $this->assertSame('done', $row['status']);
        }
        $doneRowAfter = $verifyDb->getInvoiceById((int) $doneRow['id']);
        $this->assertSame('done', $doneRowAfter['status'], 'A már korábban lezárt sor státusza nem változhatott — nem lehetett újra claim-elve.');
    }

    /**
     * A `testNavInvoiceQueueClaimIsAtomicAcrossRealConcurrentProcesses()`
     * pontos mintája, DE a bejövő-számla sync EGYETLEN, közös sorára (nem N
     * darab, egyenként külön claim-elhető queue-sorra) — ez pontosan a
     * Phase 6 terv 6. és 20. pontjának bizonyítéka: "két párhuzamos syncből
     * csak egy tényleges feldolgozás marad". Minden gyermekfolyamat PONTOSAN
     * EGYSZER próbál claim-elni; aki sikerrel jár, 300ms-ig "dolgozik"
     * (usleep) MIELŐTT felszabadítaná a zárat — ha az atomikus claim
     * valójában nem lenne atomikus, egy MÁSIK, ezalatt induló folyamat is
     * sikerrel claim-elhetné ugyanazt a sort ("dupla feldolgozás").
     */
    public function testIncomingInvoiceSyncClaimIsAtomicAcrossRealConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_incoming_sync_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $processCount = 16;

        // A `new Database(...)` maga hozza létre (és seedeli az egyetlen
        // 'nav' sync-sort) a sémát — külön setup-adat NEM kell, csak a
        // fájl létrehozása MIELŐTT a gyermekfolyamatok elindulnak.
        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/sm_incoming_sync_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_incoming_sync_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $row = $db->claimIncomingInvoiceSync('nav', 1800);
            if ($row !== null) {
                $start = microtime(true);
                usleep(300000);
                $end = microtime(true);
                $db->markIncomingInvoiceSyncSuccess('nav', '2026-01-01T00:00:00Z', 'test');
                file_put_contents($argv[3], "claimed,$start,$end\n", FILE_APPEND | LOCK_EX);
            } else {
                file_put_contents($argv[3], "skipped\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_incoming_sync_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                proc_close($handle);
            }
        }
        @unlink($devNull);

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $claimedWindows = [];
        $skippedCount = 0;
        foreach ($lines as $line) {
            if ($line === 'skipped') {
                $skippedCount++;
                continue;
            }
            [, $start, $end] = explode(',', $line);
            $claimedWindows[] = ['start' => (float) $start, 'end' => (float) $end];
        }

        $this->assertCount($processCount, $lines, "Mind a $processCount folyamatnak pontosan egyszer kellett volna próbálkoznia.");
        $this->assertGreaterThanOrEqual(1, count($claimedWindows), 'Legalább egy folyamatnak sikerrel claim-elnie kellett a sync-sort.');
        $this->assertSame($processCount, count($claimedWindows) + $skippedCount);

        // A VALÓDI, kritikus bizonyíték: a claim-elt "birtoklási ablakok"
        // (claim ... 300ms munka ... release) IDŐBEN SOSE fedhetik egymást
        // — ha egy folyamat lassabban indult (proc_open ütemezési
        // ingadozás) és emiatt csak egy KORÁBBI claim felszabadulása UTÁN
        // próbálkozott, az önmagában legitim (a sor akkor már szabad
        // volt), DE két folyamat egyidejűleg SOSE tarthatja a zárat — ez
        // bizonyítaná, hogy két syncworker párhuzamosan dolgozna ugyanazon
        // a szinkronizáción.
        usort($claimedWindows, static fn ($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($claimedWindows); $i++) {
            $this->assertGreaterThanOrEqual(
                $claimedWindows[$i - 1]['end'],
                $claimedWindows[$i]['start'],
                'Két claim-elt "birtoklási ablak" időben átfedte egymást — ez azt jelentené, hogy két folyamat EGYSZERRE dolgozott ugyanazon a bejövő-számla syncen (a claim NEM atomikus).'
            );
        }

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $finalState = $verifyDb->getIncomingInvoiceSyncState('nav');
        $this->assertSame('success', $finalState['status']);
        $this->assertNull($finalState['locked_at']);
    }
}
