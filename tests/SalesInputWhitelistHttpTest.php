<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/NavInvoiceXmlBuilder.php';
require_once __DIR__ . '/../src/SzamlazzClient.php';

/**
 * Phase 5 remediáció — valódi HTTP-n (php -S):
 *   - DB-05: az ÁFA-kulcs (VatAllocation::SUPPORTED_RATES) és a fizetési mód
 *     (a Beállítások listája) szerveroldalon ellenőrzött minden értékesítési
 *     írási útvonalon — közvetlen HTTP-kéréssel sem kerülhet más érték a DB-be;
 *     a számla-építők (NAV, Számlázz.hu) sem fogadnak el mást;
 *   - DB-12: a webes rendelés értéke a WooCommerce sorösszege, nem a
 *     kerekített egységár × mennyiség.
 */
final class SalesInputWhitelistHttpTest extends TestCase
{
    private const WEBHOOK_SECRET = 'db05-webhook-secret';

    private static string $root;
    private static string $baseUrl;
    /** @var resource|null */
    private static $server;
    private static string $jar;
    private static Database $db;
    private static int $productId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_db05_http_' . bin2hex(random_bytes(6));
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        mkdir(self::$root . '/data', 0775, true);
        $config = str_replace("'change-me-webhook-secret'", var_export(self::WEBHOOK_SECRET, true), (string) file_get_contents($projectRoot . '/config/config.php'));
        file_put_contents(self::$root . '/config/config.php', $config);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$root . '/webroot'], [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']], $pipes, self::$root);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && !@fsockopen('127.0.0.1', $port, $e, $es, 0.2)) {
            usleep(100_000);
        }
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$jar = self::$root . '/cookies.txt';
        self::request('GET', '/api/auth-status.php');
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        self::$productId = self::$db->saveProduct(['name' => 'DB05 termék', 'barcode' => 'DB05-1', 'price' => 100, 'net_price' => 78.74, 'vat_rate' => '27']);
        self::$db->setStock(self::$productId, 1000);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        @mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function request(string $method, string $path, $body = null, array $headers = [], bool $raw = false): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $raw ? $body : json_encode($body));
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => self::$jar, CURLOPT_COOKIEFILE => self::$jar]);
        $out = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $out, true);
        return ['status' => $status, 'body' => (string) $out, 'json' => is_array($json) ? $json : null];
    }

    private function post(string $path, array $body): array
    {
        $csrf = self::request('GET', '/api/auth-status.php')['json']['csrf_token'];
        return self::request('POST', $path, $body, ['X-CSRF-Token' => $csrf]);
    }

    private function salesCount(): int
    {
        return (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    }

    private function manualSale(string $vat, $payment = 'Készpénz'): array
    {
        return $this->post('/api/sale.php', ['items' => [['manual' => true, 'name' => "kézi $vat", 'qty' => 1, 'unit_price' => 100, 'vat_rate' => $vat]], 'payment_method' => $payment]);
    }

    public function testSaleRejectsUnsupportedManualVatRatesAndAcceptsSupportedOnes(): void
    {
        $before = $this->salesCount();
        foreach (['-5', '13', '100', 'abc', '27 ', ''] as $bad) {
            $res = $this->manualSale($bad);
            $this->assertSame(400, $res['status'], "vat_rate='$bad' — " . $res['body']);
        }
        $this->assertSame($before, $this->salesCount(), 'egyetlen érvénytelen eladás sem került a DB-be');
        foreach (VatAllocation::SUPPORTED_RATES as $ok) {
            $this->assertSame(200, $this->manualSale($ok)['status'], "vat_rate='$ok'");
        }
        $this->assertSame($before + count(VatAllocation::SUPPORTED_RATES), $this->salesCount());
    }

    public function testSaleRejectsPaymentMethodsOutsideTheConfiguredList(): void
    {
        $before = $this->salesCount();
        foreach (['készpénz', 'Bitcoin', ['Készpénz'], ''] as $bad) {
            $res = $this->post('/api/sale.php', ['items' => [['product_id' => self::$productId, 'qty' => 1]], 'payment_method' => $bad]);
            $this->assertSame(400, $res['status'], 'payment_method=' . json_encode($bad));
        }
        $this->assertSame($before, $this->salesCount());
        $this->assertSame(200, $this->post('/api/sale.php', ['items' => [['product_id' => self::$productId, 'qty' => 1]], 'payment_method' => 'Bankkártya'])['status']);
    }

    public function testProductPurchaseAndSettingsWritePathsRejectUnsupportedVatRates(): void
    {
        $this->assertSame(400, $this->post('/api/product-save.php', ['name' => 'rossz áfa', 'gross_price' => 100, 'vat_rate' => '13'])['status']);
        $this->assertSame(200, $this->post('/api/product-save.php', ['name' => 'jó áfa', 'gross_price' => 100, 'vat_rate' => '5'])['status']);
        $this->assertSame(400, $this->post('/api/purchase-save.php', ['lines' => [['product_id' => self::$productId, 'qty' => 1, 'unit_cost_net' => 10, 'vat_rate' => '100']]])['status']);
        $this->assertSame(400, $this->post('/api/settings.php', ['szamlazz_default_vat' => '13'])['status']);
        $this->assertSame(200, $this->post('/api/settings.php', ['szamlazz_default_vat' => '18'])['status']);
    }

    public function testInvoiceModificationRejectsUnsupportedVatAndPaymentMethod(): void
    {
        $base = ['original_invoice_id' => 1, 'buyer' => ['nev' => 'X']];
        $this->assertSame(400, $this->post('/api/invoice-modify.php', $base + ['items' => [['name' => 'x', 'qty' => 1, 'unit_price_gross' => 10, 'vat_rate' => '13']]])['status']);
        $this->assertSame(400, $this->post('/api/invoice-modify.php', $base + ['items' => [['name' => 'x', 'qty' => 1, 'unit_price_gross' => 10, 'vat_rate' => '27']], 'payment_method' => 'Bitcoin'])['status']);
    }

    public function testInvoiceBuildersRejectUnsupportedVatRates(): void
    {
        $this->assertSame(['type' => 'percentage', 'rate' => 0.27], NavInvoiceXmlBuilder::vatCategory('27'));
        $this->assertSame('exemption', NavInvoiceXmlBuilder::vatCategory('AAM')['type']);
        foreach (['13', '100', '-5', 'abc'] as $bad) {
            try {
                NavInvoiceXmlBuilder::vatCategory($bad);
                $this->fail("NAV: '$bad' nem fogadható el");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('ÁFA-kód', $e->getMessage());
            }
        }
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/SzamlazzClient.php');
        $this->assertStringContainsString("VatAllocation::isSupportedRate((string) \$item['vat_rate'])", $source, 'a Számlázz.hu-tétel építése is ellenőriz');
    }

    private function webhookOrder(int $orderId, array $lineItems, string $paymentTitle): array
    {
        $order = ['id' => $orderId, 'number' => (string) $orderId, 'status' => 'processing', 'payment_method_title' => $paymentTitle,
            'billing' => ['first_name' => 'Web', 'last_name' => 'Vevő', 'postcode' => '1111', 'city' => 'Budapest', 'address_1' => 'Fő utca 1.'],
            'line_items' => $lineItems];
        $raw = json_encode($order);
        return self::request('POST', '/api/webhook.php', $raw, ['X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true))], true);
    }

    public function testWebshopOrderKeepsTheWooCommerceLineTotalAndOnlyOfferedPaymentMethods(): void
    {
        $this->assertSame(200, $this->post('/api/settings.php', ['szamlazz_default_vat' => '27'])['status'], 'a párosítatlan tételek alapértelmezett kulcsa (másik teszt átállíthatta)');
        $hook = $this->webhookOrder(random_int(1_000_000, 9_000_000), [
            ['product_id' => 0, 'quantity' => 3, 'name' => '10 Ft / 3', 'total' => '7.87', 'total_tax' => '2.13'],
            ['product_id' => 0, 'quantity' => 7, 'name' => '100 Ft / 7', 'total' => '78.74', 'total_tax' => '21.26'],
        ], 'Stripe kártya');
        $this->assertSame(200, $hook['status'], $hook['body']);
        $draftId = (int) $hook['json']['draft_id'];
        $this->assertSame(110.0, (float) self::$db->getWebshopOrder($draftId)['total'], 'DB-12: 10.00 + 100.00, nem 9.99 + 99.96');

        $this->assertSame(400, $this->post('/api/webshop-order-confirm.php', ['id' => $draftId, 'payment_method' => 'Bitcoin'])['status']);
        $confirm = $this->post('/api/webshop-order-confirm.php', ['id' => $draftId, 'payment_method' => 'Stripe kártya']);
        $this->assertSame(200, $confirm['status'], $confirm['body']);

        $sale = self::$db->getSaleWithItems((int) $confirm['json']['sale_id']);
        $this->assertSame(110.0, Database::saleGrossValue($sale));
        $totals = VatAllocation::totals(VatAllocation::invoiceItems(Database::saleGrossValue($sale), $sale['items']));
        $this->assertSame(['net' => 86.61, 'vat' => 23.39, 'gross' => 110.0], $totals, 'a számla fillérre a WooCommerce-sorok (7.87+78.74 / 2.13+21.26)');
    }
}
