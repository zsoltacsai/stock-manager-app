<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — a kör 24/34. pontja: AiDailyIntelligence → ActionProposalService
 * integráció. FakeAiProvider-t használ (lásd tests/AiAgentRunnerTest.php),
 * UGYANAZZAL a mintával, mint tests/AiDailyIntelligenceTest.php (Fázis 7).
 */
final class AiDailyIntelligenceProposalIntegrationTest extends TestCase
{
    private function seedDecliningProduct(Database $db, string $name, int $previousQty, int $currentQty, int $stockQty = 500): int
    {
        $pdo = $db->pdo();
        $pdo->prepare('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES (?, "db", 1000, 787, ?, "Italok", "27")')
            ->execute([$name, $stockQty]);
        $productId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, 'Készpénz', ?)")
            ->execute([$previousQty * 1000, date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $saleId1 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, ?, ?, 1000, "27")')
            ->execute([$saleId1, $productId, $name, $previousQty]);

        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, 'Készpénz', ?)")
            ->execute([$currentQty * 1000, date('Y-m-d H:i:s', strtotime('-5 days'))]);
        $saleId2 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, ?, ?, 1000, "27")')
            ->execute([$saleId2, $productId, $name, $currentQty]);

        return $productId;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    public function testDisabledSettingCreatesNoProposals(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Kritikus visszaesés', 20, 2); // -90%, critical
        $provider = new FakeAiProvider([new AiChatResponse('Összefoglaló.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => false]);

        $intelligence->generateForDate($this->today());

        $this->assertCount(0, $db->listActionProposals());
    }

    public function testEnabledAndEligibleAnomalyCreatesProposal(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Kritikus visszaesés', 20, 2); // -90%, critical
        $provider = new FakeAiProvider([new AiChatResponse('Összefoglaló.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => true]);

        $intelligence->generateForDate($this->today());

        $proposals = $db->listActionProposals();
        $this->assertCount(1, $proposals);
        $this->assertSame('sales_review', $proposals[0]['proposal_type']);
        $this->assertSame('daily_intelligence', $proposals[0]['agent']);
        $this->assertSame('pending', $proposals[0]['status']);
    }

    public function testLowSeverityAnomalyDoesNotCreateProposal(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Enyhe visszaesés', 10, 6); // -40% -> medium severity
        $provider = new FakeAiProvider([new AiChatResponse('Összefoglaló.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => true]);

        $intelligence->generateForDate($this->today());

        $this->assertCount(0, $db->listActionProposals());
    }

    public function testInsufficientDataDoesNotCreateProposal(): void
    {
        $db = tests_new_database(); // nincs eladási adat egyáltalán
        $provider = new FakeAiProvider([new AiChatResponse('Nincs jelentős megállapítás.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => true]);

        $intelligence->generateForDate($this->today());

        $this->assertCount(0, $db->listActionProposals());
    }

    public function testDuplicateFindingAcrossTwoRunsDoesNotDuplicateProposal(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Kritikus visszaesés', 20, 2);
        $provider = new FakeAiProvider([
            new AiChatResponse('Első futás.', []),
            new AiChatResponse('Második futás.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => true]);

        $intelligence->generateForDate($this->today());
        $this->assertCount(1, $db->listActionProposals());

        // A kör 34. pontja — "daily report rerun → no duplicate proposal":
        // a MEGLÉVŐ idempotencia (claimAiDailyReportSlot) egy MÁSODIK
        // ugyanaznapi hívást önmagában is 'skipped'-ként állítana le —
        // itt SZÁNDÉKOSAN visszaállítjuk 'pending'-re a napi jelentés
        // sorát, hogy a TÉNYLEGES javaslat-fingerprint-védelmet
        // bizonyítsuk, ne csak a Fázis 7 idempotenciáját.
        $pdo = $db->pdo();
        $pdo->prepare("UPDATE ai_daily_reports SET status = 'pending' WHERE report_date = ?")->execute([$this->today()]);

        $intelligence->generateForDate($this->today());

        $this->assertCount(1, $db->listActionProposals(), 'Egy második, ugyanazt a jelenséget találó futás SOSE hozhat létre második javaslatot.');
    }

    public function testCreatedProposalEvidenceReflectsStockAtCreationTime(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Kritikus visszaesés', 20, 2, 15);
        $provider = new FakeAiProvider([new AiChatResponse('Összefoglaló.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => true]);

        $intelligence->generateForDate($this->today());

        $proposals = $db->listActionProposals();
        $this->assertCount(1, $proposals);
        $evidence = json_decode((string) $proposals[0]['evidence_json'], true);
        $this->assertSame(15, $evidence['current_stock']);
    }

    public function testDisabledAiActionProposalsDoesNotAffectReportGeneration(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Kritikus visszaesés', 20, 2);
        $provider = new FakeAiProvider([new AiChatResponse('Összefoglaló szöveg.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_action_proposals_enabled' => false]);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['has_significant_findings']);
    }
}
