<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/UpdateLock.php';

use PHPUnit\Framework\TestCase;

final class UpdateLockTest extends TestCase
{
    public function testAcquireSucceedsWhenFree(): void
    {
        $db = tests_new_database();
        $lock = new UpdateLock($db, 'token-a', 'host-a');
        $this->assertTrue($lock->acquire());
        $this->assertTrue($db->isUpdateLockHeld());
    }

    public function testSecondAcquireFailsWhileHeld(): void
    {
        $db = tests_new_database();
        $first = new UpdateLock($db, 'token-a', 'host-a');
        $second = new UpdateLock($db, 'token-b', 'host-b');

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire(), 'Egy MÁR birtokolt zárat egy másik token nem szerezhet meg.');
    }

    public function testReleaseByNonOwnerTokenIsANoOp(): void
    {
        $db = tests_new_database();
        $owner = new UpdateLock($db, 'owner-token', 'host-a');
        $this->assertTrue($owner->acquire());

        // Egy MÁSIK (nem a birtokos) UpdateLock-példány próbálja felszabadítani.
        $impostor = new UpdateLock($db, 'impostor-token', 'host-b');
        $impostor->release();

        $this->assertTrue($db->isUpdateLockHeld(), 'A zárat csak a tényleges birtokos token oldhatja fel.');
    }

    public function testReleaseByOwnerActuallyFreesTheLock(): void
    {
        $db = tests_new_database();
        $lock = new UpdateLock($db, 'owner-token', 'host-a');
        $this->assertTrue($lock->acquire());

        $lock->release();

        $this->assertFalse($db->isUpdateLockHeld());
        $other = new UpdateLock($db, 'another-token', 'host-b');
        $this->assertTrue($other->acquire(), 'Felszabadítás után egy másik token ismét megszerezheti a zárat.');
    }

    public function testStaleLockCanBeReclaimedByAnotherProcess(): void
    {
        $db = tests_new_database();
        $stale = new UpdateLock($db, 'stale-token', 'crashed-host');
        $this->assertTrue($stale->acquire(3600));

        // Az "elavultságot" közvetlenül a DB-sorban szimuláljuk — egy
        // korábbi, ténylegesen összeomlott folyamat sose futtatta volna le
        // a release()-t.
        $db->pdo()->exec("UPDATE update_state SET lock_started_at = datetime('now', '-2 hours') WHERE id = 1");

        $recovering = new UpdateLock($db, 'recovering-token', 'new-host');
        $this->assertTrue($recovering->acquire(3600), 'Egy elavult (stale) zárnak felülírhatónak kell lennie egy új próbálkozás által.');
    }

    public function testFreshLockIsNotConsideredStale(): void
    {
        $db = tests_new_database();
        $holder = new UpdateLock($db, 'holder-token', 'host-a');
        $this->assertTrue($holder->acquire(3600));

        $challenger = new UpdateLock($db, 'challenger-token', 'host-b');
        $this->assertFalse($challenger->acquire(3600), 'Egy friss (nem elavult) zárat nem szabad felülírni.');
    }

    /**
     * Valódi, több különálló OS-folyamattal bizonyítja, hogy a zár
     * ténylegesen kizárja az egyidejű birtoklást — ugyanaz a minta, mint
     * DatabaseTest::testIncomingInvoiceSyncClaimIsAtomicAcrossRealConcurrentProcesses().
     * Ez a 18. pont ("Két cron vagy két admin request ne tudjon
     * párhuzamos update-et indítani") valódi, folyamat-szintű bizonyítéka —
     * nem csak az, hogy ugyanabban a PHP-folyamatban egymás után hívott
     * claimUpdateLock() helyesen viselkedik.
     */
    public function testUpdateLockClaimIsAtomicAcrossRealConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/ft_update_lock_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $processCount = 16;

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_update_lock_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_update_lock_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/UpdateLock.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $lock = new UpdateLock($db, 'proc-' . getmypid(), 'test-host-' . getmypid());
            if ($lock->acquire(1800)) {
                $start = microtime(true);
                usleep(300000);
                $end = microtime(true);
                $lock->release();
                file_put_contents($argv[3], "claimed,$start,$end\n", FILE_APPEND | LOCK_EX);
            } else {
                file_put_contents($argv[3], "skipped\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_update_lock_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
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
        $this->assertGreaterThanOrEqual(1, count($claimedWindows), 'Legalább egy folyamatnak sikerrel claim-elnie kellett a zárat.');
        $this->assertSame($processCount, count($claimedWindows) + $skippedCount);

        usort($claimedWindows, static fn ($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($claimedWindows); $i++) {
            $this->assertGreaterThanOrEqual(
                $claimedWindows[$i - 1]['end'],
                $claimedWindows[$i]['start'],
                'Két folyamat SOSE birtokolhatja egyidejűleg az update-zárat — ez párhuzamos telepítést jelentene.'
            );
        }
    }
}
