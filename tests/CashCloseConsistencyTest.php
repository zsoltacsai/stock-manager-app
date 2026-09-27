<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/SqlCapturePdo.php';

/**
 * Phase 5 remediáció — DB-03 (P-A): a kasszazárás egyetlen konzisztens
 * állapotból számol. A zárás első utasítása egy írás a műszak során (SQLite:
 * kizárólagos író-zár; InnoDB: X-zár a műszak során, a SUM-ok pillanatképe a
 * zár UTÁN jön létre). A zárás közben érkező mozgás/eladás a zárás commitjáig
 * vár, utána lezárt műszakot lát — csendes kihagyás nincs. Valódi SQLite
 * folyamatokkal; a MySQL-ág SQL-rögzítéssel ellenőrzött (élő MySQL nincs).
 */
final class CashCloseConsistencyTest extends TestCase
{
    private function tempDb(?string &$path): Database
    {
        $path = sys_get_temp_dir() . '/sm_db03_' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            foreach (['', '-wal', '-shm', '.reading', '.results', '.child.php', '.log'] as $s) { @unlink($path . $s); }
        });
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
    }

    /** @return array{0:int,1:int} [registerId, sessionId] */
    private function openSession(Database $db): array
    {
        $loc = $db->saveLocation(['name' => 'L', 'address' => '', 'is_default' => 1]);
        $reg = $db->saveCashRegister(['location_id' => $loc, 'name' => 'K', 'code' => 'K-' . bin2hex(random_bytes(3))]);
        $sid = $db->openCashSession($reg, null, 100.0);
        $db->recordCashMovement($sid, null, 'cash_in', 50.0, 'előzetes');
        return [$reg, $sid];
    }

    public function testRealConcurrentMovementAndSaleDuringCloseAreNeverSilentlyOmitted(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $db = $this->tempDb($path);
        [$reg, $sid] = $this->openSession($db);
        $root = dirname(__DIR__);
        file_put_contents($path . '.results', '');
        file_put_contents($path . '.child.php', <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            final class SlowCloseDb extends Database {
                public ?string $marker = null;
                public function computeExpectedCash(array $session, array $methods): float {
                    $r = parent::computeExpectedCash($session, $methods);
                    if ($this->marker) { touch($this->marker); usleep(1500000); }
                    return $r;
                }
            }
            [$_, $root, $path, $role, $sid, $reg, $out] = $argv;
            $cfg = ['driver' => 'sqlite', 'sqlite' => ['path' => $path]];
            try {
                if ($role === 'close') {
                    $db = new SlowCloseDb($cfg, $root);
                    $db->marker = $path . '.reading';
                    $res = $db->closeCashSession((int) $sid, 150.0, ['Készpénz']);
                    $r = 'close:ok:' . $res['expected_amount'];
                } else {
                    $db = new Database($cfg, $root);
                    while (!is_file($path . '.reading')) { usleep(2000); }
                    if ($role === 'movement') {
                        $db->recordCashMovement((int) $sid, null, 'cash_in', 25.0, 'zárás közben');
                        $r = 'movement:ok';
                    } else {
                        $db->beginTransaction();
                        $saleId = $db->insertSale(40.0, 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, (int) $reg);
                        $db->commit();
                        $r = 'sale:ok:' . $saleId;
                    }
                }
            } catch (Throwable $e) {
                $r = $role . ':fail:' . $e->getMessage();
            }
            file_put_contents($out, $r . "\n", FILE_APPEND | LOCK_EX);
            PHP);
        $handles = [];
        foreach (['close', 'movement', 'sale'] as $role) {
            $handles[] = proc_open([PHP_BINARY, $path . '.child.php', $root, $path, $role, (string) $sid, (string) $reg, $path . '.results'], [1 => ['file', $path . '.log', 'a'], 2 => ['file', $path . '.log', 'a']], $pipes);
        }
        foreach ($handles as $h) {
            proc_close($h);
        }
        $results = array_values(array_filter(explode("\n", trim((string) file_get_contents($path . '.results')))));
        $byRole = [];
        foreach ($results as $r) {
            $byRole[explode(':', $r)[0]] = $r;
        }

        $this->assertStringStartsWith('close:ok:', $byRole['close'] ?? '', 'a zárás nem bukik el a párhuzamos írás miatt: ' . implode(' | ', $results));
        $verify = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], $root);
        $session = $verify->getCashSession($sid);
        $this->assertSame('closed', $session['status']);
        // Az invariáns: a lezárt műszakhoz tartozó MINDEN sor benne van a tárolt várható összegben.
        $this->assertEqualsWithDelta($verify->computeExpectedCash($session, ['Készpénz']), (float) $session['expected_amount'], 0.001, 'nincs a zárás után a műszakhoz rögzített, de kihagyott sor');
        // A zárás alatt érkező mozgás vagy előbb rögzült (és benne van), vagy elutasítva — soha nem "rögzítve, de kihagyva".
        $this->assertMatchesRegularExpression('/^movement:(ok|fail:Ez a műszak nincs nyitva)/', $byRole['movement'] ?? '');
    }

    public function testCloseStillRejectsAnAlreadyClosedSession(): void
    {
        $db = tests_new_database();
        [, $sid] = $this->openSession($db);
        $db->closeCashSession($sid, 150.0, ['Készpénz']);

        $this->expectExceptionMessage('le van zárva');
        $db->closeCashSession($sid, 150.0, ['Készpénz']);
    }

    /** MySQL-ág (SQL-rögzítés): a tranzakció első utasítása a műszak sorának zároló írása, az összesítések előtt. */
    public function testMysqlPathLocksTheSessionRowBeforeAnyRead(): void
    {
        $pdo = new SqlCapturePdo();
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'mysql', 'pdo' => $pdo, 'dbConfig' => ['driver' => 'mysql']] as $k => $v) {
            $rp = new ReflectionProperty(Database::class, $k);
            $rp->setAccessible(true);
            $rp->setValue($db, $v);
        }
        $pdo->results['/^SELECT \* FROM cash_sessions WHERE id = \?$/'] = [['id' => 5, 'status' => 'open', 'opening_amount' => 100]];

        $db->closeCashSession(5, 100.0, ['Készpénz']);

        $sql = $pdo->statements();
        $this->assertSame('BEGIN', $sql[0]);
        $this->assertSame('UPDATE cash_sessions SET status = status WHERE id = ?', $sql[1], 'a zár megszerzése az első utasítás — előtte nincs konzisztens (pillanatkép-) olvasás');
        $firstSum = array_key_first(array_filter($sql, fn ($s) => str_starts_with($s, 'SELECT COALESCE(SUM')));
        $this->assertGreaterThan(1, $firstSum);
        $this->assertSame('COMMIT', end($sql));
    }
}
