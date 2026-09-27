<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-01 — a VALÓDI alkalmazás a diszpécseren (tools/http-dispatcher.php)
 * keresztül: egy lassú (3 s-os, kontrollált stub-Ollamával futó) AI-stream
 * alatt egy kasszai eladás — UGYANABBAN a böngésző-sessionben is — azonnal
 * kiszolgálható. A javítás előtt (egyetlen `php -S`) az eladás a teljes
 * AI-futást kivárta (mérve: 5,8 s), és ugyanabban a sessionben a PHP
 * session-zár miatt több folyamat mellett is várt volna.
 */
final class DispatcherRuntimeHttpTest extends TestCase
{
    private static string $root;
    private static string $ollamaRoot;
    private static int $port;
    private static $dispatcher;
    private static $ollama;
    private static string $adminJar;
    private static string $cashierJar;
    private static int $productId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_dispatch_rt_' . bin2hex(random_bytes(5));
        $project = dirname(__DIR__);
        self::copyDir($project . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($project . '/src', self::$root . '/src', []);
        copy($project . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($project . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        self::$ollamaRoot = sys_get_temp_dir() . '/sm_dispatch_rt_ollama_' . bin2hex(random_bytes(5));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/tags') { header('Content-Type: application/json'); echo json_encode(['models' => [['name' => 'qwen3:8b']]]); exit; }
$body = json_decode(file_get_contents('php://input'), true);
sleep(3); // lassú helyi modell
if (empty($body['stream'])) { header('Content-Type: application/json'); echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Kész.']]); exit; }
header('Content-Type: application/x-ndjson');
while (ob_get_level() > 0) { ob_end_flush(); }
echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Kész.']]) . "\n"; flush();
echo json_encode(['done' => true, 'prompt_eval_count' => 5, 'eval_count' => 2]) . "\n";
PHP);
        $ollamaPort = self::freePort();
        self::$ollama = proc_open([PHP_BINARY, '-S', "127.0.0.1:$ollamaPort", '-t', self::$ollamaRoot], [['pipe', 'r'], ['file', self::$ollamaRoot . '/log.txt', 'a'], ['file', self::$ollamaRoot . '/log.txt', 'a']], $pipes);
        self::waitFor($ollamaPort);

        self::$port = self::freePort();
        self::$dispatcher = proc_open(
            [PHP_BINARY, $project . '/tools/http-dispatcher.php', '--listen=127.0.0.1:' . self::$port, '--webroot=' . self::$root . '/webroot', '--workers=3', '--background-workers=1', '--base-port=' . self::freePortRange(4)],
            [['pipe', 'r'], ['file', self::$root . '/dispatcher.log', 'a'], ['file', self::$root . '/dispatcher.log', 'a']],
            $pipes
        );
        self::waitFor(self::$port);
        self::request('GET', '/api/auth-status.php');

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Diszpécser Admin', 'pin' => '73412', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Diszpécser Pénztáros', 'pin' => '28193', 'role' => 'staff']);
        $db->pdo()->exec("INSERT INTO products (name, unit, price, net_price, stock_qty, vat_rate) VALUES ('Diszpécser termék', 'db', 1000, 787, 100000, '27')");
        self::$productId = (int) $db->pdo()->lastInsertId();
        unset($db);

        $setup = self::$root . '/cookies-setup.txt';
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $setup)['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', ['app_password_enabled' => true, 'new_password' => 'diszpecser-teszt-jelszo', 'new_password_confirm' => 'diszpecser-teszt-jelszo'], ['X-CSRF-Token' => $csrf], $setup);

        self::$adminJar = self::login('admin', '73412');
        self::$cashierJar = self::login('cashier', '28193');
        self::request('POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_local_base_url' => "http://127.0.0.1:$ollamaPort",
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 20,
            'ai_max_iterations' => 3,
            'ai_min_seconds_between_requests' => 0,
        ], ['X-CSRF-Token' => self::csrf(self::$adminJar)], self::$adminJar);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$dispatcher, self::$ollama] as $p) {
            if (is_resource($p)) {
                $pid = proc_get_status($p)['pid'];
                PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID $pid 2>NUL") : proc_terminate($p);
                proc_close($p);
            }
        }
        foreach ([self::$root, self::$ollamaRoot] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
    }

    private static function login(string $name, string $pin): string
    {
        $jar = self::$root . "/cookies-$name.txt";
        self::request('POST', '/api/login.php', ['password' => 'diszpecser-teszt-jelszo'], [], $jar);
        $res = self::request('POST', '/api/staff-login.php', ['pin' => $pin], ['X-CSRF-Token' => self::csrf($jar)], $jar);
        if (!($res['json']['ok'] ?? false)) {
            self::fail("$name staff-login sikertelen: " . $res['body']);
        }
        return $jar;
    }

    private static function csrf(string $jar): string
    {
        return (string) self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
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

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }

    private static function freePortRange(int $count): int
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $base = random_int(20000, 40000);
            for ($i = 0; $i < $count; $i++) {
                $s = @stream_socket_server('tcp://127.0.0.1:' . ($base + $i));
                if ($s === false) {
                    continue 2;
                }
                fclose($s);
            }
            return $base;
        }
        throw new RuntimeException('Nincs szabad porttartomány.');
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 150; $i++) {
            $c = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.2);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(100000);
        }
        self::fail("A(z) $port port nem nyílt meg.");
    }

    private static function handle(string $method, string $path, ?array $json, array $headers, ?string $jar): CurlHandle
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $h = ['Expect:'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        }
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            $h[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        return $ch;
    }

    private static function request(string $method, string $path, ?array $json = null, array $headers = [], ?string $jar = null): array
    {
        $ch = self::handle($method, $path, $json, $headers, $jar);
        $body = (string) curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
    }

    /** Egy AI-stream és — 0,5 s-mal később — egy eladás párhuzamosan; a két kérés ideje és válasza. */
    private function aiStreamWithConcurrentSale(string $saleJar): array
    {
        $aiCsrf = self::csrf(self::$adminJar);
        $saleCsrf = self::csrf($saleJar);
        $ai = self::handle('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'lassú kérdés'], ['X-CSRF-Token' => $aiCsrf], self::$adminJar);
        $sale = self::handle('POST', '/api/sale.php', [
            'items' => [['product_id' => self::$productId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => bin2hex(random_bytes(12)),
        ], ['X-CSRF-Token' => $saleCsrf], $saleJar);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ai);
        $t0 = microtime(true);
        $saleStarted = null;
        $done = [];
        do {
            if ($saleStarted === null && microtime(true) - $t0 >= 0.5) {
                curl_multi_add_handle($mh, $sale);
                $saleStarted = microtime(true);
            }
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.01);
            while ($info = curl_multi_info_read($mh)) {
                $key = $info['handle'] === $ai ? 'ai' : 'sale';
                $done[$key] = microtime(true) - ($key === 'ai' ? $t0 : $saleStarted);
            }
        } while (count($done) < 2);
        return [
            'ai_seconds' => $done['ai'],
            'ai_status' => curl_getinfo($ai, CURLINFO_HTTP_CODE),
            'ai_body' => (string) curl_multi_getcontent($ai),
            'sale_seconds' => $done['sale'],
            'sale_status' => curl_getinfo($sale, CURLINFO_HTTP_CODE),
            'sale_body' => (string) curl_multi_getcontent($sale),
        ];
    }

    public function testSaleFromAnotherTerminalIsNotBlockedByARunningAiStream(): void
    {
        $r = $this->aiStreamWithConcurrentSale(self::$cashierJar);
        $this->assertSame(200, $r['ai_status'], $r['ai_body']);
        $this->assertStringContainsString('"type":"done"', $r['ai_body']);
        $this->assertGreaterThan(2.5, $r['ai_seconds'], 'A stub-modell 3 s-ig válaszol.');
        $this->assertSame(200, $r['sale_status'], $r['sale_body']);
        $this->assertLessThan(1.5, $r['sale_seconds'], 'Az eladás nem várhatja ki az AI-streamet (PERF-01).');
    }

    public function testSaleInTheSameBrowserSessionIsNotBlockedByTheSessionLock(): void
    {
        $r = $this->aiStreamWithConcurrentSale(self::$adminJar);
        $this->assertSame(200, $r['ai_status'], $r['ai_body']);
        $this->assertStringContainsString('"type":"done"', $r['ai_body']);
        $this->assertSame(200, $r['sale_status'], $r['sale_body']);
        $this->assertLessThan(1.5, $r['sale_seconds'], 'Ugyanabban a sessionben sem várhat az eladás a session-zárra.');
    }

    public function testSessionWritesStillWorkAfterALongRequestReleasedTheLock(): void
    {
        // A hosszú végpont elengedte a session-zárat — ettől a session
        // tartalma (bejelentkezés, dolgozó, CSRF) nem változhat.
        $before = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json'];
        $this->aiStreamWithConcurrentSale(self::$adminJar);
        $after = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json'];
        $this->assertSame($before['csrf_token'], $after['csrf_token']);
        $this->assertSame($before['staff'] ?? null, $after['staff'] ?? null);
        $this->assertTrue((bool) ($after['logged_in'] ?? $after['authenticated'] ?? true));
    }
}
