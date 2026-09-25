<?php

declare(strict_types=1);

require_once __DIR__ . '/InvoiceConsistencyAssertions.php';

use PHPUnit\Framework\TestCase;

/**
 * N-5 (post-remediation audit) — END-TO-END: ugyanaz az eladás a valódi
 * sale.php / webshop-végponton át, a TÉNYLEGESEN elküldött Számlázz.hu XML-
 * ben, az `invoices` tükör-sorban, az eladás közös ÁFA-bontásában, a napi
 * zárásban és az értékesítési riportban (→ Dashboard, AI) — fillérre
 * ugyanazzal a nettó/ÁFA/bruttó értékkel.
 *
 * Önálló, ideiglenes repó-másolat, PHP beépített szerverrel; a Számlázz.hu
 * egy LOOPBACK STUB, ami minden kapott XML-t elment — a teszt ezeket parse-
 * olja. Élő Számlázz.hu sandbox nincs: az XML-helyesség helyi stubbal
 * validált, a szolgáltatói elfogadás élőben nincs igazolva.
 */
final class InvoiceSaleValueConsistencyHttpTest extends TestCase
{
    use InvoiceConsistencyAssertions;

    private const WEBHOOK_SECRET = 'n5-webhook-secret';
    private const BUYER = ['nev' => 'N5 Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'];

    private static string $root;
    private static string $baseUrl;
    private static string $stubUrl;
    /** @var resource[] */
    private static array $servers = [];
    private static string $jar;
    private static Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_n5_http_' . bin2hex(random_bytes(6));
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
$n = count(glob(__DIR__ . '/calls/*.xml')) + 1;
file_put_contents(__DIR__ . '/calls/' . sprintf('%06d', $n) . '.xml', $xml);
header('Content-Type: text/plain; charset=UTF-8');
echo 'xmlagentresponse=DONE;SZ-N5-' . $n;
PHP);
        $stubPort = self::startServer(self::$root . '/stub', 'stub');
        self::$stubUrl = 'http://127.0.0.1:' . $stubPort . '/fake-szamlazz.php';

        $config = file_get_contents($projectRoot . '/config/config.php');
        $config = str_replace("'change-me-webhook-secret'", var_export(self::WEBHOOK_SECRET, true), $config);
        $config = str_replace("'https://www.szamlazz.hu/szamla/'", var_export(self::$stubUrl, true), $config);
        $config = str_replace("'download_pdf'     => true", "'download_pdf'     => false", $config);
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
        self::request('POST', '/api/security-settings-save.php', ['app_password_enabled' => true, 'new_password' => 'n5-jelszo-teszt', 'new_password_confirm' => 'n5-jelszo-teszt'], ['X-CSRF-Token' => $csrf], $setupJar);
        self::$jar = self::$root . '/cookies-n5.txt';
        self::request('POST', '/api/login.php', ['password' => 'n5-jelszo-teszt'], [], self::$jar);
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
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
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

    private function lastStubXml(): string
    {
        $calls = $this->stubCalls();
        return file_get_contents(end($calls));
    }

    private function stubCalls(): array
    {
        $calls = glob(self::$root . '/stub/calls/*.xml') ?: [];
        sort($calls);
        return $calls;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    /** @return array{0: array, 1: array} [napi zárás, értékesítési riport] */
    private function reports(): array
    {
        return [self::$db->getDailySummary($this->today()), self::$db->getSalesReportSummary($this->today(), $this->today())];
    }

    private static function manual(string $name, float $price, int $qty, string $rate): array
    {
        return ['manual' => true, 'name' => $name, 'unit_price' => $price, 'qty' => $qty, 'vat_rate' => $rate];
    }

    /**
     * Egy számlázott eladás a valódi sale.php-n át; minden rétegen
     * összeveti: tárolt eladás közös bontása = elküldött XML = tükör-sor =
     * napi zárás különbsége = értékesítési riport különbsége.
     *
     * @return array{sale_id:int, lines:array, sum:array}
     */
    private function invoicedSale(array $payload, string $label): array
    {
        [$closeBefore, $reportBefore] = $this->reports();
        $callsBefore = count($this->stubCalls());

        $res = $this->post('/api/sale.php', $payload + ['payment_method' => 'Készpénz', 'buyer' => self::BUYER, 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $res['status'], "$label: " . $res['body']);
        $this->assertTrue($res['json']['invoice']['success'] ?? false, "$label: számla " . $res['body']);
        $saleId = (int) $res['json']['sale_id'];

        $calls = $this->stubCalls();
        $this->assertCount($callsBefore + 1, $calls, "$label: pontosan egy Számlázz.hu-hívás");
        $lines = self::szamlazzXmlLines(file_get_contents(end($calls)));
        $this->assertInvoiceLinesWellFormed($lines, $label);

        // 1) Eladás-szint: a tárolt eladás közös ÁFA-bontása.
        $sale = self::$db->getSaleWithItems($saleId);
        $breakdown = Database::vatBreakdown(Database::saleGrossValue($sale), $sale['items']);
        $this->assertInvoiceMatchesBreakdown($lines, $breakdown, "$label (eladás)");

        // 2) Riport-szint: napi zárás és értékesítési riport különbsége.
        [$closeAfter, $reportAfter] = $this->reports();
        $this->assertInvoiceMatchesReportDelta($lines, self::reportDelta($closeBefore, $closeAfter), "$label (napi zárás)");
        $reportDelta = self::reportDelta($reportBefore, $reportAfter);
        $sum = self::sumLines($lines);
        $this->assertSame([$sum['net'], $sum['vat'], $sum['gross']], [$reportDelta['net'], $reportDelta['vat'], $reportDelta['gross']], "$label (értékesítési riport)");

        // 3) Tükör-sor (invoices) és a válasz számlaszáma.
        $mirror = self::$db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertSame('done', $mirror['status']);
        $this->assertSame([$sum['net'], $sum['vat'], $sum['gross']], [self::cents($mirror['net_total']), self::cents($mirror['vat_total']), self::cents($mirror['gross_total'])], "$label (tükör-sor)");

        return ['sale_id' => $saleId, 'lines' => $lines, 'sum' => $sum];
    }

    private function coupon(string $type, float $value): string
    {
        $code = 'N5' . strtoupper(bin2hex(random_bytes(3)));
        self::$db->saveCoupon(['code' => $code, 'type' => $type, 'value' => $value, 'is_active' => true]);
        return $code;
    }

    private function customerWithPoints(int $points): int
    {
        $id = self::$db->saveCustomer(['name' => 'N5 Pontos ' . bin2hex(random_bytes(2))]);
        self::$db->pdo()->prepare('UPDATE customers SET loyalty_points = ? WHERE id = ?')->execute([$points, $id]);
        return $id;
    }

    private function giftCard(float $balance): string
    {
        $code = 'N5G' . strtoupper(bin2hex(random_bytes(3)));
        self::$db->issueGiftCard($code, $balance, null, null);
        return $code;
    }

    // ------------------------------------------------------------------
    // Kötelező N-5 reprodukció
    // ------------------------------------------------------------------

    public function testN5AuditReproductionSaleReportAndInvoiceAreAllTenForints(): void
    {
        $r = $this->invoicedSale(['items' => [self::manual('N5 termék', 10.0, 3, '27')], 'coupon_code' => $this->coupon('fixed', 20)], '3×10 / 20 kupon');

        $sale = self::$db->getSaleWithItems($r['sale_id']);
        $b = Database::vatBreakdown(Database::saleGrossValue($sale), $sale['items']);
        $this->assertSame([10.0, 7.87, 2.13], [$b['gross'], $b['net'], $b['vat']], 'Eladás: bruttó 10.00, nettó 7.87, ÁFA 2.13');
        $this->assertSame(['net' => 787, 'vat' => 213, 'gross' => 1000], ['net' => $r['sum']['net'], 'vat' => $r['sum']['vat'], 'gross' => $r['sum']['gross']], 'Számla-XML: bruttó 10.00, nettó 7.87, ÁFA 2.13 (korábban 9.99 / 7.86)');

        // A riport-oldal ugyanez — a napi zárás/riport különbsége a fenti helperben.
        $this->assertSame(['6.66', '3.34'], array_column(array_column($r['lines'], 'raw'), 'bruttoErtek'));

        // Az audit eredeti kosara: három külön 1 db-os 10 Ft-os sor, 20 Ft kupon.
        $three = $this->invoicedSale(['items' => [self::manual('N5 a', 10.0, 1, '27'), self::manual('N5 b', 10.0, 1, '27'), self::manual('N5 c', 10.0, 1, '27')], 'coupon_code' => $this->coupon('fixed', 20)], '3 sor × 10 / 20 kupon');
        $this->assertSame(['net' => 787, 'vat' => 213, 'gross' => 1000], ['net' => $three['sum']['net'], 'vat' => $three['sum']['vat'], 'gross' => $three['sum']['gross']]);
        $this->assertSame(['3.34', '3.33', '3.33'], array_column(array_column($three['lines'], 'raw'), 'bruttoErtek'), 'A maradék fillér determinisztikusan az első sorra kerül.');
    }

    // ------------------------------------------------------------------
    // Eset-mátrix a valódi végponton át
    // ------------------------------------------------------------------

    public function testSingleAndIdenticalAndDifferentlyPricedLines(): void
    {
        $this->invoicedSale(['items' => [self::manual('Egy sor', 1270.0, 1, '27')]], 'egy sor');
        $this->invoicedSale(['items' => [self::manual('Azonos', 10.0, 1, '27'), self::manual('Azonos', 10.0, 1, '27'), self::manual('Azonos', 10.0, 1, '27')]], 'több azonos sor');
        $this->invoicedSale(['items' => [self::manual('A', 199.0, 2, '27'), self::manual('B', 349.0, 1, '27'), self::manual('C', 487.0, 3, '27')], 'coupon_code' => $this->coupon('fixed', 101)], 'különböző árak + kupon');
    }

    public function testMixedVatRatesWithCoupon(): void
    {
        $r = $this->invoicedSale(['items' => [
            self::manual('27%', 999.0, 1, '27'), self::manual('18%', 555.0, 1, '18'), self::manual('5%', 444.0, 1, '5'),
            self::manual('0%', 333.0, 1, '0'), self::manual('AAM', 390.0, 1, 'AAM'), self::manual('TAM', 279.0, 1, 'TAM'),
        ], 'coupon_code' => $this->coupon('fixed', 500)], 'vegyes kulcs + kupon');
        $byRate = $r['sum']['by_rate'];
        $this->assertSame(6, count($byRate));
        foreach (['0', 'AAM', 'TAM'] as $exempt) {
            $this->assertSame(0, $byRate[$exempt]['vat']);
        }
        $this->assertSame(250000, $r['sum']['gross']);
    }

    public function testPercentCouponPointDiscountAndCouponPlusPoints(): void
    {
        $this->invoicedSale(['items' => [self::manual('Százalék', 333.0, 3, '27')], 'coupon_code' => $this->coupon('percent', 10)], 'százalékos kupon');
        $this->invoicedSale(['items' => [self::manual('Pont', 500.0, 2, '27')], 'customer_id' => $this->customerWithPoints(3), 'redeem_points' => 3], 'pontbeváltás');
        $this->invoicedSale(['items' => [self::manual('Kupon+pont', 500.0, 2, '27'), self::manual('Kupon+pont 18', 7.0, 3, '18')], 'coupon_code' => $this->coupon('fixed', 30), 'customer_id' => $this->customerWithPoints(7), 'redeem_points' => 7], 'kupon + pontbeváltás');
    }

    public function testGiftCardSalesKeepTheirFullValueOnTheInvoice(): void
    {
        // B-06: az utalvány fizetési eszköz — a számla és a riport az eladás teljes értékét mutatja.
        $partial = $this->invoicedSale(['items' => [self::manual('Utalvány részben', 1270.0, 1, '27')], 'gift_card_code' => $this->giftCard(1000.0)], 'utalvány részben');
        $this->assertSame(127000, $partial['sum']['gross']);
        $full = $this->invoicedSale(['items' => [self::manual('Utalvány teljesen', 635.0, 2, '27')], 'gift_card_code' => $this->giftCard(5000.0)], 'utalvány teljesen');
        $this->assertSame(127000, $full['sum']['gross']);
        $this->assertSame('Ajándékutalvány', (string) simplexml_load_string($this->lastStubXml())->fejlec->fizmod);
        $both = $this->invoicedSale(['items' => [self::manual('K+U', 10.0, 3, '27'), self::manual('K+U 5%', 99.0, 3, '5')], 'coupon_code' => $this->coupon('fixed', 20), 'gift_card_code' => $this->giftCard(100.0)], 'kupon + utalvány');
        $this->assertSame(30700, $both['sum']['gross'], '327 − 20 kupon = 307.00, ebből 100 utalvány');
    }

    public function testSmallAndRoundingHeavyValues(): void
    {
        $this->invoicedSale(['items' => [self::manual('1 Ft', 1.0, 1, '27'), self::manual('2 Ft', 2.0, 1, '27'), self::manual('3 Ft', 3.0, 1, '27')]], '1/2/3 Ft');
        $this->invoicedSale(['items' => [self::manual('1 Ft × 7', 1.0, 7, '27')], 'coupon_code' => $this->coupon('fixed', 2)], '1 Ft × 7, 2 Ft kupon');
        $this->invoicedSale(['items' => [self::manual('2 Ft × 3', 2.0, 3, '18')]], '2 Ft × 3, 18%');
        $this->invoicedSale(['items' => [self::manual('3 Ft × 7', 3.0, 7, '5')], 'coupon_code' => $this->coupon('fixed', 1)], '3 Ft × 7, 5%, 1 Ft kupon');
        $this->invoicedSale(['items' => [self::manual('99.99', 99.99, 7, '27'), self::manual('0.99', 0.99, 13, '18')], 'coupon_code' => $this->coupon('percent', 33)], 'kerekítés-nehéz');
    }

    public function testWebshopOrderInvoiceFollowsTheSameAllocation(): void
    {
        // Webes rendelés: 3 db, sorösszeg 10 Ft → egységár 3.33, eladás 9.99.
        $orderId = random_int(1_000_000, 9_000_000);
        $order = ['id' => $orderId, 'number' => (string) $orderId, 'status' => 'processing',
            'billing' => ['first_name' => 'Web', 'last_name' => 'Vevő', 'postcode' => '1111', 'city' => 'Budapest', 'address_1' => 'Fő utca 1.'],
            'line_items' => [['product_id' => 0, 'quantity' => 3, 'name' => 'N5 webes', 'total' => '7.87', 'total_tax' => '2.13']]];
        $raw = json_encode($order);
        $hook = self::request('POST', '/api/webhook.php', $raw, ['X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true))], null, true);
        $this->assertSame(200, $hook['status'], $hook['body']);

        [$closeBefore] = $this->reports();
        $callsBefore = count($this->stubCalls());
        $confirm = $this->post('/api/webshop-order-confirm.php', ['id' => (int) $hook['json']['draft_id'], 'payment_method' => 'Utánvét', 'issue_invoice' => true]);
        $this->assertSame(200, $confirm['status'], $confirm['body']);
        $this->assertTrue($confirm['json']['invoice']['success'] ?? false, $confirm['body']);
        $this->assertCount($callsBefore + 1, $this->stubCalls());

        $lines = self::szamlazzXmlLines($this->lastStubXml());
        $this->assertInvoiceLinesWellFormed($lines, 'webes rendelés');
        $sale = self::$db->getSaleWithItems((int) $confirm['json']['sale_id']);
        $this->assertInvoiceMatchesBreakdown($lines, Database::vatBreakdown(Database::saleGrossValue($sale), $sale['items']), 'webes rendelés');
        [$closeAfter] = $this->reports();
        $this->assertInvoiceMatchesReportDelta($lines, self::reportDelta($closeBefore, $closeAfter), 'webes rendelés (napi zárás)');
        $this->assertSame(['net' => 787, 'vat' => 212, 'gross' => 999], array_intersect_key(self::sumLines($lines), ['net' => 1, 'vat' => 1, 'gross' => 1]), 'sor-szintű nettó: 9.99/1.27 = 7.87 (a régi egységár-alapú számla 7.86-ot adott)');
    }

    // ------------------------------------------------------------------
    // Visszáru: a számla nem változik (jóváíró számla kézi), a riport
    // teljes visszárunál pontosan nullára hozza az eladást
    // ------------------------------------------------------------------

    /** @return array<string, array{0: array, 1: string}> */
    public static function fullReturnCases(): array
    {
        return [
            'kuponos eladás' => [['coupon' => ['fixed', 20]], 'kupon'],
            'utalványos eladás' => [['gift' => 1000.0], 'utalvány'],
            'vegyes kulcs + kupon + utalvány' => [['coupon' => ['fixed', 55], 'gift' => 300.0, 'mixed' => true], 'vegyes'],
        ];
    }

    /** @dataProvider fullReturnCases */
    public function testFullReturnNetsTheSaleToZeroInEveryReportAndLeavesTheInvoiceUntouched(array $opts, string $label): void
    {
        $items = !empty($opts['mixed'])
            ? [self::manual('V27', 10.0, 3, '27'), self::manual('V5', 99.0, 2, '5'), self::manual('VAAM', 7.0, 1, 'AAM')]
            : [self::manual('R', 635.0, 2, '27')];
        [$closeStart] = $this->reports();
        $payload = ['items' => $items];
        if (isset($opts['coupon'])) {
            $payload['coupon_code'] = $this->coupon(...$opts['coupon']);
        }
        if (isset($opts['gift'])) {
            $payload['gift_card_code'] = $this->giftCard($opts['gift']);
        }
        $sale = $this->invoicedSale($payload, "$label (eladás)");
        $callsAfterSale = count($this->stubCalls());

        $stored = self::$db->getSaleWithItems($sale['sale_id']);
        $ret = $this->post('/api/return-create.php', ['sale_id' => $sale['sale_id'], 'items' => array_map(fn ($si) => ['sale_item_id' => (int) $si['id'], 'qty' => (int) $si['qty']], $stored['items']), 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $ret['status'], $ret['body']);
        $this->assertCount($callsAfterSale, $this->stubCalls(), 'A visszáru nem állít ki automatikus számlát.');

        [$closeEnd, $reportEnd] = $this->reports();
        $delta = self::reportDelta($closeStart, $closeEnd);
        $this->assertSame(['net' => 0, 'vat' => 0, 'gross' => 0, 'by_rate' => []], $delta, "$label: teljes visszáru után a napi zárás nettó hatása 0, kulcsonként is");
        $this->assertSame($closeEnd['total_net'], $reportEnd['total_net']);
        $this->assertSame($closeEnd['total_vat'], $reportEnd['total_vat']);
    }

    public function testPartialReturnKeepsReportsInternallyConsistent(): void
    {
        $sale = $this->invoicedSale(['items' => [self::manual('P27', 10.0, 3, '27'), self::manual('P18', 7.0, 3, '18')], 'coupon_code' => $this->coupon('fixed', 7)], 'részleges visszáru eladása');
        $stored = self::$db->getSaleWithItems($sale['sale_id']);
        $ret = $this->post('/api/return-create.php', ['sale_id' => $sale['sale_id'], 'items' => [['sale_item_id' => (int) $stored['items'][0]['id'], 'qty' => 1]], 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->assertSame(200, $ret['status'], $ret['body']);

        [$close, $report] = $this->reports();
        foreach (['total_gross', 'total_net', 'total_vat', 'total_returns'] as $k) {
            $this->assertSame($close[$k], $report[$k], "$k: napi zárás vs. riport");
        }
        $this->assertSame(self::cents($close['total_gross']), self::cents($close['total_net']) + self::cents($close['total_vat']));
        foreach ($close['by_vat_rate'] as $rate => $row) {
            $this->assertSame(self::cents($row['gross']), self::cents($row['net']) + self::cents($row['vat']), "kulcs $rate");
        }
    }
}
