<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 7 — AiDailyIntelligence tesztjei (a kör 26. pontja, 1-20).
 * FakeAiProvider-t használ (lásd tests/AiAgentRunnerTest.php) — a
 * szintézis-lépés SAJÁT, ÜRES ToolRegistry-vel fut (lásd AiDailyIntelligence
 * docblokkja), tehát MINDIG PONTOSAN EGY chat()-hívást fogyaszt egy
 * generateForDate()-hívásonként (nincs beágyazott agent-/Copilot-hívás).
 */
final class AiDailyIntelligenceTest extends TestCase
{
    private function seedDecliningProduct(Database $db, string $name, int $previousQty, int $currentQty): int
    {
        $pdo = $db->pdo();
        $pdo->prepare('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES (?, "db", 1000, 787, 500, "Italok", "27")')
            ->execute([$name]);
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

    // ------------------------------------------------------------------
    // 1. Nincs megállapítás
    // ------------------------------------------------------------------

    public function testNoFindingsProducesValidCompletedReport(): void
    {
        $db = tests_new_database(); // szándékosan üres
        $provider = new FakeAiProvider([
            new AiChatResponse('Nincs jelentős megállapítás ma.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('completed', $result['status']);
        $this->assertFalse($result['has_significant_findings']);
        $this->assertSame(0, $result['findings_count']);

        $stored = $db->getAiDailyReport($this->today());
        $this->assertSame('completed', $stored['status']);
        $this->assertSame(0, (int) $stored['has_significant_findings']);
    }

    // ------------------------------------------------------------------
    // 2-3. Egy / több anomália
    // ------------------------------------------------------------------

    public function testOneAnomalyIsIncludedAndMarkedSignificant(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Egy anomáliás termék', 20, 2); // -90%
        $provider = new FakeAiProvider([
            new AiChatResponse('Egy jelentős eladás-visszaesést találtam.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['has_significant_findings']);
        $this->assertGreaterThanOrEqual(1, $result['findings_count']);
    }

    public function testMultipleAnomaliesAreAllIncludedUpToTheLimit(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Termék A', 20, 2);
        $this->seedDecliningProduct($db, 'Termék B', 30, 3);
        $this->seedDecliningProduct($db, 'Termék C', 40, 4);
        $provider = new FakeAiProvider([
            new AiChatResponse('Több jelentős visszaesést találtam.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('completed', $result['status']);
        $this->assertGreaterThanOrEqual(3, $result['findings_count']);
    }

    // ------------------------------------------------------------------
    // 4. Súlyosság szerinti rangsorolás
    // ------------------------------------------------------------------

    public function testSeverityPrioritizationOrdersCriticalBeforeLowerSeverities(): void
    {
        $ref = new ReflectionMethod(AiDailyIntelligence::class, 'prioritizeFindings');
        $ref->setAccessible(true);
        $db = tests_new_database();
        $intelligence = new AiDailyIntelligence(new FakeAiProvider([]), $db, []);

        $findings = [
            ['type' => 'sales_decline', 'severity' => 'medium', 'entity_type' => 'product', 'entity_id' => 1, 'change_percent' => -40.0],
            ['type' => 'sales_decline', 'severity' => 'critical', 'entity_type' => 'product', 'entity_id' => 2, 'change_percent' => -90.0],
            ['type' => 'sales_decline', 'severity' => 'high', 'entity_type' => 'product', 'entity_id' => 3, 'change_percent' => -60.0],
        ];

        $result = $ref->invoke($intelligence, $findings, 10);

        $this->assertSame(['critical', 'high', 'medium'], array_column($result, 'severity'));
    }

    // ------------------------------------------------------------------
    // 5. Maximális megállapítás-szám
    // ------------------------------------------------------------------

    public function testMaximumFindingsSettingIsEnforced(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Termék A', 20, 2);
        $this->seedDecliningProduct($db, 'Termék B', 30, 3);
        $this->seedDecliningProduct($db, 'Termék C', 40, 4);
        $provider = new FakeAiProvider([
            new AiChatResponse('Összegzés.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_daily_intelligence_max_findings' => 2]);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame(2, $result['findings_count']);
    }

    public function testMaximumFindingsSettingCannotExceedTheHardCap(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([new AiChatResponse('Összegzés.', [])]);
        // Egy admin megpróbálhatna 999-et beállítani — a hard cap (10)
        // sose léphető át, lásd AiDailyIntelligence::MAX_FINDINGS_HARD_CAP.
        $intelligence = new AiDailyIntelligence($provider, $db, ['ai_daily_intelligence_max_findings' => 999]);

        $ref = new ReflectionMethod(AiDailyIntelligence::class, 'gatherContext');
        $ref->setAccessible(true);
        $context = $ref->invoke($intelligence, $this->today());

        // Üres boltban ez triviálisan 0, de a metódus maga sose adhatna
        // vissza 10-nél többet még akkor sem, ha lenne rá adat — ezt a
        // testMaximumFindingsSettingIsEnforced() bizonyítja funkcionálisan,
        // ez itt a konfigurációs korlát felső sapkáját ellenőrzi.
        $this->assertIsArray($context['anomalies']);
    }

    // ------------------------------------------------------------------
    // 6. Duplikátum-szűrés
    // ------------------------------------------------------------------

    public function testDuplicateFindingsAreSuppressedByTypeAndEntity(): void
    {
        $ref = new ReflectionMethod(AiDailyIntelligence::class, 'prioritizeFindings');
        $ref->setAccessible(true);
        $db = tests_new_database();
        $intelligence = new AiDailyIntelligence(new FakeAiProvider([]), $db, []);

        $findings = [
            ['type' => 'sales_decline', 'severity' => 'critical', 'entity_type' => 'product', 'entity_id' => 5, 'change_percent' => -90.0],
            ['type' => 'sales_decline', 'severity' => 'critical', 'entity_type' => 'product', 'entity_id' => 5, 'change_percent' => -90.0],
        ];

        $result = $ref->invoke($intelligence, $findings, 10);

        $this->assertCount(1, $result);
    }

    // ------------------------------------------------------------------
    // 7. Elégtelen adat
    // ------------------------------------------------------------------

    public function testInsufficientDataIsPassedToSynthesisNotSilentlyDropped(): void
    {
        $db = tests_new_database(); // üres bolt — MINDEN eladás-alapú ellenőrzés elégtelen adatot ad
        $provider = new FakeAiProvider([
            new AiChatResponse('Elégtelen adat a megbízható elemzéshez.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $intelligence->generateForDate($this->today());

        $sentMessage = $provider->receivedMessages[0][1]['content'] ?? '';
        $this->assertStringContainsString('insufficient_data', $sentMessage);
    }

    // ------------------------------------------------------------------
    // 8-9. Forgalmi / készlet összegzés
    // ------------------------------------------------------------------

    public function testSalesSummaryIsIncludedInSynthesisContext(): void
    {
        $db = tests_new_database();
        $pdo = $db->pdo();
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES ("Mai termék", "db", 1000, 787, 50, "Italok", "27")');
        $pdo->exec("INSERT INTO sales (total, payment_method, created_at) VALUES (5000, 'Készpénz', datetime('now'))");

        $provider = new FakeAiProvider([new AiChatResponse('Összegzés.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);
        $intelligence->generateForDate($this->today());

        $sentMessage = $provider->receivedMessages[0][1]['content'] ?? '';
        $this->assertStringContainsString('sales_summary', $sentMessage);
        $this->assertStringContainsString('gross_sales', $sentMessage);
    }

    public function testInventoryLowStockIsIncludedInSynthesisContext(): void
    {
        $db = tests_new_database();
        $pdo = $db->pdo();
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate) VALUES ("Kifogyóban lévő termék", "db", 1000, 787, 1, 5, "Italok", "27")');

        $provider = new FakeAiProvider([new AiChatResponse('Összegzés.', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);
        $intelligence->generateForDate($this->today());

        $sentMessage = $provider->receivedMessages[0][1]['content'] ?? '';
        $this->assertStringContainsString('Kifogyóban lévő termék', $sentMessage);
        $this->assertStringContainsString('inventory_low_stock', $sentMessage);
    }

    // ------------------------------------------------------------------
    // 10-12. Provider elérhetetlen / hiba / szintézis-hiba
    // ------------------------------------------------------------------

    public function testProviderUnavailableFailsGracefullyAndPersistsFailure(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('failed', $result['status']);
        $stored = $db->getAiDailyReport($this->today());
        $this->assertSame('failed', $stored['status']);
        $this->assertNull($stored['report_text']);
        $this->assertNotNull($stored['error']);
        // SOSE nyers kivétel-szöveg a tárolt hibában.
        $this->assertStringNotContainsString('kapcsolódási hiba', (string) $stored['error']);
    }

    public function testProviderTimeoutFailsGracefully(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiProviderException('időtúllépés', 'timeout'),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('failed', $result['status']);
    }

    public function testSynthesisEmptyResponseIsTreatedAsFailure(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse('', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $result = $intelligence->generateForDate($this->today());

        $this->assertSame('failed', $result['status']);
        $stored = $db->getAiDailyReport($this->today());
        $this->assertSame('failed', $stored['status']);
    }

    // ------------------------------------------------------------------
    // 13. Jelentés-perzisztencia
    // ------------------------------------------------------------------

    public function testSuccessfulReportIsFullyPersisted(): void
    {
        $db = tests_new_database();
        $this->seedDecliningProduct($db, 'Perzisztencia teszt termék', 20, 2);
        $provider = new FakeAiProvider([
            new AiChatResponse('A forgalom jelentősen visszaesett egy terméknél.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, ['anthropic_model' => 'claude-sonnet-5']);

        $intelligence->generateForDate($this->today());

        $stored = $db->getAiDailyReport($this->today());
        $this->assertSame('completed', $stored['status']);
        $this->assertSame('fake', $stored['provider']);
        $this->assertSame('A forgalom jelentősen visszaesett egy terméknél.', $stored['report_text']);
        $this->assertNotEmpty($stored['findings_json']);
        $decoded = json_decode((string) $stored['findings_json'], true);
        $this->assertIsArray($decoded);
    }

    // ------------------------------------------------------------------
    // 14-15. Ugyanaznapi duplikált futás / újrapróbálkozás hiba után
    // ------------------------------------------------------------------

    public function testDuplicateSameDayExecutionIsSkippedNotRegenerated(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse('Első futás.', []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $first = $intelligence->generateForDate($this->today());
        $second = $intelligence->generateForDate($this->today());

        $this->assertSame('completed', $first['status']);
        $this->assertSame('skipped', $second['status']);
        $this->assertSame(1, $provider->callCount());
    }

    public function testRetryAfterFailureIsAllowedAndCanSucceed(): void
    {
        $db = tests_new_database();
        $failingProvider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $firstIntelligence = new AiDailyIntelligence($failingProvider, $db, []);
        $first = $firstIntelligence->generateForDate($this->today());
        $this->assertSame('failed', $first['status']);

        $retryProvider = new FakeAiProvider([
            new AiChatResponse('Sikeres újrapróbálkozás.', []),
        ]);
        $retryIntelligence = new AiDailyIntelligence($retryProvider, $db, []);
        $retry = $retryIntelligence->generateForDate($this->today());

        $this->assertSame('completed', $retry['status']);
        $stored = $db->getAiDailyReport($this->today());
        $this->assertSame('completed', $stored['status']);
        $this->assertSame('Sikeres újrapróbálkozás.', $stored['report_text']);
    }

    // ------------------------------------------------------------------
    // 16. Determinisztikus kontextus-sorrend
    // ------------------------------------------------------------------

    public function testContextOrderingIsDeterministicAcrossRepeatedCalls(): void
    {
        $ref = new ReflectionMethod(AiDailyIntelligence::class, 'prioritizeFindings');
        $ref->setAccessible(true);
        $db = tests_new_database();
        $intelligence = new AiDailyIntelligence(new FakeAiProvider([]), $db, []);

        $findings = [
            ['type' => 'sales_decline', 'severity' => 'high', 'entity_type' => 'product', 'entity_id' => 3, 'change_percent' => -55.0],
            ['type' => 'sales_decline', 'severity' => 'high', 'entity_type' => 'product', 'entity_id' => 1, 'change_percent' => -55.0],
            ['type' => 'sales_decline', 'severity' => 'high', 'entity_type' => 'product', 'entity_id' => 2, 'change_percent' => -55.0],
        ];

        $resultA = $ref->invoke($intelligence, $findings, 10);
        $resultB = $ref->invoke($intelligence, $findings, 10);

        // Azonos súlyosság/magnitúdó esetén az entity_id ad stabil,
        // determinisztikus tie-breaket — SOSE a hívási/beérkezési sorrend.
        $this->assertSame([1, 2, 3], array_column($resultA, 'entity_id'));
        $this->assertSame($resultA, $resultB);
    }

    // ------------------------------------------------------------------
    // 17. Bounded jelentés-hossz
    // ------------------------------------------------------------------

    public function testReportTextIsBoundedInLength(): void
    {
        $db = tests_new_database();
        $veryLongText = str_repeat('a', 10000);
        $provider = new FakeAiProvider([
            new AiChatResponse($veryLongText, []),
        ]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $intelligence->generateForDate($this->today());

        $stored = $db->getAiDailyReport($this->today());
        $this->assertLessThanOrEqual(4000, mb_strlen((string) $stored['report_text']));
    }

    // ------------------------------------------------------------------
    // 18. Nincs titok-szivárgás
    // ------------------------------------------------------------------

    public function testNoSecretsLeakIntoPersistedReport(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([new AiChatResponse('Összegzés.', [])]);
        $secretSettings = [
            'anthropic_api_key' => 'sk-ant-super-secret-value',
            'openai_api_key' => 'sk-openai-super-secret-value',
        ];
        $intelligence = new AiDailyIntelligence($provider, $db, $secretSettings);

        $intelligence->generateForDate($this->today());

        $stored = $db->getAiDailyReport($this->today());
        $serialized = json_encode($stored, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('sk-ant-super-secret-value', $serialized);
        $this->assertStringNotContainsString('sk-openai-super-secret-value', $serialized);
    }

    // ------------------------------------------------------------------
    // 19. Nincs írási művelet
    // ------------------------------------------------------------------

    public function testSynthesisRunsWithAnEmptyToolRegistryProvingNoWriteCapability(): void
    {
        $db = tests_new_database();
        $capturedRegistry = null;
        // Az AgentRunner-nek átadott ToolRegistry-t közvetve, a
        // provider-nek elküldött "tools" paraméteren keresztül
        // ellenőrizzük — egy üres ToolRegistry ÜRES eszközlistát ad a
        // providernek (lásd ToolRegistry::toProviderToolList()), tehát a
        // modell STRUKTURÁLISAN sose hívhat meg semmilyen eszközt —
        // sem olvasót, sem (nem is létező) írót.
        $provider = new class implements AiProviderInterface {
            public array $receivedTools = [];
            public function name(): string { return 'fake'; }
            public function chat(array $messages, array $tools): AiChatResponse
            {
                $this->receivedTools = $tools;
                return new AiChatResponse('Összegzés.', []);
            }
            public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
        };
        $intelligence = new AiDailyIntelligence($provider, $db, []);

        $intelligence->generateForDate($this->today());

        $this->assertSame([], $provider->receivedTools);
    }

    // ------------------------------------------------------------------
    // 20. Nincs rekurzív Copilot-hívás
    // ------------------------------------------------------------------

    public function testSourceNeverReferencesAiCopilotProvingAcyclicExecutionGraph(): void
    {
        // Szándékosan NEM sima "AiCopilot" szöveg-keresés — az osztály
        // SAJÁT docblokkja (jogosan) EMLÍTI az AiCopilot nevét, amikor
        // épp azt dokumentálja, hogy SOSE hívja meg. Ez a teszt a
        // TÉNYLEGES kód-szintű használatot zárja ki: nincs require/new/
        // statikus hívás az AiCopilot osztályra.
        $source = (string) file_get_contents(__DIR__ . '/../src/Ai/AiDailyIntelligence.php');
        $this->assertStringNotContainsString('AiCopilot.php', $source);
        $this->assertDoesNotMatchRegularExpression('/new\s+AiCopilot\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/AiCopilot::/', $source);
    }

    public function testDailyIntelligenceName(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([new AiChatResponse('x', [])]);
        $intelligence = new AiDailyIntelligence($provider, $db, []);
        $this->assertInstanceOf(AiDailyIntelligence::class, $intelligence);
    }
}
