<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — a kör 17/18/31. pontja: VALÓDI, több-folyamatos konkurrencia-
 * bizonyíték az állapotátmenetekre. UGYANAZ a proc_open-mintát követi,
 * mint DatabaseTest::testCloseCashSessionAtomicGuardPreventsDoubleClose
 * AcrossRealConcurrentProcesses() — lásd ott a docblokkot a minta
 * indoklásáért.
 */
final class ActionProposalConcurrencyTest extends TestCase
{
    private function setUpProposal(string $dbPath, int $stockQty = 8): array
    {
        $projectRoot = dirname(__DIR__);
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $productId = $db->saveProduct([
            'name' => 'Konkurrencia teszt termék',
            'barcode' => 'CONC-' . bin2hex(random_bytes(4)),
            'price' => 1000,
            'net_price' => 787,
            'vat_rate' => 27,
        ]);
        $db->setStock($productId, $stockQty);
        $staffId = $db->saveStaff(['name' => 'Konkurrencia Admin', 'pin' => '1234', 'role' => 'admin']);

        $service = new ActionProposalService($db, []);
        $finding = [
            'type' => 'low_stock_elevated_sales',
            'severity' => 'high',
            'entity_type' => 'product',
            'entity_id' => $productId,
            'entity_name' => 'Konkurrencia teszt termék',
            'metric' => 'qty',
            'current_value' => 12.0,
            'baseline_value' => 6.0,
            'change_percent' => 100.0,
            'reason_code' => 'low_stock_with_sales_uplift',
        ];
        $row = $service->createFromFinding($finding, 'daily_intelligence', null, null, null);

        return [(int) $row['id'], $productId, $staffId];
    }

    public function testConcurrentApprovalsOnlyOneSucceeds(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_proposal_approve_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        [$proposalId, , $staffId] = $this->setUpProposal($dbPath);

        $resultFile = sys_get_temp_dir() . '/sm_proposal_approve_concurrency_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_proposal_approve_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/Ai/ActionProposal.php';
            require $argv[1] . '/src/Ai/ActionProposalService.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $service = new ActionProposalService($db, []);
            $result = $service->approve((int) $argv[3], (int) $argv[4]);
            if ($result['ok']) {
                file_put_contents($argv[5], "approved\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $processCount = 12;
        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_proposal_approve_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, (string) $staffId, $resultFile],
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

        $successes = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $this->assertCount(
            1,
            $successes,
            'Pontosan EGY folyamatnak kellett volna sikeresen jóváhagynia a javaslatot.'
        );

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertSame('approved', $proposal['status']);
    }

    public function testApprovalAndRejectionRaceOnlyOneSucceeds(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_proposal_race_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        [$proposalId, , $staffId] = $this->setUpProposal($dbPath);

        $resultFile = sys_get_temp_dir() . '/sm_proposal_race_concurrency_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_proposal_race_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/Ai/ActionProposal.php';
            require $argv[1] . '/src/Ai/ActionProposalService.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $service = new ActionProposalService($db, []);
            $action = $argv[6];
            $result = $action === 'approve'
                ? $service->approve((int) $argv[3], (int) $argv[4])
                : $service->reject((int) $argv[3], (int) $argv[4], null);
            if ($result['ok']) {
                file_put_contents($argv[5], $action . "\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_proposal_race_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < 6; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, (string) $staffId, $resultFile, 'approve'],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, (string) $staffId, $resultFile, 'reject'],
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

        $successes = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $this->assertCount(
            1,
            $successes,
            'Az approve/reject verseny közül PONTOSAN EGYNEK kellett volna sikeresen lezárulnia.'
        );

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertContains($proposal['status'], ['approved', 'rejected']);
        $winner = reset($successes);
        $this->assertSame($winner === 'approve' ? 'approved' : 'rejected', $proposal['status']);
    }

    public function testExpiredProposalCannotBeApprovedAcrossConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/sm_proposal_expired_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        [$proposalId, , $staffId] = $this->setUpProposal($dbPath);

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET expires_at = datetime('now', '-1 hour') WHERE id = ?")->execute([$proposalId]);
        unset($db);

        $resultFile = sys_get_temp_dir() . '/sm_proposal_expired_concurrency_result_' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($resultFile, '');
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_proposal_expired_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/Ai/ActionProposal.php';
            require $argv[1] . '/src/Ai/ActionProposalService.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $service = new ActionProposalService($db, []);
            $result = $service->approve((int) $argv[3], (int) $argv[4]);
            if ($result['ok']) {
                file_put_contents($argv[5], "approved\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_proposal_expired_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < 8; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $proposalId, (string) $staffId, $resultFile],
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

        $successes = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $this->assertCount(0, $successes, 'Egy lejárt javaslatot SOSE szabad jóváhagyni, még konkurrens kísérletek mellett sem.');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $proposal = $verifyDb->getActionProposal($proposalId);
        $this->assertSame('expired', $proposal['status']);
    }
}
