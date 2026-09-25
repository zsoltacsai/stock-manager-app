<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-05 / B-06 / B-07 / B-08 — HTTP-szintű végpont-tesztek (a code-path
 * bizonyítékú hibákhoz a spec szerint end-to-end regresszió is kell).
 * Önálló, ideiglenes másolatban futó PHP beépített szerver (ugyanaz a
 * minta, mint CashSessionEndpointsHttpTest), plusz egy KÜLÖN loopback
 * Számlázz.hu-stub szerver (a beépített szerver egyszálú — a sale.php nem
 * hívhatná saját magát). A valódi Számlázz.hu-t / WooCommerce-t / NAV-ot
 * SOSE hívja: a WooCommerce-push workert a teszt folyamaton belül, egy
 * állapottartó hamis bolttal futtatja a szerver adatbázisán.
 */
final class InventorySyncEndToEndHttpTest extends TestCase
{
    private const WEBHOOK_SECRET = 'e2e-webhook-secret-b08';

    private static string $root;
    private static string $baseUrl;
    private static string $stubUrl;
    /** @var resource[] */
    private static array $servers = [];
    private static string $jar;
    private static Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_e2e_http_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        foreach (['config', 'data', 'invoices', 'stub/calls'] as $dir) {
            mkdir(self::$root . '/' . $dir, 0775, true);
        }

        file_put_contents(self::$root . '/stub/fake-szamlazz.php', <<<'PHP'
<?php
$xml = isset($_FILES['action-xmlagentxmlfile']) ? file_get_contents($_FILES['action-xmlagentxmlfile']['tmp_name']) : '';
file_put_contents(__DIR__ . '/calls/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.xml', $xml);
header('Content-Type: text/plain; charset=UTF-8');
echo 'xmlagentresponse=DONE;SZ-E2E-' . count(glob(__DIR__ . '/calls/*.xml'));
PHP);
        $stubPort = self::startServer(self::$root . '/stub', 'stub');
        self::$stubUrl = 'http://127.0.0.1:' . $stubPort . '/fake-szamlazz.php';

        $config = file_get_contents($projectRoot . '/config/config.php');
        $config = str_replace("'change-me-webhook-secret'", var_export(self::WEBHOOK_SECRET, true), $config);
        $config = str_replace("'https://www.szamlazz.hu/szamla/'", var_export(self::$stubUrl, true), $config);
        $config = str_replace("'download_pdf'     => true", "'download_pdf'     => false", $config);
        // Védőkorlát: ha a config szerkezete változna és a csere elmaradna,
        // a teszt NE hívhassa a valódi Számlázz.hu-t.
        if (!str_contains($config, self::$stubUrl) || str_contains($config, 'www.szamlazz.hu') || !str_contains($config, self::WEBHOOK_SECRET)) {
            self::fail('A teszt-config nem mutat a loopback stubra — leállítva.');
        }
        file_put_contents(self::$root . '/config/config.php', $config);

        $port = self::startServer(self::$root . '/webroot', 'app');
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        self::request('GET', '/api/auth-status.php');
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);

        $setupJar = self::$root . '/cookies-setup.txt';
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $setupJar)['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', ['app_password_enabled' => true, 'new_password' => 'e2e-jelszo-b05', 'new_password_confirm' => 'e2e-jelszo-b05'], ['X-CSRF-Token' => $csrf], $setupJar);
        self::$jar = self::$root . '/cookies-e2e.txt';
        self::request('POST', '/api/login.php', ['password' => 'e2e-jelszo-b05'], [], self::$jar);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    private static function startServer(string $docRoot, string $name): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $sockName = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($sockName, strrpos($sockName, ':') + 1);
        $log = self::$root . "/server-$name.log";
        self::$servers[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, self::$root);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return $port;
            }
            usleep(100_000);
        }
        self::fail("A(z) $name teszt-szerver nem indult el.");
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function request(string $method, string $path, $body = null, array $headers = [], ?string $jar = null, bool $rawBody = false): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody ? $body : json_encode($body));
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'body' => (string) $raw, 'json' => is_array($json) ? $json : null];
    }

    private function post(string $path, array $body): array
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$jar)['json']['csrf_token'];
        return self::request('POST', $path, $body, ['X-CSRF-Token' => $csrf], self::$jar);
    }

    private function webhook(array $order): array
    {
        $raw = json_encode($order);
        $signature = base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true));
        return self::request('POST', '/api/webhook.php', $raw, ['X-WC-Webhook-Signature' => $signature], null, true);
    }

    private function linkedProduct(string $barcode, int $wcId, int $stock): int
    {
        $p = self::$db->saveProduct(['name' => "E2E $barcode", 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => $barcode]);
        self::$db->incrementStock($p, $stock);
        self::$db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([$wcId, $p]);
        return $p;
    }

    private function stock(int $p): int
    {
        return (int) self::$db->findProductById($p)['stock_qty'];
    }

    private function stubCalls(): array
    {
        return glob(self::$root . '/stub/calls/*.xml') ?: [];
    }

    // ------------------------------------------------------------------

    public function testB05SaleEndpointStoresLocationAndReturnEndpointRestoresIt(): void
    {
        $p = self::$db->saveProduct(['name' => 'E2E telephelyes', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => '5990000077001']);
        $loc = self::$db->saveLocation(['name' => 'E2E bolt ' . bin2hex(random_bytes(2))]);
        self::$db->transferStock($p, null, $loc, 5, null);

        $sale = $this->post('/api/sale.php', ['items' => [['product_id' => $p, 'qty' => 2]], 'payment_method' => 'Készpénz', 'location_id' => $loc, 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $sale['status'], $sale['body']);
        $saleId = (int) $sale['json']['sale_id'];
        $this->assertSame($loc, (int) self::$db->getSaleWithItems($saleId)['location_id']);

        $items = self::$db->getSaleWithItems($saleId)['items'];
        $return = $this->post('/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => (int) $items[0]['id'], 'qty' => 2]], 'reason' => 'E2E']);
        $this->assertSame(200, $return['status'], $return['body']);

        $atLoc = (int) array_column(self::$db->getLocationStockForProduct($p), 'stock_qty', 'location_id')[$loc];
        $this->assertSame([5, 5], [$this->stock($p), $atLoc]);
    }

    public function testB08WebhookPosSaleRejectAndConfirmThroughEndpoints(): void
    {
        $p = $this->linkedProduct('5990000077002', 7702, 5);
        $store = new FakeWooStoreForHttpE2E([7702 => 5]);
        $worker = new WcPushQueueWorker(self::$db, $store);

        $order = fn (int $id, int $qty) => ['id' => $id, 'number' => (string) $id, 'status' => 'processing', 'billing' => ['first_name' => 'Web', 'last_name' => 'Vevő'],
            'line_items' => [['product_id' => 7702, 'quantity' => $qty, 'name' => 'E2E', 'total' => (string) (1000 * $qty), 'total_tax' => (string) (270 * $qty)]]];

        $first = $this->webhook($order(880001, 2));
        $this->assertSame(200, $first['status'], $first['body']);
        $draftId = (int) $first['json']['draft_id'];
        $duplicate = $this->webhook($order(880001, 2));
        $this->assertTrue($duplicate['json']['ignored'] ?? false, 'Duplikált webhook.');
        $store->stock[7702] = 3; // a WooCommerce a rendeléskor levont

        $sale = $this->post('/api/sale.php', ['items' => [['product_id' => $p, 'qty' => 1]], 'payment_method' => 'Készpénz', 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $sale['status'], $sale['body']);
        $worker->processDuePushes(50);
        $this->assertSame(2, $store->stock[7702], 'Kötelező eset HTTP-n át: nem 4.');

        $reject = $this->post('/api/webshop-order-reject.php', ['id' => $draftId]);
        $this->assertSame(200, $reject['status'], $reject['body']);
        $worker->processDuePushes(50);
        $this->assertSame(4, $store->stock[7702], 'Elutasítás: a foglalás felszabadul.');

        $second = $this->webhook($order(880002, 1));
        $store->stock[7702] = 3;
        $confirm = $this->post('/api/webshop-order-confirm.php', ['id' => (int) $second['json']['draft_id'], 'payment_method' => 'Utánvét']);
        $this->assertSame(200, $confirm['status'], $confirm['body']);
        $this->assertSame(3, $this->stock($p));
        $worker->processDuePushes(50);
        $this->assertSame(3, $store->stock[7702], 'Megerősítés: nincs dupla levonás.');
        $this->assertSame(0, (int) self::$db->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE status != 'done'")->fetchColumn());
    }

    public function testB06AndB07FullyGiftCardPaidInvoicedSaleThroughEndpoints(): void
    {
        $p = self::$db->saveProduct(['name' => 'E2E utalványos', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => '5990000077003']);
        self::$db->incrementStock($p, 5);
        $code = 'E2E' . strtoupper(bin2hex(random_bytes(3)));
        self::$db->issueGiftCard($code, 5000.0, null, null);
        $callsBefore = count($this->stubCalls());
        $key = bin2hex(random_bytes(8));
        $payload = ['items' => [['product_id' => $p, 'qty' => 1]], 'payment_method' => 'Készpénz', 'gift_card_code' => $code, 'idempotency_key' => $key,
            'buyer' => ['nev' => 'E2E Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.']];

        $sale = $this->post('/api/sale.php', $payload);
        $this->assertSame(200, $sale['status'], $sale['body']);
        $this->assertTrue($sale['json']['invoice']['success'] ?? false, $sale['body']);
        $saleId = (int) $sale['json']['sale_id'];
        $stored = self::$db->getSaleWithItems($saleId);
        $this->assertSame([0.0, 1270.0], [(float) $stored['total'], (float) $stored['gift_card_redeemed']]);

        // A számla már nem 0 Ft-os: a tétel az eladás értékén, fizetési mód az utalvány.
        $calls = $this->stubCalls();
        $this->assertCount($callsBefore + 1, $calls);
        $xml = simplexml_load_string(file_get_contents(end($calls)));
        $this->assertSame('1270', (string) $xml->tetelek->tetel->bruttoErtek);
        $this->assertSame('Ajándékutalvány', (string) $xml->fejlec->fizmod);

        // Az alkalmazás (Europe/Budapest) napja — a teszt-folyamat UTC-je éjfél körül más napot adna.
        $day = self::request('GET', '/api/daily-summary.php?date=' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->format('Y-m-d'), null, [], self::$jar);
        $this->assertSame(200, $day['status'], $day['body']);
        $this->assertGreaterThanOrEqual(1270.0, $day['json']['summary']['total_gross'] ?? $day['json']['total_gross']);

        // B-07: az idempotens visszajátszás nem indít második számlát.
        $replay = $this->post('/api/sale.php', $payload);
        $this->assertTrue($replay['json']['replayed'] ?? false, $replay['body']);
        $this->assertSame($sale['json']['invoice']['invoice_number'], $replay['json']['invoice']['invoice_number']);
        $this->assertCount($callsBefore + 1, $this->stubCalls());
    }
}

class FakeWooStoreForHttpE2E extends WooCommerceClient
{
    public function __construct(public array $stock)
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->stock[$wcProductId] = $qty;
    }

    public function pushProduct(int $wcProductId, array $fields): void
    {
    }
}
