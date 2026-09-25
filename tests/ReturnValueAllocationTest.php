<?php

declare(strict_types=1);

require_once __DIR__ . '/InvoiceConsistencyAssertions.php';

use PHPUnit\Framework\TestCase;

/**
 * A-03 (Architecture Audit Phase 3) regresszió — a visszáru pénzügyi értéke
 * az EREDETI eladás közös allokációjából (VatAllocation::returnAllocation()),
 * nem egy külön arányosításból.
 *
 * Az audit reprodukciója: 3 × 10 Ft, 20 Ft kupon → az eladás 10.00 (sorai
 * 3.34 / 3.33 / 3.33). Három részleges visszáru a korábbi szabállyal
 * (round(10 × 10/30, 2) = 3.33 mindháromszor) → 9.99, a napi zárásban
 * 0.01 Ft forgalom maradt. Elvárás: minden megengedett visszáru-sorrendben
 * a visszáruk összege fillérre az eladás bruttója, nettója és ÁFÁ-ja
 * (kulcsonként is), a végső riporthatás 0.
 */
final class ReturnValueAllocationTest extends TestCase
{
    use InvoiceConsistencyAssertions;

    private function today(): string
    {
        return date('Y-m-d');
    }

    /** @param list<array{0:float,1:int,2:string}> $lines [kedvezmény előtti egységár, db, kulcs] */
    private function sale(Database $db, float $paid, array $lines, float $giftCard = 0.0, ?int $couponId = null, float $couponDiscount = 0.0, ?int $customerId = null, int $pointsEarned = 0): int
    {
        $saleId = $db->insertSale($paid, 'Készpénz', null, $customerId, $pointsEarned, 0, $couponId, $couponDiscount, $giftCard, null);
        foreach ($lines as $i => [$price, $qty, $rate]) {
            $db->insertSaleItem($saleId, ['product_id' => null, 'name' => "Tétel $i ($rate)", 'qty' => $qty, 'unit_price' => $price, 'vat_rate' => $rate]);
        }
        return $saleId;
    }

    /** Egy visszáru a return-create.php-vel azonos módon (a pénzügyi értéket a processReturn() számolja). */
    private function returnQty(Database $db, int $saleId, array $qtyByLineIndex): array
    {
        $sale = $db->getSaleWithItems($saleId);
        $items = [];
        foreach ($qtyByLineIndex as $i => $qty) {
            $si = $sale['items'][$i];
            $items[] = ['sale_item_id' => (int) $si['id'], 'product_id' => null, 'name' => $si['name'], 'qty' => $qty, 'unit_price' => (float) $si['unit_price']];
        }
        $returnId = $db->processReturn($saleId, $items, 'A-03', null, 0.0, $sale);
        return $db->getReturnById($returnId);
    }

    private function saleBreakdownCents(Database $db, int $saleId): array
    {
        $sale = $db->getSaleWithItems($saleId);
        return self::breakdownCents(Database::vatBreakdown(Database::saleGrossValue($sale), $sale['items']));
    }

    /** Az eladás összes visszárujának rögzített értéke, fillérben, kulcsonként. */
    private function returnsCents(Database $db, int $saleId): array
    {
        $stmt = $db->pdo()->prepare('SELECT ri.value_gross, ri.value_net, ri.value_vat, si.vat_rate FROM return_items ri JOIN returns r ON r.id = ri.return_id JOIN sale_items si ON si.id = ri.sale_item_id WHERE r.sale_id = ?');
        $stmt->execute([$saleId]);
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $l = ['rate' => $row['vat_rate'], 'gross' => self::cents($row['value_gross']), 'net' => self::cents($row['value_net']), 'vat' => self::cents($row['value_vat'])];
            $this->assertSame($l['gross'], $l['net'] + $l['vat'], 'visszáru-sor: nettó + ÁFA = bruttó');
            $lines[] = $l;
        }
        return self::sumLines($lines);
    }

    private function assertDayIsZero(Database $db, string $label): void
    {
        foreach (['napi zárás' => $db->getDailySummary($this->today()), 'riport' => $db->getSalesReportSummary($this->today(), $this->today())] as $name => $s) {
            $this->assertSame([0, 0, 0], [self::cents($s['total_gross']), self::cents($s['total_net']), self::cents($s['total_vat'])], "$label / $name: teljes visszavétel után 0");
        }
        foreach ($db->getDailySummary($this->today())['by_vat_rate'] as $rate => $row) {
            $this->assertSame([0, 0, 0], [self::cents($row['gross']), self::cents($row['net']), self::cents($row['vat'])], "$label: kulcs $rate is 0");
        }
    }

    private function assertAllReturnsEqualTheSale(Database $db, int $saleId, string $label): void
    {
        $sale = $this->saleBreakdownCents($db, $saleId);
        $ret = $this->returnsCents($db, $saleId);
        $this->assertSame(['net' => $sale['net'], 'vat' => $sale['vat'], 'gross' => $sale['gross']], ['net' => $ret['net'], 'vat' => $ret['vat'], 'gross' => $ret['gross']], "$label: Σ visszáru = eladás (fillér)");
        $this->assertSame($sale['by_rate'], $ret['by_rate'], "$label: kulcsonként is");
        $sum = $db->pdo()->query("SELECT SUM(value_gross), SUM(value_net), SUM(value_vat) FROM returns WHERE sale_id = $saleId")->fetch(PDO::FETCH_NUM);
        $this->assertSame([$sale['gross'], $sale['net'], $sale['vat']], array_map([self::class, 'cents'], $sum), "$label: a visszáru-sorok összesítője");
    }

    /** @return list<list<int>> */
    private static function permutations(array $xs): array
    {
        if (count($xs) <= 1) {
            return [$xs];
        }
        $out = [];
        foreach ($xs as $i => $x) {
            $rest = $xs;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $p) {
                $out[] = array_merge([$x], $p);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Kötelező A-03 reprodukció — mind a 6 sorrend
    // ------------------------------------------------------------------

    /** @return array<string, array{0: list<int>}> */
    public static function threeLineOrders(): array
    {
        $cases = [];
        foreach (self::permutations([0, 1, 2]) as $p) {
            $cases[implode('→', $p)] = [$p];
        }
        return $cases;
    }

    /** @dataProvider threeLineOrders */
    public function testThreeTimesTenWithTwentyCouponReturnedInAnyOrderSumsToTheSale(array $order): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'A03', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $saleId = $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']], 0.0, $couponId, 20.0);
        $b = $this->saleBreakdownCents($db, $saleId);
        $this->assertSame([1000, 787, 213], [$b['gross'], $b['net'], $b['vat']], 'az eladás: bruttó 10.00, nettó 7.87, ÁFA 2.13');

        $expectedLineGross = [334, 333, 333]; // az eladás allokációja: a maradék fillér az első soré
        $refunds = [];
        foreach ($order as $step => $line) {
            $ret = $this->returnQty($db, $saleId, [$line => 1]);
            $this->assertSame($expectedLineGross[$line], self::cents($ret['value_gross']), "a(z) $line. sor visszavett értéke az eladásbeli allokációja");
            $this->assertSame($expectedLineGross[$line], self::cents($ret['total_refund']), 'készpénzes eladás: a visszajáró pénz = az érték');
            $refunds[] = self::cents($ret['total_refund']);

            // Köztes riport: a még meg nem fordított sorok allokált értéke marad.
            $remaining = array_sum(array_diff_key($expectedLineGross, array_flip(array_slice($order, 0, $step + 1))));
            $this->assertSame($remaining, self::cents($db->getDailySummary($this->today())['total_gross']), 'köztes napi zárás');
        }
        $this->assertSame(1000, array_sum($refunds), 'Σ visszáru = 10.00 (korábban 9.99)');
        $this->assertAllReturnsEqualTheSale($db, $saleId, '3×10/20 kupon ' . implode('→', $order));
        $this->assertDayIsZero($db, '3×10/20 kupon ' . implode('→', $order));
    }

    public function testSingleLineOfThreeReturnedInOneTwoOrThreeInstallments(): void
    {
        foreach ([[1, 1, 1], [2, 1], [1, 2], [3]] as $parts) {
            $db = tests_new_database();
            $couponId = $db->saveCoupon(['code' => 'A03S', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
            $saleId = $this->sale($db, 10.0, [[10.0, 3, '27']], 0.0, $couponId, 20.0);
            $values = [];
            foreach ($parts as $qty) {
                $values[] = self::cents($this->returnQty($db, $saleId, [0 => $qty])['value_gross']);
            }
            $this->assertSame(1000, array_sum($values), json_encode($parts));
            $this->assertAllReturnsEqualTheSale($db, $saleId, 'egy sor, részletek ' . json_encode($parts));
            $this->assertDayIsZero($db, json_encode($parts));
        }
        // A felosztás determinisztikus: az első visszavett darab kapja a maradék fillért.
        $db = tests_new_database();
        $saleId = $this->sale($db, 10.0, [[10.0, 3, '27']]);
        $this->assertSame([334, 333, 333], array_map(fn () => self::cents($this->returnQty($db, $saleId, [0 => 1])['value_gross']), [1, 2, 3]));
    }

    public function testTwoPartialReturnsInEitherOrderGiveTheSameTotal(): void
    {
        $totals = [];
        foreach ([[[0 => 2], [1 => 1]], [[1 => 1], [0 => 2]], [[0 => 1, 1 => 1], [0 => 1]], [[0 => 1], [0 => 1, 1 => 1]]] as $steps) {
            $db = tests_new_database();
            $couponId = $db->saveCoupon(['code' => 'A03T', 'type' => 'fixed', 'value' => 7, 'is_active' => true]);
            $saleId = $this->sale($db, 23.0, [[10.0, 2, '27'], [10.0, 1, '18']], 0.0, $couponId, 7.0);
            $sum = 0;
            foreach ($steps as $s) {
                $sum += self::cents($this->returnQty($db, $saleId, $s)['value_gross']);
            }
            $totals[] = $sum;
            $this->assertAllReturnsEqualTheSale($db, $saleId, 'két részlet ' . json_encode($steps));
            $this->assertDayIsZero($db, json_encode($steps));
        }
        $this->assertSame([2300, 2300, 2300, 2300], $totals, 'sorrendtől független');
    }

    // ------------------------------------------------------------------
    // Vegyes ÁFA, kupon, utalvány, hűségpont
    // ------------------------------------------------------------------

    public function testMixedVatPartialReturnsFollowTheSaleAllocationPerRate(): void
    {
        $spec = [[999.0, 1, '27'], [555.0, 2, '18'], [444.0, 1, '5'], [333.0, 3, '0'], [390.0, 1, 'AAM'], [279.0, 2, 'TAM']];
        $orders = [
            [[0 => 1, 1 => 1], [2 => 1, 3 => 2], [1 => 1, 4 => 1, 5 => 1], [3 => 1, 5 => 1]],
            [[5 => 2, 3 => 3], [4 => 1], [1 => 2, 2 => 1], [0 => 1]],
        ];
        $couponId = null;
        foreach ($orders as $o => $steps) {
            $db = tests_new_database();
            $couponId = $db->saveCoupon(['code' => "A03M$o", 'type' => 'fixed', 'value' => 777, 'is_active' => true]);
            // 999 + 1110 + 444 + 999 + 390 + 558 = 4500, 777 kupon → 3723 érték.
            $saleId = $this->sale($db, 3723.0, $spec, 0.0, $couponId, 777.0);
            $sale = $this->saleBreakdownCents($db, $saleId);
            foreach ($steps as $s) {
                $ret = $this->returnQty($db, $saleId, $s);
                $this->assertSame(self::cents($ret['value_gross']), self::cents($ret['value_net']) + self::cents($ret['value_vat']));
            }
            $this->assertAllReturnsEqualTheSale($db, $saleId, "vegyes ÁFA, sorrend #$o");
            $this->assertDayIsZero($db, "vegyes ÁFA, sorrend #$o");
            foreach (['0', 'AAM', 'TAM'] as $exempt) {
                $this->assertSame(0, $this->returnsCents($db, $saleId)['by_rate'][$exempt]['vat'] ?? null, "$exempt: nincs ÁFA a visszárun sem");
            }
            $this->assertSame(372300, $sale['gross']);
        }
    }

    public function testCouponPartialReturnCarriesTheCouponShareOfTheReturnedLine(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'A03C', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        // 3 × 333 = 999, 10% kupon (99.90) → 899.10.
        $saleId = $this->sale($db, 899.1, [[333.0, 3, '27']], 0.0, $couponId, 99.9);
        $db->incrementCouponUsage($couponId);
        $first = $this->returnQty($db, $saleId, [0 => 1]);
        $this->assertSame(29970, self::cents($first['value_gross']), '899.10 / 3 = 299.70, a kupon arányos részével');
        $this->assertSame(1, (int) $db->pdo()->query("SELECT times_used FROM coupons WHERE id = $couponId")->fetchColumn(), 'részleges visszárunál a kupon-felhasználás marad (dokumentált szabály)');
        $this->returnQty($db, $saleId, [0 => 2]);
        $this->assertAllReturnsEqualTheSale($db, $saleId, 'százalékos kupon');
        $this->assertDayIsZero($db, 'százalékos kupon');
    }

    public function testCashPaidAndGiftCardPaidAndMixedSalesKeepValueAndMoneySeparate(): void
    {
        // [befizetett, utalvány, sorok]
        $cases = [
            'készpénzes' => [2540.0, 0.0, [[1270.0, 2, '27']]],
            'teljesen utalványos' => [0.0, 2540.0, [[1270.0, 2, '27']]],
            'vegyes utalvány + készpénz' => [1540.0, 1000.0, [[1270.0, 2, '27']]],
        ];
        foreach ($cases as $label => [$paid, $gift, $spec]) {
            $db = tests_new_database();
            $cardId = $gift > 0 ? $db->issueGiftCard('A03G' . bin2hex(random_bytes(2)), $gift, null, null) : null;
            $saleId = $this->sale($db, $paid, $spec, $gift);
            if ($cardId) {
                $db->redeemGiftCard($cardId, $gift, $saleId);
            }

            $first = $this->returnQty($db, $saleId, [0 => 1]);
            $this->assertSame(127000, self::cents($first['value_gross']), "$label: a visszavett darab értéke a teljes eladási érték fele");
            $this->assertSame((int) round($paid * 50), self::cents($first['total_refund']), "$label: a fizetési módon visszajáró pénz a befizetett rész fele");
            $this->assertSame(0, self::cents($first['gift_card_refund']), "$label: az utalvány részleges visszárunál változatlanul nem íródik vissza (B-06)");
            $mid = $db->getDailySummary($this->today());
            $this->assertSame(127000, self::cents($mid['total_gross']), "$label: a riport az értéket vonja le (nem csak a visszajáró pénzt)");

            $second = $this->returnQty($db, $saleId, [0 => 1]);
            $this->assertSame(self::cents($paid), self::cents($first['total_refund']) + self::cents($second['total_refund']), "$label: Σ visszajáró pénz = befizetett");
            $this->assertSame(self::cents($gift), self::cents($second['gift_card_refund']), "$label: teljes visszavételkor az utalvány visszaíródik");
            if ($cardId) {
                $this->assertSame($gift, (float) $db->pdo()->query("SELECT current_balance FROM gift_cards WHERE id = $cardId")->fetchColumn());
            }
            $this->assertAllReturnsEqualTheSale($db, $saleId, $label);
            $this->assertDayIsZero($db, $label);
            $pay = $db->getDailySummary($this->today())['by_payment_method'];
            $this->assertSame(0, array_sum(array_map(fn ($r) => self::cents($r['total']), $pay)), "$label: a fizetési mód szerinti bontás is 0");
        }
    }

    public function testCouponPlusGiftCardAndLoyaltyPlusCoupon(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'A03CG', 'type' => 'fixed', 'value' => 70, 'is_active' => true]);
        $cardId = $db->issueGiftCard('A03CG', 500.0, null, null);
        // 3 × 333 = 999, 70 kupon → 929; 500 utalvány + 429 készpénz.
        $saleId = $this->sale($db, 429.0, [[333.0, 3, '27']], 500.0, $couponId, 70.0);
        $db->redeemGiftCard($cardId, 500.0, $saleId);
        foreach ([1, 1, 1] as $qty) {
            $this->returnQty($db, $saleId, [0 => $qty]);
        }
        $this->assertAllReturnsEqualTheSale($db, $saleId, 'kupon + utalvány');
        $this->assertDayIsZero($db, 'kupon + utalvány');
        $this->assertSame(0, (int) $db->pdo()->query("SELECT times_used FROM coupons WHERE id = $couponId")->fetchColumn(), 'teljes visszavételkor a kupon visszapörög');

        $db = tests_new_database();
        $customer = $db->saveCustomer(['name' => 'A-03 Vevő']);
        $couponId = $db->saveCoupon(['code' => 'A03L', 'type' => 'fixed', 'value' => 33, 'is_active' => true]);
        $saleId = $this->sale($db, 1000.0, [[500.0, 1, '27'], [533.0, 1, '5']], 0.0, $couponId, 33.0, $customer, 10);
        $db->applyLoyaltyPoints($customer, 10, $saleId, 'A-03');
        $this->returnQty($db, $saleId, [1 => 1]);
        $this->assertSame(10, (int) $db->findCustomerById($customer)['loyalty_points'], 'részleges visszárunál a pont marad');
        $this->returnQty($db, $saleId, [0 => 1]);
        $this->assertSame(0, (int) $db->findCustomerById($customer)['loyalty_points'], 'teljes visszavételkor egyszer pörög vissza');
        $this->assertAllReturnsEqualTheSale($db, $saleId, 'hűségpont + kupon');
        $this->assertDayIsZero($db, 'hűségpont + kupon');
    }

    // ------------------------------------------------------------------
    // Property-teszt, védőkorlátok, régi visszáruk, számla érintetlensége
    // ------------------------------------------------------------------

    public function testSeededRandomSalesReturnedInRandomInstallmentsAlwaysSumToTheSale(): void
    {
        mt_srand(20260925);
        $rates = ['27', '18', '5', '0', 'AAM', 'TAM'];
        for ($n = 0; $n < 40; $n++) {
            $db = tests_new_database();
            $spec = [];
            $subtotal = 0.0;
            for ($i = 0, $k = mt_rand(1, 4); $i < $k; $i++) {
                $price = mt_rand(0, 3) === 0 ? mt_rand(1, 3) : mt_rand(1, 250000) / 100;
                $qty = mt_rand(1, 5);
                $spec[] = [$price, $qty, $rates[mt_rand(0, 5)]];
                $subtotal += $price * $qty;
            }
            $value = round($subtotal * mt_rand(0, 100) / 100, 2);
            $gift = mt_rand(0, 2) === 0 ? round($value * mt_rand(0, 100) / 100, 2) : 0.0;
            $saleId = $this->sale($db, round($value - $gift, 2), $spec, $gift);

            // Minden darabot visszaveszünk, véletlen részletekben és sorrendben.
            $units = [];
            foreach ($spec as $i => [, $qty]) {
                for ($u = 0; $u < $qty; $u++) {
                    $units[] = $i;
                }
            }
            shuffle($units);
            while ($units) {
                $chunk = array_splice($units, 0, mt_rand(1, 3));
                $this->returnQty($db, $saleId, array_count_values($chunk));
            }
            $this->assertAllReturnsEqualTheSale($db, $saleId, "véletlen #$n " . json_encode([$value, $gift, $spec]));
            $this->assertDayIsZero($db, "véletlen #$n");
        }
    }

    public function testOverReturnIsStillRejectedAndNothingIsRecorded(): void
    {
        $db = tests_new_database();
        $saleId = $this->sale($db, 30.0, [[10.0, 3, '27']]);
        $this->returnQty($db, $saleId, [0 => 2]);
        try {
            $this->returnQty($db, $saleId, [0 => 2]);
            $this->fail('F-03: csak 1 db vihető még vissza.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 db', $e->getMessage());
        }
        $this->assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM returns WHERE sale_id = $saleId")->fetchColumn());
    }

    public function testReturnsRecordedBeforeTheFixKeepTheirLegacyReportValue(): void
    {
        // V36 előtti visszáru (value_gross = NULL): a riport a korábbi módon
        // (returnGrossValue() szétosztva a visszavett sorokra) számol vele.
        $db = tests_new_database();
        $saleId = $this->sale($db, 30.0, [[10.0, 3, '27']]);
        $sale = $db->getSaleWithItems($saleId);
        $db->pdo()->exec("INSERT INTO returns (sale_id, total_refund, reason, created_at) VALUES ($saleId, 10, 'régi', '" . date('Y-m-d H:i:s') . "')");
        $legacyId = (int) $db->pdo()->lastInsertId();
        $db->pdo()->prepare('INSERT INTO return_items (return_id, sale_item_id, name, qty, unit_price, created_at) VALUES (?, ?, ?, 1, 10, ?)')->execute([$legacyId, (int) $sale['items'][0]['id'], 'régi', date('Y-m-d H:i:s')]);
        $this->assertSame(2000, self::cents($db->getDailySummary($this->today())['total_gross']));
        $this->assertSame(2000, self::cents($db->getSalesReportSummary($this->today(), $this->today())['total_gross']));
    }

    public function testReturnsDoNotChangeTheSalesInvoiceAllocation(): void
    {
        $db = tests_new_database();
        $saleId = $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']]);
        $sale = $db->getSaleWithItems($saleId);
        $before = VatAllocation::invoiceItems(Database::saleGrossValue($sale), $sale['items']);
        $this->returnQty($db, $saleId, [1 => 1]);
        $this->returnQty($db, $saleId, [0 => 1, 2 => 1]);
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame($before, VatAllocation::invoiceItems(Database::saleGrossValue($sale), $sale['items']), 'N-5: az eladás számla-allokációja a visszáruktól független');
        $this->assertSame(['3.34', '3.33', '3.33'], array_map(fn ($i) => (string) $i['line_gross'], $before));
    }

    public function testRevenueTrendUsesTheRecordedReturnValue(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'A03R', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $saleId = $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']], 0.0, $couponId, 20.0);
        foreach ([2, 0, 1] as $line) {
            $this->returnQty($db, $saleId, [$line => 1]);
        }
        $trend = array_column($db->getDailyRevenueTrend(1), 'total', 'date');
        $this->assertSame(0, self::cents($trend[$this->today()] ?? 0), 'a trend sem hagy 0.01 maradékot');
    }
}
