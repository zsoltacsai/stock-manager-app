<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-04 / PERF-09 — a kassza és a Termékek oldal szerveroldali keresése,
 * szűrése, rendezése és lapozása. A régi, kliensoldali szabályokat
 * (app.js / beszerzes.js keresés, termekek.js renderTable()) itt PHP-ban
 * és — a magyar rendezéshez — a valódi JS-motorral (node, localeCompare('hu'))
 * reprodukáljuk, és az új szerveroldali eredményt azzal vetjük össze.
 */
final class ProductCatalogScalingTest extends TestCase
{
    private string $dir;
    private Database $db;

    private const WORDS = ['alma', 'Álom', 'ablak', 'áfonya', 'Csoki', 'cukor', 'cirok', 'dzsem', 'Dió', 'édes', 'Eper', 'gyömbér', 'gomba', 'Hagyma',
        'ígéret', 'kávé', 'kő', 'Köles', 'lekvár', 'lyuk', 'Málna', 'nyúl', 'narancs', 'ócska', 'olaj', 'Ökör', 'Őszibarack', 'öv', 'paprika',
        'szilva', 'sajt', 'Tej', 'tyúk', 'túró', 'Üveg', 'ürge', 'Ű-szűrő', 'zab', 'Zsemle', 'zeller', 'Prémium', 'Bio', 'Mini', '10 db', '-akció'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_catalog_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0775, true);
        $this->db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/db.sqlite']], dirname(__DIR__));
        mt_srand(11);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO products (name, sku, cikkszam, barcode, group_name, stock_qty, purchase_price_net, net_price, price, show_webshop, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 1500; $i++) {
            $w = self::WORDS;
            $name = $w[mt_rand(0, count($w) - 1)] . ' ' . $w[mt_rand(0, count($w) - 1)] . (mt_rand(0, 3) ? '' : ' ' . mt_rand(1, 20));
            $stmt->execute([
                $name,
                mt_rand(0, 2) ? 'SKU-' . mt_rand(1, 400) . (mt_rand(0, 5) ? '' : 'Ő') : null,
                mt_rand(0, 2) ? 'K' . mt_rand(1, 999) : null,
                mt_rand(0, 3) ? sprintf('599%010d', $i * 13) : null,
                mt_rand(0, 4) ? ['Italok', 'Édesség', 'Pékáru', 'Zöldség', 'Ökotermék'][mt_rand(0, 4)] : null,
                mt_rand(-3, 30),
                mt_rand(0, 5) ? mt_rand(100, 9000) / 10 : 0,
                mt_rand(100, 9000),
                mt_rand(100, 12000),
                mt_rand(0, 1),
                mt_rand(0, 9) === 0 ? 1 : 0,
            ]);
        }
        $pdo->commit();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private static function lower(?string $s): string
    {
        return mb_strtolower((string) $s, 'UTF-8');
    }

    public function testPosSearchMatchesThePreviousClientSideRule(): void
    {
        // Az előszűrő (ASCII-szakasz LIKE) határesetei: a K (U+212A) és az İ (U+0130)
        // nagybetű ASCII-ra kisbetűsödik; LIKE-helyettesítő és escape-karakterek.
        $insert = $this->db->pdo()->prepare('INSERT INTO products (name, sku, price, net_price, purchase_price_net) VALUES (?, ?, 100, 79, 0)');
        foreach ([["\u{212A}ELVIN mérleg", null], ["\u{130}stanbul tea", null], ['100% gyümölcslé', null], ['a_b termék', 'X_1'], ['c\\d termék', 'BS\\1'], ['Ők ÖRÖM', 'ŐSKU']] as [$n, $sku]) {
            $insert->execute([$n, $sku]);
        }
        $catalog = $this->db->listProducts(100000); // a korábbi /api/products.php lista
        foreach (['alma', 'ÁLOM', 'álom', 'ő', 'Ősz', 'sku-1', 'ő-', 'ű', ' tej ', '10', 'zs', 'nincs-ilyen', '%', '_',
                  'kelvin', 'k', 'ELV', "i\u{307}stan", 'stanbul', '100%', '0% gy', 'a_b', 'x_1', 'c\\d', '\\', 'ők ö', 'ősku'] as $query) {
            $q = self::lower(trim($query));
            $expected = array_slice(array_values(array_filter($catalog, static fn ($p) => str_contains(self::lower($p['name']), $q) || str_contains(self::lower($p['sku']), $q))), 0, 20);
            $actual = $this->db->searchProductsForPos($query, 20);
            $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), "Eltérő találatok: „{$query}”");
            if ($expected) {
                $this->assertSame($expected[0], $actual[0], 'A találat a teljes termékrekord.');
            }
        }
        $this->assertSame([], $this->db->searchProductsForPos('   '));
    }

    public function testActiveBarcodeLookupFollowsThePreviousPurchasePageRule(): void
    {
        $catalog = $this->db->listProducts(100000);
        $withBarcode = array_values(array_filter($catalog, static fn ($p) => $p['barcode'] !== null));
        foreach (array_slice($withBarcode, 0, 30) as $p) {
            $first = null;
            foreach ($catalog as $c) {
                if ($c['barcode'] === $p['barcode']) {
                    $first = $c;
                    break;
                }
            }
            $this->assertSame($first['id'], $this->db->findActiveProductByBarcode($p['barcode'])['id']);
        }
        $deleted = $this->db->pdo()->query("SELECT barcode FROM products WHERE is_deleted = 1 AND barcode IS NOT NULL AND barcode NOT IN (SELECT barcode FROM products WHERE is_deleted = 0 AND barcode IS NOT NULL) LIMIT 1")->fetchColumn();
        if ($deleted !== false) {
            $this->assertNull($this->db->findActiveProductByBarcode($deleted), 'Törölt termék vonalkódja nem ad találatot (mint a korábbi katalógusban).');
        }
    }

    /** A régi termekek.js renderTable() szűrése, PHP-ban (a rendezés nélkül). */
    private static function oldFilter(array $catalog, array $f): array
    {
        return array_values(array_filter($catalog, static function ($p) use ($f) {
            if (($f['name'] ?? '') !== '' && !str_contains(self::lower($p['name']), self::lower(trim($f['name'])))) return false;
            if (($f['cikkszam'] ?? '') !== '' && !str_contains(self::lower($p['cikkszam']), self::lower(trim($f['cikkszam'])))) return false;
            if (($f['barcode'] ?? '') !== '' && !str_contains(self::lower($p['barcode']), self::lower(trim($f['barcode'])))) return false;
            if (($f['group'] ?? '') !== '' && $p['group_name'] !== $f['group']) return false;
            if (!empty($f['zero_stock']) && (float) $p['stock_qty'] > 0) return false;
            if (!empty($f['webshop_only']) && !(float) $p['show_webshop']) return false;
            return true;
        }));
    }

    public function testProductsPageFiltersCountsGroupsAndPagingMatchThePreviousClientSideView(): void
    {
        $cases = [
            [],
            ['name' => 'ALMA'],
            ['name' => 'ő', 'group' => 'Ökotermék'],
            ['cikkszam' => 'k1', 'zero_stock' => true],
            ['barcode' => '59900000', 'webshop_only' => true],
            ['include_deleted' => true, 'name' => 'tej'],
            ['name' => 'nincs ilyen termék'],
        ];
        foreach ($cases as $filters) {
            $catalog = $this->db->listProducts(100000, !empty($filters['include_deleted']));
            $expected = self::oldFilter($catalog, $filters);
            usort($expected, static fn ($a, $b) => [(float) $a['stock_qty']] <=> [(float) $b['stock_qty']]); // stabil — alap: név szerinti lista
            $page = $this->db->listProductsPage($filters, 'stock_qty', 'asc', 0, 100);
            $label = json_encode($filters, JSON_UNESCAPED_UNICODE);
            $this->assertSame(array_column($expected, 'id'), $page['ids'], "Szűrés/rendezés eltér: $label");
            $this->assertSame(count($expected), $page['filtered_count']);
            $this->assertSame(count($catalog), $page['total_count'], "A „szűrt / összes” számláló összes része: $label");
            $this->assertSame(array_slice(array_column($expected, 'id'), 0, 100), array_column($page['products'], 'id'));
            $groups = array_values(array_unique(array_filter(array_column($catalog, 'group_name'))));
            sort($groups);
            $actualGroups = $page['groups'];
            sort($actualGroups);
            $this->assertSame($groups, $actualGroups);
        }

        $all = $this->db->listProductsPage([], 'name', 'asc', 0, 100);
        $second = $this->db->listProductsPage([], 'name', 'asc', 100, 100);
        $this->assertSame(array_slice($all['ids'], 100, 100), array_column($second['products'], 'id'), 'A második oldal a rendezett azonosítólista következő 100 eleme.');
        $this->assertCount(100, $all['products'], 'A DOM-ba csak egy oldalnyi sor kerül.');
        $this->assertSame($this->db->findProductById($all['ids'][0]), $all['products'][0], 'Az oldal sorai teljes termékrekordok (szerkesztéshez).');
    }

    public function testNumericSortsMatchThePreviousStableClientSortInBothDirections(): void
    {
        $catalog = $this->db->listProducts(100000);
        foreach (['stock_qty', 'purchase_price_net', 'net_price', 'price'] as $col) {
            foreach (['asc', 'desc'] as $dir) {
                $expected = $catalog;
                usort($expected, static function ($a, $b) use ($col, $dir) {
                    $cmp = (float) $a[$col] <=> (float) $b[$col]; // JS: Number(null) === 0
                    return $dir === 'asc' ? $cmp : -$cmp;
                });
                $this->assertSame(array_column($expected, 'id'), $this->db->listProductsPage([], $col, $dir, 0, 10)['ids'], "$col $dir");
            }
        }
    }

    public function testTextSortsMatchJavaScriptHungarianLocaleCompare(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('A node nem érhető el — a JS localeCompare(\'hu\') referencia nem futtatható.');
        }
        $node = strtok($node, "\r\n");
        $catalog = $this->db->listProducts(100000);
        $input = $this->dir . '/catalog.json';
        file_put_contents($input, json_encode(array_map(static fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'cikkszam' => $p['cikkszam'], 'group_name' => $p['group_name'], 'barcode' => $p['barcode']], $catalog), JSON_UNESCAPED_UNICODE));
        $script = $this->dir . '/sort.cjs';
        // A korábbi termekek.js sortValue() + filtered.sort() szó szerint.
        file_put_contents($script, <<<'JS'
const list = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));
const out = {};
for (const col of ['name', 'cikkszam', 'group_name', 'barcode']) {
    for (const dir of ['asc', 'desc']) {
        const sorted = list.slice().sort((a, b) => {
            const cmp = String((a[col] || '').toLowerCase()).localeCompare(String((b[col] || '').toLowerCase()), 'hu');
            return dir === 'asc' ? cmp : -cmp;
        });
        out[col + ' ' + dir] = sorted.map(p => p.id);
    }
}
process.stdout.write(JSON.stringify(out));
JS);
        $expected = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($input)), true);
        $this->assertIsArray($expected, 'A node-referencia nem futott le.');
        foreach ($expected as $key => $ids) {
            [$col, $dir] = explode(' ', $key);
            $this->assertSame($ids, $this->db->listProductsPage([], $col, $dir, 0, 10)['ids'], "Magyar rendezés eltér: $key");
        }
    }

    public function testFastHungarianSortKeyIsByteIdenticalToTheGeneralPath(): void
    {
        mt_srand(5);
        $atoms = ['a', 'á', 'c', 's', 'z', 'd', 'g', 'y', 'l', 'n', 't', 'o', 'ö', 'ő', 'u', 'ü', 'ű', 'é', 'í', 'ó', 'ú', 'ä', 'ç', 'ł',
            'cs', 'dzs', 'sz', 'zs', 'ccs', 'ssz', ' ', '-', '.', '0', '9', '/', "'", 'k', 'x', 'q', 'w', 'ß', 'ω', '€'];
        for ($n = 0; $n < 5000; $n++) {
            $s = '';
            for ($k = mt_rand(0, 12); $k > 0; $k--) {
                $s .= $atoms[mt_rand(0, count($atoms) - 1)];
            }
            $this->assertSame(Database::huSortKeySlow($s), Database::huSortKey($s), json_encode($s, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testStreamedCatalogIsByteIdenticalToThePreviousResponse(): void
    {
        $expected = json_encode(['products' => $this->db->listProducts(100000, true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $actual = '{"products":[';
        $first = true;
        $this->db->eachProduct(100000, true, static function (array $row) use (&$actual, &$first): void {
            $actual .= ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
        });
        $actual .= ']}';
        $this->assertSame($expected, $actual);
    }

    public function testCatalogOperationsUseMemoryIndependentOfCatalogSize(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 40000)
            INSERT INTO products (name, sku, stock_qty, price, net_price, long_description)
            SELECT 'Tömeg termék ' || x, 'T' || x, x % 7, 100, 79, printf('%.500c', 'x') FROM n");
        $pdo->commit();

        memory_reset_peak_usage();
        $base = memory_get_usage();
        $count = 0;
        $this->db->eachProduct(100000, false, static function () use (&$count): void {
            $count++;
        });
        $this->assertGreaterThan(40000, $count);
        $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $base, 'A katalógus streamelése nem tarthatja memóriában az összes sort.');

        memory_reset_peak_usage();
        $base = memory_get_usage();
        $this->assertCount(20, $this->db->searchProductsForPos('tömeg', 20));
        $page = $this->db->listProductsPage(['name' => 'tömeg'], 'name', 'asc', 20000, 100);
        $this->assertCount(100, $page['products']);
        $this->assertSame(40000, $page['filtered_count']);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $base, 'Keresés és lapozás 40 000 feletti katalógusnál is kis memóriával.');
    }
}
