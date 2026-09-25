<?php

/**
 * B-15 — a lapozott (page/page_size) lista-végpontok közös bemenet-
 * kezelése. Korábban `max(1, (int) $_GET['page'])` után az offset
 * (page − 1) × page_size PHP_INT_MAX közelében float-tá csordult túl, és a
 * `int $offset` paraméteren (strict_types) TypeError → HTTP 500 lett.
 *
 * A meglévő végpont-szerződés megmarad: hiányzó / nem szám / 0 / negatív
 * oldal → 1. oldal; a szám-előtag számít, mint az (int) átalakításnál
 * ("2.5" → 2, "007" → 7). ÚJ: az oldalszám felső korlátja MAX_PAGE — egy
 * ennél nagyobb (akár PHP_INT_MAX-nál hosszabb) szám determinisztikusan
 * MAX_PAGE lesz, az offset így mindig kicsi, túlcsordulás-mentes egész.
 * A válaszban a ténylegesen használt (korlátozott) oldalszám szerepel.
 */
final class Pagination
{
    public const MAX_PAGE = 100000;

    /**
     * @return array{page:int, page_size:int, offset:int}
     */
    public static function fromQuery(array $query, int $defaultPageSize = 20, int $maxPageSize = 100): array
    {
        $page = self::boundedPositiveInt($query['page'] ?? null, 1, self::MAX_PAGE);
        $pageSize = array_key_exists('page_size', $query)
            ? self::boundedPositiveInt($query['page_size'], 1, $maxPageSize)
            : $defaultPageSize;
        return ['page' => $page, 'page_size' => $pageSize, 'offset' => ($page - 1) * $pageSize];
    }

    /**
     * Egy lekérdezési paraméter pozitív egész értéke [$fallback, $max]
     * között: a vezető decimális számjegyek számítanak (előjel nélkül vagy
     * '+'-szal); minden más (üres, szöveg, negatív, tömb) a $fallback. A
     * számjegyek hosszából dönt, mielőtt egészre alakítana — így
     * PHP-verziótól/platformtól függő túlcsordulás nem lehetséges.
     */
    private static function boundedPositiveInt(mixed $raw, int $fallback, int $max): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return $fallback;
        }
        if (!preg_match('/^\s*\+?(\d+)/', (string) $raw, $m)) {
            return $fallback;
        }
        $digits = ltrim($m[1], '0');
        if ($digits === '') {
            return $fallback; // 0 (vagy csupa nulla)
        }
        if (strlen($digits) > strlen((string) $max)) {
            return $max;
        }
        return min((int) $digits, $max);
    }
}
