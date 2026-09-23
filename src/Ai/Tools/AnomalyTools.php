<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolDefinition.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../AnomalyDetector.php';
require_once __DIR__ . '/SalesTools.php';
require_once __DIR__ . '/InventoryTools.php';

/**
 * A harmadik FountainTrade AI-eszközkészlet (Fázis 5, Anomaly Agent) —
 * ez az osztály a KANDIDÁTUM-KIVÁLASZTÁST és az ADATLEKÉRDEZÉST végzi,
 * a TÉNYLEGES "anomália-e ez?" döntést KIZÁRÓLAG a MEGLÉVŐ
 * `AnomalyDetector`-nek adja át (lásd ott a docblokkot — "The backend
 * determines whether something is anomalous"). Ez az osztály SOSE dönt
 * saját maga küszöbről/súlyosságról.
 *
 * ÚJRAFELHASZNÁLÁS, NEM ÚJRAIMPLEMENTÁCIÓ (lásd a kör 2. pontja: "Do not
 * duplicate them"): a tényleges forgalmi/készlet-számításokat KIZÁRÓLAG a
 * MEGLÉVŐ `SalesTools`/`InventoryTools`/`Database` metódusok végzik —
 * ez az osztály egy PÉLDÁNYT tart mindkét meglévő eszközkészlyből, és
 * azok PUBLIKUS metódusait hívja közvetlen PHP-hívásként (nem a
 * ToolRegistry-n keresztül — az kizárólag az LLM felé való kiajánláshoz
 * kell). Az egyetlen ÚJ Database-metódus, amit ehhez a fázishoz
 * hozzáadtunk: `getProductsWithStockAboveZero()` (a "lassan mozgó
 * készlet" kandidátum-listájához — lásd ott a docblokkot, miért nem
 * volt erre meglévő lekérdezés).
 *
 * KÉT eszköz van (lásd a kör 7. pontja: "Use the minimum tool set
 * needed" — SZÁNDÉKOSAN NINCS harmadik, "get_entity_anomaly_detail"
 * eszköz: minden anomália-rekord már eleve gazdag `evidence`-et
 * tartalmaz, és a modell a MEGLÉVŐ, keresztezett `get_product`/
 * `get_stock_status`/`get_product_sales_trend` eszközökkel tud
 * mélyebbre menni egy adott entity_id-ra — pontosan úgy, ahogy a kör 8.
 * pontjának második példája is javasolja):
 *   - `get_sales_anomalies` — eladás-visszaesés/megugrás (termékenként)
 *     + visszáru-arány anomália (bolt-szinten).
 *   - `get_inventory_anomalies` — készlet/eladás divergencia, alacsony
 *     készlet + megugrott eladás, lassan mozgó készlet.
 *
 * SZÁNDÉKOSAN NEM implementált kategóriák ebben a körben (lásd a kör 2.
 * pontja: "Do NOT implement all categories blindly... Prefer a smaller
 * number of high-quality anomaly types"): kategória-szintű (G) és
 * óránkénti (H) anomália — mindkettő megbízható megvalósításához új,
 * nem-triviális aggregációs logika kellene, amit ebben a fázisban nem
 * lehetett elég alaposan, a meglévő kódra támaszkodva levezetni; a
 * "stock growth without corresponding sales growth" (C) kategóriát a
 * `stock_sales_divergence` fedi le, a MEGLÉVŐ, már tesztelt
 * `Database::getStockForecastBulk()` "estimated_days_remaining"
 * proxy-jával (nincs új, közvetlen készlet-időbeli-mozgás-aggregáció).
 */
final class AnomalyTools
{
    /** Hány terméket vizsgálunk a jelenlegi/összehasonlítási top-lista alapján (bounded, lásd a kör 22. pontja). */
    private const CANDIDATE_SCAN_LIMIT = 500;
    /** Az alacsony-készlet kandidátum-lista mérete — UGYANAZ, mint InventoryTools::MAX_LOW_STOCK_LIMIT. */
    private const LOW_STOCK_CANDIDATE_LIMIT = 50;
    /** A lassan-mozgó-készlet vizsgálati ablaka — UGYANAZ, mint AnomalyDetector::SLOW_MOVING_MIN_WINDOW_DAYS-nél nagyobb, ésszerű alapértelmezés. */
    private const SLOW_MOVING_WINDOW_DAYS = 30;
    /** A divergencia-vizsgálathoz használt getStockForecastBulk() ablak — UGYANAZ, mint az InventoryTools alapértelmezése. */
    private const DIVERGENCE_FORECAST_WINDOW_DAYS = 30;

    private const DEFAULT_RESULT_LIMIT = 10;
    private const MAX_RESULT_LIMIT = 30;

    private readonly SalesTools $salesTools;
    private readonly InventoryTools $inventoryTools;
    private readonly AnomalyDetector $detector;

    public function __construct(
        private readonly Database $db,
        private readonly array $appSettings,
    ) {
        $this->salesTools = new SalesTools($db, $appSettings);
        $this->inventoryTools = new InventoryTools($db, $appSettings);
        $this->detector = new AnomalyDetector();
    }

    public static function registerAll(ToolRegistry $registry, Database $db, array $appSettings): void
    {
        $tools = new self($db, $appSettings);
        $periodEnum = ReportPeriod::PRESETS;
        $periodDescription = 'Előre definiált időszak (' . implode(', ', $periodEnum) . '). Ha nincs megadva "compare_period"/"compare_date_from"/"compare_date_to", az összehasonlítási időszak automatikusan a közvetlenül megelőző, AZONOS HOSSZÚSÁGÚ időszak.';

        $registry->register(new ToolDefinition(
            'get_sales_anomalies',
            'Determinisztikusan megállapított forgalmi anomáliák (jelentős eladás-visszaesés/megugrás termékenként, visszáru-arány anomália bolt-szinten) egy időszakra, egy összehasonlítási (alap-)időszakhoz képest. Az "anomália"-döntést, a súlyosságot és a %-os változásokat a rendszer számítja — ezeket SOSE számítsd újra vagy módosítsd. Ha a válasz "data_quality" mezője NEM üres, az azt jelenti, hogy egy vagy több ellenőrzéshez NEM volt elég adat — ezt EXPLICIT jelezd a felhasználónak, NE értelmezd "nincs anomáliaként".',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'A vizsgált időszak — ' . $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'A vizsgált időszak kezdő dátuma ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'A vizsgált időszak záró dátuma ÉÉÉÉ-HH-NN.'],
                    'compare_period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'Opcionális összehasonlítási időszak.'],
                    'compare_date_from' => ['type' => 'string', 'description' => 'Opcionális összehasonlítási kezdő dátum.'],
                    'compare_date_to' => ['type' => 'string', 'description' => 'Opcionális összehasonlítási záró dátum.'],
                    'limit' => ['type' => 'integer', 'description' => 'Legfeljebb ennyi anomáliát adjon vissza, súlyosság szerint csökkenő sorrendben (alapértelmezett 10, max 30).'],
                ],
            ],
            [$tools, 'getSalesAnomalies'],
        ));

        $registry->register(new ToolDefinition(
            'get_inventory_anomalies',
            'Determinisztikusan megállapított készlet-jellegű anomáliák: készlet/eladás divergencia (visszaeső eladás mellett túlméretezett készlet), alacsony készlet + megugrott eladás, lassan mozgó készlet. Az "anomália"-döntést és a súlyosságot a rendszer számítja. Ha a válasz "data_quality" mezője NEM üres, egy ellenőrzéshez NEM volt elég adat — ezt EXPLICIT jelezd, NE "nincs anomáliaként".',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'A vizsgált időszak — ' . $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'A vizsgált időszak kezdő dátuma ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'A vizsgált időszak záró dátuma ÉÉÉÉ-HH-NN.'],
                    'compare_period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'Opcionális összehasonlítási időszak.'],
                    'compare_date_from' => ['type' => 'string', 'description' => 'Opcionális összehasonlítási kezdő dátum.'],
                    'compare_date_to' => ['type' => 'string', 'description' => 'Opcionális összehasonlítási záró dátum.'],
                    'limit' => ['type' => 'integer', 'description' => 'Legfeljebb ennyi anomáliát adjon vissza (alapértelmezett 10, max 30).'],
                ],
            ],
            [$tools, 'getInventoryAnomalies'],
        ));
    }

    // ------------------------------------------------------------------

    public function getSalesAnomalies(array $args): array
    {
        [$current, $previous] = $this->resolveComparisonRanges($args);
        $limit = $this->resolveLimit($args);

        $merged = $this->mergeProductPeriods(
            $this->db->getTopProductsReport($current['from'], $current['to'], null, 0, self::CANDIDATE_SCAN_LIMIT),
            $this->db->getTopProductsReport($previous['from'], $previous['to'], null, 0, self::CANDIDATE_SCAN_LIMIT)
        );

        $findings = [];
        $dataQuality = [];

        if (empty($merged)) {
            // Lásd a kör 6. pontja: "Do not convert 'not enough data' into
            // 'no anomaly.'" — ha a vizsgált ÉS az összehasonlítási
            // időszakban EGYÜTTESEN sincs egyetlen eladott termék sem,
            // ez NEM "minden rendben", hanem elégtelen adat.
            $dataQuality[] = ['check' => 'sales_change', 'status' => 'insufficient_data', 'reason' => 'no_sales_data_in_either_period'];
        }
        foreach ($merged as $pid => $m) {
            $result = $this->detector->detectSalesChange(
                $m['current_qty'], $m['previous_qty'], 'product', $pid, $m['name'],
                ['current_revenue' => $m['current_revenue'], 'previous_revenue' => $m['previous_revenue']]
            );
            if ($result['status'] === 'anomaly') {
                $findings[] = $result['record'];
            }
        }

        // Visszáru-arány — bolt-szintű, a MEGLÉVŐ SalesTools-on keresztül
        // (nincs párhuzamos visszáru-számítás).
        $currentSummary = $this->salesTools->getSalesSummary(['date_from' => $current['from'], 'date_to' => $current['to']]);
        $previousSummary = $this->salesTools->getSalesSummary(['date_from' => $previous['from'], 'date_to' => $previous['to']]);
        $currentReturns = $this->salesTools->getReturnsSummary(['date_from' => $current['from'], 'date_to' => $current['to']]);
        $previousReturns = $this->salesTools->getReturnsSummary(['date_from' => $previous['from'], 'date_to' => $previous['to']]);
        $returnResult = $this->detector->detectReturnRateAnomaly(
            $currentReturns['return_ratio_pct'], $currentSummary['transactions'],
            $previousReturns['return_ratio_pct'], $previousSummary['transactions'],
            'shop', 0, 'FountainTrade'
        );
        if ($returnResult['status'] === 'anomaly') {
            $findings[] = $returnResult['record'];
        } elseif ($returnResult['status'] === 'insufficient_data') {
            $dataQuality[] = ['check' => 'return_rate_anomaly', 'status' => 'insufficient_data', 'reason' => $returnResult['reason']];
        }

        $findings = $this->sortBySeverity($findings);
        $truncated = count($findings) > $limit;
        $findings = array_slice($findings, 0, $limit);

        return [
            'date_from' => $current['from'],
            'date_to' => $current['to'],
            'compare_date_from' => $previous['from'],
            'compare_date_to' => $previous['to'],
            'count' => count($findings),
            'truncated' => $truncated,
            'anomalies' => $findings,
            // Lásd fent — SOSE hallgatólagosan "normal"-ba olvasztott
            // elégtelen-adat esetek, a modell ezt KÜLÖN kell kommunikálja.
            'data_quality' => $dataQuality,
        ];
    }

    public function getInventoryAnomalies(array $args): array
    {
        [$current, $previous] = $this->resolveComparisonRanges($args);
        $limit = $this->resolveLimit($args);

        $merged = $this->mergeProductPeriods(
            $this->db->getTopProductsReport($current['from'], $current['to'], null, 0, self::CANDIDATE_SCAN_LIMIT),
            $this->db->getTopProductsReport($previous['from'], $previous['to'], null, 0, self::CANDIDATE_SCAN_LIMIT)
        );

        $findings = [];
        $dataQuality = [];
        $divergingProductIds = [];

        if (empty($merged)) {
            $dataQuality[] = ['check' => 'stock_sales_divergence', 'status' => 'insufficient_data', 'reason' => 'no_sales_data_in_either_period'];
        }

        // 1) Készlet/eladás divergencia — csak a ténylegesen visszaeső
        // (vagy legalább nem növekvő) forgalmú termékekre, EGY bulk
        // getStockForecastBulk()-hívással (nincs N+1).
        $decliningCandidateIds = [];
        foreach ($merged as $pid => $m) {
            if ($m['previous_qty'] >= AnomalyDetector::MIN_BASELINE_QTY && $m['current_qty'] <= $m['previous_qty']) {
                $decliningCandidateIds[] = $pid;
            }
        }
        $forecast = $decliningCandidateIds ? $this->db->getStockForecastBulk($decliningCandidateIds, self::DIVERGENCE_FORECAST_WINDOW_DAYS) : [];
        foreach ($decliningCandidateIds as $pid) {
            $m = $merged[$pid];
            $f = $forecast[$pid] ?? null;
            $result = $this->detector->detectStockSalesDivergence(
                $m['current_qty'], $m['previous_qty'],
                $f['status'] ?? 'insufficient_data', $f['estimated_days_remaining'] ?? null,
                'product', $pid, $m['name']
            );
            if ($result['status'] === 'anomaly') {
                $findings[] = $result['record'];
                $divergingProductIds[$pid] = true;
            }
        }

        // 2) Alacsony készlet + megugrott eladás — a MEGLÉVŐ
        // InventoryTools::getLowStockProducts() bounded kandidátum-listáját
        // használja (nincs párhuzamos "mi számít alacsony készletnek"
        // logika).
        $lowStock = $this->inventoryTools->getLowStockProducts(['filter' => 'low', 'limit' => self::LOW_STOCK_CANDIDATE_LIMIT]);
        foreach ($lowStock['products'] as $p) {
            $pid = (int) $p['id'];
            $m = $merged[$pid] ?? null;
            if ($m === null) {
                continue; // nem volt elég eladási aktivitás egyáltalán a top-lista tartományában
            }
            $result = $this->detector->detectLowStockElevatedSales($m['current_qty'], $m['previous_qty'], true, 'product', $pid, $m['name']);
            if ($result['status'] === 'anomaly') {
                $findings[] = $result['record'];
            }
        }

        // 3) Lassan mozgó készlet — a Fázis 5-ben új
        // getProductsWithStockAboveZero()-val (bounded), a fenti "current"
        // top-lista helyett egy SAJÁT, hosszabb ablakra vetítve (lásd
        // SLOW_MOVING_WINDOW_DAYS), hogy egy rövid "period" (pl. "today")
        // mellett is értelmes maradjon. DUPLIKÁTUM-ELNYOMÁS: egy olyan
        // terméket, ami MÁR divergenciaként szerepel, itt NEM adjuk hozzá
        // még egyszer — a divergencia gazdagabb (ok-okozati) evidence-et
        // ad ugyanarra a "túl sok készlet, túl kevés eladás" jelenségre.
        $slowMovingTo = date('Y-m-d');
        $slowMovingFrom = date('Y-m-d', strtotime('-' . self::SLOW_MOVING_WINDOW_DAYS . ' days'));
        $soldInSlowMovingWindow = [];
        foreach ($this->db->getTopProductsReport($slowMovingFrom, $slowMovingTo, null, 0, self::CANDIDATE_SCAN_LIMIT) as $r) {
            $soldInSlowMovingWindow[(int) $r['product_id']] = (int) $r['qty'];
        }
        $stockCandidates = $this->db->getProductsWithStockAboveZero(1, self::CANDIDATE_SCAN_LIMIT);
        if (empty($stockCandidates)) {
            $dataQuality[] = ['check' => 'slow_moving_stock', 'status' => 'insufficient_data', 'reason' => 'no_products_with_stock'];
        }
        foreach ($stockCandidates as $p) {
            $pid = (int) $p['id'];
            if (isset($divergingProductIds[$pid])) {
                continue;
            }
            $soldQty = $soldInSlowMovingWindow[$pid] ?? 0;
            $result = $this->detector->detectSlowMovingStock($p['stock_qty'], $soldQty, self::SLOW_MOVING_WINDOW_DAYS, 'product', $pid, $p['name']);
            if ($result['status'] === 'anomaly') {
                $findings[] = $result['record'];
            }
        }

        $findings = $this->sortBySeverity($findings);
        $truncated = count($findings) > $limit;
        $findings = array_slice($findings, 0, $limit);

        return [
            'date_from' => $current['from'],
            'date_to' => $current['to'],
            'compare_date_from' => $previous['from'],
            'compare_date_to' => $previous['to'],
            'count' => count($findings),
            'truncated' => $truncated,
            'anomalies' => $findings,
            'data_quality' => $dataQuality,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * A vizsgált ("current") ÉS az összehasonlítási ("previous") időszak
     * feloldása — a "current"-et a MEGLÉVŐ SalesTools::resolveDateRange()
     * adja (lásd ott a docblokkja, miért lett public), a "previous"-t
     * VAGY szintén explicit adja meg a hívó (compare_period/compare_date_from/
     * compare_date_to), VAGY hiányukban EZ a metódus számítja ki
     * determinisztikusan: a "current"-tel AZONOS HOSSZÚSÁGÚ, közvetlenül
     * megelőző időszak. Ez az EGYETLEN hely, ahol ez az "alapértelmezett
     * összehasonlítási időszak" logika létezik.
     *
     * @return array{0:array{from:string,to:string},1:array{from:string,to:string}}
     */
    private function resolveComparisonRanges(array $args): array
    {
        $current = $this->salesTools->resolveDateRange($args);

        $hasExplicitCompare = !empty($args['compare_period'])
            || (!empty($args['compare_date_from']) && !empty($args['compare_date_to']));
        if ($hasExplicitCompare) {
            $previous = $this->salesTools->resolveDateRange($args, 'compare_date_from', 'compare_date_to', 'compare_period');
            return [$current, $previous];
        }

        $fromDate = new DateTimeImmutable($current['from']);
        $toDate = new DateTimeImmutable($current['to']);
        $spanDays = (int) $fromDate->diff($toDate)->days + 1;
        $previousTo = $fromDate->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($spanDays - 1) . ' days');

        return [$current, ['from' => $previousFrom->format('Y-m-d'), 'to' => $previousTo->format('Y-m-d')]];
    }

    private function resolveLimit(array $args): int
    {
        return isset($args['limit'])
            ? max(1, min(self::MAX_RESULT_LIMIT, (int) $args['limit']))
            : self::DEFAULT_RESULT_LIMIT;
    }

    /**
     * A jelenlegi/előző időszaki top-termék riportokat (Database::
     * getTopProductsReport() — MEGLÉVŐ, visszáru-nettósított) fésüli
     * össze termékenként — egyetlen PHP-hurok, nincs harmadik SQL.
     *
     * @return array<int,array{name:string,current_qty:float,current_revenue:float,previous_qty:float,previous_revenue:float}>
     */
    private function mergeProductPeriods(array $currentRows, array $previousRows): array
    {
        $merged = [];
        foreach ($currentRows as $r) {
            $pid = (int) $r['product_id'];
            $merged[$pid] = [
                'name' => $r['name'],
                'current_qty' => (float) $r['qty'],
                'current_revenue' => (float) $r['revenue'],
                'previous_qty' => 0.0,
                'previous_revenue' => 0.0,
            ];
        }
        foreach ($previousRows as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($merged[$pid])) {
                $merged[$pid] = ['name' => $r['name'], 'current_qty' => 0.0, 'current_revenue' => 0.0, 'previous_qty' => 0.0, 'previous_revenue' => 0.0];
            }
            $merged[$pid]['previous_qty'] = (float) $r['qty'];
            $merged[$pid]['previous_revenue'] = (float) $r['revenue'];
        }
        return $merged;
    }

    /**
     * Determinisztikus rendezés (lásd a kör 16. pontja, "deterministic
     * ordering"): súlyosság szerint csökkenő, majd a %-os változás
     * abszolútértéke szerint csökkenő, végül entity_id szerint növekvő —
     * ez utóbbi garantálja, hogy TELJES holtverseny esetén is mindig
     * ugyanaz a sorrend jöjjön ki (nincs PHP-sort-instabilitásból eredő
     * véletlenszerűség).
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private function sortBySeverity(array $findings): array
    {
        $severityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($findings, static function (array $a, array $b) use ($severityRank) {
            $rankDiff = ($severityRank[$b['severity']] ?? 0) <=> ($severityRank[$a['severity']] ?? 0);
            if ($rankDiff !== 0) {
                return $rankDiff;
            }
            $magA = abs($a['change_percent'] ?? 0.0);
            $magB = abs($b['change_percent'] ?? 0.0);
            $magDiff = $magB <=> $magA;
            if ($magDiff !== 0) {
                return $magDiff;
            }
            return $a['entity_id'] <=> $b['entity_id'];
        });
        return $findings;
    }
}
