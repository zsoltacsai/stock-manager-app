<?php

/**
 * 1.3.0 — központi, DB-független döntéstámogató szolgáltatás: a
 * biztonsági készlet / rendelési pont / javasolt mennyiség / árrés
 * KISZÁMÍTÁSÁÉRT felel, a Database.php-tól elválasztva (lásd a kör 10.
 * pontja: "ne legyenek szétszórva az endpointokban"). Ez az osztály SOSE
 * fut adatbázis-lekérdezést — mindig már lekérdezett, egyszerű
 * bemenetekkel (aktuális készlet, napi fogyás, küszöb, árak) dolgozik,
 * ezért közvetlenül, adatbázis nélkül unit-tesztelhető.
 *
 * MINDEN képlet EXPLICIT dokumentálva (lásd a kör 1. pontja: "ne legyen
 * fekete doboz") — nincs olyan szám, aminek a forrása/levezetése ne
 * lenne itt, egy helyen, leírva.
 *
 * Miért ezek a konkrét képletek (és miért nem a klasszikus "biztonsági
 * készlet = kereslet szórása × kiszolgálási szint × átfutási idő"
 * statisztikai formula)? A FountainTrade jelenlegi adatmodellje NEM
 * tárol sem beszállítónkénti szállítási időt (a `suppliers` táblának
 * nincs lead-time mezője), sem kereslet-szórást (csak a napi átlagot
 * számolja a meglévő getStockForecastBulk()) — a kör 1. pontja
 * kifejezetten tiltja, hogy ezekhez ÖNKÉNYESEN kitaláljunk adatot. Ehelyett
 * a MÁR MEGLÉVŐ, a boltvezető által ténylegesen beállított
 * `low_stock_threshold` (ill. a globális `low_stock_default_threshold`)
 * mezőt használjuk biztonsági készletnek — ez az EGYETLEN már létező,
 * boltvezető-szándékot kifejező "ez alá sose essen a készlet" adat a
 * rendszerben. A rendelési pont és a célkészlet-szint ebből és a MEGLÉVŐ
 * napi fogyás-előrejelzésből (getStockForecastBulk()) származtatott,
 * fix (dokumentált, könnyen módosítható) napszámú konstansokkal.
 */
final class PurchaseDecisionService
{
    /**
     * Hány nap alatt ér rá a boltvezető ténylegesen új beszerzést
     * indítani, miután a készlet a rendelési pont alá esett — mivel a
     * beszállítói átfutási időt a jelenlegi adatmodell nem tárolja, ez
     * egy konzervatív, DOKUMENTÁLT alapérték (nem számított, nem
     * mért érték). Ha a jövőben beszállítónkénti szállítási idő kerül
     * rögzítésre, ez a konstans lecserélhető azzal.
     */
    public const REVIEW_PERIOD_DAYS = 7;

    /**
     * Egy induló beszerzésnek hány NAPNYI készletet kell biztosítania a
     * biztonsági szint FÖLÖTT, hogy ne kelljen szinte azonnal újra
     * rendelni — szintén dokumentált, fix alapérték (2 hetes
     * utántöltési ciklus egy kisbolt számára ésszerű kiindulópont).
     */
    public const TARGET_COVERAGE_DAYS = 14;

    /** "Sürgős" minősítéshez: ennyi napon belüli (vagy már bekövetkezett) kifogyás számít sürgősnek. */
    public const URGENT_DAYS_THRESHOLD = 3;

    /** "Hamarosan elfogy" minősítéshez — ugyanaz a küszöb, mint a Dashboard "Figyelmet igényel" blokkjának forecast_low tétele (1.2.0), a konzisztencia miatt. */
    public const SOON_DAYS_THRESHOLD = 7;

    /**
     * Biztonsági készlet — lásd az osztály docblockját a döntés
     * indoklásáért. Egyszerű áttétel a MEGLÉVŐ küszöb-mezőre, hogy a
     * hívó kód (Database/endpoint) ne ismételje meg ezt a döntést.
     */
    public static function safetyStock(int $productThresholdOrDefault): int
    {
        return max(0, $productThresholdOrDefault);
    }

    /**
     * Rendelési pont (db) = biztonsági készlet + (napi fogyás × áttekintési
     * ciklus napjai). Azt a készletszintet jelzi, aminél a boltvezetőnek
     * MOST kellene rendelnie ahhoz, hogy mire ténylegesen sor kerül a
     * rendelésre (REVIEW_PERIOD_DAYS), a készlet még pont ne essen a
     * biztonsági szint alá.
     *
     * @param float|null $avgDailyConsumption null/nincs megbízható adat esetén a rendelési pont = biztonsági készlet (nincs mivel korrigálni).
     */
    public static function reorderPoint(int $safetyStock, ?float $avgDailyConsumption): int
    {
        if ($avgDailyConsumption === null || $avgDailyConsumption <= 0) {
            return $safetyStock;
        }
        return (int) round($safetyStock + $avgDailyConsumption * self::REVIEW_PERIOD_DAYS);
    }

    /**
     * Javasolt beszerzési mennyiség (db) = a célkészlet-szint (biztonsági
     * készlet + napi fogyás × TARGET_COVERAGE_DAYS) és a jelenlegi
     * készlet különbsége, legalább 0. Ha nincs megbízható napi fogyás-
     * adat, a régóta bevált, MEGLÉVŐ ökölszabályra esik vissza (lásd
     * Database::getLowStockReport() — "a küszöb duplájára tölt fel"),
     * hogy konzisztens maradjon azzal, amit a felhasználó a Beszerzési
     * javaslat oldalon (1.1.1 óta) már megszokott olyan termékeknél,
     * amikhez nincs elég eladási előzmény egy megbízható rátához.
     */
    public static function recommendedQuantity(int $safetyStock, ?float $avgDailyConsumption, int $currentStock): int
    {
        if ($avgDailyConsumption === null || $avgDailyConsumption <= 0) {
            return max(1, ($safetyStock * 2) - $currentStock);
        }
        $targetStockLevel = $safetyStock + $avgDailyConsumption * self::TARGET_COVERAGE_DAYS;
        return max(0, (int) round($targetStockLevel - $currentStock));
    }

    /**
     * Sürgősségi besorolás a Beszerzési javaslat listához (lásd a kör 2.
     * pontja). KIZÁRÓLAG a MEGLÉVŐ getStockForecastBulk() eredményét és a
     * jelenlegi készletet nézi — nincs saját, párhuzamos előrejelzés.
     *
     * @param array{status:string, estimated_days_remaining:?int}|null $forecast
     * @return string 'urgent'|'soon'|'low'
     */
    public static function classifyUrgency(?array $forecast, int $currentStock, int $threshold): string
    {
        if ($currentStock <= 0) {
            return 'urgent';
        }
        if ($forecast !== null && $forecast['status'] === 'ok') {
            $days = $forecast['estimated_days_remaining'];
            if ($days <= self::URGENT_DAYS_THRESHOLD) {
                return 'urgent';
            }
            if ($days <= self::SOON_DAYS_THRESHOLD) {
                return 'soon';
            }
        }
        // Nincs megbízható előrejelzés (insufficient_data/zero_consumption),
        // de a készlet a küszöb alatt van — objektíven alacsony, csak nem
        // tudjuk megmondani MIKOR fogy el ténylegesen.
        return 'low';
    }

    /**
     * Emberi olvasható indoklás a javaslathoz (lásd a kör 1. pontja
     * példája: "az aktuális fogyási ütem mellett várhatóan 2 napon belül
     * elfogy."). Determinisztikus szövegsablon, nincs benne szabad
     * szöveg-generálás.
     */
    public static function buildReason(?array $forecast, int $currentStock, int $threshold): string
    {
        if ($currentStock <= 0) {
            return 'A termék jelenleg nincs készleten.';
        }
        if ($forecast !== null && $forecast['status'] === 'ok') {
            $days = $forecast['estimated_days_remaining'];
            return "Az aktuális fogyási ütem mellett várhatóan $days napon belül elfogy.";
        }
        if ($forecast !== null && $forecast['status'] === 'zero_consumption') {
            return "A készlet ($currentStock db) a beállított küszöb ($threshold db) alatt van, de a termék jelenleg nem fogy — nincs sürgős kifogyási kockázat.";
        }
        return "A készlet ($currentStock db) a beállított küszöb ($threshold db) alatt van, de nincs elegendő eladási előzmény a kifogyás megbízható becsléséhez.";
    }

    /**
     * Árrés — lásd a kör 5. pontja. Ft-ban és %-ban, a MEGLÉVŐ
     * adatmodell nettó értékein (products.net_price / .purchase_price_net,
     * NINCS új párhuzamos ár-értelmezés bevezetve). NULL-t ad vissza (nem
     * hamis 0-t/100%-ot), ha az eladási ár <= 0, vagy a beszerzési ár
     * nem megbízható — a hívónak (Database) kell eldöntenie a
     * $hasReliableCost jelzőt (lásd Database::productsHavePurchaseHistory()),
     * mert csak ott ismert, hogy egy 0 purchase_price_net "sose lett még
     * beszerezve" (megbízhatatlan) vagy "ténylegesen 0-ért lett beszerezve"
     * (megbízható, ritka, de lehetséges eset).
     *
     * @return array{margin_ft: float, margin_pct: float}|null
     */
    public static function computeMargin(?float $netSellPrice, ?float $netCostPrice, bool $hasReliableCost): ?array
    {
        if ($netSellPrice === null || $netSellPrice <= 0) {
            return null;
        }
        if (!$hasReliableCost || $netCostPrice === null || $netCostPrice < 0) {
            return null;
        }
        $marginFt = round($netSellPrice - $netCostPrice, 2);
        $marginPct = round(($marginFt / $netSellPrice) * 100, 1);
        return ['margin_ft' => $marginFt, 'margin_pct' => $marginPct];
    }
}
