<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Biztonsági audit F-03 — a visszavehető mennyiség korlátja a kérésen belüli
 * ÖSSZESÍTETT mennyiségre vonatkozik tételenként (Database::processReturn()
 * tranzakción belüli ellenőrzése). Az endpoint-szintű duplikátum-elutasítást
 * a tests/SecurityRemediationServerAuthHttpTest.php bizonyítja valódi HTTP-n.
 */
final class ReturnAggregationTest extends TestCase
{
    /** @return array{0: Database, 1: array, 2: int} [db, sale (tételekkel), productId] */
    private function saleOf(int $qty, float $unitPrice = 1000.0, ?Database $db = null): array
    {
        $db ??= tests_new_database();
        $productId = $db->saveProduct(['name' => 'Visszáru-aggregációs termék', 'barcode' => 'RAGG-' . bin2hex(random_bytes(4)), 'price' => $unitPrice, 'net_price' => round($unitPrice / 1.27, 2), 'vat_rate' => 27]);
        $db->setStock($productId, 10);
        $saleId = $db->insertSale($qty * $unitPrice, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'Visszáru-aggregációs termék', 'qty' => $qty, 'unit_price' => $unitPrice, 'vat_rate' => 27]);
        return [$db, $db->getSaleWithItems($saleId), $productId];
    }

    private function line(array $saleItem, int $qty): array
    {
        return [
            'sale_item_id' => (int) $saleItem['id'],
            'product_id'   => $saleItem['product_id'],
            'name'         => $saleItem['name'],
            'qty'          => $qty,
            'unit_price'   => (float) $saleItem['unit_price'],
        ];
    }

    private function stockOf(Database $db, int $productId): int
    {
        return (int) $db->findProductById($productId)['stock_qty'];
    }

    public function testDuplicateLinesExceedingSoldQuantityAreRejectedWithoutAnySideEffect(): void
    {
        [$db, $sale, $productId] = $this->saleOf(1);
        $item = $sale['items'][0];

        try {
            $db->processReturn((int) $sale['id'], [$this->line($item, 1), $this->line($item, 1), $this->line($item, 1)], 'dupla', null, 3000.0, $sale);
            $this->fail('Az ismételt sorokkal az eladott mennyiségnél többet visszavevő kérésnek el kell buknia.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vihető vissza', $e->getMessage());
        }

        $this->assertSame([], $db->getReturnedQuantitiesForSale((int) $sale['id']));
        $this->assertSame(10, $this->stockOf($db, $productId), 'Elutasított visszárunál a készlet nem változhat (nincs fantom-készlet).');
    }

    public function testExactRemainingQuantityIsAllowedAndOneMoreIsRejected(): void
    {
        [$db, $sale, $productId] = $this->saleOf(3);
        $item = $sale['items'][0];

        $db->processReturn((int) $sale['id'], [$this->line($item, 2)], 'első', null, 2000.0, $sale);
        $db->processReturn((int) $sale['id'], [$this->line($item, 1)], 'maradék', null, 1000.0, $sale);
        $this->assertSame(13, $this->stockOf($db, $productId));

        $this->expectException(RuntimeException::class);
        $db->processReturn((int) $sale['id'], [$this->line($item, 1)], 'plusz egy', null, 1000.0, $sale);
    }

    public function testRemainingPlusOneSplitAcrossDuplicateLinesIsRejected(): void
    {
        [$db, $sale, $productId] = $this->saleOf(3);
        $item = $sale['items'][0];
        $db->processReturn((int) $sale['id'], [$this->line($item, 1)], 'első', null, 1000.0, $sale);

        try {
            // maradék 2 — két sor, összesen 3
            $db->processReturn((int) $sale['id'], [$this->line($item, 2), $this->line($item, 1)], 'átcsempészett', null, 3000.0, $sale);
            $this->fail('A két sor ÖSSZEGE meghaladja a maradék visszavehető mennyiséget.');
        } catch (RuntimeException) {
        }
        $this->assertSame([(int) $item['id'] => 1], $db->getReturnedQuantitiesForSale((int) $sale['id']));
        $this->assertSame(11, $this->stockOf($db, $productId));
    }

    public function testDifferentSaleItemsInOneRequestStillWork(): void
    {
        [$db, $sale, $productId] = $this->saleOf(2);
        $secondProductId = $db->saveProduct(['name' => 'Második tétel', 'barcode' => 'RAGG2-' . bin2hex(random_bytes(4)), 'price' => 500, 'net_price' => 394, 'vat_rate' => 27]);
        $db->setStock($secondProductId, 5);
        $db->insertSaleItem((int) $sale['id'], ['product_id' => $secondProductId, 'name' => 'Második tétel', 'qty' => 1, 'unit_price' => 500.0, 'vat_rate' => 27]);
        $sale = $db->getSaleWithItems((int) $sale['id']);

        $db->processReturn((int) $sale['id'], [$this->line($sale['items'][0], 2), $this->line($sale['items'][1], 1)], 'vegyes', null, 2500.0, $sale);

        $this->assertSame(12, $this->stockOf($db, $productId));
        $this->assertSame(6, $this->stockOf($db, $secondProductId));
    }

    public function testZeroOrNegativeQuantityLineIsRejectedAtDatabaseLevel(): void
    {
        [$db, $sale, $productId] = $this->saleOf(2);
        $item = $sale['items'][0];

        foreach ([0, -5] as $badQty) {
            try {
                $db->processReturn((int) $sale['id'], [$this->line($item, 2), $this->line($item, $badQty)], 'negatív', null, 2000.0, $sale);
                $this->fail("A $badQty mennyiségű sort el kell utasítani.");
            } catch (RuntimeException) {
            }
        }
        $this->assertSame(10, $this->stockOf($db, $productId));
    }

    public function testCashReconciliationOnlyReflectsTheValidRefund(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Aggregációs telephely']), 'name' => 'Aggregációs kassza', 'code' => 'RAGG1']);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        [, $sale, $productId] = $this->saleOf(1, 1000.0, $db);
        $item = $sale['items'][0];

        try {
            $db->processReturn((int) $sale['id'], [$this->line($item, 1), $this->line($item, 1), $this->line($item, 1)], 'dupla', null, 3000.0, $sale, $registerId);
        } catch (RuntimeException) {
        }
        $this->assertSame(10000.0, $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']), 'Egy elutasított túl-visszatérítés sose csökkentheti a várható kasszaegyenleget.');

        $db->processReturn((int) $sale['id'], [$this->line($item, 1)], 'jogos', null, 1000.0, $sale, $registerId);
        $this->assertSame(9000.0, $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']));
        $this->assertSame(11, $this->stockOf($db, $productId));
    }
}
