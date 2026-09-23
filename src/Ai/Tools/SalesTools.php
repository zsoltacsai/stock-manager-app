<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolDefinition.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../../ReportPeriod.php';

/**
 * A második, olvasás-kizárólagos FountainTrade AI-eszközkészlet (Fázis 4,
 * Sales Agent) — lásd InventoryTools docblokkja az azonos indoklásért:
 * MINDEN tényleges számítást (bruttó/nettó forgalom, visszáru, kosárérték,
 * százalékos változás, óránkénti bontás) a MEGLÉVŐ Database-rétegben,
 * determinisztikusan végzünk el — ez az osztály csak vékony,
 * bemenet-validáló csomagolás köréjük, SOSE dönt önállóan üzleti
 * definícióról (mi számít "nettó forgalomnak", hogyan kell a
 * kosárértéket számolni, stb.). A modell SOSE kap közvetlen
 * adatbázis-hozzáférést vagy PDO-t — lásd ToolRegistry docblokkja.
 *
 * ÚJRAFELHASZNÁLÁS, NEM ÚJRAIMPLEMENTÁCIÓ: minden eszköz a MEGLÉVŐ
 * Database::getSalesReportSummary()/getTopProductsReport()/
 * getSalesMarginSummary() metódusokra épül (ugyanazok, amiket a
 * webroot/*riport* oldalak is használnak) — csak 4 ÚJ, kis, célzott
 * Database-metódus került hozzáadásuk, ahol a meglévő réteg TÉNYLEGESEN
 * nem tudott válaszolni (kategória-összesítés, óránkénti bontás,
 * egyetlen termék tetszőleges dátumtartományra, visszáru-darabszám) —
 * lásd az egyes metódusok docblokkját a "Fázis 4" jelöléssel.
 *
 * DÁTUMTARTOMÁNY-KEZELÉS: minden eszköz a MEGLÉVŐ ReportPeriod::resolve()-ra
 * delegál (lásd resolveDateRange() lent) — SOSE saját maga értelmez
 * dátumot/időszakot. A modell vagy egy `period` kulcsszót ad meg (pl.
 * "last_30_days", "this_week" — ugyanaz a fehérlista, mint a riport-
 * oldalak "period" paramétere), vagy explicit `date_from`/`date_to`-t —
 * mindkét esetben a VÉGSŐ, ellenőrzött tartományt a ReportPeriod adja
 * vissza (formátum-, sorrend- és maximális tartomány-ellenőrzéssel
 * együtt), lásd a kör 5. pontja ("Do NOT scatter date calculations
 * across SalesTools").
 */
final class SalesTools
{
    private const MAX_TOP_LIMIT = 50;
    private const MAX_CATEGORY_LIMIT = 50;

    public function __construct(
        private readonly Database $db,
        private readonly array $appSettings,
    ) {
    }

    public static function registerAll(ToolRegistry $registry, Database $db, array $appSettings): void
    {
        $tools = new self($db, $appSettings);
        $periodEnum = ReportPeriod::PRESETS;
        $periodDescription = 'Előre definiált időszak (' . implode(', ', $periodEnum) . '). "custom" esetén (vagy ha nincs "period" megadva) a date_from/date_to mezők kötelezők.';

        $registry->register(new ToolDefinition(
            'get_sales_summary',
            'Forgalmi összesítő egy adott időszakra: bruttó forgalom, nettó forgalom, visszáru, tranzakciószám, átlagos kosárérték, fizetési mód szerinti bontás. A meglévő FountainTrade forgalmi riport (Database::getSalesReportSummary) pontos definícióit használja — a bruttó/nettó/visszáru értékeket SOSE számítsd újra saját magad.',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN formátumban (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN formátumban (period helyett/mellett).'],
                ],
            ],
            [$tools, 'getSalesSummary'],
        ));

        $registry->register(new ToolDefinition(
            'compare_sales_periods',
            'Két időszak forgalmának összehasonlítása (pl. ez a hónap vs. előző hónap) — a különbségeket és a százalékos változásokat a rendszer számítja ki, ne találj ki/becsülj saját magad semmilyen %-ot.',
            [
                'type' => 'object',
                'properties' => [
                    'current_period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'A jelenlegi (vizsgált) időszak — ' . $periodDescription],
                    'current_from' => ['type' => 'string', 'description' => 'A jelenlegi időszak kezdő dátuma ÉÉÉÉ-HH-NN (current_period helyett/mellett).'],
                    'current_to' => ['type' => 'string', 'description' => 'A jelenlegi időszak záró dátuma ÉÉÉÉ-HH-NN.'],
                    'previous_period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'Az összehasonlítási (korábbi) időszak.'],
                    'previous_from' => ['type' => 'string', 'description' => 'Az összehasonlítási időszak kezdő dátuma ÉÉÉÉ-HH-NN (previous_period helyett/mellett).'],
                    'previous_to' => ['type' => 'string', 'description' => 'Az összehasonlítási időszak záró dátuma ÉÉÉÉ-HH-NN.'],
                ],
            ],
            [$tools, 'compareSalesPeriods'],
        ));

        $registry->register(new ToolDefinition(
            'get_top_selling_products',
            'A legjobban fogyó termékek egy adott időszakban, darabszám szerint csökkenő sorrendben, visszáruval nettósítva.',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'limit' => ['type' => 'integer', 'description' => 'Legfeljebb ennyi terméket adjon vissza (alapértelmezett 10, max 50).'],
                ],
            ],
            [$tools, 'getTopSellingProducts'],
        ));

        $registry->register(new ToolDefinition(
            'get_top_categories',
            'A legjobban teljesítő termékkategóriák (a termékek group_name mezője szerint) forgalom szerint csökkenő sorrendben, egy adott időszakban.',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'limit' => ['type' => 'integer', 'description' => 'Legfeljebb ennyi kategóriát adjon vissza (alapértelmezett 10, max 50).'],
                ],
            ],
            [$tools, 'getTopCategories'],
        ));

        $registry->register(new ToolDefinition(
            'get_sales_by_hour',
            'A forgalom napszak (0-23 óra) szerinti eloszlása egy adott időszakban — melyik órában van a legnagyobb/legkisebb forgalom. A "legforgalmasabb óra" mezőt a rendszer számítja ki, ne találgass.',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                ],
            ],
            [$tools, 'getSalesByHour'],
        ));

        $registry->register(new ToolDefinition(
            'get_product_sales_trend',
            'Egy adott termék eladási trendje (mennyiség, forgalom) egy időszakban, opcionálisan egy másik időszakhoz hasonlítva (pl. visszaesés kimutatásához). A változásokat/%-okat a rendszer számítja.',
            [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'A termék belső azonosítója.'],
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'A vizsgált időszak — ' . $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'A vizsgált időszak kezdő dátuma ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'A vizsgált időszak záró dátuma ÉÉÉÉ-HH-NN.'],
                    'compare_period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => 'Opcionális összehasonlítási időszak.'],
                    'compare_date_from' => ['type' => 'string', 'description' => 'Az összehasonlítási időszak kezdő dátuma (opcionális).'],
                    'compare_date_to' => ['type' => 'string', 'description' => 'Az összehasonlítási időszak záró dátuma (opcionális).'],
                ],
                'required' => ['product_id'],
            ],
            [$tools, 'getProductSalesTrend'],
        ));

        $registry->register(new ToolDefinition(
            'get_returns_summary',
            'Visszáru-összesítő egy adott időszakra: visszáru összege, darabszáma, és a visszáru-arány (a visszáru előtti bruttó forgalomhoz viszonyítva). Az arányt a rendszer számítja.',
            [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => $periodEnum, 'description' => $periodDescription],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN (period helyett/mellett).'],
                ],
            ],
            [$tools, 'getReturnsSummary'],
        ));
    }

    // ------------------------------------------------------------------

    public function getSalesSummary(array $args): array
    {
        $range = $this->resolveDateRange($args);
        $report = $this->db->getSalesReportSummary($range['from'], $range['to']);

        return [
            'date_from'         => $range['from'],
            'date_to'           => $range['to'],
            'gross_sales'       => $report['total_gross'],
            'net_sales'         => $report['total_net'],
            'returns'           => $report['total_returns'],
            'transactions'      => $report['sales_count'],
            'avg_basket'        => $report['avg_sale_gross'],
            'by_payment_method' => $report['by_payment_method'],
        ];
    }

    public function compareSalesPeriods(array $args): array
    {
        $current = $this->resolveDateRange($args, 'current_from', 'current_to', 'current_period');
        $previous = $this->resolveDateRange($args, 'previous_from', 'previous_to', 'previous_period');

        $currentReport = $this->db->getSalesReportSummary($current['from'], $current['to']);
        $previousReport = $this->db->getSalesReportSummary($previous['from'], $previous['to']);

        $currentView = $this->projectSummary($current, $currentReport);
        $previousView = $this->projectSummary($previous, $previousReport);

        return [
            'current'  => $currentView,
            'previous' => $previousView,
            'diff'     => [
                'gross_sales_change'     => round($currentView['gross_sales'] - $previousView['gross_sales'], 2),
                'gross_sales_change_pct' => $this->percentChange($previousView['gross_sales'], $currentView['gross_sales']),
                'net_sales_change'       => round($currentView['net_sales'] - $previousView['net_sales'], 2),
                'net_sales_change_pct'   => $this->percentChange($previousView['net_sales'], $currentView['net_sales']),
                'transactions_change'    => $currentView['transactions'] - $previousView['transactions'],
                'transactions_change_pct'=> $this->percentChange((float) $previousView['transactions'], (float) $currentView['transactions']),
                'avg_basket_change'      => round($currentView['avg_basket'] - $previousView['avg_basket'], 2),
                'avg_basket_change_pct'  => $this->percentChange($previousView['avg_basket'], $currentView['avg_basket']),
            ],
        ];
    }

    public function getTopSellingProducts(array $args): array
    {
        $range = $this->resolveDateRange($args);
        $limit = isset($args['limit']) ? max(1, min(self::MAX_TOP_LIMIT, (int) $args['limit'])) : 10;

        $rows = $this->db->getTopProductsReport($range['from'], $range['to'], null, 0, $limit);
        $products = array_map(static fn ($p) => [
            'product_id'  => $p['product_id'],
            'name'        => $p['name'],
            'barcode'     => $p['barcode'],
            'group_name'  => $p['group_name'],
            'qty'         => $p['qty'],
            'revenue'     => $p['revenue'],
        ], $rows);

        return [
            'date_from' => $range['from'],
            'date_to'   => $range['to'],
            'count'     => count($products),
            'products'  => $products,
        ];
    }

    public function getTopCategories(array $args): array
    {
        $range = $this->resolveDateRange($args);
        $limit = isset($args['limit']) ? max(1, min(self::MAX_CATEGORY_LIMIT, (int) $args['limit'])) : 10;

        $categories = $this->db->getTopCategoriesReport($range['from'], $range['to'], $limit);

        return [
            'date_from'  => $range['from'],
            'date_to'    => $range['to'],
            'count'      => count($categories),
            'categories' => $categories,
        ];
    }

    public function getSalesByHour(array $args): array
    {
        $range = $this->resolveDateRange($args);
        $hours = $this->db->getSalesByHourReport($range['from'], $range['to']);

        $busiest = null;
        foreach ($hours as $h) {
            if ($busiest === null || $h['gross'] > $busiest['gross']) {
                $busiest = $h;
            }
        }

        return [
            'date_from'    => $range['from'],
            'date_to'      => $range['to'],
            'hours'        => $hours,
            'busiest_hour' => $busiest,
        ];
    }

    public function getProductSalesTrend(array $args): array
    {
        if (empty($args['product_id'])) {
            throw new InvalidArgumentException('Add meg a "product_id" mezőt.');
        }
        $productId = (int) $args['product_id'];
        $product = $this->db->findProductById($productId);
        if (!$product) {
            throw new InvalidArgumentException('Nincs ilyen azonosítójú termék: ' . $productId);
        }

        $range = $this->resolveDateRange($args);
        $current = $this->db->getProductSalesInRange($productId, $range['from'], $range['to']);

        $result = [
            'product_id' => $productId,
            'name'       => $product['name'],
            'current'    => $current,
        ];

        $hasComparison = !empty($args['compare_period']) || (!empty($args['compare_date_from']) && !empty($args['compare_date_to']));
        if ($hasComparison) {
            $compareRange = $this->resolveDateRange($args, 'compare_date_from', 'compare_date_to', 'compare_period');
            $previous = $this->db->getProductSalesInRange($productId, $compareRange['from'], $compareRange['to']);
            $result['previous'] = $previous;
            $result['qty_change'] = $current['qty'] - $previous['qty'];
            $result['qty_change_pct'] = $this->percentChange((float) $previous['qty'], (float) $current['qty']);
            $result['revenue_change'] = round($current['revenue'] - $previous['revenue'], 2);
            $result['revenue_change_pct'] = $this->percentChange($previous['revenue'], $current['revenue']);
        }

        return $result;
    }

    public function getReturnsSummary(array $args): array
    {
        $range = $this->resolveDateRange($args);
        $report = $this->db->getSalesReportSummary($range['from'], $range['to']);
        $returnsCount = $this->db->getReturnsCountInRange($range['from'], $range['to']);
        // getSalesReportSummary() 'total_gross'-a MÁR visszáruval csökkentett
        // (lásd Database::getSalesReportSummary() docblokkja/forráskódja) —
        // a visszáru ELŐTTI bruttó forgalmat itt, a hívó felé kell
        // visszaadni, hogy a "visszáru-arány" a helyes, teljes nevezőre
        // vonatkozzon.
        $preReturnGross = round($report['total_gross'] + $report['total_returns'], 2);

        return [
            'date_from'                  => $range['from'],
            'date_to'                    => $range['to'],
            'returns_amount'             => $report['total_returns'],
            'returns_count'              => $returnsCount,
            'gross_sales_before_returns' => $preReturnGross,
            'return_ratio_pct'           => $preReturnGross > 0 ? round($report['total_returns'] / $preReturnGross * 100, 1) : 0.0,
        ];
    }

    // ------------------------------------------------------------------

    private function projectSummary(array $range, array $report): array
    {
        return [
            'date_from'    => $range['from'],
            'date_to'      => $range['to'],
            'gross_sales'  => $report['total_gross'],
            'net_sales'    => $report['total_net'],
            'returns'      => $report['total_returns'],
            'transactions' => $report['sales_count'],
            'avg_basket'   => $report['avg_sale_gross'],
        ];
    }

    /**
     * Az EGYETLEN hely, ahol egy eszköz dátumtartományt értelmez — MINDEN
     * SalesTools metódus ezen keresztül kap `from`/`to` értéket, SOSE
     * közvetlenül a $args-ból. A tényleges értelmezést (formátum,
     * sorrend, maximális tartomány) teljes egészében a MEGLÉVŐ
     * ReportPeriod::resolve() végzi — lásd az osztály docblokkja.
     *
     * SZÁNDÉKOSAN public (Fázis 5, Anomaly Agent) — az `AnomalyTools` ezt
     * hívja meg közvetlenül, hogy NE kelljen egy második, majdnem
     * ugyanolyan dátumtartomány-értelmezőt írni (lásd a kör 12. pontja:
     * "Do not invent a second date system"). Tisztán funkcionális,
     * mellékhatás-mentes segédmetódus — a public láthatóság nem nyit meg
     * semmilyen új mellékhatást/állapotot.
     *
     * @return array{from:string,to:string}
     */
    public function resolveDateRange(array $args, string $fromKey = 'date_from', string $toKey = 'date_to', string $periodKey = 'period'): array
    {
        $period = isset($args[$periodKey]) ? trim((string) $args[$periodKey]) : '';
        if ($period !== '' && $period !== 'custom') {
            $resolved = ReportPeriod::resolve($period, $args[$fromKey] ?? null, $args[$toKey] ?? null);
        } else {
            $dateFrom = trim((string) ($args[$fromKey] ?? ''));
            $dateTo = trim((string) ($args[$toKey] ?? ''));
            if ($dateFrom === '' || $dateTo === '') {
                throw new InvalidArgumentException("Add meg a \"$periodKey\" kulcsszót (pl. \"last_30_days\") VAGY a \"$fromKey\"/\"$toKey\" dátumtartományt.");
            }
            $resolved = ReportPeriod::resolve('custom', $dateFrom, $dateTo);
        }

        // A jövőbe nyúló tartományt a rendszer nem "hibaként" utasítja el
        // (a SQL egyszerűen 0 sort ad vissza rá), DE ha a TELJES tartomány
        // a jövőben van, azt a modellnek egyértelműen jeleznünk kell —
        // különben egy hallgatólagosan üres összesítőt könnyen "nincs
        // forgalom"-ként magyarázna, ahelyett hogy azt mondaná: "ez az
        // időszak még nem telt el".
        if ($resolved['from'] > date('Y-m-d')) {
            throw new InvalidArgumentException('A kért időszak teljes egészében a jövőben van — erre az időszakra még nincs adat.');
        }

        return ['from' => $resolved['from'], 'to' => $resolved['to']];
    }

    /**
     * Egyetlen, közös százalékos-változás formula — lásd a kör 6. pontja:
     * "The percentage calculations must happen in backend PHP, never in
     * the LLM." `null`, ha az előző érték 0 (nincs értelmezhető
     * százalékos alap) — a hívó ilyenkor a nyers (abszolút) különbséget
     * adja tovább, a modellnek pedig ezt kell szövegesen kommunikálnia,
     * SOSE egy kitalált %-ot.
     */
    private function percentChange(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.0001) {
            return null;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
