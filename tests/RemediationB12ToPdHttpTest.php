<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ReportPeriod.php';
require_once __DIR__ . '/../src/Pagination.php';

use PHPUnit\Framework\TestCase;

/**
 * B-12 / B-14 / B-15 / P-D — HTTP-szintű végpont-tesztek egy önálló,
 * ideiglenes másolatban futó PHP beépített szerveren (ugyanaz a minta, mint
 * InventorySyncEndToEndHttpTest). Külső szolgáltatást nem hív.
 *
 * B-12 determinisztikus dupla-kérés: a teszt-folyamat író-zárat tart
 * (BEGIN IMMEDIATE) az SQLite-adatbázison (WAL: az olvasások nem
 * blokkolódnak), a kérés így az idempotencia-előellenőrzésen ÁTJUT, majd az
 * írásnál vár; ekkor a teszt beszúrja az "első kérés" győztes sorát és
 * elengedi a zárat. A kérés innen pontosan azon az ágon fut tovább, amit
 * egy valódi, közel egyidejű dupla beküldés második példánya ér el.
 */
final class RemediationB12ToPdHttpTest extends TestCase
{
    private const WEBHOOK_SECRET = 'b12-pd-webhook-secret';

    private static string $root;
    private static string $baseUrl;
    /** @var resource|null */
    private static $server;
    private static string $jar;
    private static Database $db;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_b12pd_http_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        foreach (['config', 'data', 'invoices'] as $dir) {
            mkdir(self::$root . '/' . $dir, 0775, true);
        }
        $config = str_replace("'change-me-webhook-secret'", var_export(self::WEBHOOK_SECRET, true), file_get_contents($projectRoot . '/config/config.php'));
        if (!str_contains($config, self::WEBHOOK_SECRET)) {
            self::fail('A teszt-config webhook-titka nem állítható be.');
        }
        file_put_contents(self::$root . '/config/config.php', $config);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$root . '/webroot'], [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']], $pipes, self::$root);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && !@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
            usleep(100_000);
        }

        self::request('GET', '/api/auth-status.php');
        self::$dbPath = self::$root . '/data/stock.sqlite';
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$dbPath]], self::$root);
        $setupJar = self::$root . '/cookies-setup.txt';
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $setupJar)['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', ['app_password_enabled' => true, 'new_password' => 'b12-jelszo-teszt', 'new_password_confirm' => 'b12-jelszo-teszt'], ['X-CSRF-Token' => $csrf], $setupJar);
        self::$jar = self::$root . '/cookies-main.txt';
        self::request('POST', '/api/login.php', ['password' => 'b12-jelszo-teszt'], [], self::$jar);
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
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function handle(string $method, string $path, $body = null, array $headers = [], ?string $jar = null, bool $raw = false)
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
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        return $ch;
    }

    private static function responseOf($ch, string $rawBody): array
    {
        $json = json_decode($rawBody, true);
        return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $rawBody, 'json' => is_array($json) ? $json : null];
    }

    private static function request(string $method, string $path, $body = null, array $headers = [], ?string $jar = null, bool $raw = false): array
    {
        $ch = self::handle($method, $path, $body, $headers, $jar, $raw);
        $out = (string) curl_exec($ch);
        $r = self::responseOf($ch, $out);
        curl_close($ch);
        return $r;
    }

    private function csrf(): string
    {
        return self::request('GET', '/api/auth-status.php', null, [], self::$jar)['json']['csrf_token'];
    }

    private function post(string $path, array $body): array
    {
        return self::request('POST', $path, $body, ['X-CSRF-Token' => $this->csrf()], self::$jar);
    }

    private function get(string $path): array
    {
        return self::request('GET', $path, null, [], self::$jar);
    }

    /**
     * A kérést elindítja, amíg a teszt-folyamat író-zárat tart; $whileBlocked
     * a zár alatt fut (itt szúrja be a "győztes" sort), utána a zár elenged.
     */
    private function requestWhileWriteLocked(string $path, array $body, callable $whileBlocked): array
    {
        $lock = new PDO('sqlite:' . self::$dbPath);
        $lock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lock->exec('PRAGMA busy_timeout = 5000');
        $csrf = $this->csrf();
        $lock->exec('BEGIN IMMEDIATE');
        $ch = self::handle('POST', $path, $body, ['X-CSRF-Token' => $csrf], self::$jar);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);
        $deadline = microtime(true) + 1.2; // a kérés átjut az előellenőrzésen, és az írásnál vár
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running && microtime(true) < $deadline);
        $whileBlocked($lock);
        $lock->exec('COMMIT');
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
        } while ($running);
        $r = self::responseOf($ch, (string) curl_multi_getcontent($ch));
        curl_multi_remove_handle($multi, $ch);
        curl_multi_close($multi);
        return $r;
    }

    private function assertNoRawDatabaseText(array $res, array $sensitive = []): void
    {
        foreach (array_merge(['SQLSTATE', 'INSERT ', 'SELECT ', 'UPDATE ', 'constraint', 'no such table', 'database is locked'], $sensitive) as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $res['body'], "Nyers adatbázis-szöveg a válaszban: $needle");
        }
    }

    private function cashRegister(): array
    {
        $loc = self::$db->saveLocation(['name' => 'B12 ' . bin2hex(random_bytes(3))]);
        $registerId = self::$db->saveCashRegister(['location_id' => $loc, 'name' => 'B12 kassza', 'code' => 'B12' . bin2hex(random_bytes(3))]);
        return [$loc, $registerId];
    }

    // ------------------------------------------------------------------
    // B-12
    // ------------------------------------------------------------------

    public function testB12DoubleSubmitCashMovementReplaysTheWinnerInsteadOfRawSqlError(): void
    {
        [, $registerId] = $this->cashRegister();
        $sessionId = self::$db->openCashSession($registerId, null, 10000.0);
        $key = 'b12-move-' . bin2hex(random_bytes(6));
        $winnerId = null;

        $res = $this->requestWhileWriteLocked('/api/cash-movement.php', ['cash_session_id' => $sessionId, 'type' => 'cash_in', 'amount' => 500, 'reason' => 'B12 dupla', 'idempotency_key' => $key], function (PDO $lock) use ($sessionId, $key, &$winnerId) {
            $lock->prepare('INSERT INTO cash_movements (cash_session_id, staff_id, type, amount, reason, idempotency_key, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)')
                ->execute([$sessionId, 'cash_in', 500, 'B12 dupla', $key, date('Y-m-d H:i:s')]);
            $winnerId = (int) $lock->lastInsertId();
        });

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame($winnerId, $res['json']['id'], 'Az első kérés eredménye jön vissza.');
        // (a replay-válasz a meglévő szerződés szerint a mozgás-sort adja vissza)
        $this->assertNoRawDatabaseText($res, ['cash_movements', 'UNIQUE']);
        $this->assertSame(1, (int) self::$db->pdo()->query("SELECT COUNT(*) FROM cash_movements WHERE idempotency_key = " . self::$db->pdo()->quote($key))->fetchColumn());
    }

    public function testB12DoubleSubmitCashSessionOpenReplaysTheWinner(): void
    {
        [, $registerId] = $this->cashRegister();
        $key = 'b12-open-' . bin2hex(random_bytes(6));
        $fingerprint = hash('sha256', $registerId . '|' . number_format(20000, 2, '.', ''));
        $winnerId = null;

        $res = $this->requestWhileWriteLocked('/api/cash-session-open.php', ['cash_register_id' => $registerId, 'opening_amount' => 20000, 'idempotency_key' => $key], function (PDO $lock) use ($registerId, $key, $fingerprint, &$winnerId) {
            $lock->prepare("INSERT INTO cash_sessions (cash_register_id, staff_id, opening_amount, status, idempotency_key, idempotency_fingerprint, opened_at) VALUES (?, NULL, 20000, 'open', ?, ?, ?)")
                ->execute([$registerId, $key, $fingerprint, date('Y-m-d H:i:s')]);
            $winnerId = (int) $lock->lastInsertId();
        });

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame($winnerId, $res['json']['id']);
        $this->assertNoRawDatabaseText($res);
    }

    public function testB12RealBusinessConflictStaysA409WithItsMessage(): void
    {
        [, $registerId] = $this->cashRegister();
        self::$db->openCashSession($registerId, null, 1000.0);

        $res = $this->post('/api/cash-session-open.php', ['cash_register_id' => $registerId, 'opening_amount' => 5000, 'idempotency_key' => 'b12-other-' . bin2hex(random_bytes(4))]);
        $this->assertSame(409, $res['status']);
        $this->assertSame('Ehhez a pénztárgéphez már van nyitott műszak.', $res['json']['error']);

        $first = $this->post('/api/stock-take-start.php', []);
        $second = $this->post('/api/stock-take-start.php', []);
        $this->assertSame(409, $second['status'], $second['body']);
        $this->assertStringContainsString('nyitott', $second['json']['error']);
        $this->post('/api/stock-take-complete.php', ['id' => $first['json']['id'], 'apply_corrections' => false]);
    }

    public function testB12GenericDbErrorIsA500WithoutSqlOrSchemaNames(): void
    {
        $p = self::$db->saveProduct(['name' => 'B12 mozgatás', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1, 'price' => 1, 'barcode' => null]);
        $loc = self::$db->saveLocation(['name' => 'B12 cél']);
        self::$db->pdo()->exec('ALTER TABLE stock_transfers RENAME TO stock_transfers_b12');
        try {
            $res = $this->post('/api/stock-transfer.php', ['product_id' => $p, 'to_location_id' => $loc, 'qty' => 1]);
        } finally {
            self::$db->pdo()->exec('ALTER TABLE stock_transfers_b12 RENAME TO stock_transfers');
        }
        // Korábban: 409 + "SQLSTATE[HY000]: General error: 1 no such table: stock_transfers".
        $this->assertSame(500, $res['status'], $res['body']);
        $this->assertSame('Váratlan szerverhiba történt. Próbáld újra, vagy értesítsd az üzemeltetőt.', $res['json']['error']);
        $this->assertNoRawDatabaseText($res, ['stock_transfers']);
    }

    public function testB12ConstraintAndTransactionFailureInsideReturnIsNotABusinessConflict(): void
    {
        $saleId = self::$db->insertSale(1000.0, 'Készpénz');
        self::$db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'B12 tétel', 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => '27']);
        $itemId = (int) self::$db->getSaleWithItems($saleId)['items'][0]['id'];
        self::$db->pdo()->exec("CREATE TRIGGER b12_constraint BEFORE INSERT ON return_items BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: return_items.qty'); END");
        try {
            $res = $this->post('/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $itemId, 'qty' => 1]], 'reason' => 'B12']);
        } finally {
            self::$db->pdo()->exec('DROP TRIGGER b12_constraint');
        }
        $this->assertSame(500, $res['status'], $res['body']);
        $this->assertNoRawDatabaseText($res, ['return_items']);
        $this->assertSame([], self::$db->getReturnsForSale($saleId), 'A visszáru-tranzakció teljesen visszagördült.');
    }

    public function testB12LockTimeoutIsA503RetryableWithoutRawText(): void
    {
        $lock = new PDO('sqlite:' . self::$dbPath);
        $lock->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $csrf = $this->csrf();
        $lock->exec('BEGIN IMMEDIATE'); // az író-zár a kérés teljes busy_timeout-ján (5 s) túl is marad
        try {
            $res = self::request('POST', '/api/stock-take-start.php', [], ['X-CSRF-Token' => $csrf], self::$jar);
        } finally {
            $lock->exec('ROLLBACK');
        }
        $this->assertSame(503, $res['status'], $res['body']);
        $this->assertTrue($res['json']['retryable'] ?? false);
        $this->assertNoRawDatabaseText($res);
    }

    // ------------------------------------------------------------------
    // B-14
    // ------------------------------------------------------------------

    public function testB14DashboardTodayIsTheServerApplicationDay(): void
    {
        // A szerver (api/_bootstrap.php: Europe/Budapest) napja — a teszt-
        // folyamat UTC-ben fut, éjfél körül a kettő eltér.
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->format('Y-m-d');
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        foreach ([$yesterday, $today] as $day) {
            self::$db->pdo()->prepare("INSERT INTO ai_daily_reports (report_date, status, provider, model, findings_json, findings_count, has_significant_findings, report_text, started_at, completed_at, created_at)
                VALUES (?, 'completed', 'local', 'teszt', '[]', 0, 0, ?, ?, ?, ?)")
                ->execute([$day, "Jelentés $day", "$day 07:00:00", "$day 07:01:00", "$day 07:00:00"]);
        }

        $res = $this->get('/api/ai-daily-report.php?date=today');
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame($today, $res['json']['report']['report_date']);
        $this->assertSame(400, $this->get('/api/ai-daily-report.php?date=tomorrow')['status'], 'Csak a "today" kulcsszó új.');
    }

    // ------------------------------------------------------------------
    // B-15
    // ------------------------------------------------------------------

    public static function extremePages(): array
    {
        return [
            'PHP_INT_MAX' => [(string) PHP_INT_MAX, Pagination::MAX_PAGE],
            'PHP_INT_MAX-1' => [(string) (PHP_INT_MAX - 1), Pagination::MAX_PAGE],
            'óriás string' => [str_repeat('9', 60), Pagination::MAX_PAGE],
            '0' => ['0', 1], '-1' => ['-1', 1], 'abc' => ['abc', 1], 'üres' => ['', 1],
            '2.5' => ['2.5', 2], '007' => ['007', 7],
        ];
    }

    /** @dataProvider extremePages */
    public function testB15ListEndpointsHandleExtremePageValues(string $page, int $expectedPage): void
    {
        foreach (['/api/ai-history-list.php', '/api/ai-action-proposals-list.php'] as $endpoint) {
            $res = $this->get($endpoint . '?page=' . rawurlencode($page));
            $this->assertSame(200, $res['status'], "$endpoint page=$page: " . $res['body']);
            $this->assertSame($expectedPage, $res['json']['page'], $endpoint);
            $this->assertSame(20, $res['json']['page_size']);
            $this->assertIsBool($res['json']['has_more']);
            if ($expectedPage === Pagination::MAX_PAGE) {
                $this->assertFalse($res['json']['has_more']);
            }
        }
    }

    // ------------------------------------------------------------------
    // P-D — aláírt WooCommerce-webhookkal
    // ------------------------------------------------------------------

    private function webhook(array $order): array
    {
        $raw = json_encode($order);
        return self::request('POST', '/api/webhook.php', $raw, ['X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true))], null, true);
    }

    private function wcOrder(int $id, string $status, int $wcProductId, int $qty): array
    {
        return ['id' => $id, 'number' => (string) $id, 'status' => $status, 'billing' => ['first_name' => 'Web', 'last_name' => 'Vevő'],
            'line_items' => [['product_id' => $wcProductId, 'quantity' => $qty, 'name' => 'PD', 'total' => (string) (1000 * $qty), 'total_tax' => (string) (270 * $qty)]]];
    }

    public function testPdCancellationWebhookReleasesOnceAndKeepsConfirmedSales(): void
    {
        $wcId = 4700 + random_int(1, 999);
        $p = self::$db->saveProduct(['name' => 'PD webhook', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
        self::$db->incrementStock($p, 5);
        self::$db->pdo()->prepare('UPDATE products SET wc_product_id = ?, sync_to_woocommerce = 1 WHERE id = ?')->execute([$wcId, $p]);
        $orderA = 800000 + random_int(1, 99999);
        $orderB = $orderA + 1;

        $this->assertSame(200, $this->webhook($this->wcOrder($orderA, 'processing', $wcId, 2))['status']);
        $this->assertSame(2, self::$db->getPendingWebOrderQty($p));

        $cancel = $this->webhook($this->wcOrder($orderA, 'cancelled', $wcId, 2));
        $this->assertSame(200, $cancel['status'], $cancel['body']);
        $this->assertSame('released', $cancel['json']['outcome']);
        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));
        $this->assertSame('already_released', $this->webhook($this->wcOrder($orderA, 'cancelled', $wcId, 2))['json']['outcome'], 'Duplikált lemondás: no-op.');
        $this->assertTrue($this->webhook($this->wcOrder($orderA, 'processing', $wcId, 2))['json']['ignored'] ?? false, 'Visszajátszott rendelés nem foglal újra.');
        $this->assertSame(0, self::$db->getPendingWebOrderQty($p));

        // Megerősítés után érkező visszatérítés: a helyi eladás marad.
        $draftB = (int) $this->webhook($this->wcOrder($orderB, 'processing', $wcId, 1))['json']['draft_id'];
        $this->assertSame(200, $this->post('/api/webshop-order-confirm.php', ['id' => $draftB, 'payment_method' => 'Utánvét'])['status']);
        $this->assertSame(4, (int) self::$db->findProductById($p)['stock_qty']);
        $refund = $this->webhook($this->wcOrder($orderB, 'refunded', $wcId, 1));
        $this->assertSame('confirmed_kept', $refund['json']['outcome']);
        $this->assertSame(4, (int) self::$db->findProductById($p)['stock_qty'], 'Nincs vak visszatöltés.');

        $list = $this->get('/api/webshop-orders-list.php?status=confirmed');
        $row = current(array_filter($list['json']['orders'] ?? [], fn ($o) => (int) $o['wc_order_id'] === $orderB));
        $this->assertSame('refunded', $row['wc_status'] ?? null, 'A felület látja a WooCommerce-státuszt.');
    }
}
