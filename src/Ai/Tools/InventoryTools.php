<?php

declare(strict_types=1);

require_once __DIR__ . '/../ToolDefinition.php';
require_once __DIR__ . '/../ToolRegistry.php';

/**
 * Az első, olvasás-kizárólagos FountainTrade AI-eszközkészlet — lásd a
 * kör 6. és 8. pontja: MINDEN tényleges számítást (készlet, sebesség,
 * előrejelzés) a meglévő Database-rétegben, determinisztikusan végzünk
 * el, ez az osztály csak vékony, bemenet-validáló csomagolás köréjük.
 * A modell SOSE kap közvetlen adatbázis-hozzáférést vagy PDO-t — lásd
 * ToolRegistry docblokkja.
 */
final class InventoryTools
{
    public function __construct(
        private readonly Database $db,
        private readonly array $appSettings,
    ) {
    }

    public static function registerAll(ToolRegistry $registry, Database $db, array $appSettings): void
    {
        $tools = new self($db, $appSettings);

        $registry->register(new ToolDefinition(
            'get_product',
            'Egy termék adatainak lekérése azonosító (id), vonalkód, vagy név-részlet alapján. Név esetén több találat is visszajöhet — ilyenkor kérdezz vissza, melyikre gondolt a felhasználó, ne találgass.',
            [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'A termék belső azonosítója, ha ismert.'],
                    'barcode' => ['type' => 'string', 'description' => 'A termék vonalkódja, ha ismert.'],
                    'name' => ['type' => 'string', 'description' => 'A termék nevének egy része (pl. "Coca Cola").'],
                ],
            ],
            [$tools, 'getProduct'],
        ));

        $registry->register(new ToolDefinition(
            'get_stock_status',
            'Egy adott termék jelenlegi készletállapota: mennyiség, riasztási küszöb, és hogy alacsony-e a készlet.',
            [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'A termék belső azonosítója.'],
                    'barcode' => ['type' => 'string', 'description' => 'A termék vonalkódja (product_id helyett is megadható).'],
                ],
            ],
            [$tools, 'getStockStatus'],
        ));

        $registry->register(new ToolDefinition(
            'get_low_stock_products',
            'Azon termékek listája, amik alacsony készletűek (a riasztási küszöbük alatt vagy azon vannak), vagy amik teljesen elfogytak.',
            [
                'type' => 'object',
                'properties' => [
                    'filter' => ['type' => 'string', 'enum' => ['low', 'out'], 'description' => '"low" = küszöb alatt (alapértelmezett), "out" = teljesen elfogyott.'],
                    'limit' => ['type' => 'integer', 'description' => 'Legfeljebb ennyi terméket adjon vissza (alapértelmezett 30, max 50).'],
                ],
            ],
            [$tools, 'getLowStockProducts'],
        ));

        $registry->register(new ToolDefinition(
            'get_product_sales_velocity',
            'Egy termék eladási sebessége (átlagos napi fogyás) egy adott időablakban, és ebből becsült hátralévő napok a készlet elfogyásáig. A számítást a rendszer végzi, ne találj ki saját számokat.',
            [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'A termék belső azonosítója.'],
                    'barcode' => ['type' => 'string', 'description' => 'A termék vonalkódja (product_id helyett is megadható).'],
                    'window_days' => ['type' => 'integer', 'description' => 'Az elemzési ablak napokban (alapértelmezett 30, 1-90 között).'],
                ],
                'required' => ['product_id'],
            ],
            [$tools, 'getProductSalesVelocity'],
        ));

        $registry->register(new ToolDefinition(
            'get_inventory_movements',
            'Egy termék (vagy az összes termék) készletmozgásai (eladás/beszerzés/visszáru/leltár) egy megadott dátumtartományban.',
            [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'Opcionális — ha nincs megadva, minden termék mozgása.'],
                    'date_from' => ['type' => 'string', 'description' => 'Kezdő dátum ÉÉÉÉ-HH-NN formátumban.'],
                    'date_to' => ['type' => 'string', 'description' => 'Záró dátum ÉÉÉÉ-HH-NN formátumban.'],
                    'type' => ['type' => 'string', 'enum' => ['sale', 'purchase', 'return', 'stock_take'], 'description' => 'Opcionális szűrés mozgás-típusra.'],
                ],
                'required' => ['date_from', 'date_to'],
            ],
            [$tools, 'getInventoryMovements'],
        ));
    }

    // ------------------------------------------------------------------

    private const MAX_LOW_STOCK_LIMIT = 50;
    private const MAX_MOVEMENTS_LIMIT = 100;
    private const MAX_NAME_SEARCH_RESULTS = 10;

    public function getProduct(array $args): array
    {
        if (!empty($args['id'])) {
            $product = $this->db->findProductById((int) $args['id']);
            if (!$product) {
                throw new InvalidArgumentException('Nincs ilyen azonosítójú termék: ' . (int) $args['id']);
            }
            return ['found' => true, 'match_type' => 'id', 'product' => $this->projectProduct($product)];
        }
        if (!empty($args['barcode'])) {
            $product = $this->db->findProductByBarcode((string) $args['barcode']);
            if (!$product) {
                throw new InvalidArgumentException('Nincs ilyen vonalkódú termék: ' . (string) $args['barcode']);
            }
            return ['found' => true, 'match_type' => 'barcode', 'product' => $this->projectProduct($product)];
        }
        if (!empty($args['name'])) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new InvalidArgumentException('A "name" mező nem lehet üres.');
            }
            $matches = $this->db->searchProductsByName($name, self::MAX_NAME_SEARCH_RESULTS);
            if (!$matches) {
                return ['found' => false, 'match_type' => 'name', 'candidates' => []];
            }
            return [
                'found' => true,
                'match_type' => 'name',
                'candidate_count' => count($matches),
                'candidates' => array_map(fn ($p) => $this->projectProduct($p), $matches),
            ];
        }
        throw new InvalidArgumentException('Add meg az "id", "barcode" vagy "name" mezők egyikét.');
    }

    public function getStockStatus(array $args): array
    {
        $product = $this->resolveProduct($args, 'product_id');
        $threshold = $product['low_stock_threshold'] !== null
            ? (int) $product['low_stock_threshold']
            : (int) ($this->appSettings['low_stock_default_threshold'] ?? 5);
        $stockQty = (int) $product['stock_qty'];

        return [
            'product_id' => (int) $product['id'],
            'name' => $product['name'],
            'barcode' => $product['barcode'],
            'stock_qty' => $stockQty,
            'unit' => $product['unit'],
            'low_stock_threshold' => $threshold,
            'is_low_stock' => $stockQty > 0 && $stockQty <= $threshold,
            'is_out_of_stock' => $stockQty <= 0,
        ];
    }

    public function getLowStockProducts(array $args): array
    {
        $filter = ($args['filter'] ?? 'low') === 'out' ? 'out' : 'low';
        $limit = isset($args['limit']) ? max(1, min(self::MAX_LOW_STOCK_LIMIT, (int) $args['limit'])) : 30;

        $threshold = (int) ($this->appSettings['low_stock_default_threshold'] ?? 5);
        $rows = $this->db->getLowStockReport($threshold, $filter);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'filter' => $filter,
            'count' => count($rows),
            'truncated' => $truncated,
            'products' => $rows,
        ];
    }

    public function getProductSalesVelocity(array $args): array
    {
        $product = $this->resolveProduct($args, 'product_id');
        $windowDays = isset($args['window_days']) ? max(1, min(90, (int) $args['window_days'])) : 30;

        $forecast = $this->db->getStockForecastBulk([(int) $product['id']], $windowDays);
        $entry = $forecast[(int) $product['id']] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException('A sebesség-számítás nem sikerült ehhez a termékhez.');
        }

        return [
            'product_id' => (int) $product['id'],
            'name' => $product['name'],
            'current_stock' => (int) $product['stock_qty'],
        ] + $entry;
    }

    public function getInventoryMovements(array $args): array
    {
        $dateFrom = (string) ($args['date_from'] ?? '');
        $dateTo = (string) ($args['date_to'] ?? '');
        if (!$this->isValidDate($dateFrom) || !$this->isValidDate($dateTo)) {
            throw new InvalidArgumentException('A "date_from" és "date_to" mezőket ÉÉÉÉ-HH-NN formátumban add meg.');
        }
        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('A "date_from" nem lehet később, mint a "date_to".');
        }

        $filters = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        if (!empty($args['product_id'])) {
            $filters['product_id'] = (int) $args['product_id'];
        }
        if (!empty($args['type'])) {
            $type = (string) $args['type'];
            if (!in_array($type, ['sale', 'purchase', 'return', 'stock_take'], true)) {
                throw new InvalidArgumentException('Érvénytelen mozgás-típus: ' . $type);
            }
            $filters['type'] = $type;
        }

        // Database::getStockMovements() {'movements','total','has_more'}
        // alakot ad vissza (lapozható riport-nézethez) — itt csak a
        // ténylegesen kért oldalt adjuk tovább a modellnek, a "total"/
        // "has_more" mezőkből számolva a "truncated" jelzőt.
        $report = $this->db->getStockMovements($filters, self::MAX_MOVEMENTS_LIMIT);
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'count' => count($report['movements']),
            'total' => $report['total'],
            'truncated' => (bool) $report['has_more'],
            'movements' => $report['movements'],
        ];
    }

    // ------------------------------------------------------------------

    private function resolveProduct(array $args, string $idKey): array
    {
        if (!empty($args[$idKey])) {
            $product = $this->db->findProductById((int) $args[$idKey]);
            if (!$product) {
                throw new InvalidArgumentException('Nincs ilyen azonosítójú termék: ' . (int) $args[$idKey]);
            }
            return $product;
        }
        if (!empty($args['barcode'])) {
            $product = $this->db->findProductByBarcode((string) $args['barcode']);
            if (!$product) {
                throw new InvalidArgumentException('Nincs ilyen vonalkódú termék: ' . (string) $args['barcode']);
            }
            return $product;
        }
        throw new InvalidArgumentException("Add meg a \"$idKey\" vagy a \"barcode\" mezők egyikét.");
    }

    /** Csak a hasznos, bounded mezőket adjuk vissza — nem a teljes nyers products sort (pl. belső WooCommerce mezők nélkül). */
    private function projectProduct(array $product): array
    {
        return [
            'id' => (int) $product['id'],
            'name' => $product['name'],
            'barcode' => $product['barcode'] ?? null,
            'sku' => $product['sku'] ?? null,
            'group_name' => $product['group_name'] ?? null,
            'unit' => $product['unit'] ?? null,
            'stock_qty' => (int) ($product['stock_qty'] ?? 0),
            'price' => isset($product['price']) ? (float) $product['price'] : null,
            'net_price' => isset($product['net_price']) ? (float) $product['net_price'] : null,
            'low_stock_threshold' => isset($product['low_stock_threshold']) && $product['low_stock_threshold'] !== null
                ? (int) $product['low_stock_threshold']
                : null,
        ];
    }

    private function isValidDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
