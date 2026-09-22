<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — kasszaműszak-race javítás, VALÓDI, különálló OS-
 * folyamatokkal bizonyítva (ugyanaz a minta, mint
 * tests/PurchaseAndWcPushConcurrencyTest.php / tests/InvoiceOperationConcurrencyTest.php:
 * `proc_open()` gyerek-PHP-szkriptek, UGYANAZON a megosztott SQLite fájlon).
 *
 * Minden KÖRBEN: egy friss műszak nyílik egy közös pénztárgéphez, majd
 * EGYSZERRE indul egy "lezáró" folyamat és több "eladó" folyamat, mind
 * UGYANARRA a cash_register_id-re — pontosan a design dokumentum HIGH
 * kockázatként azonosított forgatókönyve, valódi versenyhelyzetben.
 *
 * A bizonyítandó invariáns NEM az, hogy melyik folyamat "nyer" (ez
 * eredendően nem-determinisztikus egy valódi versenyben) — hanem hogy a
 * SQLite egyetlen-írós szerializációja miatt a sales.id (AUTOINCREMENT,
 * a tényleges commit-sorrendet tükrözi) szerint rendezett eredmény SOSE
 * "villog": ha egy eladás már NULL cash_session_id-t kapott (mert a
 * lezárás akkor már megtörtént), egyetlen KÉSŐBBI (nagyobb id-jű) eladás
 * SEM kaphatja vissza a (közben lezárt) session azonosítóját. Ez pontosan
 * a "sale soha nem kerül már lezárt sessionbe" + "ne legyen silent orphan
 * sale" követelmény közvetlen, végrehajtható bizonyítéka.
 */
final class CashSessionSaleRaceConcurrencyTest extends TestCase
{
    private const SELLERS_PER_ROUND = 12;
    private const ROUNDS = 5;

    public function testRepeatedCloseVsSaleRaceNeverReattachesAClosedSession(): void
    {
        $dbPath = sys_get_temp_dir() . '/ft_cash_race_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $locationId = $setupDb->saveLocation(['name' => 'Konkurrencia race telephely']);
        $registerId = $setupDb->saveCashRegister(['location_id' => $locationId, 'name' => 'Race konkurrencia kassza', 'code' => 'RACECONC']);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_cash_race_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $sellerScript = sys_get_temp_dir() . '/ft_cash_race_seller_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($sellerScript, $this->sellerChildScript());
        register_shutdown_function(static function () use ($sellerScript) {
            @unlink($sellerScript);
        });

        $closerScript = sys_get_temp_dir() . '/ft_cash_race_closer_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($closerScript, $this->closerChildScript());
        register_shutdown_function(static function () use ($closerScript) {
            @unlink($closerScript);
        });

        $devNull = sys_get_temp_dir() . '/ft_cash_race_out_' . bin2hex(random_bytes(4)) . '.log';

        $anyRoundHadAClose = false;

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $roundDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
            $sessionId = $roundDb->openCashSession($registerId, null, 10000.0);
            $roundTag = 'RACE-ROUND-' . $round;
            unset($roundDb);

            @unlink($resultFile);
            $handles = [];
            // Az "eladó" folyamatok.
            for ($s = 0; $s < self::SELLERS_PER_ROUND; $s++) {
                $handles[] = proc_open(
                    [PHP_BINARY, $sellerScript, $projectRoot, $dbPath, $resultFile, (string) $registerId, $roundTag],
                    [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                    $pipes
                );
            }
            // A "lezáró" folyamat — UGYANEBBEN a pillanatban indítva.
            $handles[] = proc_open(
                [PHP_BINARY, $closerScript, $projectRoot, $dbPath, $resultFile, (string) $sessionId],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );

            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    proc_close($handle);
                }
            }

            $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
            $results = array_map(static fn (string $l) => json_decode($l, true), $lines);

            $closerResults = array_values(array_filter($results, static fn ($r) => $r['role'] === 'closer'));
            $sellerResults = array_values(array_filter($results, static fn ($r) => $r['role'] === 'seller'));

            $this->assertCount(1, $closerResults, 'Pontosan egy lezáró folyamatnak kellett futnia körönként.');
            $this->assertCount(self::SELLERS_PER_ROUND, $sellerResults, "Mind a " . self::SELLERS_PER_ROUND . " eladó folyamatnak sikeresen le kellett futnia (kivétel nélkül) körben #$round.");
            foreach ($sellerResults as $r) {
                $this->assertTrue($r['success'] ?? false, 'Egyetlen eladó folyamat sem szabadna, hogy kivétellel/hibával fusson le: ' . json_encode($r));
            }
            if ($closerResults[0]['closed'] ?? false) {
                $anyRoundHadAClose = true;
            }

            // A KULCS-ELLENŐRZÉS: a sales.id szerint (== tényleges commit-
            // sorrend SQLite-ban) rendezett cash_session_id-sorozatban, ha
            // egyszer NULL jelenik meg, utána SOSE térhet vissza a session
            // azonosítója — "villogás" a lezárás előtti/utáni állapot közt
            // pontosan a régi hiba tünete lett volna.
            $pdoProp = new ReflectionProperty(Database::class, 'pdo');
            $pdoProp->setAccessible(true);
            $checkDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
            $pdo = $pdoProp->getValue($checkDb);
            $stmt = $pdo->prepare("SELECT id, cash_session_id FROM sales WHERE payment_method = ? ORDER BY id ASC");
            $stmt->execute([$roundTag]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(self::SELLERS_PER_ROUND, $rows, "Körönként pontosan " . self::SELLERS_PER_ROUND . " eladásnak kell rögzülnie (kör #$round).");

            $seenNull = false;
            foreach ($rows as $row) {
                if ($row['cash_session_id'] === null) {
                    $seenNull = true;
                    continue;
                }
                $this->assertFalse($seenNull, "Kör #$round: egy NULL cash_session_id UTÁN egy KÉSŐBBI (nagyobb id-jű) eladás mégis a (feltehetően közben lezárt) session-hez kötődött — ez PONTOSAN a javítandó race tünete.");
                $this->assertSame((string) $sessionId, (string) $row['cash_session_id'], "Kör #$round: egy nem-NULL cash_session_id kizárólag a kör TÉNYLEGES session-jére mutathat, semmi másra.");
            }
        }

        $this->assertTrue($anyRoundHadAClose, 'Legalább egy körben a lezárás ténylegesen sikerült kellett legyen (különben a teszt nem bizonyít semmit).');
    }

    private function sellerChildScript(): string
    {
        return <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';

            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $registerId = (int) $argv[4];
            $roundTag = $argv[5];

            // A PRAGMA busy_timeout (lásd Database.php konstruktor) a
            // legtöbb tranziens "database is locked" esetet lefedi, de
            // Windows alatt, sok VALÓDI, külön OS-folyamat által egyszerre
            // indított rövid tranzakció esetén elvétve MÉG a timeout-on
            // belül is előfordulhat egy azonnali SQLITE_BUSY — ez a
            // konkurrencia-teszt INFRASTRUKTÚRÁJÁNAK (nem az insertSale()
            // tényleges logikájának) egy ismert, tolerált jelensége,
            // ugyanúgy, mint a meglévő DatabaseTest.php atomikus-guard
            // tesztjeiben (lásd testOpenCashSessionAtomicGuard... — ott a
            // catch csak RuntimeException-t vár, egy esetleges PDOException
            // egyszerűen "vesztesként" jelenne meg). Itt EXPLICIT, rövid
            // újrapróbálkozással kezeljük, hogy a teszt a TÉNYLEGES race-
            // viselkedést bizonyítsa, ne a Windows/SQLite fájlzárolás
            // esetleges zaját.
            $attempts = 0;
            while (true) {
                $attempts++;
                try {
                    // SZÁNDÉKOSAN NINCS előzetes getOpenCashSession()-lekérdezés
                    // itt — pontosan ez a lényeg: a nyers $registerId megy az
                    // insertSale()-nek, a tényleges session-választás az ő
                    // dolga, a beszúrás pillanatában.
                    $saleId = $db->insertSale(100.0, $roundTag, null, null, 0, 0, null, 0, 0, null, null, null, $registerId);
                    file_put_contents($argv[3], json_encode(['role' => 'seller', 'success' => true, 'sale_id' => $saleId]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (PDOException $e) {
                    if ($attempts < 25 && str_contains($e->getMessage(), 'locked')) {
                        usleep(20000);
                        continue;
                    }
                    file_put_contents($argv[3], json_encode(['role' => 'seller', 'success' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (Throwable $e) {
                    file_put_contents($argv[3], json_encode(['role' => 'seller', 'success' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                }
            }
            PHP;
    }

    private function closerChildScript(): string
    {
        return <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';

            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $sessionId = (int) $argv[4];

            // Lásd a seller-szkript docblokkja a retry indoklásáért — ugyanaz
            // a tolerált, tranziens Windows/SQLite fájlzárolási zaj.
            $attempts = 0;
            while (true) {
                $attempts++;
                try {
                    $db->closeCashSession($sessionId, 10000.0, []);
                    file_put_contents($argv[3], json_encode(['role' => 'closer', 'closed' => true]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (PDOException $e) {
                    if ($attempts < 25 && str_contains($e->getMessage(), 'locked')) {
                        usleep(20000);
                        continue;
                    }
                    file_put_contents($argv[3], json_encode(['role' => 'closer', 'closed' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (Throwable $e) {
                    file_put_contents($argv[3], json_encode(['role' => 'closer', 'closed' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                }
            }
            PHP;
    }
}
