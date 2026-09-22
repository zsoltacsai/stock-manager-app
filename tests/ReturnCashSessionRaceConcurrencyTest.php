<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Release-blocker javítás — kasszaműszak-race a VISSZÁRU oldalán, VALÓDI,
 * különálló OS-folyamatokkal bizonyítva. Ugyanaz a minta, mint
 * tests/CashSessionSaleRaceConcurrencyTest.php az eladásoknál (lásd annak
 * docblokkja a teljes indoklásért) — itt "returner" gyerek-folyamatok
 * (processReturn()) versenyeznek egy "closer" folyamattal (closeCashSession())
 * UGYANARRA a cash_register_id-re.
 *
 * Minden "returner" a SAJÁT, előre, a kör elején létrehozott eladását adja
 * vissza (különálló sale/sale_item minden folyamathoz) — ez szándékosan
 * kerüli el, hogy a "már visszavett mennyiség" üzleti szabály (ami NEM
 * ennek a race-nek a tárgya) bármelyik folyamatot elutasítsa, így a teszt
 * kizárólag a cash_session_id-hozzárendelés race-ét vizsgálja izoláltan.
 *
 * A bizonyítandó invariáns — ugyanaz, mint a sale-oldali tesztben — NEM az,
 * hogy melyik folyamat "nyer", hanem hogy a returns.id (AUTOINCREMENT, a
 * tényleges commit-sorrendet tükrözi) szerint rendezett eredmény SOSE
 * "villog": ha egy visszáru már NULL cash_session_id-t kapott, egyetlen
 * KÉSŐBBI (nagyobb id-jű) visszáru SEM kaphatja vissza a (közben lezárt)
 * session azonosítóját.
 */
final class ReturnCashSessionRaceConcurrencyTest extends TestCase
{
    private const RETURNERS_PER_ROUND = 12;
    private const ROUNDS = 5;

    public function testRepeatedCloseVsReturnRaceNeverReattachesAClosedSession(): void
    {
        $dbPath = sys_get_temp_dir() . '/ft_return_race_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $locationId = $setupDb->saveLocation(['name' => 'Return konkurrencia race telephely']);
        $registerId = $setupDb->saveCashRegister(['location_id' => $locationId, 'name' => 'Return race konkurrencia kassza', 'code' => 'RRACECONC']);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_return_race_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $returnerScript = sys_get_temp_dir() . '/ft_return_race_returner_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($returnerScript, $this->returnerChildScript());
        register_shutdown_function(static function () use ($returnerScript) {
            @unlink($returnerScript);
        });

        $closerScript = sys_get_temp_dir() . '/ft_return_race_closer_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($closerScript, $this->closerChildScript());
        register_shutdown_function(static function () use ($closerScript) {
            @unlink($closerScript);
        });

        $devNull = sys_get_temp_dir() . '/ft_return_race_out_' . bin2hex(random_bytes(4)) . '.log';

        $anyRoundHadAClose = false;

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $roundDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
            $sessionId = $roundDb->openCashSession($registerId, null, 10000.0);
            $roundTag = 'RRACE-ROUND-' . $round;

            // Minden "returner" a SAJÁT, ELŐRE létrehozott eladását kapja —
            // lásd osztály-docblokk. A sale_id-kat a kör-tag-gel jelölt
            // reason mezőn keresztül azonosítjuk vissza az ellenőrzésnél.
            $saleIds = [];
            for ($i = 0; $i < self::RETURNERS_PER_ROUND; $i++) {
                $saleId = $roundDb->insertSale(10000.0, 'Készpénz');
                $roundDb->insertSaleItem($saleId, [
                    'product_id' => null,
                    'name'       => 'Konkurrencia visszáru tétel',
                    'qty'        => 100,
                    'unit_price' => 100.0,
                    'vat_rate'   => 27,
                ]);
                $saleIds[] = $saleId;
            }
            unset($roundDb);

            @unlink($resultFile);
            $handles = [];
            foreach ($saleIds as $saleId) {
                $handles[] = proc_open(
                    [PHP_BINARY, $returnerScript, $projectRoot, $dbPath, $resultFile, (string) $registerId, $roundTag, (string) $saleId],
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
            $returnerResults = array_values(array_filter($results, static fn ($r) => $r['role'] === 'returner'));

            $this->assertCount(1, $closerResults, 'Pontosan egy lezáró folyamatnak kellett futnia körönként.');
            $this->assertCount(self::RETURNERS_PER_ROUND, $returnerResults, "Mind a " . self::RETURNERS_PER_ROUND . " visszáru-folyamatnak sikeresen le kellett futnia (kivétel nélkül) körben #$round.");
            foreach ($returnerResults as $r) {
                $this->assertTrue($r['success'] ?? false, 'Egyetlen visszáru-folyamat sem szabadna, hogy kivétellel/hibával fusson le: ' . json_encode($r));
            }
            if ($closerResults[0]['closed'] ?? false) {
                $anyRoundHadAClose = true;
            }

            // A KULCS-ELLENŐRZÉS: a returns.id szerint (== tényleges commit-
            // sorrend SQLite-ban) rendezett cash_session_id-sorozatban, ha
            // egyszer NULL jelenik meg, utána SOSE térhet vissza a session
            // azonosítója.
            $pdoProp = new ReflectionProperty(Database::class, 'pdo');
            $pdoProp->setAccessible(true);
            $checkDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
            $pdo = $pdoProp->getValue($checkDb);
            $stmt = $pdo->prepare("SELECT id, cash_session_id FROM returns WHERE reason = ? ORDER BY id ASC");
            $stmt->execute([$roundTag]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(self::RETURNERS_PER_ROUND, $rows, "Körönként pontosan " . self::RETURNERS_PER_ROUND . " visszárunak kell rögzülnie (kör #$round).");

            $seenNull = false;
            foreach ($rows as $row) {
                if ($row['cash_session_id'] === null) {
                    $seenNull = true;
                    continue;
                }
                $this->assertFalse($seenNull, "Kör #$round: egy NULL cash_session_id UTÁN egy KÉSŐBBI (nagyobb id-jű) visszáru mégis a (feltehetően közben lezárt) session-hez kötődött — ez PONTOSAN a javítandó race tünete.");
                $this->assertSame((string) $sessionId, (string) $row['cash_session_id'], "Kör #$round: egy nem-NULL cash_session_id kizárólag a kör TÉNYLEGES session-jére mutathat, semmi másra.");
            }
        }

        $this->assertTrue($anyRoundHadAClose, 'Legalább egy körben a lezárás ténylegesen sikerült kellett legyen (különben a teszt nem bizonyít semmit).');
    }

    private function returnerChildScript(): string
    {
        return <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';

            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $registerId = (int) $argv[4];
            $roundTag = $argv[5];
            $saleId = (int) $argv[6];

            $sale = $db->getSaleWithItems($saleId);
            $item = $sale['items'][0];

            // Lásd tests/CashSessionSaleRaceConcurrencyTest.php seller-
            // szkriptjének docblokkja a retry indoklásáért — ugyanaz a
            // tolerált, tranziens Windows/SQLite fájlzárolási zaj, nem a
            // processReturn() tényleges logikájának hibája.
            $attempts = 0;
            while (true) {
                $attempts++;
                try {
                    // SZÁNDÉKOSAN NINCS előzetes getOpenCashSession()-
                    // lekérdezés itt — pontosan ez a lényeg: a nyers
                    // $registerId megy a processReturn()-nek, a tényleges
                    // session-választás az ő dolga, a beszúrás pillanatában.
                    $returnId = $db->processReturn(
                        $saleId,
                        [[
                            'sale_item_id' => (int) $item['id'],
                            'product_id'   => $item['product_id'],
                            'name'         => $item['name'],
                            'qty'          => 1,
                            'unit_price'   => (float) $item['unit_price'],
                        ]],
                        $roundTag,
                        null,
                        100.0,
                        $sale,
                        $registerId
                    );
                    file_put_contents($argv[3], json_encode(['role' => 'returner', 'success' => true, 'return_id' => $returnId]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (PDOException $e) {
                    if ($attempts < 25 && str_contains($e->getMessage(), 'locked')) {
                        usleep(20000);
                        continue;
                    }
                    file_put_contents($argv[3], json_encode(['role' => 'returner', 'success' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
                    break;
                } catch (Throwable $e) {
                    file_put_contents($argv[3], json_encode(['role' => 'returner', 'success' => false, 'error' => $e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
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
