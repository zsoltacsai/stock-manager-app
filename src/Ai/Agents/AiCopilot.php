<?php

declare(strict_types=1);

require_once __DIR__ . '/../AiProviderInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../AgentRunner.php';
require_once __DIR__ . '/../AgentRunResult.php';
require_once __DIR__ . '/../CopilotRunResult.php';
require_once __DIR__ . '/InventoryAgent.php';
require_once __DIR__ . '/SalesAgent.php';
require_once __DIR__ . '/AnomalyAgent.php';

/**
 * Fázis 6 — a negyedik FountainTrade AI-"agent", de NEM egy negyedik
 * domain-szakértő: az AiCopilot KIZÁRÓLAG útválasztó/összefoglaló, a
 * TÉNYLEGES üzleti tudást (eszközök, számítások) továbbra is a MEGLÉVŐ
 * InventoryAgent/SalesAgent/AnomalyAgent birtokolja — a Copilot ezeket
 * HÍVJA MEG, nem helyettesíti (lásd a kör 2. pontja).
 *
 * ARCHITEKTÚRA (a kör 1. pontja: "smallest architecture that can add a
 * provider-neutral Copilot without duplicating agent infrastructure"):
 * a Copilot MAGA IS a meglévő AgentRunner-en fut, egy SAJÁT ToolRegistry-
 * vel, amiben PONTOSAN HÁROM eszköz van regisztrálva —
 * "ask_inventory_agent" / "ask_sales_agent" / "ask_anomaly_agent" —,
 * mindegyik egy vékony wrapper, ami egyszerűen meghívja a megfelelő
 * agent MÁR LÉTEZŐ answer()-jét. Ez azt jelenti:
 *   - Nincs új, saját tool-calling ciklus — a Copilot a modellt UGYANÚGY
 *     "function calling"-gal irányítja, mint bármelyik domain-agent, az
 *     AgentRunner-en keresztül, provider-független módon (a kör 8. pontja).
 *   - A fehérlistázás (a kör 3. pontja) STRUKTURÁLISAN garantált, nem
 *     futásidejű ellenőrzéssel: a ToolRegistry (lásd ott a docblokkja)
 *     ELEVE csak a regisztrált 3 nevet fogadja el, egy modell által
 *     kitalált negyedik/tetszőleges nevet automatikusan, biztonságosan
 *     ToolResult::fail()-lel utasít el (lásd ToolRegistry::execute()) —
 *     a Copilot-nak EHHEZ semmilyen saját védelmi kódot nem kellett írnia.
 *   - REKURZIÓ STRUKTURÁLISAN LEHETETLEN (a kör 6. pontja): az
 *     InventoryAgent/SalesAgent/AnomalyAgent SAJÁT ToolRegistry-jébe
 *     KIZÁRÓLAG a domain-eszközeik (InventoryTools/SalesTools/
 *     AnomalyTools) kerülnek regisztrálásra — "ask_*_agent" eszköz
 *     EGYIKÜKNÉL SEM létezik, tehát egy agent-futás sose tud "visszahívni"
 *     a Copilot-ba, MÉG akkor sem, ha egy modell megpróbálná (lásd
 *     tests/AiCopilotTest.php recursion-tesztje).
 *
 * KÖLTSÉG-/HÍVÁSKORLÁT (a kör 9. pontja): self::MAX_AGENT_CALLS (3) —
 * ennyi domain-agent-hívásig engedi a Copilot; egy negyedik/további
 * kísérlet biztonságos ToolResult::fail()-t kap (NEM fut le), a modell
 * ezután a már meglévő eredményekkel kell összefoglaljon. Ez EGYÜTT az
 * AgentRunner SAJÁT maxIterations-ával (a Copilot saját "gondolkodási"
 * köreinek felső korlátja) zárja ki, hogy egy ártatlan kérdés
 * korlátlan számú modell-hívást indítson.
 */
final class AiCopilot
{
    private const MAX_AGENT_CALLS = 3;

    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Te a FountainTrade kasszaprogram AI Copilot-ja vagy — egy útválasztó/
összefoglaló asszisztens, aki a boltvezető/dolgozó kérdését a MEGFELELŐ
szakértő ügynök(ök)höz irányítja, majd a kapott eredményeket egyetlen,
tömör, üzleti hangvételű válaszba foglalja össze. Magyar nyelven válaszolj.

Három szakértő ügynök áll rendelkezésedre eszközként:
- ask_inventory_agent — készlettel, kifogyással, beszerzéssel kapcsolatos kérdésekhez.
- ask_sales_agent — forgalommal, eladással, visszáruval kapcsolatos kérdésekhez.
- ask_anomaly_agent — szokatlan/anomális forgalmi vagy készlet-mintázatokkal kapcsolatos kérdésekhez.

SZIGORÚ SZABÁLYOK:
1. Mindig a LEHETŐ LEGKEVESEBB releváns ügynököt hívd meg — egyszerű, egy-domain kérdéshez EGY ügynök elég (pl. "mi fogyott ki?" → csak ask_inventory_agent). Csak akkor hívj meg többet, ha a kérdés VALÓBAN több területet érint (pl. "miért esett vissza X termék eladása?" → forgalom ÉS készlet, esetleg anomália is indokolt lehet).
2. Legfeljebb 3 ügynököt hívhatsz meg egy kérdés megválaszolásához — ennyi áll rendelkezésre összesen, ne próbálj ennél többet.
3. Az ügynökök válaszait TEKINTSD HITELES, végleges üzleti bizonyítéknak — SOSE találj ki, becsülj vagy módosíts egyetlen számadatot/tényt sem, amit egy ügynök visszaadott, és SOSE végezz saját, az ügynökök eredményeitől független számítást.
4. Ha egy ügynök "elégtelen adat"-ot vagy hiányzó információt jelez, ezt a végleges válaszodban is EGYÉRTELMŰEN, külön közöld — SOSE írd át "minden rendben van"-ra vagy hagyd figyelmen kívül.
5. KORRELÁCIÓ VS. OKOZATISÁG — kritikus: ha több ügynök eredményét kombinálod (pl. csökkenő eladás ÉS nő a készlet ugyanarra a termékre), azt mondhatod, hogy ez EGYÜTT valamire UTALHAT ("ez arra utalhat, hogy..."), de SOSE állíthatod ok-okozati kapcsolatként ("X-et Y okozza") — az ügynökök együttjárást mutatnak, nem okozatiságot. Fogalmazz óvatosan.
6. Csak OLVASÁSRA vagy képes, akárcsak a mögötted álló ügynökök: nem módosíthatsz készletet, árat, eladást, kasszát, vevőt, rendelést vagy semmilyen egyéb üzleti adatot. Ha a felhasználó ilyet kérne, mondd el, hogy ehhez a megfelelő FountainTrade felületet kell használnia.
7. SOSE találj ki egy ügynök-eredményt — ha egy ügynök-hívás sikertelen volt, mondd ki egyértelműen, hogy az adott terület elemzése most nem sikerült, ne helyettesítsd kitalált adattal.
8. A végleges válaszodban röviden jelezd, mely terület(ek) eredményére alapoztál (pl. "a készlet- és forgalmi adatok alapján..."), majd a lényegre törően foglald össze a választ — ne esszézz.
PROMPT;

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly Database $db,
        private readonly array $appSettings,
        private readonly int $maxIterations = 5,
    ) {
    }

    public function answer(string $question): CopilotRunResult
    {
        $agentsUsedOrder = [];
        $agentResults = [];
        $callCount = 0;

        $invoke = function (string $agentName, object $agent, string $subQuestion) use (&$agentsUsedOrder, &$agentResults, &$callCount): array {
            if ($callCount >= self::MAX_AGENT_CALLS) {
                return [
                    'success' => false,
                    'error' => 'Elérted a Copilot maximális ügynök-hívási számát (' . self::MAX_AGENT_CALLS . ') ebben a kérdésben — foglald össze a mostanáig kapott eredményeket a felhasználónak.',
                ];
            }
            $callCount++;
            if (!in_array($agentName, $agentsUsedOrder, true)) {
                $agentsUsedOrder[] = $agentName;
            }

            /** @var AgentRunResult $result */
            $result = $agent->answer($subQuestion);
            $agentResults[$agentName] = [
                'success' => $result->success,
                'tools_used' => $result->toolsUsed,
                'error' => $result->error,
            ];

            return $result->success
                ? ['success' => true, 'answer' => $result->answer, 'tools_used' => $result->toolsUsed]
                : ['success' => false, 'error' => $result->error ?? 'Az ügynök nem tudott végleges választ adni.'];
        };

        $registry = new ToolRegistry();

        $registry->register(new ToolDefinition(
            'ask_inventory_agent',
            'A Készlet-ügynök (InventoryAgent) meghívása egy készlettel/kifogyással/beszerzéssel kapcsolatos rész-kérdéssel. A "question" argumentumban egy pontos, fókuszált kérdést adj meg (lehet a felhasználó eredeti kérdésének egy szűkebb változata is).',
            [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'A Készlet-ügynöknek feltett pontos kérdés.'],
                ],
                'required' => ['question'],
            ],
            function (array $args) use ($invoke, $question) {
                $agent = new InventoryAgent($this->provider, $this->db, $this->appSettings, $this->maxIterations);
                $sub = trim((string) ($args['question'] ?? '')) !== '' ? (string) $args['question'] : $question;
                return $invoke('inventory', $agent, $sub);
            }
        ));

        $registry->register(new ToolDefinition(
            'ask_sales_agent',
            'A Forgalmi ügynök (SalesAgent) meghívása egy forgalommal/eladással/visszáruval kapcsolatos rész-kérdéssel. A "question" argumentumban egy pontos, fókuszált kérdést adj meg (lehet a felhasználó eredeti kérdésének egy szűkebb változata is).',
            [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'A Forgalmi ügynöknek feltett pontos kérdés.'],
                ],
                'required' => ['question'],
            ],
            function (array $args) use ($invoke, $question) {
                $agent = new SalesAgent($this->provider, $this->db, $this->appSettings, $this->maxIterations);
                $sub = trim((string) ($args['question'] ?? '')) !== '' ? (string) $args['question'] : $question;
                return $invoke('sales', $agent, $sub);
            }
        ));

        $registry->register(new ToolDefinition(
            'ask_anomaly_agent',
            'Az Anomália-ügynök (AnomalyAgent) meghívása egy szokatlan forgalmi/készlet-mintázattal kapcsolatos rész-kérdéssel. A "question" argumentumban egy pontos, fókuszált kérdést adj meg (lehet a felhasználó eredeti kérdésének egy szűkebb változata is).',
            [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'Az Anomália-ügynöknek feltett pontos kérdés.'],
                ],
                'required' => ['question'],
            ],
            function (array $args) use ($invoke, $question) {
                $agent = new AnomalyAgent($this->provider, $this->db, $this->appSettings, $this->maxIterations);
                $sub = trim((string) ($args['question'] ?? '')) !== '' ? (string) $args['question'] : $question;
                return $invoke('anomaly', $agent, $sub);
            }
        ));

        $runner = new AgentRunner($this->provider, $registry, $this->maxIterations);
        $runResult = $runner->run(self::SYSTEM_INSTRUCTION, $question);

        return CopilotRunResult::fromRun($runResult, $agentsUsedOrder, $agentResults);
    }

    public static function name(): string
    {
        return 'copilot';
    }
}
