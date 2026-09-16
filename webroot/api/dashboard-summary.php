<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ReportPeriod.php';
require_once __DIR__ . '/../../src/HungarianNameDays.php';

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

// "Figyelmet igényel" — csak ténylegesen fennálló (>0) tételek, mindegyik
// egy már meglévő oldalra/riportra mutat. Sorrend: legsürgősebb elöl.
$attention = [];
if ($urgentPurchaseCount > 0) {
    $attention[] = [
        'type' => 'purchase_urgent', 'count' => $urgentPurchaseCount,
        'label' => $urgentPurchaseCount . ' sürgősen beszerzendő termék',
        'link' => 'beszerzesi-javaslat.php?urgency=urgent',
    ];
}
if ($soonPurchaseCount > 0) {
    $attention[] = [
        'type' => 'purchase_soon', 'count' => $soonPurchaseCount,
        'label' => $soonPurchaseCount . ' termék ' . PurchaseDecisionService::SOON_DAYS_THRESHOLD . ' napon belül várhatóan elfogy',
        'link' => 'beszerzesi-javaslat.php?urgency=soon',
    ];
}
if ($otherLowPurchaseCount > 0) {
    $attention[] = [
        'type' => 'purchase_low', 'count' => $otherLowPurchaseCount,
        'label' => $otherLowPurchaseCount . ' további termék alacsony készleten',
        'link' => 'beszerzesi-javaslat.php?urgency=low',
    ];
}
if ($draftWebshopOrders > 0) {
    $attention[] = [
        'type' => 'draft_webshop_orders', 'count' => $draftWebshopOrders,
        'label' => $draftWebshopOrders . ' webshop rendelés várakozik',
        'link' => 'beerkezo-eladasok.php',
    ];
}
if ($invoiceProvider === 'nav' && $navQueue['buckets']['failed'] > 0) {
    $attention[] = [
        'type' => 'invoice_failed', 'count' => $navQueue['buckets']['failed'],
        'label' => $navQueue['buckets']['failed'] . ' sikertelen NAV-számla',
        'link' => 'kimeno-szamlak.php',
    ];
} elseif ($invoiceProvider !== 'nav' && $invoiceFailures7d > 0) {
    $attention[] = [
        'type' => 'invoice_failed', 'count' => $invoiceFailures7d,
        'label' => $invoiceFailures7d . ' sikertelen számla (7 nap)',
        'link' => 'eladasok.php',
    ];
}
if ($wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'] > 0) {
    $wcFailedTotal = $wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'];
    $attention[] = [
        'type' => 'wc_failed', 'count' => $wcFailedTotal,
        'label' => $wcFailedTotal . ' sikertelen WooCommerce szinkron',
        'link' => 'woocommerce-sync.php',
    ];
}

// Rendszerállapot — KIZÁRÓLAG már meglévő, ténylegesen mért jelekből (lásd
// a kör 1. pontja: "ne legyen fiktív állapot"). Sync-hiba (valódi
// technikai hiba) piros; a többi (üzleti jellegű, önmagában nem a
// rendszer működését veszélyeztető) figyelmeztetés csak sárga.
if ($syncFailures24h > 0) {
    $systemStatus = ['level' => 'error', 'label' => 'Hiba'];
} elseif ($wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'] > 0
    || ($invoiceProvider === 'nav' ? $navQueue['buckets']['failed'] > 0 : $invoiceFailures7d > 0)
) {
    $systemStatus = ['level' => 'warning', 'label' => 'Figyelmet igényel'];
} else {
    $systemStatus = ['level' => 'ok', 'label' => 'Minden rendszer működik'];
}

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
