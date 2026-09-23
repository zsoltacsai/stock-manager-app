<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/AgentRunner.php';
require_once __DIR__ . '/AgentRunResult.php';
require_once __DIR__ . '/Tools/AnomalyTools.php';
require_once __DIR__ . '/Tools/SalesTools.php';
require_once __DIR__ . '/Tools/InventoryTools.php';

/**
 * Fázis 7 — napi AI-összefoglaló, "operational composition of existing
 * agents" (a kör 5. pontja), NEM egy új "DailyAgent". A folyamat SZIGORÚAN
 * kétfázisú:
 *
 *   1. Determinisztikus gyűjtés — a MEGLÉVŐ AnomalyTools/SalesTools/
 *      InventoryTools PHP-objektumokként, KÖZVETLENÜL (nem a ToolRegistry-n
 *      keresztül — itt nincs LLM, ami "választana" eszközt, a backend maga
 *      dönti el, mit gyűjt össze, lásd a kör 5. pontja: "Backend
 *      deterministic analytics ↓ structured findings").
 *   2. EGYETLEN szintézis-hívás — a MEGLÉVŐ AgentRunner-en, egy SZÁNDÉKOSAN
 *      ÜRES ToolRegistry-vel (a kör 9. pontja: "no recursive Copilot calls,
 *      no repeated agent invocation" — mivel a kontextus MÁR teljes egészében
 *      összegyűjtve a promptban van, a modellnek SOSE kell/lehet eszközt
 *      hívnia, tehát strukturálisan lehetetlen bármilyen további agent-/
 *      Copilot-hívást indítania innen). Ez a "small provider-neutral
 *      synthesis service ugyanazon AiProviderInterface/AgentRunner
 *      architektúrán", amit a kör 9. pontja explicit megenged az önálló
 *      Copilot-újrafelhasználás helyett — SOSE hívja meg az AiCopilot-ot,
 *      a végrehajtási gráf ezért garantáltan aciklikus.
 *
 * Az eredmény az `ai_daily_reports` táblába perzisztálódik
 * (Database::claimAiDailyReportSlot()/finalizeAiDailyReport() —
 * idempotencia/konkurencia-védelem ott, lásd a docblokkjukat).
 */
final class AiDailyIntelligence
{
    /** A kör 7. pontja: "Suggested bounded limit: maximum 10 findings per daily report." — a beállításban megadható érték is ide van felülről korlátozva. */
    public const MAX_FINDINGS_HARD_CAP = 10;
    private const MAX_LOW_STOCK_ITEMS = 10;
    private const MAX_REPORT_TEXT_LENGTH = 4000;
    public const DEFAULT_STALE_RUNNING_MINUTES = 30;

    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
Te a FountainTrade kasszaprogram napi AI-összefoglalójának szerkesztője vagy. Egy már ÖSSZEGYŰJTÖTT, strukturált adathalmazt kapsz (anomáliák, forgalmi összegzés, készlet-figyelmeztetések) — ebből készítesz egy rövid, magyar nyelvű, üzleti hangvételű napi jelentést a boltvezetőnek.

SZIGORÚ SZABÁLYOK:
1. A kapott adat HITELES és VÉGLEGES — SOSE találj ki, becsülj vagy egészíts ki egyetlen tényt vagy számadatot sem, ami nincs benne a kapott adatban.
2. SOSE végezz saját számítást (százalék, összeg, különbség) — minden szám a kapott adatból, MÁR kiszámítva jön, te ezt KIZÁRÓLAG idézed/magyarázod.
3. Világosan különböztesd meg a MEGFIGYELÉST (a kapott adat ténye) az ÉRTELMEZÉSTŐL (a te magyarázatod/javaslatod) — az értelmezés SOSE hangozhat úgy, mintha maga is tény lenne.
4. KORRELÁCIÓ VS. OKOZATISÁG — több együtt látott jelenségre mondhatod, hogy EGYÜTT valamire UTALHAT, de SOSE állíthatsz ok-okozati kapcsolatot, ha a kapott adat ezt nem bizonyítja.
5. Ha a kapott adatban "insufficient_data"/"elégtelen adat" jelzés van, ezt EGYÉRTELMŰEN, külön közöld — SOSE írd át "minden rendben van"-ra.
6. KIZÁRÓLAG a ténylegesen kapott megállapításokat foglald össze — ha a kapott anomália-lista üres, mondd ki egyértelműen, hogy nincs jelentős megállapítás mára, NE erőltess ki mesterséges "problémát".
7. A legfontosabb megállapítást(oka)t a kapott súlyosság/bizonyíték alapján emeld ki — SOSE alkoss saját, a kapott adattól független fontossági sorrendet.
8. Csak OLVASÁSRA/ELEMZÉSRE vagy képes — nem javasolhatsz és nem hajthatsz végre semmilyen készlet-, ár-, eladás-, kassza-, vevő- vagy számla-módosítást.
9. Légy tömör — legfeljebb néhány bekezdés, világos szerkezettel (anomáliák, forgalom, készlet, egyéb változások).
PROMPT;

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly Database $db,
        private readonly array $appSettings,
        private readonly int $maxIterations = 2,
    ) {
    }

    /**
     * @return array{status:string, reason?:string, error?:string, has_significant_findings?:bool, findings_count?:int}
     */
    public function generateForDate(string $reportDate): array
    {
        $staleAfterMinutes = max(5, (int) ($this->appSettings['ai_daily_intelligence_stale_minutes'] ?? self::DEFAULT_STALE_RUNNING_MINUTES));

        if (!$this->db->claimAiDailyReportSlot($reportDate, $staleAfterMinutes)) {
            // A kör 12. pontja — idempotencia: már fut, vagy már elkészült
            // ugyanerre a napra. Ez NEM hiba, a hívó (worker) egyszerűen
            // no-op-ként kezeli.
            return ['status' => 'skipped', 'reason' => 'already_running_or_completed'];
        }

        try {
            $context = $this->gatherContext($reportDate);
        } catch (Throwable $e) {
            error_log('[fountaintrade] AiDailyIntelligence adatgyűjtés sikertelen: ' . $e->getMessage());
            $this->db->finalizeAiDailyReport($reportDate, [
                'status' => 'failed',
                'error' => 'Az adatgyűjtés sikertelen — a napi jelentés nem készült el.',
            ]);
            return ['status' => 'failed', 'error' => 'Az adatgyűjtés sikertelen — a napi jelentés nem készült el.'];
        }

        // Szándékosan ÜRES ToolRegistry — lásd az osztály docblokkja: a
        // kontextus MÁR teljes, a modellnek SOSE kell (és SOSE tud) eszközt
        // hívni innen, ez zárja ki strukturálisan a rekurzív Copilot-/
        // agent-hívást.
        $registry = new ToolRegistry();
        $runner = new AgentRunner($this->provider, $registry, $this->maxIterations);
        $runResult = $runner->run(self::SYSTEM_INSTRUCTION, $this->buildUserMessage($context));

        $configuredModel = match ($this->provider->name()) {
            'anthropic' => (string) ($this->appSettings['anthropic_model'] ?? ''),
            'openai' => (string) ($this->appSettings['openai_model'] ?? ''),
            default => (string) ($this->appSettings['ai_local_model'] ?? ''),
        };

        if (!$runResult->success) {
            $this->db->finalizeAiDailyReport($reportDate, [
                'status' => 'failed',
                'provider' => $this->provider->name(),
                'model' => $configuredModel,
                'error' => (string) $runResult->error,
            ]);
            return ['status' => 'failed', 'error' => (string) $runResult->error];
        }

        $findingsCount = count($context['anomalies']);
        $hasSignificantFindings = $findingsCount > 0;
        $reportText = mb_substr((string) $runResult->answer, 0, self::MAX_REPORT_TEXT_LENGTH);
        $findingsJson = json_encode($context['anomalies'], JSON_UNESCAPED_UNICODE);

        $this->db->finalizeAiDailyReport($reportDate, [
            'status' => 'completed',
            'provider' => $this->provider->name(),
            'model' => $configuredModel,
            'has_significant_findings' => $hasSignificantFindings,
            'findings_count' => $findingsCount,
            'findings_json' => $findingsJson !== false ? $findingsJson : null,
            'report_text' => $reportText,
        ]);

        return [
            'status' => 'completed',
            'has_significant_findings' => $hasSignificantFindings,
            'findings_count' => $findingsCount,
        ];
    }

    /**
     * Determinisztikus gyűjtés — KIZÁRÓLAG a MEGLÉVŐ AnomalyTools/
     * SalesTools/InventoryTools PHP-objektumokon keresztül, egyszer, nem
     * ismételve ugyanazt a lekérdezést (a kör 19. pontja: "Do not re-run
     * the same expensive query repeatedly").
     *
     * @return array{anomalies:array,anomalies_data_quality:array,sales_summary:array,sales_comparison:array,inventory_low_stock:array}
     */
    private function gatherContext(string $reportDate): array
    {
        $anomalyTools = new AnomalyTools($this->db, $this->appSettings);
        $salesTools = new SalesTools($this->db, $this->appSettings);
        $inventoryTools = new InventoryTools($this->db, $this->appSettings);

        $salesAnomalies = $anomalyTools->getSalesAnomalies(['period' => 'last_30_days']);
        $inventoryAnomalies = $anomalyTools->getInventoryAnomalies(['period' => 'last_30_days']);

        $maxFindings = max(1, min(
            self::MAX_FINDINGS_HARD_CAP,
            (int) ($this->appSettings['ai_daily_intelligence_max_findings'] ?? self::MAX_FINDINGS_HARD_CAP)
        ));
        $anomalies = $this->prioritizeFindings(
            array_merge($salesAnomalies['anomalies'], $inventoryAnomalies['anomalies']),
            $maxFindings
        );

        $dataQuality = array_merge($salesAnomalies['data_quality'], $inventoryAnomalies['data_quality']);

        $salesSummary = $salesTools->getSalesSummary(['period' => 'today']);
        $salesComparison = $salesTools->compareSalesPeriods([
            'current_period' => 'today',
            'previous_period' => 'yesterday',
        ]);

        $lowStock = $inventoryTools->getLowStockProducts(['filter' => 'low', 'limit' => self::MAX_LOW_STOCK_ITEMS]);

        return [
            'report_date' => $reportDate,
            'anomalies' => $anomalies,
            'anomalies_data_quality' => $dataQuality,
            'sales_summary' => $salesSummary,
            'sales_comparison' => $salesComparison,
            'inventory_low_stock' => $lowStock['products'],
        ];
    }

    /**
     * Determinisztikus, stabil rendezés súlyosság szerint (kritikus >
     * magas > közepes > alacsony), majd |változás %| szerint csökkenő,
     * végül entity_id szerint növekvő (a kör 7. pontja: "do not let the
     * LLM choose which raw rows are discarded" — ez a metódus, SOSE a
     * modell, dönti el, mely rekordok maradnak a bounded limiten belül).
     * Szándékosan EGY EGYSZERŰ, generikus rendezés (nem üzleti számítás)
     * — ugyanaz az algoritmus, mint AnomalyTools::sortBySeverity(), csak
     * KÉT KÜLÖN forrás (sales+inventory anomáliák) EGYESÍTETT listáján.
     */
    private function prioritizeFindings(array $findings, int $maxFindings): array
    {
        // Duplikátum-szűrés — a kör 7. pontja ("duplicate suppression"):
        // ha (elméletileg) ugyanaz a (type, entity_type, entity_id)
        // hármas kétszer szerepelne (pl. mert egy jövőbeli AnomalyTools-
        // bővítés átfedő eszközöket adna vissza), csak az ELSŐ (a
        // hívási sorrendben korábbi) példány marad meg — SOSE a modell
        // dönt, melyik marad.
        $seen = [];
        $deduped = [];
        foreach ($findings as $finding) {
            $key = ($finding['type'] ?? '') . '|' . ($finding['entity_type'] ?? '') . '|' . ($finding['entity_id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $finding;
        }
        $findings = $deduped;

        $severityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($findings, static function (array $a, array $b) use ($severityRank) {
            $rankDiff = ($severityRank[$b['severity']] ?? 0) <=> ($severityRank[$a['severity']] ?? 0);
            if ($rankDiff !== 0) {
                return $rankDiff;
            }
            $magDiff = abs($b['change_percent'] ?? 0.0) <=> abs($a['change_percent'] ?? 0.0);
            if ($magDiff !== 0) {
                return $magDiff;
            }
            return ($a['entity_id'] ?? 0) <=> ($b['entity_id'] ?? 0);
        });
        return array_slice($findings, 0, $maxFindings);
    }

    private function buildUserMessage(array $context): string
    {
        $payload = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return "Készíts napi AI-összefoglalót erre a napra: {$context['report_date']}.\n\n"
            . "A következő, MÁR összegyűjtött és kiszámított, HITELES adat áll rendelkezésre (JSON):\n\n"
            . $payload;
    }
}
