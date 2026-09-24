<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — ActionProposalService: modell/validáció/fingerprint/
 * duplikátum-elnyomás/TTL/jóváhagyás/elutasítás/elavulás — a kör 29/30.
 * pontja szerinti eseteket fedi le. A konkurrencia-specifikus (több
 * FOLYAMATOS proc_open) teszteket lásd ActionProposalConcurrencyTest.php.
 */
final class ActionProposalServiceTest extends TestCase
{
    private function sampleFinding(array $overrides = []): array
    {
        return array_merge([
            'type' => 'low_stock_elevated_sales',
            'severity' => 'high',
            'entity_type' => 'product',
            'entity_id' => 0,
            'entity_name' => 'Teszttermék',
            'metric' => 'qty',
            'current_value' => 12.0,
            'baseline_value' => 6.0,
            'change_percent' => 100.0,
            'evidence' => ['current_qty' => 12.0, 'previous_qty' => 6.0],
            'reason_code' => 'low_stock_with_sales_uplift',
        ], $overrides);
    }

    private function seedProduct(Database $db, int $stockQty = 8): int
    {
        $id = $db->saveProduct([
            'name' => 'Teszttermék',
            'barcode' => 'TP-' . bin2hex(random_bytes(4)),
            'price' => 1000,
            'net_price' => 787,
            'vat_rate' => 27,
        ]);
        $db->setStock($id, $stockQty);
        return $id;
    }

    public function testCreateFromFindingCreatesPendingProposalWithBoundedEvidence(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $service = new ActionProposalService($db, []);

        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', 'local', 'qwen3:8b', null);

        $this->assertNotNull($row);
        $proposal = ActionProposal::fromRow($row);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame('reorder_draft', $proposal->proposalType);
        $this->assertSame('daily_intelligence', $proposal->agent);
        $this->assertSame($productId, $proposal->entityId);
        $this->assertSame(8, $proposal->evidence['current_stock']);
        $this->assertArrayNotHasKey('api_key', $proposal->evidence);
    }

    public function testCreateFromFindingRejectsUnknownAgent(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);

        $this->expectException(InvalidArgumentException::class);
        $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'copilot', null, null, null);
    }

    public function testCreateFromFindingReturnsNullForIneligibleType(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);

        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId, 'type' => 'sales_spike']), 'daily_intelligence', null, null, null);
        $this->assertNull($row);
    }

    public function testCreateFromFindingReturnsNullForLowSeverity(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);

        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId, 'severity' => 'medium']), 'daily_intelligence', null, null, null);
        $this->assertNull($row);
    }

    public function testCreateFromFindingReturnsNullForShopLevelEntity(): void
    {
        $db = tests_new_database();
        $service = new ActionProposalService($db, []);

        $row = $service->createFromFinding($this->sampleFinding(['entity_type' => 'shop', 'entity_id' => 0, 'type' => 'return_rate_anomaly']), 'daily_intelligence', null, null, null);
        $this->assertNull($row);
    }

    public function testCreateFromFindingReturnsNullWhenProductMissing(): void
    {
        $db = tests_new_database();
        $service = new ActionProposalService($db, []);

        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => 999999]), 'daily_intelligence', null, null, null);
        $this->assertNull($row);
    }

    public function testFingerprintIsDeterministicAndStableAcrossCalls(): void
    {
        $db = tests_new_database();
        $service = new ActionProposalService($db, []);
        $a = $service->computeFingerprint('reorder_draft', 'product', 5, 'low_stock_with_sales_uplift');
        $b = $service->computeFingerprint('reorder_draft', 'product', 5, 'low_stock_with_sales_uplift');
        $this->assertSame($a, $b);
    }

    public function testFingerprintDiffersForDifferentEntities(): void
    {
        $db = tests_new_database();
        $service = new ActionProposalService($db, []);
        $a = $service->computeFingerprint('reorder_draft', 'product', 5, 'low_stock_with_sales_uplift');
        $b = $service->computeFingerprint('reorder_draft', 'product', 6, 'low_stock_with_sales_uplift');
        $this->assertNotSame($a, $b);
    }

    public function testDuplicateFindingDoesNotCreateSecondProposal(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);

        $first = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $second = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $db->listActionProposals());
    }

    public function testTtlDefaultsTo48HoursAndIsConfigurable(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, ['ai_action_proposal_ttl_hours' => 2]);

        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $createdAt = strtotime($row['created_at']);
        $expiresAt = strtotime($row['expires_at']);
        $this->assertEqualsWithDelta(2 * 3600, $expiresAt - $createdAt, 5);
    }

    public function testApproveWorksWithNullStaffIdOnNoPinSystemDeployment(): void
    {
        // A kör 15. pontja — "authenticated admin/staff according to the
        // intended permission policy": a MEGLÉVŐ projekt-konvenció szerint
        // (lásd webroot/api/cash-session-open.php Auth::currentStaffId()
        // nullable-kezelése) egy dolgozói PIN-rendszer NÉLKÜLI (egy-
        // üzemeltetős) telepítésen ez legitim null — a jóváhagyásnak ITT
        // IS sikeresnek kell lennie, csak reviewed_by marad NULL.
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $result = $service->approve((int) $row['id'], null);

        $this->assertTrue($result['ok']);
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('approved', $fresh['status']);
        $this->assertNull($fresh['reviewed_by']);
    }

    public function testApproveTransitionsPendingToApprovedAndAudits(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $result = $service->approve((int) $row['id'], $staffId);

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', $result['status']);
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('approved', $fresh['status']);
        $this->assertSame($staffId, (int) $fresh['reviewed_by']);
        $this->assertNotNull($fresh['reviewed_at']);

        $auditLog = $db->getAuditLog(50);
        $found = false;
        foreach ($auditLog as $entry) {
            if ($entry['action'] === 'ai_action_proposal_approve' && (int) $entry['entity_id'] === (int) $row['id']) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'A jóváhagyásnak audit_log bejegyzést kell hagynia.');
    }

    public function testApproveNoBusinessMutationOccurs(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $service->approve((int) $row['id'], $staffId);

        $product = $db->findProductById($productId);
        $this->assertSame(8, (int) $product['stock_qty'], 'A jóváhagyás SOSE módosíthatja a tényleges készletet.');
    }

    public function testRejectTransitionsPendingToRejectedWithReason(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $result = $service->reject((int) $row['id'], $staffId, 'Nem indokolt.');

        $this->assertTrue($result['ok']);
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('rejected', $fresh['status']);
        $this->assertSame('Nem indokolt.', $fresh['rejection_reason']);
    }

    public function testRejectionReasonIsBounded(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $longReason = str_repeat('x', ActionProposal::MAX_REJECTION_REASON_LENGTH + 500);
        $service->reject((int) $row['id'], $staffId, $longReason);

        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame(ActionProposal::MAX_REJECTION_REASON_LENGTH, mb_strlen($fresh['rejection_reason']));
    }

    public function testApproveAlreadyApprovedIsRejected(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $service->approve((int) $row['id'], $staffId);

        $second = $service->approve((int) $row['id'], $staffId);

        $this->assertFalse($second['ok']);
        $this->assertSame('approved', $second['reason']);
    }

    public function testRejectAlreadyRejectedIsRejected(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $service->reject((int) $row['id'], $staffId, null);

        $second = $service->reject((int) $row['id'], $staffId, null);

        $this->assertFalse($second['ok']);
        $this->assertSame('rejected', $second['reason']);
    }

    public function testApproveInvalidIdReturnsNotFound(): void
    {
        $db = tests_new_database();
        $service = new ActionProposalService($db, []);
        $result = $service->approve(999999, 1);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['reason']);
    }

    public function testApproveExpiredProposalIsRejectedAndMarkedExpired(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, ['ai_action_proposal_ttl_hours' => 1]);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        // Mesterségesen lejártnak jelöljük (a TTL valós, óra-alapú
        // lejárását itt nem várjuk meg — közvetlenül a sort állítjuk át,
        // UGYANAZZAL a technikával, mint a Fázis 7 history-tesztek
        // backdateLatestAiEvent()-je).
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET expires_at = datetime('now', '-1 hour') WHERE id = ?")->execute([$row['id']]);

        $result = $service->approve((int) $row['id'], $staffId);

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('expired', $fresh['status']);
    }

    public function testApproveExpiredCannotBeApprovedEvenAfterExpiry(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $db->expireActionProposal((int) $row['id']);
        // Ez a fenti hívás rowCount 0-t adna vissza, ha nem lenne még
        // 'pending' — kényszerítsük a valódi 'expired' állapotot direktben:
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET status = 'expired' WHERE id = ?")->execute([$row['id']]);

        $result = $service->approve((int) $row['id'], $staffId);
        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
    }

    public function testStaleStockChangeBlocksApprovalAndMarksStale(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $this->assertSame(8, $row['evidence_json'] !== null ? json_decode($row['evidence_json'], true)['current_stock'] : null);

        // A készlet időközben MEGVÁLTOZOTT (pl. beérkezett egy rendelés) —
        // ez a kör 14. pontjának konkrét, "10:00 stock=8, 10:30 stock=25"
        // forgatókönyve.
        $db->setStock($productId, 25);

        $result = $service->approve((int) $row['id'], $staffId);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale', $result['reason']);
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('stale', $fresh['status']);
    }

    public function testStaleProposalCannotBeApprovedOnRetry(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $db->setStock($productId, 25);
        $service->approve((int) $row['id'], $staffId);

        $second = $service->approve((int) $row['id'], $staffId);

        $this->assertFalse($second['ok']);
        $this->assertSame('stale', $second['reason']);
    }

    public function testUnchangedStockDoesNotBlockApproval(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db, 8);
        $staffId = $db->saveStaff(['name' => 'Teszt Admin', 'pin' => '1234', 'role' => 'admin']);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);

        $result = $service->approve((int) $row['id'], $staffId);

        $this->assertTrue($result['ok']);
    }

    public function testListProposalsSweepsExpiredBeforeReturning(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', null, null, null);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->prepare("UPDATE ai_action_proposals SET expires_at = datetime('now', '-1 hour') WHERE id = ?")->execute([$row['id']]);

        $list = $service->listProposals(['status' => 'pending'], 20, 0);

        $this->assertCount(0, $list, 'A lejárt javaslatnak nem szabad "pending"-ként megjelennie egy listázás után.');
        $fresh = $db->getActionProposal((int) $row['id']);
        $this->assertSame('expired', $fresh['status']);
    }

    public function testEvidenceNeverContainsSecretLikeFields(): void
    {
        $db = tests_new_database();
        $productId = $this->seedProduct($db);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding($this->sampleFinding(['entity_id' => $productId]), 'daily_intelligence', 'anthropic', 'claude-sonnet-5', null);

        foreach (['api_key', 'csrf_token', 'hmac_secret', 'secret'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $row['evidence_json']);
        }
    }
}
