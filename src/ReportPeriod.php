<?php

/**
 * 1.2.0 — a Dashboard/riportok időszakválasztójának EGYETLEN, backend-
 * authoritative forrása (lásd a kör 2. pontja: "A dátumszámítás legyen
 * backend-authoritative"). A kliens csak egy `period` kulcsszót (vagy egyedi
 * `date_from`/`date_to`-t) küld — a tényleges dátumhatárokat mindig itt,
 * szerver-oldalon számoljuk ki, a `date_default_timezone_set('Europe/Budapest')`
 * beállítás alapján (lásd webroot/api/_bootstrap.php), UGYANAZZAL az
 * időzóna-kezeléssel, mint amit a meglévő napi zárás (Database::getDailySummary)
 * és bevétel-trend (getDailyRevenueTrend) is használ — egyik riport se
 * csúszhat el a másikhoz képest egy nap-határon.
 */
final class ReportPeriod
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    /**
     * @return array{from: string, to: string, period: string}
     * @throws InvalidArgumentException érvénytelen `period` vagy hiányzó/
     *         hibás egyedi dátumtartomány esetén — a hívónak (API-végpont)
     *         ezt 400-as válaszra kell fordítania, sose csendben egy
     *         alapértelmezett tartományra esni.
     */
    public static function resolve(string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if (!in_array($period, self::PRESETS, true)) {
            throw new InvalidArgumentException('Érvénytelen időszak: ' . $period);
        }

        if ($period === 'custom') {
            if (!self::isValidDate($dateFrom) || !self::isValidDate($dateTo)) {
                throw new InvalidArgumentException('Egyedi időszakhoz érvényes date_from és date_to szükséges (ÉÉÉÉ-HH-NN).');
            }
            if ($dateFrom > $dateTo) {
                throw new InvalidArgumentException('A date_from nem lehet később, mint a date_to.');
            }
            // A jövőbe nyúló vagy túl széles (pl. véletlen 50 éves) tartomány
            // driláncolt lekérdezéseket okozhatna — lásd a kör 28. pontja
            // ("oversized requests, expensive report queries"). 5 év bőven
            // elég minden valós üzleti visszatekintéshez ehhez az app-mérethez.
            $span = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
            if ($span > 1827) {
                throw new InvalidArgumentException('Az egyedi időszak legfeljebb 5 év lehet.');
            }
            return ['from' => $dateFrom, 'to' => $dateTo, 'period' => 'custom'];
        }

        $today = new DateTimeImmutable('today');

        switch ($period) {
            case 'today':
                return ['from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'period' => $period];
            case 'yesterday':
                $y = $today->modify('-1 day')->format('Y-m-d');
                return ['from' => $y, 'to' => $y, 'period' => $period];
            case 'last_7_days':
                return ['from' => $today->modify('-6 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'period' => $period];
            case 'last_30_days':
                return ['from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'period' => $period];
            case 'this_week':
                // Fázis 4 (Sales Agent) — hétfőtől induló hét, a magyar
                // üzleti konvenciónak megfelelően (nem vasárnap-kezdetű).
                return ['from' => $today->modify('monday this week')->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'period' => $period];
            case 'last_week':
                $mondayThisWeek = $today->modify('monday this week');
                return ['from' => $mondayThisWeek->modify('-7 days')->format('Y-m-d'), 'to' => $mondayThisWeek->modify('-1 day')->format('Y-m-d'), 'period' => $period];
            case 'this_month':
                return ['from' => $today->modify('first day of this month')->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'period' => $period];
            case 'last_month':
                $firstOfLastMonth = $today->modify('first day of last month');
                return ['from' => $firstOfLastMonth->format('Y-m-d'), 'to' => $firstOfLastMonth->modify('last day of this month')->format('Y-m-d'), 'period' => $period];
        }

        // Elméletileg elérhetetlen — a fenti in_array() már kiszűrte az
        // ismeretlen periódusokat — de PHP-nak explicit visszatérés kell.
        throw new InvalidArgumentException('Érvénytelen időszak: ' . $period);
    }

    private static function isValidDate(?string $date): bool
    {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }
}
