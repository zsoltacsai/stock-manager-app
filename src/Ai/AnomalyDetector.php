<?php

declare(strict_types=1);

/**
 * Fázis 5 (Anomaly Agent) — az EGYETLEN hely, ahol eldől, hogy egy mutató
 * anomáliának számít-e. KIZÁRÓLAG PHP-aritmetika, SOSE LLM/AI — lásd a
 * kör "IMPORTANT PRINCIPLE" szakasza: "The backend determines whether
 * something is anomalous. The LLM explains the anomaly." Ez az osztály
 * NEM ér el adatbázist (nincs `Database`-függősége) — tisztán a MÁR
 * lekérdezett, determinisztikus bemeneti számokból dönt; az adat-
 * lekérdezést és a kandidátumok kiválasztását az `AnomalyTools` végzi
 * (lásd ott a docblokkot).
 *
 * MINDEN küszöb itt, EGY helyen van (lásd a kör 4. pontja: "Do NOT
 * scatter... across multiple files") — `tests/AnomalyDetectorTest.php`
 * teszteli mindegyiket, beleértve a pontos határértékeket is.
 *
 * HÁROM lehetséges kimenet minden detect*() hívásra (lásd a kör 6.
 * pontja — "insufficient_data" ELSŐOSZTÁLYÚ eredmény, SOSE csendben
 * "normal"-lá alakítva):
 *   - `status => 'anomaly'`   — a `record` kulcs tartalmazza a teljes,
 *     strukturált anomália-rekordot (a kör 3. pontjában megadott séma
 *     szerint).
 *   - `status => 'normal'`    — a mutató a küszöbön belül van, `record`
 *     null.
 *   - `status => 'insufficient_data'` — nem volt elég adat egy
 *     megbízható döntéshez (pl. túl kicsi az összehasonlítási alap) —
 *     `reason` kulcs adja meg, miért.
 *
 * Ez a MINTA már létezik a kódbázisban (lásd `Database::
 * getStockForecastBulk()` `status: 'insufficient_data'|'out_of_stock'|
 * 'zero_consumption'|'ok'` mezője) — ez az osztály ugyanezt az elvet
 * követi, nem talál ki új konvenciót.
 */
final class AnomalyDetector
{
    // ------------------------------------------------------------------
    // Küszöbök — KÖZPONTOSÍTVA, DOKUMENTÁLVA (lásd a kör 4. pontja).
    // ------------------------------------------------------------------

    /** Eladás-változás: <= ennyi % -> visszaesés. */
    public const SALES_DECLINE_THRESHOLD_PCT = -30.0;
    /** Eladás-változás: >= ennyi % -> megugrás. */
    public const SALES_SPIKE_THRESHOLD_PCT = 50.0;
    /**
     * Minimum darabszám az ÖSSZEHASONLÍTÁSI (korábbi) időszakban, mielőtt
     * egyáltalán %-os változást számolnánk — lásd a kör 5. pontja
     * ("1 sale → 2 sales... may not be meaningful"). Ez a szám alatt
     * `insufficient_data`, SOSE automatikus "normal".
     */
    public const MIN_BASELINE_QTY = 5;

    /**
     * Visszáru-arány: legalább ennyi SZÁZALÉKPONTTAL (nem relatív %-kal —
     * két már-eleve-százalék-érték közötti abszolút különbség a
     * megbízhatóbb, kevésbé félrevezethető mérték) magasabb a jelenlegi
     * arány az alapvonalhoz képest.
     */
    public const RETURN_RATIO_INCREASE_THRESHOLD_PP = 10.0;
    /** Minimum tranzakciószám MINDKÉT (jelenlegi és alap-) időszakban. */
    public const MIN_RETURN_BASELINE_TRANSACTIONS = 5;

    /** Lassan mozgó készlet: az ablak minimum hossza napokban. */
    public const SLOW_MOVING_MIN_WINDOW_DAYS = 14;
    /** Lassan mozgó készlet: LEGFELJEBB ennyi darab kelt el az ablakban. */
    public const SLOW_MOVING_MAX_QTY = 1;
    public const SLOW_MOVING_STOCK_QTY_MEDIUM = 10;
    public const SLOW_MOVING_STOCK_QTY_HIGH = 50;
    public const SLOW_MOVING_STOCK_QTY_CRITICAL = 100;

    /**
     * Készlet/eladás divergencia: az eladás-visszaesésen FELÜL (lásd
     * SALES_DECLINE_THRESHOLD_PCT) a becsült hátralévő napoknak (a
     * MEGLÉVŐ `Database::getStockForecastBulk()` "estimated_days_remaining"
     * mezője) legalább ennyinek kell lennie, hogy "túlméretezett
     * készletnek" számítson a (most már lassabb) fogyáshoz képest.
     */
    public const DIVERGENCE_MIN_DAYS_REMAINING = 60;
    public const DIVERGENCE_DAYS_HIGH = 120;
    public const DIVERGENCE_DAYS_CRITICAL = 180;

    /** Alacsony készlet + megugrott eladás: az uplift küszöbe %-ban. */
    public const LOW_STOCK_SALES_UPLIFT_THRESHOLD_PCT = 50.0;

    // Súlyossági sávok az eladás-változás %-hoz (a bázis-küszöböt már
    // átlépő rekordokra — "low" súlyosság ezért SOSE fordul elő ezekre a
    // típusokra, mert maga a bekerülési küszöb már a "medium" alsó határa).
    private const SALES_CHANGE_HIGH_FLOOR = 50.0;
    private const SALES_CHANGE_CRITICAL_FLOOR = 70.0;

    // Súlyossági sávok a visszáru-arány százalékpont-különbségére (a
    // 10pp bekerülési küszöb alatt eleve "normal", tehát ez is legalább
    // "medium"-mal indul).
    private const RETURN_RATE_HIGH_FLOOR = 20.0;
    private const RETURN_RATE_CRITICAL_FLOOR = 30.0;

    // Súlyossági sávok az alacsony-készlet+megugrás uplift %-ára.
    private const LOW_STOCK_UPLIFT_HIGH_FLOOR = 70.0;
    private const LOW_STOCK_UPLIFT_CRITICAL_FLOOR = 100.0;

    // ------------------------------------------------------------------
    // A. Eladás-visszaesés / B. Eladás-megugrás — UGYANAZ a metódus,
    // mert UGYANAZ a %-os-változás-számítás és a nulla-alap/kis-minta
    // kezelés vezérli mindkét irányt — nincs két, majdnem azonos
    // implementáció.
    // ------------------------------------------------------------------

    /**
     * @param float $currentQty A vizsgált (jelenlegi) időszak darabszáma.
     * @param float $previousQty Az összehasonlítási (korábbi/alap) időszak darabszáma.
     * @param array<string,mixed> $extraEvidence Kiegészítő, már ELLENŐRZÖTT/számított kontextus (pl. bevétel) — csak dokumentációs célra kerül az 'evidence'-be, SOSE befolyásolja a döntést.
     * @return array{status:string,record?:array,reason?:string}
     */
    public function detectSalesChange(
        float $currentQty,
        float $previousQty,
        string $entityType,
        int $entityId,
        string $entityName,
        array $extraEvidence = []
    ): array {
        if ($currentQty < 0 || $previousQty < 0) {
            throw new InvalidArgumentException('A mennyiségek nem lehetnek negatívak.');
        }
        if ($previousQty < self::MIN_BASELINE_QTY) {
            // Ide esik a "nulla-alap" eset is (previousQty === 0) — SOSE
            // "normal"-ként kezelve, lásd a kör 6/9. pontja.
            return ['status' => 'insufficient_data', 'reason' => 'baseline_too_small'];
        }

        $changePct = round((($currentQty - $previousQty) / $previousQty) * 100, 2);
        $evidence = ['current_qty' => $currentQty, 'previous_qty' => $previousQty] + $extraEvidence;

        if ($changePct <= self::SALES_DECLINE_THRESHOLD_PCT) {
            return ['status' => 'anomaly', 'record' => $this->buildRecord(
                'sales_decline',
                $this->severityForMagnitude($changePct, self::SALES_CHANGE_HIGH_FLOOR, self::SALES_CHANGE_CRITICAL_FLOOR),
                $entityType, $entityId, $entityName,
                'qty', $currentQty, $previousQty, $changePct,
                $evidence, 'sales_drop_above_threshold'
            )];
        }
        if ($changePct >= self::SALES_SPIKE_THRESHOLD_PCT) {
            return ['status' => 'anomaly', 'record' => $this->buildRecord(
                'sales_spike',
                $this->severityForMagnitude($changePct, self::SALES_CHANGE_HIGH_FLOOR, self::SALES_CHANGE_CRITICAL_FLOOR),
                $entityType, $entityId, $entityName,
                'qty', $currentQty, $previousQty, $changePct,
                $evidence, 'sales_spike_above_threshold'
            )];
        }

        return ['status' => 'normal', 'record' => null];
    }

    // ------------------------------------------------------------------
    // E. Visszáru-arány anomália — bolt/időszak szintű, nem termékenkénti.
    // ------------------------------------------------------------------

    public function detectReturnRateAnomaly(
        float $currentRatioPct,
        int $currentTransactions,
        float $baselineRatioPct,
        int $baselineTransactions,
        string $entityType,
        int $entityId,
        string $entityName
    ): array {
        if ($currentRatioPct < 0 || $baselineRatioPct < 0 || $currentTransactions < 0 || $baselineTransactions < 0) {
            throw new InvalidArgumentException('A visszáru-arány/tranzakciószám nem lehet negatív.');
        }
        if ($currentTransactions < self::MIN_RETURN_BASELINE_TRANSACTIONS || $baselineTransactions < self::MIN_RETURN_BASELINE_TRANSACTIONS) {
            return ['status' => 'insufficient_data', 'reason' => 'baseline_too_small'];
        }

        $diffPp = round($currentRatioPct - $baselineRatioPct, 2);
        if ($diffPp < self::RETURN_RATIO_INCREASE_THRESHOLD_PP) {
            return ['status' => 'normal', 'record' => null];
        }

        return ['status' => 'anomaly', 'record' => $this->buildRecord(
            'return_rate_anomaly',
            $this->severityForMagnitude($diffPp, self::RETURN_RATE_HIGH_FLOOR, self::RETURN_RATE_CRITICAL_FLOOR),
            $entityType, $entityId, $entityName,
            'return_ratio_pct', $currentRatioPct, $baselineRatioPct, $diffPp,
            ['current_transactions' => $currentTransactions, 'baseline_transactions' => $baselineTransactions],
            'return_ratio_increase_above_threshold'
        )];
    }

    // ------------------------------------------------------------------
    // F. Lassan mozgó készlet.
    // ------------------------------------------------------------------

    public function detectSlowMovingStock(
        int $stockQty,
        int $soldQtyInWindow,
        int $windowDays,
        string $entityType,
        int $entityId,
        string $entityName
    ): array {
        if ($stockQty < 0 || $soldQtyInWindow < 0 || $windowDays < 0) {
            throw new InvalidArgumentException('A készlet/darabszám/ablak-hossz nem lehet negatív.');
        }
        if ($windowDays < self::SLOW_MOVING_MIN_WINDOW_DAYS) {
            return ['status' => 'insufficient_data', 'reason' => 'window_too_short'];
        }
        if ($stockQty <= 0) {
            // Nincs készlet — ez nem "lassan mozgó", hanem egy MÁSIK
            // (készlethiány) kérdés, amit a MEGLÉVŐ InventoryTools már
            // lefed (get_low_stock_products, filter=out).
            return ['status' => 'normal', 'record' => null];
        }
        if ($soldQtyInWindow > self::SLOW_MOVING_MAX_QTY) {
            return ['status' => 'normal', 'record' => null];
        }

        $severity = match (true) {
            $stockQty >= self::SLOW_MOVING_STOCK_QTY_CRITICAL => 'critical',
            $stockQty >= self::SLOW_MOVING_STOCK_QTY_HIGH => 'high',
            $stockQty >= self::SLOW_MOVING_STOCK_QTY_MEDIUM => 'medium',
            default => 'low',
        };

        return ['status' => 'anomaly', 'record' => $this->buildRecord(
            'slow_moving_stock',
            $severity,
            $entityType, $entityId, $entityName,
            'qty_sold', (float) $soldQtyInWindow, (float) self::SLOW_MOVING_MAX_QTY, null,
            ['stock_qty' => $stockQty, 'window_days' => $windowDays],
            'stock_present_sales_near_zero'
        )];
    }

    // ------------------------------------------------------------------
    // C. Készlet/eladás divergencia — a hívó a MÁR kiszámított
    // eladás-visszaesést ÉS a MEGLÉVŐ Database::getStockForecastBulk()
    // eredményét adja át (nincs itt új készlet-előrejelzési logika).
    // ------------------------------------------------------------------

    public function detectStockSalesDivergence(
        float $currentQty,
        float $previousQty,
        string $forecastStatus,
        ?int $estimatedDaysRemaining,
        string $entityType,
        int $entityId,
        string $entityName
    ): array {
        if ($currentQty < 0 || $previousQty < 0) {
            throw new InvalidArgumentException('A mennyiségek nem lehetnek negatívak.');
        }
        if ($previousQty < self::MIN_BASELINE_QTY) {
            return ['status' => 'insufficient_data', 'reason' => 'baseline_too_small'];
        }
        $changePct = round((($currentQty - $previousQty) / $previousQty) * 100, 2);
        if ($changePct > self::SALES_DECLINE_THRESHOLD_PCT) {
            // Nincs is visszaesés — a divergencia előfeltétele nem áll fenn.
            return ['status' => 'normal', 'record' => null];
        }
        if ($forecastStatus !== 'ok' || $estimatedDaysRemaining === null) {
            // A MEGLÉVŐ getStockForecastBulk() sose adott megbízható
            // "hátralévő napok" becslést (pl. túl kevés megfigyelés,
            // vagy éppen elfogyott) — nem tudunk divergenciáról
            // megbízhatóan nyilatkozni.
            return ['status' => 'insufficient_data', 'reason' => 'forecast_unavailable'];
        }
        if ($estimatedDaysRemaining < self::DIVERGENCE_MIN_DAYS_REMAINING) {
            return ['status' => 'normal', 'record' => null];
        }

        $severity = match (true) {
            $estimatedDaysRemaining >= self::DIVERGENCE_DAYS_CRITICAL => 'critical',
            $estimatedDaysRemaining >= self::DIVERGENCE_DAYS_HIGH => 'high',
            default => 'medium',
        };

        return ['status' => 'anomaly', 'record' => $this->buildRecord(
            'stock_sales_divergence',
            $severity,
            $entityType, $entityId, $entityName,
            'estimated_days_remaining', (float) $estimatedDaysRemaining, (float) self::DIVERGENCE_MIN_DAYS_REMAINING, $changePct,
            ['current_qty' => $currentQty, 'previous_qty' => $previousQty, 'sales_change_percent' => $changePct],
            'excess_stock_relative_to_slowed_sales'
        )];
    }

    // ------------------------------------------------------------------
    // D. Alacsony készlet + megugrott eladás.
    // ------------------------------------------------------------------

    public function detectLowStockElevatedSales(
        float $currentQty,
        float $previousQty,
        bool $isLowStock,
        string $entityType,
        int $entityId,
        string $entityName
    ): array {
        if ($currentQty < 0 || $previousQty < 0) {
            throw new InvalidArgumentException('A mennyiségek nem lehetnek negatívak.');
        }
        if (!$isLowStock) {
            return ['status' => 'normal', 'record' => null];
        }
        if ($previousQty < self::MIN_BASELINE_QTY) {
            return ['status' => 'insufficient_data', 'reason' => 'baseline_too_small'];
        }
        $changePct = round((($currentQty - $previousQty) / $previousQty) * 100, 2);
        if ($changePct < self::LOW_STOCK_SALES_UPLIFT_THRESHOLD_PCT) {
            return ['status' => 'normal', 'record' => null];
        }

        return ['status' => 'anomaly', 'record' => $this->buildRecord(
            'low_stock_elevated_sales',
            $this->severityForMagnitude($changePct, self::LOW_STOCK_UPLIFT_HIGH_FLOOR, self::LOW_STOCK_UPLIFT_CRITICAL_FLOOR),
            $entityType, $entityId, $entityName,
            'qty', $currentQty, $previousQty, $changePct,
            ['current_qty' => $currentQty, 'previous_qty' => $previousQty, 'is_low_stock' => true],
            'low_stock_with_sales_uplift'
        )];
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function buildRecord(
        string $type,
        string $severity,
        string $entityType,
        int $entityId,
        string $entityName,
        string $metric,
        float $currentValue,
        float $baselineValue,
        ?float $changePercent,
        array $evidence,
        string $reasonCode
    ): array {
        return [
            'type' => $type,
            'severity' => $severity,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'metric' => $metric,
            'current_value' => $currentValue,
            'baseline_value' => $baselineValue,
            'change_percent' => $changePercent,
            'evidence' => $evidence,
            'reason_code' => $reasonCode,
        ];
    }

    /**
     * Súlyossági sáv — KIZÁRÓLAG a már bekerülési küszöböt átlépő
     * rekordokra hívva (lásd a hívó helyeket): a "low" súlyosság ezért
     * SOSE fordul elő ezekre a típusokra, a küszöb maga a "medium" alsó
     * határa. Lásd a kör 10. pontja — a súlyosságot a rendszer dönti el,
     * SOSE a modell.
     */
    private function severityForMagnitude(float $magnitude, float $highFloor, float $criticalFloor): string
    {
        $magnitude = abs($magnitude);
        if ($magnitude >= $criticalFloor) {
            return 'critical';
        }
        if ($magnitude >= $highFloor) {
            return 'high';
        }
        return 'medium';
    }
}
