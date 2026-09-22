<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Release-blocker javítás — kasszaműszak-race a VISSZÁRU oldalán, ugyanaz
 * a minta, mint tests/CashSessionSaleRaceTest.php az eladásoknál. A design
 * dokumentum HIGH kockázatként azonosított forgatókönyve itt:
 *
 *   return-create.php: getOpenCashSession() -> session #N nyitva
 *   Másik folyamat:     session #N lezárása  -> commit
 *   return-create.php: processReturn(...)   -> a réginek NEM SZABAD a
 *                                               lezárt session-hez kötődnie
 *
 * A JAVÍTÁS UTÁN a processReturn() már nem is kap előre lekért session-
 * azonosítót paraméterként (lásd Database::processReturn() docblokkja) —
 * ez a teszt pontosan azt bizonyítja, hogy egy korábban lekért, időközben
 * elavult session-azonosító TÉNYLEGESEN nem jut érvényre.
 *
 * Valódi multi-process stressz-teszt: tests/ReturnCashSessionRaceConcurrencyTest.php.
 */
final class ReturnCashSessionRaceTest extends TestCase
{
    private function createSaleWithOneItem(Database $db, int $qty = 100): array
    {
        $saleId = $db->insertSale($qty * 100.0, 'Készpénz');
        $db->insertSaleItem($saleId, [
            'product_id' => null,
            'name'       => 'Race visszáru teszttétel',
            'qty'        => $qty,
            'unit_price' => 100.0,
            'vat_rate'   => 27,
        ]);
        return $db->getSaleWithItems($saleId);
    }

    private function returnOneUnit(Database $db, array $sale, ?int $cashRegisterId): int
    {
        $item = $sale['items'][0];
        return $db->processReturn(
            (int) $sale['id'],
            [[
                'sale_item_id' => (int) $item['id'],
                'product_id'   => $item['product_id'],
                'name'         => $item['name'],
                'qty'          => 1,
                'unit_price'   => (float) $item['unit_price'],
            ]],
            'race teszt visszáru',
            null,
            100.0,
            $sale,
            $cashRegisterId
        );
    }

    private function fetchReturn(Database $db, int $returnId): array
    {
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $stmt = $pdo->prepare('SELECT * FROM returns WHERE id = ?');
        $stmt->execute([$returnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }

    public function testReturnCreatedAfterSessionCloseIsNeverAttachedToTheClosedSession(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Return race telephely']), 'name' => 'Return race kassza', 'code' => 'RRACE1']);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        $sale = $this->createSaleWithOneItem($db);

        // "Kliens" korábban lekérdezi a nyitott session-t (pl. egy UI
        // frissítéskor) — ez a régi, HIBÁS kódúton a KÉSŐBBI processReturn()-
        // nek adott volna paramétert. Itt csak azt bizonyítjuk, hogy ennek a
        // korábbi lekérdezésnek a MEGLÉTE önmagában nem befolyásolja a
        // tényleges beszúrást.
        $staleObservedSession = $db->getOpenCashSession($registerId);
        $this->assertSame($sessionId, (int) $staleObservedSession['id']);

        // A műszak időközben lezárul — VALÓDI commit.
        $db->closeCashSession($sessionId, 10000.0, []);

        // A visszáru MOST rögzül — a regisztert adjuk meg (nem a fentebb
        // korábban lekért, mára elavult session-azonosítót).
        $returnId = $this->returnOneUnit($db, $sale, $registerId);

        $return = $this->fetchReturn($db, $returnId);
        $this->assertNull($return['cash_session_id'], 'Egy lezárt műszak után rögzített visszáru SOSE kötődhet a (már lezárt) régi session-höz.');
    }

    public function testReturnCreatedWhileSessionStillOpenIsCorrectlyAttached(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Return race telephely 2']), 'name' => 'Return race kassza 2', 'code' => 'RRACE2']);
        $sessionId = $db->openCashSession($registerId, null, 5000.0);
        $sale = $this->createSaleWithOneItem($db);

        $returnId = $this->returnOneUnit($db, $sale, $registerId);
        $return = $this->fetchReturn($db, $returnId);
        $this->assertSame($sessionId, (int) $return['cash_session_id'], 'Nyitott műszak esetén a visszárunak a TÉNYLEGESEN nyitott session-hez kell kötődnie.');
    }

    public function testReturnWithoutCashRegisterIsUnaffectedByAnyOpenSession(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Return race telephely 3']), 'name' => 'Return race kassza 3', 'code' => 'RRACE3']);
        $db->openCashSession($registerId, null, 1000.0);
        $sale = $this->createSaleWithOneItem($db);

        $returnId = $this->returnOneUnit($db, $sale, null);
        $return = $this->fetchReturn($db, $returnId);
        $this->assertNull($return['cash_session_id']);
    }

    public function testCashRefundAccountingReflectsTheActuallyAttachedSession(): void
    {
        // "cash refund accounting maradjon helyes" — a computeExpectedCash()
        // a returns.cash_session_id oszlopot olvassa, teljesen függetlenül
        // attól, HOGYAN íródott be — ez a teszt közvetlenül bizonyítja, hogy
        // egy helyesen (nyitott session alatt) rögzített visszáru ténylegesen
        // csökkenti a kassza várható egyenlegét.
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Return race telephely 4']), 'name' => 'Return race kassza 4', 'code' => 'RRACE4']);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        $sale = $this->createSaleWithOneItem($db);

        $this->returnOneUnit($db, $sale, $registerId);

        $session = $db->getCashSession($sessionId);
        $expected = $db->computeExpectedCash($session, ['Készpénz']);
        // Nyitó 10000 + 0 eladás EBBEN a session-ben (a sale.php-t itt nem
        // hívtuk, a teszt-sale a nyitás ELŐTT/kívül jött létre) - 100 Ft
        // (1 db visszavett tétel, 100 Ft/db) visszatérítés = 9900.
        $this->assertSame(9900.0, $expected, 'A visszáru összegének le kell vonódnia a várható kasszaegyenlegből.');

        // Non-cash visszáru NEM módosíthatja a készpénz-egyenleget.
        $nonCashSale = $db->insertSale(500.0, 'Bankkártya');
        $db->insertSaleItem($nonCashSale, ['product_id' => null, 'name' => 'Kártyás tétel', 'qty' => 1, 'unit_price' => 500.0, 'vat_rate' => 27]);
        $nonCashSaleWithItems = $db->getSaleWithItems($nonCashSale);
        $this->returnOneUnit($db, $nonCashSaleWithItems, $registerId);

        $expectedAfterNonCashReturn = $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']);
        $this->assertSame($expected, $expectedAfterNonCashReturn, 'Egy nem-készpénzes eladáshoz tartozó visszáru nem módosíthatja a készpénz-egyenleget, még ha a returns.cash_session_id ki is töltődik.');
    }

    public function testIdempotentBusinessLogicStillRejectsOverReturningWithCashRegisterAttached(): void
    {
        // "a meglévő return idempotency és üzleti logika ne változzon
        // szükségtelenül" — a "már visszavett mennyiség" ellenőrzés a
        // TRANZAKCIÓN belül fut, függetlenül a cash_register_id jelenlététől.
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Return race telephely 5']), 'name' => 'Return race kassza 5', 'code' => 'RRACE5']);
        $db->openCashSession($registerId, null, 1000.0);
        $sale = $this->createSaleWithOneItem($db, 1);

        $this->returnOneUnit($db, $sale, $registerId);

        $this->expectException(RuntimeException::class);
        $this->returnOneUnit($db, $sale, $registerId);
    }
}
