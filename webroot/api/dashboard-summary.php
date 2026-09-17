<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ReportPeriod.php';
require_once __DIR__ . '/../../src/HungarianNameDays.php';
require_once __DIR__ . '/../../src/HealthMonitor.php';

// 1.2.0 — Dashboard fő KPI-összesítő. Két réteg kombinálódik:
//  - "Mai" adatok MINDIG a naptári mai napra vonatkoznak (nem a period
//    paraméterre) — ezek a kasszás/üzletvezető számára a "mi történt ma"
//    gyors áttekintést adják, függetlenül attól, hogy éppen milyen
//    időszakot néz a bevétel-trendhez.
//  - "Időszak" adatok a kliens által választott period/date_from/date_to
//    alapján (lásd ReportPeriod::resolve() — backend-authoritative).
// Csak azok a KPI-k szerepelnek, amikhez ténylegesen van megbízható adat
// (lásd a kör 1. pontja) — nincs kitalált/becsült mező.
//
// A dashboard-újratervezés (kis UI/adat-kiegészítés, NEM önálló release —
// lásd a hívó kör explicit "ne módosíts verziószámot" utasítását) minden
// ÚJ mezője KIZÁRÓLAG már meglévő Database-metódusokból, egyszerű
// összeszámolással/küszöb-összehasonlítással épül fel — nincs új
// adatbázistábla, nincs új üzleti szabály.

$period = (string) ($_GET['period'] ?? 'today');
try {
    $resolved = ReportPeriod::resolve($period, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
} catch (InvalidArgumentException $e) {
    send_json(['error' => $e->getMessage()], 400);
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$todaySummary = $db->getSalesReportSummary($today, $today);
$yesterdaySummary = $db->getSalesReportSummary($yesterday, $yesterday);
$todayPurchase = $db->getPeriodPurchaseTotal($today, $today);

$isToday = $resolved['from'] === $today && $resolved['to'] === $today;
$periodSummary = $isToday ? $todaySummary : $db->getSalesReportSummary($resolved['from'], $resolved['to']);

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$inventory = $db->getInventoryOverview($lowStockThreshold, 5);
$wcQueue = $db->getWcQueueStatusSummary(5);
$navQueue = $db->getInvoiceQueueStatusSummary('nav');
$invoiceProvider = (string) ($appSettings['invoice_provider'] ?? 'szamlazz');
$draftWebshopOrders = $db->countDraftWebshopOrders();
$syncFailures24h = $db->countRecentSyncFailures(24);
$invoiceFailures7d = $db->countRecentInvoiceFailures(7);
$closingToday = $db->getClosing($today);
// 1.3.0 — a Dashboard "beszerzésre vár" jelzése a MEGLÉVŐ, központi
// PurchaseDecisionService-en alapuló getPurchaseRecommendations()-t
// használja (lásd a kör 9. pontja) — ez a WooCommerce-t/számlázást ÉRINTŐ
// jelzésekkel ellentétben egyetlen bulk lekérdezés, nem termékenkénti.
$purchaseRecommendations = $db->getPurchaseRecommendations($lowStockThreshold, 30);
$urgentPurchaseCount = count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'urgent'));
$soonPurchaseCount = count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'soon'));
$otherLowPurchaseCount = count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'low'));

// Csak akkor számol %-os változást, ha a tegnapi bázis ténylegesen
// rendelkezésre áll (nem 0) — 0-ból induló %-osítás hamis/értelmezhetetlen
// lenne (lásd a kör 2. pontja: "csak akkor jelenjen meg, ha az adat
// valóban rendelkezésre áll").
function dashboard_pct_change(float $today, float $yesterday): ?float
{
    if ($yesterday <= 0.0) {
        return null;
    }
    return round((($today - $yesterday) / $yesterday) * 100, 1);
}

// 1.4.0 — a "Figyelmet igényel" blokk ÉS a rendszerállapot-jelző mostantól
// a központi HealthMonitor-on keresztül épül fel (lásd a kör 11. pontja:
// "egy rendszer → egy igazságforrás") — ugyanazt a logikát hívja, mint a
// Dashboard kompakt "Rendszer állapota" widgetje és a Rendszerállapot
// oldal (system-health.php). Az itt átadott $healthSignals ugyanazokból,
// MÁR lekérdezett primitívekből épül, amiket ez a végpont eddig is
// begyűjtött — nincs extra lekérdezés emiatt (lásd a kör 16. pontja).
$healthSignals = [
    'settings' => $appSettings,
    'db_ok' => true, // ha idáig eljutottunk, a $db kapcsolat már bizonyítottan működik
    'woocommerce_configured' => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
    'wc_queue' => $wcQueue,
    'sync_failures_24h' => $syncFailures24h,
    'nav_configured' => $invoiceProvider === 'nav' && !empty($appSettings['nav_login']),
    'nav_queue' => $navQueue,
    'invoice_provider' => $invoiceProvider,
    'invoice_failures_7d' => $invoiceFailures7d,
    'draft_webshop_orders' => $draftWebshopOrders,
    'urgent_purchase_count' => $urgentPurchaseCount,
    'soon_purchase_count' => $soonPurchaseCount,
    'other_low_purchase_count' => $otherLowPurchaseCount,
    'update_state' => $db->getUpdateState(),
];
$attention = HealthMonitor::computeAttentionItems($healthSignals);
$systemStatus = HealthMonitor::computeOverallStatus(HealthMonitor::computeComponentStatuses($healthSignals));

$todayTopProducts = $db->getTopProductsReport($today, $today, null, 0, 5);
$nowTs = time();

send_json([
    'date' => [
        'iso'       => $today,
        'formatted' => HungarianNameDays::formatHungarianDate($nowTs),
        'name_day'  => HungarianNameDays::getNameDay((int) date('n', $nowTs), (int) date('j', $nowTs)),
    ],
    'system_status' => $systemStatus,
    'period' => $resolved,
    'today' => [
        'revenue_gross'   => $todaySummary['total_gross'],
        'sales_count'     => $todaySummary['sales_count'],
        'avg_sale_gross'  => $todaySummary['avg_sale_gross'],
        'purchase_gross'  => $todayPurchase['total_gross'],
        'purchase_count'  => $todayPurchase['count'],
    ],
    'today_vs_yesterday' => [
        'revenue_change_pct'    => dashboard_pct_change($todaySummary['total_gross'], $yesterdaySummary['total_gross']),
        'sales_count_change_pct' => dashboard_pct_change((float) $todaySummary['sales_count'], (float) $yesterdaySummary['sales_count']),
        'avg_sale_change_pct'   => dashboard_pct_change($todaySummary['avg_sale_gross'], $yesterdaySummary['avg_sale_gross']),
    ],
    'attention' => $attention,
    // 1.4.0 — a Dashboard kompakt "Rendszer állapota" widgetje ebből épül
    // fel, KÜLÖN system-health.php hívás nélkül (lásd a kör 16. pontja:
    // "ne legyen minden Dashboard-megnyitáskor 10-20 külön kérés") — a
    // teljes, admin-only technikai-részletes nézet a Rendszerállapot
    // oldalon (system-health.php) érhető el.
    'system_components' => HealthMonitor::computeComponentStatuses($healthSignals),
    'today_status' => [
        'closing_done'         => $closingToday !== null,
        'webshop_draft_count'  => $draftWebshopOrders,
        'invoice_failures_7d'  => $invoiceFailures7d,
    ],
    'today_top_products' => array_map(static fn ($p) => ['name' => $p['name'], 'qty' => $p['qty']], $todayTopProducts),
    'today_payment_methods' => $todaySummary['by_payment_method'],
    'period_summary' => [
        'revenue_gross'   => $periodSummary['total_gross'],
        'revenue_net'     => $periodSummary['total_net'],
        'sales_count'     => $periodSummary['sales_count'],
        'avg_sale_gross'  => $periodSummary['avg_sale_gross'],
    ],
    'inventory' => [
        'total_products'  => $inventory['total_products'],
        'zero_stock'      => $inventory['zero_stock'],
        'low_stock'       => $inventory['low_stock'],
        'negative_stock'  => $inventory['negative_stock'],
        'stock_value_net' => $inventory['stock_value_net'],
    ],
    'woocommerce' => [
        'queued'      => $wcQueue['counts']['queued'],
        'processing'  => $wcQueue['counts']['processing'],
        'failed'      => $wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'],
    ],
    'nav_invoices' => [
        'pending'  => $navQueue['buckets']['pending'],
        'failed'   => $navQueue['buckets']['failed'],
        'provider' => $invoiceProvider,
    ],
    'woocommerce_configured' => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
]);
