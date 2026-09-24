<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 6 — az AiCopilot (routing/orchestration) determinisztikus,
 * FakeAiProvider-rel vezérelt tesztjei (lásd tests/AiAgentRunnerTest.php
 * a FakeAiProvider docblokkja). Mivel a Copilot a TÉNYLEGES InventoryAgent/
 * SalesAgent/AnomalyAgent-eket hívja meg — SAJÁT AgentRunneren keresztül,
 * MINDEGYIK UGYANAZT a FakeAiProvider-példányt kapja —, egyetlen
 * scriptelt válaszlista fedi le a TELJES, beágyazott hívási láncot: a
 * Copilot saját chat()-hívásai ÉS a meghívott agentek saját (beágyazott)
 * chat()-hívásai UGYANABBÓL a sorból fogyasztanak, PONTOSAN abban a
 * sorrendben, ahogy azok ténylegesen lefutnának.
 */
final class AiCopilotTest extends TestCase
{
    private function seedAnomalyProduct(Database $db): int
    {
        $pdo = $db->pdo();
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES ("Copilot teszt termék", "db", 1000, 787, 500, "Italok", "27")');
        $productId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (20000, 'Készpénz', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $saleId1 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", 20, 1000, "27")')
            ->execute([$saleId1, $productId]);
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (2000, 'Készpénz', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-5 days'))]);
        $saleId2 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", 2, 1000, "27")')
            ->execute([$saleId2, $productId]);
        return $productId;
    }

    // ------------------------------------------------------------------
    // 1-3: egy-agent routing
    // ------------------------------------------------------------------

    public function testInventoryOnlyRouting(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'Mi fogyott ki?'])]),
            new AiChatResponse('Semmi nem fogyott ki jelenleg.', []),
            new AiChatResponse('A készlet alapján jelenleg semmi nem fogyott ki.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mi fogyott ki?');

        $this->assertTrue($result->success);
        $this->assertSame(['inventory'], $result->agentsUsed);
        $this->assertTrue($result->agentResults['inventory']['success']);
        $this->assertSame('A készlet alapján jelenleg semmi nem fogyott ki.', $result->answer);
    }

    public function testSalesOnlyRouting(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_sales_agent', ['question' => 'Mennyi volt a forgalom ezen a héten?'])]),
            new AiChatResponse('A heti forgalom stabil.', []),
            new AiChatResponse('A forgalmi adatok alapján a heti forgalom stabil.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mennyi volt a forgalom ezen a héten?');

        $this->assertTrue($result->success);
        $this->assertSame(['sales'], $result->agentsUsed);
        $this->assertTrue($result->agentResults['sales']['success']);
    }

    public function testAnomalyOnlyRouting(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_anomaly_agent', ['question' => 'Van valami szokatlan a készletben?'])]),
            new AiChatResponse('Nincs szokatlan minta.', []),
            new AiChatResponse('Az anomália-elemzés szerint nincs szokatlan minta.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Van valami szokatlan a készletben?');

        $this->assertTrue($result->success);
        $this->assertSame(['anomaly'], $result->agentsUsed);
        $this->assertTrue($result->agentResults['anomaly']['success']);
    }

    public function testCopilotDoesNotForceMultipleAgentCallsWhenOneSuffices(): void
    {
        // "Minimum agent selection" (kör 4./10. pontja): a Copilot
        // architektúrája nem KÉNYSZERÍT ki több ügynök-hívást — ha a
        // (scriptelt) modell egyetlen hívással elégedett, PONTOSAN egy
        // ügynök fut le, nincs "kötelező" második/harmadik hívás.
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'Mi fogyott ki?'])]),
            new AiChatResponse('Válasz.', []),
            new AiChatResponse('Végleges válasz.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mi fogyott ki?');

        $this->assertCount(1, $result->agentsUsed);
    }

    // ------------------------------------------------------------------
    // 4-5: több-agent routing
    // ------------------------------------------------------------------

    public function testTwoAgentRouting(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_sales_agent', ['question' => 'Csökkent-e a forgalma?']),
                new ToolCall('c2', 'ask_inventory_agent', ['question' => 'Van-e készlethiány?']),
            ]),
            new AiChatResponse('A forgalma visszaesett.', []),
            new AiChatResponse('Nincs készlethiány.', []),
            new AiChatResponse('A forgalom visszaesett, de nincs készlethiány — a visszaesés nem magyarázható kifogyással.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Miért esett vissza a termék eladása?');

        $this->assertTrue($result->success);
        $this->assertSame(['sales', 'inventory'], $result->agentsUsed);
        $this->assertTrue($result->agentResults['sales']['success']);
        $this->assertTrue($result->agentResults['inventory']['success']);
    }

    public function testThreeAgentRouting(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_sales_agent', ['question' => 'Csökkent-e az eladás?']),
                new ToolCall('c2', 'ask_inventory_agent', ['question' => 'Nő-e a készlet?']),
                new ToolCall('c3', 'ask_anomaly_agent', ['question' => 'Van-e ezzel kapcsolatos anomália?']),
            ]),
            new AiChatResponse('Az eladás csökken.', []),
            new AiChatResponse('A készlet nő.', []),
            new AiChatResponse('Ez anomáliának minősül.', []),
            new AiChatResponse('Van olyan termék, aminél az eladás csökken, miközben a készlete nő — ez lassuló készletforgásra utalhat.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Van olyan termék, amelyből nő a készlet, miközben csökken az értékesítés?');

        $this->assertTrue($result->success);
        $this->assertSame(['sales', 'inventory', 'anomaly'], $result->agentsUsed);
        $this->assertCount(3, $result->agentResults);
        $this->assertStringContainsString('utalhat', (string) $result->answer);
    }

    // ------------------------------------------------------------------
    // 7: érvénytelen agent-választás — a fehérlista strukturálisan védi (ToolRegistry)
    // ------------------------------------------------------------------

    public function testInvalidAgentSelectionIsSafelyRejectedByToolRegistry(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_finance_agent', [])]),
            new AiChatResponse('Ez a terület jelenleg nem elérhető ügynökként.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mutasd a mai bevételt fillérre pontosan.');

        $this->assertTrue($result->success);
        // Az ismeretlen "ask_finance_agent" SOSE futott le valódi agentként —
        // a Copilot SAJÁT agentsUsed/agentResults állapota üres marad,
        // mert az $invoke closure ehhez SOSE hívódott meg (a ToolRegistry
        // már előtte, saját maga elutasította).
        $this->assertSame([], $result->agentsUsed);
        $this->assertSame([], $result->agentResults);

        $toolMessages = array_values(array_filter($provider->receivedMessages[1], fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);
    }

    // ------------------------------------------------------------------
    // 8: rekurzió-védelem
    // ------------------------------------------------------------------

    public function testSubAgentToolRegistryNeverContainsCopilotTools(): void
    {
        // Strukturális bizonyíték (kör 6. pontja): egyetlen domain-agent
        // ToolRegistry-je SEM tartalmazza az "ask_*_agent" neveket —
        // rekurzió emiatt STRUKTURÁLISAN kizárt, nem futásidejű
        // ellenőrzés védi.
        $db = tests_new_database();
        $inv = new ToolRegistry();
        InventoryTools::registerAll($inv, $db, []);
        $sales = new ToolRegistry();
        SalesTools::registerAll($sales, $db, []);
        InventoryTools::registerAll($sales, $db, []);
        $anomaly = new ToolRegistry();
        AnomalyTools::registerAll($anomaly, $db, []);

        foreach (['ask_inventory_agent', 'ask_sales_agent', 'ask_anomaly_agent'] as $forbidden) {
            $this->assertFalse($inv->has($forbidden));
            $this->assertFalse($sales->has($forbidden));
            $this->assertFalse($anomaly->has($forbidden));
        }
    }

    public function testRecursionAttemptFromWithinASubAgentIsGracefullyRejected(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            // Copilot: hívja az InventoryAgent-et.
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'Mi fogyott ki?'])]),
            // A BEÁGYAZOTT InventoryAgent futás SAJÁT chat()-je egy
            // rekurzív "visszahívást" próbál — de az InventoryAgent SAJÁT
            // ToolRegistry-jében ez a név SOSE létezik.
            new AiChatResponse(null, [new ToolCall('x1', 'ask_sales_agent', [])]),
            // Az InventoryAgent ezt a biztonságos "Ismeretlen eszköz"
            // hibát látva mégis tud érdemi választ adni.
            new AiChatResponse('Rendben, ezt nem tudom lekérdezni innen, de a készlet stabil.', []),
            // Copilot végső szintézise.
            new AiChatResponse('A készlet stabil.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mi fogyott ki?');

        $this->assertTrue($result->success);
        $this->assertTrue($result->agentResults['inventory']['success']);

        // A beágyazott InventoryAgent MÁSODIK chat()-hívása (globális
        // index 2, mert index 0 a Copilot, index 1 az InventoryAgent
        // első hívása) látta az "Ismeretlen eszköz" tool-eredményt.
        $toolMessages = array_values(array_filter($provider->receivedMessages[2], fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);
    }

    // ------------------------------------------------------------------
    // 9: max-agent limit
    // ------------------------------------------------------------------

    public function testMaxAgentCallLimitIsEnforced(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_inventory_agent', ['question' => 'q1']),
                new ToolCall('c2', 'ask_sales_agent', ['question' => 'q2']),
                new ToolCall('c3', 'ask_anomaly_agent', ['question' => 'q3']),
                new ToolCall('c4', 'ask_inventory_agent', ['question' => 'q4 — negyedik, már a limit felett']),
            ]),
            new AiChatResponse('inventory válasz', []),
            new AiChatResponse('sales válasz', []),
            new AiChatResponse('anomaly válasz', []),
            // A negyedik hívás SOSE fogyaszt scriptelt választ (nem indít
            // új agent-futást) — a Copilot MÁR a limit miatt utasítja el.
            new AiChatResponse('Végleges válasz a három elért ügynök alapján.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mindent mondj el mindenről.');

        $this->assertTrue($result->success);
        $this->assertSame(['inventory', 'sales', 'anomaly'], $result->agentsUsed);
        $this->assertCount(3, $result->agentResults);
        $this->assertSame(5, $provider->callCount()); // 1 (copilot) + 3 (valódi nested agent-futás) + 1 (copilot szintézis) — a 4. hívási kísérlet NEM indított új nested futást

        $toolMessages = array_values(array_filter($provider->receivedMessages[4], fn ($m) => $m['role'] === 'tool'));
        $lastToolMessage = end($toolMessages);
        $this->assertStringContainsString('maximális', $lastToolMessage['content']);
    }

    public function testCostLimitStopsFurtherSubAgentCallsWithoutExecutingThem(): void
    {
        // Fázis 10 — a kör 11. pontja: a hívásszám-korláttól (fent)
        // FÜGGETLEN, dollár-alapú korlát — 'anthropic'/'claude-sonnet-5'
        // néven (lásd AiPricing.php, Fázis 10-ben hivatalosan ellenőrzött
        // árazással), hogy a becslés VALÓDI, nem-nulla dollárérték legyen.
        $db = tests_new_database();
        $usage = new AiUsage(1_000_000, 1_000_000, 2_000_000); // $12 becsült költség claude-sonnet-5-nél
        $secondAgentInvoked = false;
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_inventory_agent', ['question' => 'q1']),
                new ToolCall('c2', 'ask_sales_agent', ['question' => 'q2 — már a költség-korlát felett']),
            ]),
            new AiChatResponse('inventory válasz', [], $usage),
            // A második (sales) hívás SOSE fogyaszt scriptelt választ —
            // a Copilot MÁR a költség-korlát miatt utasítja el, mielőtt
            // a SalesAgent ténylegesen lefutna.
            new AiChatResponse('Végleges válasz az egy elért ügynök alapján.', []),
        ], 'anthropic');
        $appSettings = [
            'ai_provider' => 'anthropic',
            'anthropic_model' => 'claude-sonnet-5',
            'ai_max_estimated_cost_per_request' => 1.0, // $1 — az első hívás $12-je MÁR túllépi
        ];
        $copilot = new AiCopilot($provider, $db, $appSettings, 5);

        $result = $copilot->answer('Mindent mondj el mindenről.');

        $this->assertTrue($result->success, 'A Copilotnak biztonságosan, a meglévő részeredményekből kell összefoglalnia, NEM elszállnia a korlát elérésekor.');
        $this->assertSame(['inventory'], $result->agentsUsed, 'A második (sales) ügynök SOSE indulhatott el — a korlátot MÁR az első hívás elérte.');
        $this->assertCount(1, $result->agentResults);
        // 1 (copilot) + 1 (valódi inventory-futás) + 1 (copilot szintézis)
        // — a sales-hívási kísérlet NEM indított új nested futást.
        $this->assertSame(3, $provider->callCount());

        $toolMessages = array_values(array_filter($provider->receivedMessages[2], fn ($m) => $m['role'] === 'tool'));
        $lastToolMessage = end($toolMessages);
        $this->assertStringContainsString('költség-korlátot', $lastToolMessage['content']);
    }

    // ------------------------------------------------------------------
    // 10-11: agent-hiba / részleges agent-hiba
    // ------------------------------------------------------------------

    public function testSingleAgentFailureIsHandledGracefullyByCopilot(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_sales_agent', ['question' => 'Mennyi volt a forgalom?'])]),
            new AiProviderException('kapcsolódási hiba', 'unavailable'), // a SalesAgent beágyazott futása elbukik
            new AiChatResponse('A forgalmi elemzés jelenleg nem sikerült, próbáld később.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mennyi volt a forgalom?');

        $this->assertTrue($result->success); // a Copilot MAGA sikeresen lezárta a futást
        $this->assertFalse($result->agentResults['sales']['success']);
        $this->assertStringContainsString('nem érhető el', (string) $result->agentResults['sales']['error']);
    }

    public function testPartialAgentFailureAcrossTwoAgents(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_inventory_agent', ['question' => 'q1']),
                new ToolCall('c2', 'ask_sales_agent', ['question' => 'q2']),
            ]),
            new AiChatResponse('A készlet rendben.', []),
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
            new AiChatResponse('A készlet rendben, a forgalmi elemzés viszont most nem sikerült.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Kérdés mindkét területről.');

        $this->assertTrue($result->success);
        $this->assertTrue($result->agentResults['inventory']['success']);
        $this->assertFalse($result->agentResults['sales']['success']);
    }

    // ------------------------------------------------------------------
    // 12: provider-hiba a Copilot SAJÁT (legfelső) szintjén
    // ------------------------------------------------------------------

    public function testTopLevelProviderFailureBeforeAnyAgentIsInvoked(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Bármilyen kérdés.');

        $this->assertFalse($result->success);
        $this->assertSame([], $result->agentsUsed);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $result->error);
    }

    // ------------------------------------------------------------------
    // 13: szintézis-hiba (az agentek sikeresek, a végső összegzés nem)
    // ------------------------------------------------------------------

    public function testSynthesisFailureAfterSuccessfulAgentCallStillPreservesAgentResults(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'q'])]),
            new AiChatResponse('A készlet rendben.', []),
            new AiChatResponse('', []), // üres végső válasz — a Copilot AgentRunner-je ezt hibaként kezeli
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Kérdés.');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        // A beágyazott agent-eredmény MEGŐRZŐDIK, még akkor is, ha a
        // szintézis végül elbukott — a Copilot nem dobja el a MÁR
        // ténylegesen begyűjtött bizonyítékot.
        $this->assertTrue($result->agentResults['inventory']['success']);
    }

    // ------------------------------------------------------------------
    // 14: elégtelen adat átvitele (real AnomalyTools egy üres boltra)
    // ------------------------------------------------------------------

    public function testInsufficientDataFromSubAgentIsPropagatedNotCollapsedToNormal(): void
    {
        $db = tests_new_database(); // szándékosan üres — nincs eladás
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_anomaly_agent', ['question' => 'Van valami szokatlan a forgalomban?'])]),
            new AiChatResponse(null, [new ToolCall('a1', 'get_sales_anomalies', ['period' => 'last_30_days'])]),
            new AiChatResponse('Nincs elég adat a megbízható elemzéshez ebben az időszakban.', []),
            new AiChatResponse('Az anomália-elemzéshez jelenleg nincs elég adat a rendszerben — ez nem azonos azzal, hogy nincs probléma.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Van valami szokatlan a forgalomban?');

        $this->assertTrue($result->success);
        $this->assertSame(['get_sales_anomalies'], $result->agentResults['anomaly']['tools_used']);
        $this->assertStringContainsString('nincs elég adat', (string) $result->answer);
        $this->assertStringNotContainsString('minden rendben van', (string) $result->answer);
    }

    // ------------------------------------------------------------------
    // 15: korreláció vs. okozatiság szabály a system promptban
    // ------------------------------------------------------------------

    public function testSystemInstructionEnforcesCorrelationNotCausationAndCoreRules(): void
    {
        $ref = new ReflectionClass(AiCopilot::class);
        $instruction = (string) $ref->getConstant('SYSTEM_INSTRUCTION');

        $this->assertStringContainsString('OKOZATI', $instruction);
        $this->assertStringContainsString('UTALHAT', $instruction);
        $this->assertStringContainsString('Legfeljebb 3', $instruction);
        $this->assertStringContainsString('elégtelen adat', $instruction);
        $this->assertStringContainsString('nem módosíthatsz', $instruction);
        $this->assertStringContainsString('LEHETŐ LEGKEVESEBB', $instruction);
    }

    // ------------------------------------------------------------------
    // 16: nincs kitalált érték — a Copilot SOSE állít agent-használatot, ha nem történt
    // ------------------------------------------------------------------

    public function testNoAgentIsClaimedAsUsedWhenNoToolWasEverCalled(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse('Ehhez nem szükséges egyetlen ügynök lekérdezése sem, ez egy általános kérdés.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Mi az a FountainTrade?');

        $this->assertTrue($result->success);
        $this->assertSame([], $result->agentsUsed);
        $this->assertSame([], $result->agentResults);
        $this->assertSame([], $result->toolsUsed);
    }

    // ------------------------------------------------------------------
    // Végponttól-végpontig, VALÓDI eszközökkel (kör 28. pontja mintája)
    // ------------------------------------------------------------------

    public function testEndToEndCrossDomainQuestionWithRealToolsAndDeterministicEvidence(): void
    {
        $db = tests_new_database();
        $productId = $this->seedAnomalyProduct($db);

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'ask_sales_agent', ['question' => 'Csökkent-e a termék eladása?']),
                new ToolCall('c2', 'ask_anomaly_agent', ['question' => 'Van-e ezzel kapcsolatos forgalmi anomália?']),
            ]),
            // Beágyazott SalesAgent — valódi SalesTools eszközt hív.
            new AiChatResponse(null, [new ToolCall('s1', 'get_product_sales_trend', ['product_id' => $productId, 'period' => 'last_30_days'])]),
            new AiChatResponse('A termék eladása jelentősen visszaesett.', []),
            // Beágyazott AnomalyAgent — valódi AnomalyTools eszközt hív.
            new AiChatResponse(null, [new ToolCall('a1', 'get_sales_anomalies', ['period' => 'last_30_days'])]),
            new AiChatResponse('Kritikus súlyosságú eladás-visszaesést találtam ennél a terméknél.', []),
            // Copilot végső szintézise.
            new AiChatResponse('A forgalmi és anomália-elemzés is jelentős, kritikus súlyosságú eladás-visszaesést mutat ennél a terméknél.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $result = $copilot->answer('Miért esett vissza ennek a terméknek az eladása, és ez szokatlan-e?');

        $this->assertTrue($result->success);
        $this->assertSame(['sales', 'anomaly'], $result->agentsUsed);
        $this->assertSame(['get_product_sales_trend'], $result->agentResults['sales']['tools_used']);
        $this->assertSame(['get_sales_anomalies'], $result->agentResults['anomaly']['tools_used']);

        // A ténylegesen begyűjtött, determinisztikus bizonyíték (a -90%-os
        // visszaesés) a beágyazott AnomalyAgent chat()-hívásának
        // eszköz-eredményében is megjelenik — nem a modell találta ki.
        $anomalyToolCallMessages = $provider->receivedMessages[4];
        $toolMessages = array_values(array_filter($anomalyToolCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('sales_decline', $toolMessages[0]['content']);
        $this->assertStringContainsString('-90', $toolMessages[0]['content']);
    }

    public function testCopilotName(): void
    {
        $this->assertSame('copilot', AiCopilot::name());
    }
}
