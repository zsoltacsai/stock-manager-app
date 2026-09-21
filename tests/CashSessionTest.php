<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Kassza / műszakkezelés — egységszintű üzleti logika. A valódi, több-
 * folyamatos konkurrencia-bizonyítás (atomikus nyitás/zárás) a
 * DatabaseTest.php-ban van (lásd
 * testOpenCashSessionAtomicGuardPreventsDoubleOpenAcrossRealConcurrentProcesses()
 * / testCloseCashSessionAtomicGuardPreventsDoubleCloseAcrossRealConcurrentProcesses()) —
 * ez a fájl a formula-helyesség, a validáció és a Settings-backfill
 * egyszerű, egyfolyamatos eseteit fedi le.
 */
final class CashSessionTest extends TestCase
{
    private function seedRegister(Database $db): int
    {
        $locationId = $db->saveLocation(['name' => 'Teszt telephely']);
        return $db->saveCashRegister(['location_id' => $locationId, 'name' => 'Teszt kassza', 'code' => 'T' . bin2hex(random_bytes(3))]);
    }

    public function testOpenCashSessionRejectsASecondOpenOnTheSameRegister(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $db->openCashSession($registerId, null, 10000.0);

        $this->expectException(RuntimeException::class);
        $db->openCashSession($registerId, null, 5000.0);
    }

    public function testOpenCashSessionSucceedsAgainAfterThePreviousOneCloses(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $firstId = $db->openCashSession($registerId, null, 10000.0);
        $db->closeCashSession($firstId, 10000.0, ['Készpénz']);

        $secondId = $db->openCashSession($registerId, null, 8000.0);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame('open', $db->getCashSession($secondId)['status']);
    }

    public function testRecordCashMovementRejectsZeroAndNegativeAmounts(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);

        foreach ([0.0, -1.0, -0.01] as $badAmount) {
            try {
                $db->recordCashMovement($sessionId, null, 'cash_in', $badAmount, 'x');
                $this->fail("A(z) $badAmount összegű pénzmozgásnak el kellett volna utasítódnia.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('pozitív', $e->getMessage());
            }
        }
    }

    public function testRecordCashMovementRejectsAnInvalidType(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);

        $this->expectException(InvalidArgumentException::class);
        $db->recordCashMovement($sessionId, null, 'not_a_real_type', 100.0, 'x');
    }

    public function testRecordCashMovementRejectsAClosedSession(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        $db->closeCashSession($sessionId, 10000.0, ['Készpénz']);

        $this->expectException(RuntimeException::class);
        $db->recordCashMovement($sessionId, null, 'cash_in', 100.0, 'x');
    }

    public function testComputeExpectedCashOnlyCountsConfiguredCashPaymentMethods(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);

        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("INSERT INTO sales (total, payment_method, cash_session_id, created_at) VALUES (?, 'Készpénz', ?, datetime('now'))")
            ->execute([3000.0, $sessionId]);
        $pdo->prepare("INSERT INTO sales (total, payment_method, cash_session_id, created_at) VALUES (?, 'Bankkártya', ?, datetime('now'))")
            ->execute([7000.0, $sessionId]);
        $pdo->prepare("INSERT INTO sales (total, payment_method, cash_session_id, created_at) VALUES (?, 'Utánvét', ?, datetime('now'))")
            ->execute([2000.0, $sessionId]);

        // Csak a 'Készpénz'-t adjuk meg készpénzesnek -- 'Utánvét' NEM az,
        // hiába "készpénz-jellegű" a köznyelvben, amíg admin nem jelöli meg.
        $expected = $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']);
        $this->assertEqualsWithDelta(13000.0, $expected, 0.001, '10000 nyitó + 3000 készpénzes eladás, a kártyás/utánvétes eladás nem számít bele.');

        // Ha az admin 'Utánvét'-et is készpénzesnek jelöli (pl. utánvétet is
        // fizikai készpénzként kezel a bolt), a formula ezt is figyelembe veszi.
        $expectedWithUtanvet = $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz', 'Utánvét']);
        $this->assertEqualsWithDelta(15000.0, $expectedWithUtanvet, 0.001);
    }

    public function testComputeExpectedCashOnlySubtractsRefundsForOriginallyCashSales(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);

        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);

        $pdo->prepare("INSERT INTO sales (total, payment_method, cash_session_id, created_at) VALUES (?, 'Készpénz', ?, datetime('now'))")
            ->execute([5000.0, $sessionId]);
        $cashSaleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sales (total, payment_method, cash_session_id, created_at) VALUES (?, 'Bankkártya', ?, datetime('now'))")
            ->execute([4000.0, $sessionId]);
        $cardSaleId = (int) $pdo->lastInsertId();

        // Egy készpénzes eladás visszatérítése CSÖKKENTI a várható
        // készpénzt -- a kártyás eladás visszatérítése NEM.
        $pdo->prepare("INSERT INTO returns (sale_id, total_refund, cash_session_id, created_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$cashSaleId, 1000.0, $sessionId]);
        $pdo->prepare("INSERT INTO returns (sale_id, total_refund, cash_session_id, created_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$cardSaleId, 2000.0, $sessionId]);

        $expected = $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']);
        // 10000 nyitó + 5000 készpénzes eladás - 1000 készpénzes visszatérítés = 14000
        // (a kártyás eladás és annak visszatérítése egyáltalán nem számít bele).
        $this->assertEqualsWithDelta(14000.0, $expected, 0.001);
    }

    public function testCloseCashSessionRejectsAnAlreadyClosedSession(): void
    {
        $db = tests_new_database();
        $registerId = $this->seedRegister($db);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        $db->closeCashSession($sessionId, 10000.0, ['Készpénz']);

        $this->expectException(RuntimeException::class);
        $db->closeCashSession($sessionId, 10000.0, ['Készpénz']);
    }

    // -----------------------------------------------------------------
    // Settings::payment_methods 'is_cash' backfill (1.5.0 előtti
    // settings.json-okhoz) — lásd Settings::backfillPaymentMethodIsCash()
    // docblockja.
    // -----------------------------------------------------------------

    public function testSettingsBackfillsIsCashOnLegacyPaymentMethodsList(): void
    {
        $path = sys_get_temp_dir() . '/sm_settings_backfill_' . bin2hex(random_bytes(6)) . '.json';
        register_shutdown_function(static function () use ($path) { @unlink($path); });

        file_put_contents($path, json_encode([
            'payment_methods' => [
                ['value' => 'Készpénz', 'color' => '#16a34a'],
                ['value' => 'Átutalás', 'color' => '#a855f7'],
                ['value' => 'Stripe', 'color' => '#000000'],
            ],
        ]));

        $settings = new Settings($path);
        $data = $settings->read();
        $byValue = [];
        foreach ($data['payment_methods'] as $m) {
            $byValue[$m['value']] = $m['is_cash'] ?? null;
        }

        $this->assertTrue($byValue['Készpénz'], "Egy régi settings.json-ban a 'Készpénz' bejegyzésnek utólag is_cash=true-t kell kapnia.");
        $this->assertFalse($byValue['Átutalás']);
        $this->assertFalse($byValue['Stripe'], 'Egy bolt-egyedi, régi bejegyzésnek is_cash=false-t kell kapnia, nem hiányzó kulcsot.');
    }

    // -----------------------------------------------------------------
    // Séma-regresszió: mind a sales, mind a returns tábla kap
    // cash_session_id oszlopot — utóbbi könnyen kimaradhat, mert a
    // visszatérítés a visszatérítés PILLANATÁBAN nyitott műszakhoz kötődik,
    // nem az eredeti eladáséhoz (lásd computeExpectedCash() docblockja),
    // ami könnyen elfelejthető, ha valaki csak a "sales" mintát másolja.
    // -----------------------------------------------------------------

    public function testBothSalesAndReturnsGainCashSessionIdColumn(): void
    {
        $db = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);

        foreach (['sales', 'returns'] as $table) {
            $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $this->assertContains('cash_session_id', $cols, "A(z) $table táblának cash_session_id oszlopot kell kapnia.");
        }
    }

    public function testSettingsDefaultsAlreadyHaveIsCashOnEveryEntry(): void
    {
        $path = sys_get_temp_dir() . '/sm_settings_defaults_' . bin2hex(random_bytes(6)) . '.json';
        $settings = new Settings($path); // nincs fájl -> tiszta DEFAULTS
        $data = $settings->read();
        foreach ($data['payment_methods'] as $m) {
            $this->assertArrayHasKey('is_cash', $m);
        }
    }
}
