<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Ai/AiRateLimiter.php';

/**
 * AI-05 — AiRateLimiter::acquireRunSlot(): szereplőnként (dolgozó +
 * terminál) egyetlen futó AI-kérés, atomikus fájlzárral, plusz a meglévő
 * `ai_min_seconds_between_requests` az indítások között.
 */
final class AiRunSlotTest extends TestCase
{
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/sm_ai_slot_' . bin2hex(random_bytes(6));
        mkdir($this->lockDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->lockDir);
    }

    private function acquire(Database $db, ?int $staffId, ?int $clientId = null, int $minSeconds = 2): array
    {
        return AiRateLimiter::acquireRunSlot($db, ['ai_min_seconds_between_requests' => $minSeconds], $staffId, $clientId, true, $this->lockDir);
    }

    public function testSingleRequestAcquiresSlot(): void
    {
        $result = $this->acquire(tests_new_database(), 1);
        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(AiRunSlot::class, $result['slot']);
    }

    public function testParallelRequestOfSameActorIsRejectedWhileFirstRuns(): void
    {
        $db = tests_new_database();
        $first = $this->acquire($db, 1);

        $second = $this->acquire($db, 1);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_running', $second['reason']);
    }

    public function testRepeatedRequestRightAfterFinishIsThrottledByExistingSetting(): void
    {
        $db = tests_new_database();
        $first = $this->acquire($db, 1);
        $first['slot']->release();

        $second = $this->acquire($db, 1);

        $this->assertFalse($second['ok']);
        $this->assertSame('too_soon', $second['reason']);
        $this->assertGreaterThanOrEqual(1, $second['retry_after_seconds']);
    }

    public function testWindowResetAllowsNextRun(): void
    {
        $db = tests_new_database();
        $this->acquire($db, 1)['slot']->release();
        // Az előző indítás 5 másodperce volt (a slot-fájl az indítás idejét tárolja).
        foreach (glob($this->lockDir . '/ai-run-*.lock') as $f) { file_put_contents($f, (string) (time() - 5)); }

        $this->assertTrue($this->acquire($db, 1)['ok']);
    }

    public function testZeroMinIntervalStillAllowsOnlyOneConcurrentRun(): void
    {
        $db = tests_new_database();
        $first = $this->acquire($db, 1, null, 0);
        $this->assertFalse($this->acquire($db, 1, null, 0)['ok']);
        $first['slot']->release();
        $this->assertTrue($this->acquire($db, 1, null, 0)['ok'], 'min=0: befejezés után azonnal indítható');
    }

    public function testNonStreamModeSkipsMinIntervalButStillAllowsOnlyOneConcurrentRun(): void
    {
        $db = tests_new_database();
        $settings = ['ai_min_seconds_between_requests' => 2];
        $first = AiRateLimiter::acquireRunSlot($db, $settings, 1, null, false, $this->lockDir);
        $parallel = AiRateLimiter::acquireRunSlot($db, $settings, 1, null, false, $this->lockDir);
        $first['slot']->release();
        $repeat = AiRateLimiter::acquireRunSlot($db, $settings, 1, null, false, $this->lockDir);

        $this->assertTrue($first['ok']);
        $this->assertSame('already_running', $parallel['reason']);
        $this->assertTrue($repeat['ok'], 'egymás utáni nem-streamelt kérés továbbra is engedett');
    }

    public function testSlotIsReleasedWhenHolderGoesOutOfScope(): void
    {
        $db = tests_new_database();
        (function () use ($db) {
            $held = $this->acquire($db, 1, null, 0);
            $this->assertTrue($held['ok']);
        })();

        $this->assertTrue($this->acquire($db, 1, null, 0)['ok']);
    }

    public function testDifferentStaffAndDifferentTerminalsAreIndependent(): void
    {
        $db = tests_new_database();
        $holders = [
            $this->acquire($db, 1),          // 1. dolgozó a Szerver gépén
            $this->acquire($db, 2),          // 2. dolgozó a Szerver gépén
            $this->acquire($db, 1, 10),      // 1. dolgozó a 10-es kliens-gépen
            $this->acquire($db, 1, 11),      // 1. dolgozó a 11-es kliens-gépen
            $this->acquire($db, null, 12),   // dolgozó nélküli telepítés, 12-es kliens-gép
        ];
        foreach ($holders as $i => $h) {
            $this->assertTrue($h['ok'], "#$i szereplő nem blokkolhatja a többit");
        }
        $this->assertFalse($this->acquire($db, 1, 10)['ok'], 'ugyanazon a terminálon ugyanaz a dolgozó csak egyet futtathat');
    }

    public function testExistingCompletedRunCooldownIsStillHonoured(): void
    {
        $db = tests_new_database();
        $staffId = $db->saveStaff(['name' => 'Slot Admin', 'pin' => '61234', 'role' => 'admin']);
        $db->logAudit($staffId, 'ai_agent_run', 'ai', null, null, 30);

        $result = $this->acquire($db, $staffId);

        $this->assertFalse($result['ok']);
        $this->assertSame('too_soon', $result['reason']);
    }

    /** VALÓDI többfolyamatos verseny: 10 folyamat egyszerre ugyanarra a szereplőre → pontosan 1 slot. */
    public function testRealConcurrentProcessesGetExactlyOneSlot(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $projectRoot = dirname(__DIR__);
        $dbPath = $this->lockDir . '/db.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        unset($db);
        $resultFile = $this->lockDir . '/result.txt';
        $gate = $this->lockDir . '/gate';
        $child = $this->lockDir . '/child.php';
        file_put_contents($resultFile, '');
        file_put_contents($child, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require_once $argv[1] . '/src/Ai/AiRateLimiter.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            while (!is_file($argv[5])) { usleep(2000); }
            $r = AiRateLimiter::acquireRunSlot($db, ['ai_min_seconds_between_requests' => 2], 7, null, true, $argv[3]);
            file_put_contents($argv[4], ($r['ok'] ? 'ok' : $r['reason']) . "\n", FILE_APPEND | LOCK_EX);
            if ($r['ok']) { usleep(1500000); }
            PHP);
        $handles = [];
        for ($i = 0; $i < 10; $i++) {
            $handles[] = proc_open([PHP_BINARY, $child, $projectRoot, $dbPath, $this->lockDir, $resultFile, $gate], [1 => ['file', $this->lockDir . '/log', 'a'], 2 => ['file', $this->lockDir . '/log', 'a']], $pipes);
        }
        usleep(500000);
        touch($gate);
        foreach ($handles as $h) { if (is_resource($h)) proc_close($h); }

        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($resultFile)))));
        $this->assertCount(10, $lines, (string) @file_get_contents($this->lockDir . '/log'));
        $this->assertSame(1, count(array_filter($lines, static fn ($l) => $l === 'ok')));
        $this->assertSame(9, count(array_filter($lines, static fn ($l) => $l === 'already_running' || $l === 'too_soon')));
    }
}
