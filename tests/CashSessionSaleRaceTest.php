<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — kasszaműszak-race javítás, DETERMINISZTIKUS
 * (egyetlen folyamaton belüli, kontrollált sorrendű) bizonyíték. A design
 * dokumentum által HIGH kockázatként azonosított forgatókönyv pontos
 * reprodukciója:
 *
 *   Client B: getOpenCashSession() -> session #N nyitva
 *   Client A: session #N lezárása -> commit
 *   Client B: insertSale(...)      -> a réginek NEM SZABAD a lezárt
 *                                      session-hez kötődnie
 *
 * A JAVÍTÁS UTÁN az insertSale() már nem is kap session-azonosítót
 * paraméterként (lásd Database::insertSale() docblokkja) — ez a teszt
 * pontosan azt bizonyítja, hogy egy korábban lekért, időközben elavult
 * session-azonosító (amit egy hívó ESETLEG még mindig a birtokában tart,
 * pl. egy UI-állapotból) TÉNYLEGESEN nem jut érvényre, mert az insertSale()
 * a SAJÁT, atomikus al-lekérdezésével dönti el ezt, a lezárás UTÁN már
 * helyesen NULL-t adva.
 *
 * Valódi multi-process stressz-teszt: tests/CashSessionSaleRaceConcurrencyTest.php.
 */
final class CashSessionSaleRaceTest extends TestCase
{
    public function testSaleInsertedAfterSessionCloseIsNeverAttachedToTheClosedSession(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Race teszt telephely']), 'name' => 'Race kassza', 'code' => 'RACE1']);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);

        // "Client B" korábban lekérdezi a nyitott session-t (pl. egy UI
        // frissítéskor) — ez a régi, HIBÁS kódúton a KÉSŐBBI insertSale()-nek
        // adott volna paramétert. Itt csak azt bizonyítjuk, hogy ennek a
        // korábbi lekérdezésnek a MEGLÉTE önmagában nem befolyásolja a
        // tényleges beszúrást — a régi kód idejéből itt maradt névvel jelezve.
        $staleObservedSession = $db->getOpenCashSession($registerId);
        $this->assertSame($sessionId, (int) $staleObservedSession['id']);

        // "Client A" időközben lezárja a műszakot — VALÓDI commit.
        $db->closeCashSession($sessionId, 10000.0, []);

        // "Client B" MOST hoz létre egy eladást — a regisztert adja meg
        // (nem a fentebb korábban lekért, mára elavult session-azonosítót).
        $saleId = $db->insertSale(1500.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, null, null, $registerId);

        $sale = $this->fetchSale($db, $saleId);
        $this->assertNull($sale['cash_session_id'], 'Egy lezárt műszak után rögzített eladás SOSE kötődhet a (már lezárt) régi session-höz.');
        $this->assertNotSame((string) $sessionId, (string) $sale['cash_session_id']);
    }

    public function testSaleInsertedWhileSessionStillOpenIsCorrectlyAttached(): void
    {
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Race teszt telephely 2']), 'name' => 'Race kassza 2', 'code' => 'RACE2']);
        $sessionId = $db->openCashSession($registerId, null, 5000.0);

        $saleId = $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, null, null, $registerId);
        $sale = $this->fetchSale($db, $saleId);
        $this->assertSame($sessionId, (int) $sale['cash_session_id'], 'Nyitott műszak esetén az eladásnak a TÉNYLEGESEN nyitott session-hez kell kötődnie.');

        // A műszak ezután is rendben lezárható — az élő eladás jelenléte
        // nem akadályozza a lezárást (lásd computeExpectedCash()).
        $result = $db->closeCashSession($sessionId, 6000.0, []);
        $this->assertSame(0.0, $result['variance']);
    }

    public function testSaleWithoutCashRegisterIsUnaffectedByAnyOpenSession(): void
    {
        // "non-cash sale viselkedés maradjon helyes" — egy olyan eladás,
        // ami EGYÁLTALÁN nem ad meg pénztárgépet (a legtöbb, kasszakezelést
        // nem használó bolt esetén ez az ÁLTALÁNOS eset), a NULL
        // cash_session_id-t kapja, FÜGGETLENÜL attól, hogy egy MÁSIK
        // pénztárgéphez épp van-e nyitott műszak.
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Race teszt telephely 3']), 'name' => 'Race kassza 3', 'code' => 'RACE3']);
        $db->openCashSession($registerId, null, 1000.0);

        $saleId = $db->insertSale(500.0, 'Készpénz');
        $sale = $this->fetchSale($db, $saleId);
        $this->assertNull($sale['cash_session_id']);
    }

    public function testManualSaleAttachesToOpenSessionJustLikeARegularSale(): void
    {
        // "manual sale is maradjon működőképes" — a manual tételek a
        // készletlogikát kerülik el (lásd webroot/api/sale.php), de az
        // insertSale()/cash_session_id logika azonos minden eladásnál,
        // típustól függetlenül — ez itt közvetlenül az insertSale()-t
        // bizonyítja (a manual/nem-manual különbségtétel a hívó
        // sale.php-ban van, a készlet-csökkentésnél, nem itt).
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Race teszt telephely 4']), 'name' => 'Race kassza 4', 'code' => 'RACE4']);
        $sessionId = $db->openCashSession($registerId, null, 2000.0);

        $saleId = $db->insertSale(750.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, null, null, $registerId);
        $sale = $this->fetchSale($db, $saleId);
        $this->assertSame($sessionId, (int) $sale['cash_session_id']);
    }

    public function testIdempotencyKeyReplayProtectionStillWorksWithCashRegisterAttached(): void
    {
        // "ne törjön meg az idempotency" — az INSERT...SELECT forma is
        // UGYANAZT a sales.idempotency_key UNIQUE indexet üti meg, mint a
        // korábbi VALUES forma.
        $db = tests_new_database();
        $registerId = $db->saveCashRegister(['location_id' => $db->saveLocation(['name' => 'Race teszt telephely 5']), 'name' => 'Race kassza 5', 'code' => 'RACE5']);
        $db->openCashSession($registerId, null, 3000.0);

        $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'race-idem-key', null, $registerId);

        $this->expectException(PDOException::class);
        $db->insertSale(2000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, 'race-idem-key', null, $registerId);
    }

    private function fetchSale(Database $db, int $saleId): array
    {
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }
}
