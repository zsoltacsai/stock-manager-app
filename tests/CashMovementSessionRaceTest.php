<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N-1 (post-remediation audit) regresszió — pénzmozgás egy közben lezárt
 * kasszaműszakba.
 *
 * A hiba: Database::recordCashMovement() egy külön "nyitott-e a műszak"
 * ellenőrzés UTÁN egy feltétel nélküli INSERT-tel rögzített; egy KÖZBEN
 * lezárt műszakhoz is beíródott a mozgás (az audit reprodukciója: tárolt
 * elvárt összeg 10 000, valós 7 000). A javítás: atomikus
 * INSERT ... SELECT ... FROM cash_sessions WHERE id = ? AND status = 'open'.
 *
 * A többfolyamatos tesztek VALÓDI külön PHP-folyamatokat indítanak
 * ugyanazon az SQLite-adatbázison (REAL MULTI-PROCESS), ugyanazzal az
 * interleavinggel, amivel az audit a hibát reprodukálta: a teszt-folyamat
 * író-zárat tart, a gyerek a nyitottság-ellenőrzés után az írásnál vár.
 */
final class CashMovementSessionRaceTest extends TestCase
{
    private function dbPath(Database $db): string
    {
        $prop = new ReflectionProperty(Database::class, 'dbConfig');
        $prop->setAccessible(true);
        return $prop->getValue($db)['sqlite']['path'];
    }

    private function openSession(Database $db, float $opening = 10000.0): int
    {
        $loc = $db->saveLocation(['name' => 'N1 ' . bin2hex(random_bytes(3))]);
        $reg = $db->saveCashRegister(['location_id' => $loc, 'name' => 'N1', 'code' => 'N1' . bin2hex(random_bytes(3))]);
        return $db->openCashSession($reg, null, $opening);
    }

    /** Egy gyerekfolyamat, ami Database::recordCashMovement()-et hív; kimenete JSON. */
    private function spawnMovement(string $dbPath, int $sessionId, float $amount, string $key = '')
    {
        $script = sys_get_temp_dir() . '/sm_n1_move_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';
$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');
try { echo json_encode(["ok" => true, "id" => $db->recordCashMovement((int) $argv[2], null, "cash_out", (float) $argv[3], "N1", $argv[4] !== "" ? $argv[4] : null)]); }
catch (Throwable $e) { echo json_encode(["ok" => false, "class" => get_class($e), "error" => $e->getMessage()]); }');
        $proc = proc_open([PHP_BINARY, $script, $dbPath, (string) $sessionId, (string) $amount, $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$proc, $pipes, $script];
    }

    private function finish(array $spawned): array
    {
        [$proc, $pipes, $script] = $spawned;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($script);
        $json = json_decode(trim($out), true);
        $this->assertIsArray($json, 'Gyerekfolyamat kimenete: ' . $out);
        return $json;
    }

    /** Egy Database, ami egy MÁR író-zárat tartó PDO-n fut (a zárás "a zár alatt"). */
    private function databaseOn(PDO $pdo): Database
    {
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['pdo' => $pdo, 'driver' => 'sqlite', 'dbConfig' => []] as $k => $v) {
            $p = new ReflectionProperty(Database::class, $k);
            $p->setAccessible(true);
            $p->setValue($db, $v);
        }
        return $db;
    }

    private function assertReconciled(Database $db, int $sessionId): void
    {
        $s = $db->getCashSession($sessionId);
        if ($s['status'] === 'closed') {
            $this->assertEqualsWithDelta((float) $s['expected_amount'], $db->computeExpectedCash($s, ['Készpénz']), 0.001,
                'Lezárt műszak + beleírt, az elvárt összegből kimaradt mozgás — az N-1 hibaállapot.');
        }
    }

    public function testNormalMovementIsRecorded(): void
    {
        $db = tests_new_database();
        $sid = $this->openSession($db);
        $id = $db->recordCashMovement($sid, null, 'cash_in', 500.0, 'betét');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(10500.0, $db->computeExpectedCash($db->getCashSession($sid), ['Készpénz']));
    }

    public function testMovementIntoAnAlreadyClosedSessionIsRejectedAndNothingIsWritten(): void
    {
        $db = tests_new_database();
        $sid = $this->openSession($db);
        $db->closeCashSession($sid, 10000.0, ['Készpénz']);

        try {
            $db->recordCashMovement($sid, null, 'cash_out', 3000.0, 'késő');
            $this->fail('Lezárt műszakba nem rögzíthető mozgás.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nincs nyitva', $e->getMessage());
        }
        $this->assertSame(0, (int) $db->pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = $sid")->fetchColumn());
    }

    public function testMovementIntoAnUnknownSessionIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(RuntimeException::class);
        $db->recordCashMovement(987654, null, 'cash_in', 1.0, 'nincs ilyen');
    }

    public function testAuditInterleavingCloseWinsAndTheMovementIsRejected(): void
    {
        // Az audit N-1 reprodukciója: a mozgás a nyitottság-ellenőrzés után
        // az írásnál vár, közben a zárás lefut és commitol.
        $db = tests_new_database();
        $sid = $this->openSession($db);
        $path = $this->dbPath($db);

        $lock = new PDO('sqlite:' . $path);
        $lock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lock->exec('PRAGMA busy_timeout = 5000');
        $lock->exec('BEGIN IMMEDIATE');
        $child = $this->spawnMovement($path, $sid, 3000.0);
        usleep(1_200_000); // a gyerek betöltött, a műszakot (régi kódban) nyitottnak látta, az írásnál vár

        $closer = $this->databaseOn($lock); // a closeCashSession() utasításai, a zár alatt
        $session = $closer->getCashSession($sid);
        $expected = $closer->computeExpectedCash($session, ['Készpénz']);
        $lock->prepare("UPDATE cash_sessions SET status = 'closed', closing_amount = ?, expected_amount = ?, variance = 0, closed_at = ? WHERE id = ? AND status = 'open'")
            ->execute([$expected, $expected, date('Y-m-d H:i:s'), $sid]);
        $lock->exec('COMMIT');

        $result = $this->finish($child);
        $this->assertFalse($result['ok'], 'A zárás nyert — a mozgásnak el kell buknia: ' . json_encode($result));
        $this->assertSame('RuntimeException', $result['class']);
        $this->assertSame(0, (int) $db->pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = $sid")->fetchColumn());
        $this->assertReconciled($db, $sid);
    }

    public function testReverseInterleavingMovementWinsAndTheCloseCountsIt(): void
    {
        // A mozgás nyer: a zárás vagy újrapróbálandó hibával elbukik (SQLite
        // az elavult pillanatképről nem vált írásra), vagy — ha utána fut —
        // az elvárt összegbe beszámítja. Soha nem "lezárt + kimaradt".
        $db = tests_new_database();
        $sid = $this->openSession($db);
        $path = $this->dbPath($db);

        $lock = new PDO('sqlite:' . $path);
        $lock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lock->exec('PRAGMA busy_timeout = 5000');
        $lock->exec('BEGIN IMMEDIATE');
        $script = sys_get_temp_dir() . '/sm_n1_close_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';
$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');
try { echo json_encode(["ok" => true] + $db->closeCashSession((int) $argv[2], 7000.0, ["Készpénz"])); }
catch (Throwable $e) { echo json_encode(["ok" => false, "class" => get_class($e), "error" => $e->getMessage()]); }');
        $proc = proc_open([PHP_BINARY, $script, $path, (string) $sid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        usleep(1_200_000);
        $this->databaseOn($lock)->recordCashMovement($sid, null, 'cash_out', 3000.0, 'N1 fordított');
        $lock->exec('COMMIT');
        $result = $this->finish([$proc, $pipes, $script]);

        $s = $db->getCashSession($sid);
        $this->assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE cash_session_id = $sid")->fetchColumn());
        if ($result['ok']) {
            $this->assertSame(7000.0, (float) $result['expected_amount'], 'A zárás beszámította a mozgást.');
        } else {
            $this->assertSame('open', $s['status'], 'A zárás elbukott — a műszak nyitva maradt, újra lezárható: ' . json_encode($result));
            $this->assertSame(7000.0, $db->closeCashSession($sid, 7000.0, ['Készpénz'])['expected_amount']);
        }
        $this->assertReconciled($db, $sid);
    }

    public function testManyRoundsOfRealConcurrentMovementsAndCloseKeepEveryClosedSessionReconciled(): void
    {
        $db = tests_new_database();
        $path = $this->dbPath($db);
        for ($round = 0; $round < 6; $round++) {
            $sid = $this->openSession($db);
            $children = [];
            for ($i = 0; $i < 3; $i++) {
                $children[] = $this->spawnMovement($path, $sid, 100.0 + $i);
            }
            $closerScript = sys_get_temp_dir() . '/sm_n1_c_' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($closerScript, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';
$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');
for ($i = 0; $i < 20; $i++) { try { $db->closeCashSession((int) $argv[2], 0.0, ["Készpénz"]); echo "closed"; exit; } catch (Throwable $e) { if (str_contains($e->getMessage(), "le van zárva")) { echo "already"; exit; } usleep(50000); } } echo "gaveup";');
            $closer = proc_open([PHP_BINARY, $closerScript, $path, (string) $sid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp);
            foreach ($children as $child) {
                $this->finish($child);
            }
            $closeOut = trim(stream_get_contents($cp[1]));
            proc_close($closer);
            @unlink($closerScript);
            $this->assertSame('closed', $closeOut);
            $this->assertReconciled($db, $sid);
        }
    }

    public function testOtherSessionsAreUnaffected(): void
    {
        $db = tests_new_database();
        $a = $this->openSession($db);
        $b = $this->openSession($db);
        $db->closeCashSession($b, 10000.0, ['Készpénz']);

        $db->recordCashMovement($a, null, 'cash_in', 200.0, 'A-ba');
        try {
            $db->recordCashMovement($b, null, 'cash_in', 200.0, 'B-be');
            $this->fail('A lezárt B műszakba nem rögzíthető.');
        } catch (RuntimeException $e) {
        }
        $this->assertSame(10200.0, $db->computeExpectedCash($db->getCashSession($a), ['Készpénz']));
    }

    public function testDuplicateIdempotencyKeyStillRaisesTheUniqueViolationTheEndpointReplays(): void
    {
        $db = tests_new_database();
        $sid = $this->openSession($db);
        $db->recordCashMovement($sid, null, 'cash_in', 50.0, 'x', 'n1-key');
        try {
            $db->recordCashMovement($sid, null, 'cash_in', 50.0, 'x', 'n1-key');
            $this->fail('Ugyanaz a kulcs nem rögzíthető kétszer.');
        } catch (PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE idempotency_key = 'n1-key'")->fetchColumn());
    }
}
