<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8B — ActionExecutor: a kör 24. pontjának 18 esete. A tényleges
 * mutáció mindig a `reorder_draft` → ReorderDraftExecutor útvonalon
 * megy (ez az EGYETLEN whitelistelt végrehajtható típus, lásd
 * ActionExecutor::EXECUTABLE_TYPES).
 */
final class ActionExecutorTest extends TestCase
{
    private function sampleFinding(int $productId, array $overrides = []): array
    {
        return array_merge([
            'type' => 'low_stock_elevated_sales',
            'severity' => 'high',
            'entity_type' => 'product',
            'entity_id' => $productId,
            'entity_name' => 'Teszttermék',
            'metric' => 'qty',
            'current_value' => 12.0,
            'baseline_value' => 6.0,
            'change_percent' => 100.0,
            'reason_code' => 'low_stock_with_sales_uplift',
        ], $overrides);
    }

    private function seedProduct(Database $db, int $stockQty = 3, ?int $threshold = 10): int
    {
        $id = $db->saveProduct([
            'name' => 'Teszttermék',
            'barcode' => 'EX-' . bin2hex(random_bytes(4)),
            'price' => 1000,
            'net_price' => 787,
            'vat_rate' => 27,
            'low_stock_threshold' => $threshold,
        ]);
        $db->setStock($id, $stockQty);
        return $id;
    }

    private function seedApprovedProposal(Database $db, int $productId, string $type = 'low_stock_elevated_sales'): array
    {
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding($productId, ['type' => $type]), 'daily_intelligence', null, null, null);
        $this->assertNotNull($row);
        $service->approve((int) $row['id'], null);
        return $db->getActionProposal((int) $row['id']);
    }

    // ------------------------------------------------------------------
    // 1. approved reorder_draft executes
    // ------------------------------------------------------------------

    public function testApprovedReorderDraftExecutesSuccessfully(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($result['ok']);
        $this->assertSame('executed', $result['status']);
        $this->assertSame('reorder_draft', $result['result']['action']);
        $this->assertTrue($result['result']['success']);
        $this->assertGreaterThan(0, $result['result']['quantity']);

        $fresh = $db->getActionProposal((int) $proposal['id']);
        $this->assertSame('executed', $fresh['status']);
        $this->assertNotNull($fresh['executed_at']);
    }

    // ------------------------------------------------------------------
    // 2-5. pending/rejected/expired/stale proposal rejected
    // ------------------------------------------------------------------

    public function testPendingProposalCannotBeExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding($productId), 'daily_intelligence', null, null, null);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $row['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('pending', $result['reason']);
    }

    public function testRejectedProposalCannotBeExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding($productId), 'daily_intelligence', null, null, null);
        $service->reject((int) $row['id'], null, null);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $row['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['reason']);
    }

    public function testExpiredProposalCannotBeExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, ['ai_action_proposal_ttl_hours' => 1]);
        $row = $service->createFromFinding($this->sampleFinding($productId), 'daily_intelligence', null, null, null);
        $service->approve((int) $row['id'], null);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'expired' WHERE id = ?")->execute([$row['id']]);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $row['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
    }

    public function testStaleProposalCannotBeExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding($productId), 'daily_intelligence', null, null, null);
        $service->approve((int) $row['id'], null);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'stale' WHERE id = ?")->execute([$row['id']]);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $row['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['reason']);
    }

    // ------------------------------------------------------------------
    // 6. unknown action rejected (inventory_review / sales_review)
    // ------------------------------------------------------------------

    public function testInformationalProposalTypeCannotBeExecuted(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $proposal = $this->seedApprovedProposal($db, $productId, 'stock_sales_divergence');
        $this->assertSame('inventory_review', $proposal['proposal_type']);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_executable', $result['reason']);
        $fresh = $db->getActionProposal((int) $proposal['id']);
        $this->assertSame('approved', $fresh['status'], 'Egy nem végrehajtható típusú javaslat állapota NEM változhat.');
    }

    // ------------------------------------------------------------------
    // 7. invalid proposal rejected
    // ------------------------------------------------------------------

    public function testInvalidProposalIdReturnsNotFound(): void
    {
        $db = tests_new_database();
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute(999999, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['reason']);
    }

    // ------------------------------------------------------------------
    // 8. current product missing
    // ------------------------------------------------------------------

    public function testMissingProductAtExecutionTimeIsStale(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare('UPDATE products SET is_deleted = 1 WHERE id = ?')->execute([$productId]);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['reason']);
    }

    // ------------------------------------------------------------------
    // 9. current stock changed
    // ------------------------------------------------------------------

    public function testStockChangedSinceApprovalBlocksExecution(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $db->setStock($productId, 50);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['reason']);
        $draft = $db->findPurchaseOrderDraftByProposalId((int) $proposal['id']);
        $this->assertNull($draft, 'Stale esetén SOSE jöhet létre piszkozat.');
    }

    // ------------------------------------------------------------------
    // 10. current business rule changed (küszöb módosult, a mennyiséget
    // a FRISS küszöbbel kell újraszámolni, nem a javaslat-időbeli
    // adattal).
    // ------------------------------------------------------------------

    public function testQuantityIsRecalculatedFromCurrentThresholdNotProposalTimeData(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);

        // A küszöböt (üzleti szabály) a jóváhagyás UTÁN megemeljük — a
        // készlet (a stale-ellenőrzés alapja) VÁLTOZATLAN marad, tehát
        // ez nem eredményez stale-t, de a kiszámított mennyiségnek
        // tükröznie kell az ÚJ küszöböt.
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare('UPDATE products SET low_stock_threshold = 40 WHERE id = ?')->execute([$productId]);

        $executor = new ActionExecutor($db, []);
        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($result['ok']);
        // safetyStock=40, nincs megbízható napi fogyás -> (40*2) - 3 = 77
        $this->assertSame(77, $result['result']['quantity']);
    }

    // ------------------------------------------------------------------
    // 11. invalid quantity (a számított mennyiség <= 0 -> stale, nincs
    // mit rendelni)
    // ------------------------------------------------------------------

    public function testZeroOrNegativeComputedQuantityIsStale(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);

        // A készlet a jóváhagyás UTÁN már ELÉG (>= biztonsági szint*2),
        // tehát a stale-ellenőrzés (current_stock != evidence.current_stock)
        // ÉS a mennyiség-számítás egyaránt "nincs mit rendelni"-t adna —
        // itt kifejezetten a mennyiség-oldali védelmet ellenőrizzük.
        $db->setStock($productId, 25);
        $executor = new ActionExecutor($db, []);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['reason']);
    }

    // ------------------------------------------------------------------
    // 12. quantity exceeds configured limits
    // ------------------------------------------------------------------

    public function testQuantityIsCappedByConfiguredMaximum(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 0, 1000); // hatalmas biztonsági készlet -> hatalmas javasolt mennyiség
        $proposal = $this->seedApprovedProposal($db, $productId);
        $executor = new ActionExecutor($db, ['ai_reorder_draft_max_quantity' => 50]);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($result['ok']);
        $this->assertSame(50, $result['result']['quantity']);
    }

    // ------------------------------------------------------------------
    // 13. transaction rollback
    // ------------------------------------------------------------------

    public function testFailureDuringExecutionRollsBackAndCreatesNoDraft(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);

        // A terméket a claim UTÁN, de a revalidálás UTÁN, közvetlenül a
        // stratégia előtt töröljük a DB-ből (nem csak is_deleted=1, hanem
        // ténylegesen) — ez az isStale()-t NEM fogja kiváltani (mert az
        // csak a current_stock mezőt nézi, és a törölt sor UPDATE-je nem
        // fut le előbb), de a ReorderDraftExecutor findProductById()-ja
        // null-t fog kapni, ami ActionExecutionStaleException-t dob —
        // ez a "stale, nem execution_failed" ágat bizonyítja. A valódi,
        // TECHNIKAI hiba (nem üzleti okú) rollback-jét egy közvetlen
        // Database-hívással szimuláljuk: a purchase_order_drafts táblát
        // ideiglenesen eldobjuk, hogy az INSERT kivételt dobjon.
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->exec('DROP TABLE purchase_order_drafts');

        $executor = new ActionExecutor($db, []);
        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('execution_failed', $result['reason']);
        $fresh = $db->getActionProposal((int) $proposal['id']);
        $this->assertSame('execution_failed', $fresh['status']);
        $this->assertNotNull($fresh['execution_error']);
        // A hiba SOSE nyers kivétel-részletet ad a hívónak.
        $this->assertStringNotContainsString('SQLSTATE', (string) $result['error']);
    }

    // ------------------------------------------------------------------
    // 14. successful execution result persisted
    // ------------------------------------------------------------------

    public function testSuccessfulResultIsPersistedAndReadableAfterFreshLoad(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $executor = new ActionExecutor($db, []);
        $executor->execute((int) $proposal['id'], null);

        $fresh = $db->getActionProposal((int) $proposal['id']);
        $decoded = json_decode((string) $fresh['execution_result_json'], true);
        $this->assertSame('reorder_draft', $decoded['action']);
        $this->assertSame('purchase_order_draft', $decoded['reference_type']);
        $this->assertIsInt($decoded['reference_id']);

        $draft = $db->getPurchaseOrderDraft((int) $decoded['reference_id']);
        $this->assertNotNull($draft);
        $this->assertSame($productId, (int) $draft['product_id']);
    }

    // ------------------------------------------------------------------
    // 15. execution failure persisted
    // ------------------------------------------------------------------

    public function testExecutionFailureIsPersistedWithBoundedError(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->exec('DROP TABLE purchase_order_drafts');

        $executor = new ActionExecutor($db, []);
        $executor->execute((int) $proposal['id'], null);

        $fresh = $db->getActionProposal((int) $proposal['id']);
        $this->assertSame('execution_failed', $fresh['status']);
        $this->assertNotNull($fresh['execution_failed_at']);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $fresh['execution_error']));
    }

    // ------------------------------------------------------------------
    // 16. duplicate execution is idempotent
    // ------------------------------------------------------------------

    public function testDuplicateExecutionReturnsExistingResultWithoutSecondDraft(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $executor = new ActionExecutor($db, []);

        $first = $executor->execute((int) $proposal['id'], null);
        $second = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['already_executed'] ?? false);
        $this->assertSame($first['result']['reference_id'], $second['result']['reference_id']);

        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . (int) $proposal['id'])->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------------
    // 17. retry after safe failure
    // ------------------------------------------------------------------

    public function testRetryAfterSafeFailureSucceedsAndCreatesExactlyOneDraft(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->exec('ALTER TABLE purchase_order_drafts RENAME TO purchase_order_drafts_tmp');

        $executor = new ActionExecutor($db, []);
        $failed = $executor->execute((int) $proposal['id'], null);
        $this->assertSame('execution_failed', $failed['reason']);

        // "Helyreáll" a rendszer (a tábla visszakerül) — ez szimulálja,
        // hogy a hiba oka elmúlt, a retry biztonságos.
        $pdo->exec('ALTER TABLE purchase_order_drafts_tmp RENAME TO purchase_order_drafts');

        $retry = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($retry['ok']);
        $this->assertSame('executed', $retry['status']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM purchase_order_drafts WHERE proposal_id = ' . (int) $proposal['id'])->fetchColumn();
        $this->assertSame(1, $count, 'Az újrapróbálkozás UTÁN pontosan EGY piszkozatnak szabad léteznie.');
    }

    // ------------------------------------------------------------------
    // 18. ambiguous execution protected
    // ------------------------------------------------------------------

    public function testStuckExecutingStateIsNotBlindlyRetriedBeforeStaleWindow(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $executor = new ActionExecutor($db, []);

        // Szimulálunk egy "elakadt" végrehajtást — a claim megtörtént
        // (status='executing'), de a folyamat (elméletileg) összeomlott,
        // MIELŐTT a tranzakció lezárult volna. Ez EGY MÁSIK, egyidejű
        // hívás számára "kétértelmű" állapot — az ActionExecutor NEM
        // próbálhatja VAKON újra, amíg a staleAfterMinutes ablak le nem
        // telt.
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'executing', execution_started_at = datetime('now') WHERE id = ?")->execute([$proposal['id']]);

        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertFalse($result['ok']);
        $this->assertSame('already_executing', $result['reason']);
        $draft = $db->findPurchaseOrderDraftByProposalId((int) $proposal['id']);
        $this->assertNull($draft, 'Egy kétértelmű (friss "executing") állapotú javaslatra SOSE szabad vakon újrapróbálkozni.');
    }

    public function testStuckExecutingStateIsReclaimedAfterStaleWindow(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 3, 10);
        $proposal = $this->seedApprovedProposal($db, $productId);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        // Egy RÉGI, "elakadt" executing sor — a staleAfterMinutes ablakon TÚL.
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'executing', execution_started_at = datetime('now', '-2 hours') WHERE id = ?")->execute([$proposal['id']]);

        $executor = new ActionExecutor($db, ['ai_action_execution_stale_minutes' => 30]);
        $result = $executor->execute((int) $proposal['id'], null);

        $this->assertTrue($result['ok']);
        $this->assertSame('executed', $result['status']);
    }
}
