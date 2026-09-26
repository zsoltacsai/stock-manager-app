<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AI-03 — egy javaslat csak akkor hajtható végre, ha jóváhagyott, NEM
 * járt le, nem elavult és még nem hajtották végre. A lejárat a
 * végrehajtásig érvényes (nem csak a jóváhagyásig), és az atomikus
 * claim WHERE-feltételében dől el (nincs TOCTOU-ablak).
 *
 * A korábbi ActionExecutorTest::testExpiredProposalCannotBeExecuted
 * kézzel állított 'expired' státuszt — a valós esetet (jóváhagyott sor,
 * lejárt expires_at) ezek a tesztek fedik le.
 */
final class ActionProposalExpiryExecutionTest extends TestCase
{
    private function seedProduct(Database $db, int $stockQty = 3, int $threshold = 10): int
    {
        $id = $db->saveProduct([
            'name' => 'AI-03 termék ' . bin2hex(random_bytes(3)),
            'barcode' => 'AI03-' . bin2hex(random_bytes(4)),
            'price' => 1000,
            'net_price' => 787,
            'vat_rate' => 27,
            'low_stock_threshold' => $threshold,
        ]);
        $db->setStock($id, $stockQty);
        return $id;
    }

    private function createProposal(Database $db, int $productId): int
    {
        $row = (new ActionProposalService($db, []))->createFromFinding([
            'type' => 'low_stock_elevated_sales', 'severity' => 'high', 'entity_type' => 'product',
            'entity_id' => $productId, 'entity_name' => 'AI-03 termék', 'metric' => 'qty',
            'current_value' => 12.0, 'baseline_value' => 6.0, 'change_percent' => 100.0,
            'reason_code' => 'low_stock_with_sales_uplift',
        ], 'daily_intelligence', null, null, null);
        $this->assertNotNull($row);
        return (int) $row['id'];
    }

    private function approvedProposal(Database $db, ?int $productId = null): int
    {
        $id = $this->createProposal($db, $productId ?? $this->seedProduct($db));
        $this->assertTrue((new ActionProposalService($db, []))->approve($id, null)['ok'] ?? true);
        $this->assertSame('approved', $db->getActionProposal($id)['status']);
        return $id;
    }

    private function setExpiresAt(Database $db, int $id, string $expiresAt): void
    {
        $db->pdo()->prepare('UPDATE ai_action_proposals SET expires_at = ? WHERE id = ?')->execute([$expiresAt, $id]);
    }

    private function draftCount(Database $db, int $id): int
    {
        return (int) $db->pdo()->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . $id)->fetchColumn();
    }

    public function testPendingProposalIsNotExecutable(): void
    {
        $db = tests_new_database();
        $id = $this->createProposal($db, $this->seedProduct($db));

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('pending', $result['reason']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testRejectedProposalIsNotExecutable(): void
    {
        $db = tests_new_database();
        $id = $this->createProposal($db, $this->seedProduct($db));
        (new ActionProposalService($db, []))->reject($id, null, null);

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('rejected', $result['reason']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    /** A Phase 4 audit AI-03 reprodukciója: approved + expires_at = 2000-01-01 → a javítás előtt 'executed'. */
    public function testPhase4ReproductionApprovedButExpiredProposalIsNotExecuted(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $this->setExpiresAt($db, $id, '2000-01-01 00:00:00');

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
        $this->assertSame('expired', $db->getActionProposal($id)['status']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testProposalThatExpiresBetweenApprovalAndExecutionIsNotExecuted(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        // A jóváhagyáskor még érvényes volt; a végrehajtás előtt 1 másodperccel lejárt.
        $this->setExpiresAt($db, $id, date('Y-m-d H:i:s', time() - 1));

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('expired', $result['reason']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testFreshApprovedProposalExecutes(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $this->setExpiresAt($db, $id, date('Y-m-d H:i:s', time() + 3600));

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertTrue($result['ok']);
        $this->assertSame('executed', $db->getActionProposal($id)['status']);
        $this->assertSame(1, $this->draftCount($db, $id));
    }

    public function testStaleApprovedProposalIsNotExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $id = $this->approvedProposal($db, $productId);
        $db->setStock($productId, 50); // a jóváhagyás után az alapadat megváltozott

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('stale', $result['reason']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testStaleAndExpiredApprovedProposalIsNotExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $id = $this->approvedProposal($db, $productId);
        $db->setStock($productId, 50);
        $this->setExpiresAt($db, $id, '2000-01-01 00:00:00');

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testFailedExecutionCannotBeRetriedAfterExpiry(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $db->pdo()->prepare("UPDATE ai_action_proposals SET status = 'execution_failed', expires_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([$id]);

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('expired', $result['reason']);
        $this->assertSame('expired', $db->getActionProposal($id)['status']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testStaleExecutingRowIsNotReclaimedAfterExpiry(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $db->pdo()->prepare("UPDATE ai_action_proposals SET status = 'executing', execution_started_at = '2000-01-01 00:00:00', expires_at = '2000-01-02 00:00:00' WHERE id = ?")->execute([$id]);

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('expired', $result['reason']);
        $this->assertSame('expired', $db->getActionProposal($id)['status']);
        $this->assertSame(0, $this->draftCount($db, $id));
    }

    public function testInProgressExecutingRowIsNotTouchedByExpiry(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $db->pdo()->prepare("UPDATE ai_action_proposals SET status = 'executing', execution_started_at = ?, expires_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);

        $result = (new ActionExecutor($db, []))->execute($id, null);

        $this->assertSame('already_executing', $result['reason']);
        $this->assertSame('executing', $db->getActionProposal($id)['status']);
    }

    public function testReplayOfExecutedProposalAfterExpiryReturnsExistingResultWithoutSecondDraft(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $executor = new ActionExecutor($db, []);
        $this->assertTrue($executor->execute($id, null)['ok']);
        $this->setExpiresAt($db, $id, '2000-01-01 00:00:00');
        (new ActionProposalService($db, []))->listProposals([], 50, 0);

        $replay = $executor->execute($id, null);

        $this->assertTrue($replay['ok']);
        $this->assertTrue($replay['already_executed']);
        $this->assertSame('executed', $db->getActionProposal($id)['status'], 'a sweep végrehajtott sort nem írhat át');
        $this->assertSame(1, $this->draftCount($db, $id));
    }

    public function testClaimRejectsExpiredApprovedRowAtDatabaseLevel(): void
    {
        $db = tests_new_database();
        $id = $this->approvedProposal($db);
        $this->setExpiresAt($db, $id, date('Y-m-d H:i:s', time() - 1));

        $this->assertFalse($db->claimActionProposalExecution($id, ['approved', 'execution_failed']));
        $this->assertSame('approved', $db->getActionProposal($id)['status']);
    }

    public function testSweepExpiresApprovedAndFailedButNotExecutedOrExecuting(): void
    {
        $db = tests_new_database();
        $approved = $this->approvedProposal($db);
        $failed = $this->approvedProposal($db);
        $executing = $this->approvedProposal($db);
        $executed = $this->approvedProposal($db);
        $pdo = $db->pdo();
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'execution_failed' WHERE id = ?")->execute([$failed]);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'executing', execution_started_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $executing]);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'executed' WHERE id = ?")->execute([$executed]);
        $pdo->exec("UPDATE ai_action_proposals SET expires_at = '2000-01-01 00:00:00'");

        $db->sweepExpiredActionProposals();

        $this->assertSame('expired', $db->getActionProposal($approved)['status']);
        $this->assertSame('expired', $db->getActionProposal($failed)['status']);
        $this->assertSame('executing', $db->getActionProposal($executing)['status']);
        $this->assertSame('executed', $db->getActionProposal($executed)['status']);
    }

    /** VALÓDI többfolyamatos verseny: 12 párhuzamos execute egy lejárt, jóváhagyott javaslatra → 0 végrehajtás. */
    public function testConcurrentExecuteOfExpiredApprovedProposalExecutesNothing(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $projectRoot = dirname(__DIR__);
        $dbPath = sys_get_temp_dir() . '/sm_ai03_' . bin2hex(random_bytes(8)) . '.sqlite';
        $resultFile = $dbPath . '.result';
        $child = $dbPath . '.child.php';
        $log = $dbPath . '.log';
        register_shutdown_function(static function () use ($dbPath, $resultFile, $child, $log) {
            foreach ([$dbPath, $dbPath . '-shm', $dbPath . '-wal', $resultFile, $child, $log] as $f) { @unlink($f); }
        });
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $id = $this->approvedProposal($db);
        $this->setExpiresAt($db, $id, '2000-01-01 00:00:00');
        file_put_contents($resultFile, '');
        file_put_contents($child, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require_once $argv[1] . '/src/Ai/ActionExecutor.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $r = (new ActionExecutor($db, []))->execute((int) $argv[3], null);
            file_put_contents($argv[4], (($r['ok'] ?? false) ? 'executed' : ($r['reason'] ?? 'unknown')) . "\n", FILE_APPEND | LOCK_EX);
            PHP);
        $handles = [];
        for ($i = 0; $i < 12; $i++) {
            $handles[] = proc_open([PHP_BINARY, $child, $projectRoot, $dbPath, (string) $id, $resultFile], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        }
        foreach ($handles as $h) { if (is_resource($h)) proc_close($h); }

        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($resultFile)))));
        $this->assertCount(12, $lines);
        $this->assertSame([], array_values(array_filter($lines, static fn ($l) => $l !== 'expired')), 'minden folyamat expired választ kap');
        $verify = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $this->assertSame(0, $this->draftCount($verify, $id));
        $this->assertSame('expired', $verify->getActionProposal($id)['status']);
    }
}
