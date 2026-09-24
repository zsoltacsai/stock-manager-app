<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 5/6. pontja: rövid, magyar, EMBER-olvasható felirat
 * egy eszköz/agent NEVÉHEZ, a Copilot-UX progresszió-eseményeihez (pl.
 * "Készletadatok ellenőrzése..."). KIZÁRÓLAG megjelenítési célú
 * "fordítóréteg" — SOSE befolyásolja, MELYIK eszköz fut le (az a
 * MEGLÉVŐ ToolRegistry/AgentRunner logika, változatlanul). Egy
 * ismeretlen (jövőbeli) eszköznév BIZTONSÁGOS, generikus feliratot kap,
 * SOSE hibát/hiányzó szöveget.
 */
final class AiToolLabels
{
    /** @var array<string,string> */
    private const TOOL_LABELS = [
        'ask_inventory_agent' => 'Készletadatok elemzése…',
        'ask_sales_agent' => 'Forgalmi adatok elemzése…',
        'ask_anomaly_agent' => 'Anomáliák keresése…',
        'get_sales_anomalies' => 'Forgalmi anomáliák ellenőrzése…',
        'get_inventory_anomalies' => 'Készlet-anomáliák ellenőrzése…',
        'get_sales_summary' => 'Forgalmi összegzés lekérdezése…',
        'compare_sales_periods' => 'Időszakok összehasonlítása…',
        'get_top_selling_products' => 'Legkelendőbb termékek lekérdezése…',
        'get_top_categories' => 'Legkelendőbb kategóriák lekérdezése…',
        'get_sales_by_hour' => 'Óránkénti forgalom lekérdezése…',
        'get_product_sales_trend' => 'Termék eladási trendjének elemzése…',
        'get_returns_summary' => 'Visszáruk összegzése…',
        'get_product' => 'Termékadatok lekérdezése…',
        'get_stock_status' => 'Készletállapot ellenőrzése…',
        'get_low_stock_products' => 'Alacsony készletű termékek lekérdezése…',
        'get_product_sales_velocity' => 'Fogyási ütem elemzése…',
        'unknown_tool' => 'Ismeretlen eszköz — elutasítva',
        'get_inventory_movements' => 'Készletmozgások ellenőrzése…',
    ];

    /** @var array<string,string> */
    private const AGENT_LABELS = [
        'copilot' => 'Kérdés feldolgozása…',
        'inventory' => 'Készletadatok ellenőrzése…',
        'sales' => 'Forgalmi adatok elemzése…',
        'anomaly' => 'Anomáliák keresése…',
    ];

    public static function forTool(string $toolName): string
    {
        return self::TOOL_LABELS[$toolName] ?? "$toolName eszköz futtatása…";
    }

    public static function forAgent(string $agentName): string
    {
        return self::AGENT_LABELS[$agentName] ?? 'Feldolgozás…';
    }
}
