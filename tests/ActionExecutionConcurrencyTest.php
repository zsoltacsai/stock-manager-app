<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8B — a kör 12/25. pontja: VALÓDI, több-folyamatos konkurrencia-
 * bizonyíték a végrehajtásra. UGYANAZ a proc_open-minta, mint
 * tests/ActionProposalConcurrencyTest.php (Fázis 8A) / DatabaseTest.php
 * cash-session tesztjei.
 */
final class ActionExecutionConcurrencyTest extends TestCase
{
    /** @return array{0:int,1:int} [proposalId, productId] */
    private function setUpApprovedProposal(string $dbPath, int $stockQty = 3, int $threshold = 10): array
    {
        $projectRoot = dirname(__DIR__);
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $productId = $db->saveProduct([
            'name' => 'Végrehajtás konkurrencia teszt termék',
            'barcode' => 'EXEC-' . bin2hex(random_bytes(4)),
            'price' => 1000,
            'net_price' => 787,
            'vat_rate' => 27,
            'low_stock_threshold' => $threshold,
        ]);
        $db->setStock($productId, $stockQty);

        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding([
            'type' => 'low_stock_elevated_sales', 'severity' => 'high', 'entity_type' => 'product',
            'entity_id' => $productId, 'entity_name' => 'Végrehajtás konkurrencia teszt termék', 'metric' => 'qty',
            'current_value' => 12.0, 'baseline_value' => 6.0, 'change_percent' => 100.0,
            'reason_code' => 'low_stock_with_sales_uplift',
        ], 'daily_intelligence', null, null, null);
        $service->approve((int) $row['id'], null);

        return [(int) $row['id'], $productId];
    }

    private function writeExecuteChildScript(string $path): void
    {
        file_put_contents($path, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require_once $argv[1] . '/src/Ai/ActionExecutor.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $executor = new ActionExecutor($db, []);
            $result = $executor->execute((int) $argv[3], null);
            if (($result['ok'] ?? false) && ($result['status'] ?? '') === 'executed' && empty($result['already_executed'])) {
                file_put_contents($argv[4], "executed\n", FILE_APPEND | LOCK_EX);
            } elseif (($result['ok'] ?? false) && !empty($result['already_executed'])) {
                file_put_contents($argv[4], "already_executed\n", FILE_APPEND | LOCK_EX);
            } elseif (!empty($result['reason'])) {
                file_put_contents($argv[4], $result['reason'] . "\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
    }

    // ------------------------------------------------------------------
    // A. két egyidejű execute ugyanarra a javaslatra -> pontosan EGY
    //    tényleges üzleti mutáció (piszkozat).
    // ------------------------------------------------------------------

    public function testConcurrentExecuteRequestsCreateExactlyOneDraft(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_exec_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath); @unlink($dbPath . '-shm'); @unlink($dbPath . '-wal');
        });
        $projectRoot = dirname(__DIR__);
        [$proposalId] = $this->setUpApprovedProposal($dbPath);

        $resultFile = sys_get_temp_dir() . '/sm_exec_concurrency_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) { @unlink($resultFile); });

        $childScriptPath = sys_get_temp_dir() . '/sm_exec_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        $this->writeExecuteChildScript($childScriptPath);
        register_shutdown_function(static function () use ($childScriptPath) { @unlink($childScriptPath); });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_exec_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < 12; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $h) { if (is_resource($h)) proc_close($h); }
        @unlink($devNull);

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $executedCount = count(array_filter($lines, static fn($l) => $l === 'executed'));
        $this->assertSame(1, $executedCount, 'Pontosan EGY folyamatnak kellett volna TÉNYLEGESEN végrehajtania (a többinek already_executing/already_executed választ kellett kapnia).');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $count = (int) $verifyDb->pdo()->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . $proposalId)->fetchColumn();
        $this->assertSame(1, $count, 'Pontosan EGY beszerzési piszkozatnak szabad létrejönnie, függetlenül a versengő kérések számától.');
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertSame('executed', $proposal['status']);
    }

    // ------------------------------------------------------------------
    // B. egy execute + egy MÁR lezajlott (a jóváhagyás után, a
    //    végrehajtás előtt bekövetkező) stale-t okozó készletváltozás
    //    versenyhelyzete -> zero business mutation.
    // ------------------------------------------------------------------

    public function testExecuteRacingWithStockChangeNeverCreatesDraftWhenStale(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_exec_stale_race_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath); @unlink($dbPath . '-shm'); @unlink($dbPath . '-wal');
        });
        $projectRoot = dirname(__DIR__);
        [$proposalId, $productId] = $this->setUpApprovedProposal($dbPath, 3, 10);

        // A készlet MÁR megváltozott, MIELŐTT a végrehajtási kísérletek
        // elindulnának — determinisztikusan minden kísérletnek stale-t
        // KELL adnia, sose piszkozatot.
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $db->setStock($productId, 99);
        unset($db);

        $resultFile = sys_get_temp_dir() . '/sm_exec_stale_race_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) { @unlink($resultFile); });
        $childScriptPath = sys_get_temp_dir() . '/sm_exec_stale_race_child_' . bin2hex(random_bytes(6)) . '.php';
        $this->writeExecuteChildScript($childScriptPath);
        register_shutdown_function(static function () use ($childScriptPath) { @unlink($childScriptPath); });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_exec_stale_race_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < 8; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $h) { if (is_resource($h)) proc_close($h); }
        @unlink($devNull);

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $executedCount = count(array_filter($lines, static fn($l) => $l === 'executed'));
        $this->assertSame(0, $executedCount, 'Elavult (stale) állapotban SOSE szabad ténylegesen végrehajtani.');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $count = (int) $verifyDb->pdo()->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . $proposalId)->fetchColumn();
        $this->assertSame(0, $count);
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertSame('stale', $proposal['status']);
    }

    // ------------------------------------------------------------------
    // C. két egyidejű újrapróbálkozás egy sikertelen végrehajtás UTÁN.
    // ------------------------------------------------------------------

    public function testConcurrentRetriesAfterFailureCreateExactlyOneDraft(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_exec_retry_race_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath); @unlink($dbPath . '-shm'); @unlink($dbPath . '-wal');
        });
        $projectRoot = dirname(__DIR__);
        [$proposalId] = $this->setUpApprovedProposal($dbPath);

        // Első kísérlet mesterségesen sikertelen (a piszkozat-tábla
        // ideiglenesen nincs meg) -> 'execution_failed'.
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $db->pdo()->exec('ALTER TABLE purchase_order_drafts RENAME TO purchase_order_drafts_tmp');
        $executor = new ActionExecutor($db, []);
        $first = $executor->execute($proposalId, null);
        $this->assertSame('execution_failed', $first['reason']);
        $db->pdo()->exec('ALTER TABLE purchase_order_drafts_tmp RENAME TO purchase_order_drafts');
        unset($db, $executor);

        $resultFile = sys_get_temp_dir() . '/sm_exec_retry_race_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) { @unlink($resultFile); });
        $childScriptPath = sys_get_temp_dir() . '/sm_exec_retry_race_child_' . bin2hex(random_bytes(6)) . '.php';
        $this->writeExecuteChildScript($childScriptPath);
        register_shutdown_function(static function () use ($childScriptPath) { @unlink($childScriptPath); });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_exec_retry_race_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < 10; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $h) { if (is_resource($h)) proc_close($h); }
        @unlink($devNull);

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $executedCount = count(array_filter($lines, static fn($l) => $l === 'executed'));
        $this->assertSame(1, $executedCount, 'A versengő újrapróbálkozások közül pontosan EGYnek kellett volna ténylegesen végrehajtania.');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $count = (int) $verifyDb->pdo()->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . $proposalId)->fetchColumn();
        $this->assertSame(1, $count);
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertSame('executed', $proposal['status']);
    }
}
