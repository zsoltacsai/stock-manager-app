<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AI security remediáció — VALÓDI HTTP-n, valódi php -S folyamatokkal:
 *  - Szerver node (node_role = server) KÉT php -S folyamattal ugyanazon az
 *    app-gyökéren (közös DB, közös temp) — valódi többfolyamatos
 *    párhuzamosság Windowson is (a beépített szerver egyszálú);
 *  - két Kliens node (node_role = client), két külön regisztrált kliens-géppel;
 *  - vezérelhető helyi "Ollama" stub (NEM valódi Ollama — nem élő
 *    provider-validáció), amely a futás közben a Szerver SQLite DB-jét is
 *    módosíthatja (dolgozó deaktiválása, kliens visszavonása stb.).
 *
 * Lefedi: AI-01 (streamelt tool-keret HTTP-n), AI-04 (ai-health
 * jogosultság), AI-05 (egy szereplő párhuzamos futásai), AI-09 (futás
 * közbeni visszavonás és megszakítás).
 */
final class AiSecurityRemediationHttpTest extends TestCase
{
    private const APP_PASSWORD = 'ai-remediacio-http-teszt';
    private const ADMIN_PIN = '81734';
    private const CASHIER_PIN = '27391';
    private const ADMIN2_PIN = '46152';

    private static string $root;
    private static string $dbPath;
    private static int $port1;
    private static int $port2;
    private static string $ollamaRoot;
    private static int $ollamaPort;
    /** @var array<int,resource> */
    private static array $processes = [];
    /** @var array<string,array{root:string,port:int,registered_client_id:int}> */
    private static array $clients = [];
    private static int $adminId;
    private static string $adminJar;
    private static string $cashierJar;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);
        self::$ollamaRoot = sys_get_temp_dir() . '/sm_ai_rem_ollama_' . bin2hex(random_bytes(6));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/hits.log', "$path\n", FILE_APPEND | LOCK_EX);
$control = json_decode((string) @file_get_contents(__DIR__ . '/control.json'), true) ?: ['mode' => 'final'];
if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path !== '/api/chat') { http_response_code(404); exit; }
$call = (int) @file_get_contents(__DIR__ . '/calls.txt') + 1;
file_put_contents(__DIR__ . '/calls.txt', (string) $call);
if (!empty($control['sleep_ms'])) { usleep((int) $control['sleep_ms'] * 1000); }
if (!empty($control['sql']) && $call === (int) ($control['trigger_call'] ?? 2)) {
    $pdo = new PDO('sqlite:' . $control['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    $pdo->prepare($control['sql'])->execute($control['params'] ?? []);
}
$body = json_decode(file_get_contents('php://input'), true);
$hasToolResult = false;
foreach ($body['messages'] ?? [] as $m) { if (($m['role'] ?? '') === 'tool') { $hasToolResult = true; } }
$toolCall = fn () => ['function' => ['name' => 'get_low_stock_products', 'arguments' => new stdClass()]];
switch ($control['mode'] ?? 'final') {
    case 'burst300':
        echo $hasToolResult
            ? json_encode(['message' => ['role' => 'assistant', 'content' => 'Kész.'], 'done' => true])
            : json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => array_map($toolCall, range(1, 300))], 'done' => true]);
        break;
    case 'tool_loop':
        echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [$toolCall()]], 'done' => true]);
        break;
    default:
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Minden rendben.'], 'done' => true]);
}
PHP);
        self::$ollamaPort = self::findFreePort();
        self::spawn(self::$ollamaRoot, self::$ollamaPort, self::$ollamaRoot);

        // --- Szerver node, két php -S folyamattal ---
        self::$root = sys_get_temp_dir() . '/sm_ai_rem_server_' . bin2hex(random_bytes(6));
        self::copyApp($projectRoot, self::$root, true);
        self::$dbPath = self::$root . '/data/stock.sqlite';
        file_put_contents(self::$root . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => self::$dbPath], 'mysql' => []],
            'node_role' => 'server',
        ], true) . ';');
        self::$port1 = self::findFreePort();
        self::spawn(self::$root . '/webroot', self::$port1, self::$root);
        self::$port2 = self::findFreePort();
        self::spawn(self::$root . '/webroot', self::$port2, self::$root);

        require_once $projectRoot . '/src/Database.php';
        require_once $projectRoot . '/src/Settings.php';
        self::request(self::$port1, 'GET', '/api/auth-status.php', null, [], self::jar('init'));
        $db = self::db();
        self::$adminId = $db->saveStaff(['name' => 'Rem Admin', 'pin' => self::ADMIN_PIN, 'role' => 'admin']);
        $db->saveStaff(['name' => 'Rem Pénztáros', 'pin' => self::CASHIER_PIN, 'role' => 'cashier']);
        $db->saveStaff(['name' => 'Rem Admin 2', 'pin' => self::ADMIN2_PIN, 'role' => 'admin']);
        $clientA = $db->registerClient('Rem kliens A');
        $clientB = $db->registerClient('Rem kliens B');
        unset($db);
        (new Settings(self::$root . '/data/settings.json'))->save([
            'app_password_hash' => password_hash(self::APP_PASSWORD, PASSWORD_DEFAULT),
            'app_password_enabled' => true,
        ]);

        self::$adminJar = self::serverLogin(self::$port1, self::ADMIN_PIN, 'admin');
        self::$cashierJar = self::serverLogin(self::$port1, self::CASHIER_PIN, 'cashier');
        $saved = self::request(self::$port1, 'POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
            'ai_max_tool_calls' => 20,
            'ai_min_seconds_between_requests' => 0,
        ], ['X-CSRF-Token' => self::csrf(self::$port1, self::$adminJar)], self::$adminJar);
        if ($saved['status'] !== 200) {
            self::fail('AI-beállítás sikertelen: ' . $saved['body']);
        }

        foreach (['A' => $clientA, 'B' => $clientB] as $name => $client) {
            $clientRoot = sys_get_temp_dir() . '/sm_ai_rem_client' . $name . '_' . bin2hex(random_bytes(6));
            self::copyApp($projectRoot, $clientRoot, false);
            file_put_contents($clientRoot . '/config/installer-generated.php', '<?php return ' . var_export([
                'shop' => ['name' => 'X', 'address' => 'X'],
                'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . self::$port1, 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']],
            ], true) . ';');
            $port = self::findFreePort();
            self::spawn($clientRoot . '/webroot', $port, $clientRoot);
            self::$clients[$name] = ['root' => $clientRoot, 'port' => $port, 'registered_client_id' => (int) $client['id']];
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $p) {
            if (is_resource($p)) { proc_terminate($p); proc_close($p); }
        }
        self::removeDir(self::$root);
        self::removeDir(self::$ollamaRoot);
        foreach (self::$clients as $c) { self::removeDir($c['root']); }
    }

    protected function setUp(): void
    {
        $this->setOllama(['mode' => 'final']);
    }

    // ------------------------------------------------------------------
    // Segédfüggvények
    // ------------------------------------------------------------------

    private static function db(): Database
    {
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$dbPath]], self::$root);
    }

    private function setOllama(array $control): void
    {
        file_put_contents(self::$ollamaRoot . '/control.json', json_encode($control + ['db' => self::$dbPath]));
        @unlink(self::$ollamaRoot . '/calls.txt');
        file_put_contents(self::$ollamaRoot . '/hits.log', '');
    }

    private function hits(string $path): int
    {
        return substr_count((string) @file_get_contents(self::$ollamaRoot . '/hits.log'), "$path\n");
    }

    private static function spawn(string $docRoot, int $port, string $cwd): void
    {
        self::$processes[] = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot],
            [1 => ['file', $cwd . '/php-s-' . $port . '.log', 'w'], 2 => ['file', $cwd . '/php-s-' . $port . '.log', 'w']],
            $pipes,
            $cwd
        );
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100_000);
        }
        self::fail("A teszt-szerver ($port) nem indult el.");
    }

    private static function copyApp(string $projectRoot, string $to, bool $withData): void
    {
        self::copyDir($projectRoot . '/webroot', $to . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', $to . '/src', []);
        copy($projectRoot . '/schema.sql', $to . '/schema.sql');
        mkdir($to . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', $to . '/config/config.php');
        if ($withData) { mkdir($to . '/data', 0775, true); }
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        if (!is_dir($from)) return;
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) continue;
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            is_dir("$dir/$item") && !is_link("$dir/$item") ? self::removeDir("$dir/$item") : @unlink("$dir/$item");
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function jar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '-' . bin2hex(random_bytes(3)) . '.txt';
    }

    private static function curlHandle(int $port, string $method, string $path, ?array $body, array $headers, ?string $jar, int $timeoutMs = 30000)
    {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        $lines = [];
        foreach ($headers as $k => $v) { $lines[] = "$k: $v"; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => $timeoutMs]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $lines[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
        return $ch;
    }

    private static function request(int $port, string $method, string $path, ?array $body = null, array $headers = [], ?string $jar = null, int $timeoutMs = 30000): array
    {
        $ch = self::curlHandle($port, $method, $path, $body, $headers, $jar, $timeoutMs);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => (string) $raw];
    }

    private static function csrf(int $port, string $jar): string
    {
        return (string) (self::request($port, 'GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'] ?? '');
    }

    private static function serverLogin(int $port, string $pin, string $name): string
    {
        $jar = self::jar($name);
        self::request($port, 'GET', '/api/auth-status.php', null, [], $jar);
        self::request($port, 'POST', '/api/login.php', ['password' => self::APP_PASSWORD], [], $jar);
        $res = self::request($port, 'POST', '/api/staff-login.php', ['pin' => $pin], ['X-CSRF-Token' => self::csrf($port, $jar)], $jar);
        if (!($res['json']['ok'] ?? false)) {
            self::fail("Staff-login ($name) sikertelen: " . $res['body']);
        }
        return $jar;
    }

    private static function clientLogin(string $client, string $pin): string
    {
        $port = self::$clients[$client]['port'];
        $jar = self::jar('client' . $client);
        self::request($port, 'GET', '/api/auth-status.php', null, [], $jar);
        $res = self::request($port, 'POST', '/api/staff-login.php', ['pin' => $pin], ['X-CSRF-Token' => self::csrf($port, $jar)], $jar);
        if (!($res['json']['ok'] ?? false)) {
            self::fail("Kliens staff-login ($client) sikertelen: " . $res['body']);
        }
        return $jar;
    }

    private function stream(int $port, string $jar, string $agent = 'inventory', int $timeoutMs = 60000): array
    {
        $res = self::request($port, 'POST', '/api/ai-agent-stream.php', ['agent' => $agent, 'message' => 'Mi fogy ki?'], ['X-CSRF-Token' => self::csrf($port, $jar)], $jar, $timeoutMs);
        $events = [];
        foreach (explode("\n", $res['body']) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $events[] = json_decode(substr($line, 6), true);
            }
        }
        $res['events'] = $events;
        $done = array_values(array_filter($events, static fn ($e) => ($e['type'] ?? '') === 'done'));
        $res['done'] = $done[0] ?? null;
        return $res;
    }

    private function doneField(array $res, string $field)
    {
        $this->assertNotNull($res['done'], 'nincs done esemény: ' . $res['body']);
        return $res['done']['payload'][$field] ?? $res['done'][$field] ?? null;
    }

    // ------------------------------------------------------------------
    // AI-04 — ai-health.php
    // ------------------------------------------------------------------

    public function testCashierHealthReadGetsNoProviderMetadataAndTriggersNoOutboundCall(): void
    {
        $res = self::request(self::$port1, 'GET', '/api/ai-health.php', null, [], self::$cashierJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['enabled' => true, 'admin_only' => true], $res['json']);
        $this->assertStringNotContainsString('qwen3', $res['body']);
        $this->assertStringNotContainsString('127.0.0.1', $res['body']);
        $this->assertSame(0, $this->hits('/api/tags'));
    }

    public function testCashierCannotForceHealthCall(): void
    {
        $res = self::request(self::$port1, 'GET', '/api/ai-health.php?force=1', null, [], self::$cashierJar);

        $this->assertSame(403, $res['status']);
        $this->assertSame(0, $this->hits('/api/tags'), 'a pénztáros kérése nem indíthat külső hívást');
    }

    public function testAdminHealthReadStillReturnsProviderAndModel(): void
    {
        $res = self::request(self::$port1, 'GET', '/api/ai-health.php', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame('local', $res['json']['provider']);
        $this->assertSame('qwen3:8b', $res['json']['model']);
        $this->assertArrayNotHasKey('admin_only', $res['json']);
    }

    public function testAdminForceHealthStillPerformsTheCheck(): void
    {
        $res = self::request(self::$port1, 'GET', '/api/ai-health.php?force=1', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame('available', $res['json']['status']);
        $this->assertGreaterThanOrEqual(1, $this->hits('/api/tags'));
    }

    public function testHealthWithoutSessionIsRejected(): void
    {
        $res = self::request(self::$port1, 'GET', '/api/ai-health.php?force=1', null, [], self::jar('anon'));

        $this->assertSame(401, $res['status']);
        $this->assertSame(0, $this->hits('/api/tags'));
    }

    public function testHealthPostWithoutCsrfTokenIsRejected(): void
    {
        $res = self::request(self::$port1, 'POST', '/api/ai-health.php?force=1', [], [], self::$adminJar);

        $this->assertSame(403, $res['status']);
        $this->assertSame(0, $this->hits('/api/tags'));
    }

    public function testClientSessionHealthFollowsTheSameRolePolicy(): void
    {
        $cashier = self::clientLogin('A', self::CASHIER_PIN);
        $admin = self::clientLogin('A', self::ADMIN_PIN);
        $port = self::$clients['A']['port'];

        $cashierRead = self::request($port, 'GET', '/api/ai-health.php', null, [], $cashier);
        $cashierForce = self::request($port, 'GET', '/api/ai-health.php?force=1', null, [], $cashier);
        $this->assertSame(0, $this->hits('/api/tags'));
        $adminForce = self::request($port, 'GET', '/api/ai-health.php?force=1', null, [], $admin);

        $this->assertSame(['enabled' => true, 'admin_only' => true], $cashierRead['json']);
        $this->assertSame(403, $cashierForce['status']);
        $this->assertSame('qwen3:8b', $adminForce['json']['model'] ?? null, $adminForce['body']);
        $this->assertGreaterThanOrEqual(1, $this->hits('/api/tags'));
    }

    // ------------------------------------------------------------------
    // AI-07 — Ollama URL: vezető által beállított (ACCEPTED), séma-szűrt
    // ------------------------------------------------------------------

    public function testOllamaUrlCannotBeSetByCashierAndNonHttpSchemesAreRejected(): void
    {
        $cashier = self::request(self::$port1, 'POST', '/api/settings.php', ['ai_local_base_url' => 'http://169.254.169.254'], ['X-CSRF-Token' => self::csrf(self::$port1, self::$cashierJar)], self::$cashierJar);
        $this->assertSame(403, $cashier['status']);

        foreach (['file:///C:/Windows/win.ini', 'gopher://127.0.0.1:11434/', 'javascript:alert(1)', 'ftp://127.0.0.1/'] as $url) {
            $res = self::request(self::$port1, 'POST', '/api/settings.php', ['ai_local_base_url' => $url], ['X-CSRF-Token' => self::csrf(self::$port1, self::$adminJar)], self::$adminJar);
            $this->assertSame(400, $res['status'], $url);
        }
        $health = self::request(self::$port1, 'GET', '/api/ai-health.php?force=1', null, [], self::$adminJar);
        $this->assertSame('available', $health['json']['status'], 'a helyi Ollama URL változatlan maradt');
    }

    // ------------------------------------------------------------------
    // AI-01 — streamelt tool-keret HTTP-n
    // ------------------------------------------------------------------

    public function testStreamingRunWithThreeHundredToolCallsExecutesAtMostTheConfiguredBudget(): void
    {
        $this->setOllama(['mode' => 'burst300']);

        $res = $this->stream(self::$port1, self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame('tool_call_limit', $this->doneField($res, 'limit_reached'));
        $started = array_filter($res['events'], static fn ($e) => ($e['type'] ?? '') === 'tool_call_started');
        $this->assertCount(20, $started);
        $this->assertSame(1, $this->hits('/api/chat'));
    }

    public function testCashierCannotStartAStream(): void
    {
        $res = $this->stream(self::$port1, self::$cashierJar);

        $this->assertSame(403, $res['status']);
        $this->assertSame(0, $this->hits('/api/chat'));
    }

    // ------------------------------------------------------------------
    // AI-05 — egy szereplő párhuzamos futásai
    // ------------------------------------------------------------------

    /** @return array{0:CurlMultiHandle,1:CurlHandle} */
    private function startAsyncStream(int $port, string $jar, string $agent = 'inventory'): array
    {
        $ch = self::curlHandle($port, 'POST', '/api/ai-agent-stream.php', ['agent' => $agent, 'message' => 'Mi fogy ki?'], ['X-CSRF-Token' => self::csrf($port, $jar)], $jar, 60000);
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $deadline = microtime(true) + 10;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.05);
        } while ($this->hits('/api/chat') === 0 && microtime(true) < $deadline);
        $this->assertSame(1, $this->hits('/api/chat'), 'az első futás elindult (a slotot tartja)');
        return [$mh, $ch];
    }

    private function finishAsync(array $handles): array
    {
        [$mh, $ch] = $handles;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);
        $body = (string) curl_multi_getcontent($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        return ['status' => $status, 'body' => $body];
    }

    public function testParallelStreamsOfSameActorFromTwoSessionsOnTwoServerProcessesAreLimitedToOne(): void
    {
        $this->setOllama(['mode' => 'final', 'sleep_ms' => 2500]);
        $secondSession = self::serverLogin(self::$port2, self::ADMIN_PIN, 'admin-second');
        $first = $this->startAsyncStream(self::$port1, self::$adminJar);

        $second = $this->stream(self::$port2, $secondSession);
        $nonStream = self::request(self::$port2, 'POST', '/api/ai-inventory.php', ['message' => 'Mi fogy ki?'], ['X-CSRF-Token' => self::csrf(self::$port2, $secondSession)], $secondSession);
        $firstResult = $this->finishAsync($first);

        $this->assertSame(429, $second['status'], $second['body']);
        $this->assertStringContainsString('Már fut egy AI-kérésed', $second['body']);
        $this->assertSame(429, $nonStream['status'], 'a nem-streamelt AI-végpont ugyanazt a slotot használja');
        $this->assertSame(200, $firstResult['status']);
        $this->assertStringContainsString('"type":"done"', $firstResult['body']);
        $this->assertSame(1, $this->hits('/api/chat'), 'csak az első futás hívta a providert');
    }

    public function testDifferentAdminsCanRunInParallelOnDifferentTerminals(): void
    {
        $this->setOllama(['mode' => 'final', 'sleep_ms' => 1500]);
        $admin2 = self::serverLogin(self::$port2, self::ADMIN2_PIN, 'admin2');
        $first = $this->startAsyncStream(self::$port1, self::$adminJar);

        $second = $this->stream(self::$port2, $admin2);
        $firstResult = $this->finishAsync($first);

        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertTrue($this->doneField($second, 'success'));
        $this->assertSame(200, $firstResult['status']);
    }

    public function testSameAdminOnTwoClientTerminalsIsNotBlocked(): void
    {
        $this->setOllama(['mode' => 'final', 'sleep_ms' => 1500]);
        $onA = self::clientLogin('A', self::ADMIN_PIN);
        $onB = self::clientLogin('B', self::ADMIN_PIN);
        $first = $this->startAsyncStream(self::$clients['A']['port'], $onA);

        $second = $this->stream(self::$clients['B']['port'], $onB);
        $firstResult = $this->finishAsync($first);

        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertSame(200, $firstResult['status']);
    }

    public function testRepeatedRequestIsThrottledByExistingSettingAndWindowResets(): void
    {
        $csrf = self::csrf(self::$port1, self::$adminJar);
        self::request(self::$port1, 'POST', '/api/settings.php', ['ai_min_seconds_between_requests' => 2], ['X-CSRF-Token' => $csrf], self::$adminJar);
        try {
            usleep(2_100_000);
            $first = $this->stream(self::$port1, self::$adminJar);
            $repeat = $this->stream(self::$port1, self::$adminJar);
            usleep(2_100_000);
            $afterWindow = $this->stream(self::$port1, self::$adminJar);
        } finally {
            self::request(self::$port1, 'POST', '/api/settings.php', ['ai_min_seconds_between_requests' => 0], ['X-CSRF-Token' => self::csrf(self::$port1, self::$adminJar)], self::$adminJar);
        }

        $this->assertSame(200, $first['status']);
        $this->assertSame(429, $repeat['status']);
        $this->assertArrayHasKey('retry_after_seconds', $repeat['json']);
        $this->assertSame(200, $afterWindow['status']);
    }

    // ------------------------------------------------------------------
    // AI-09 — futás közbeni visszavonás / megszakítás
    // ------------------------------------------------------------------

    private function newAdmin(string $pin): int
    {
        return self::db()->saveStaff(['name' => 'Rem visszavonandó ' . $pin, 'pin' => $pin, 'role' => 'admin']);
    }

    public function testStaffDeactivatedDuringStreamStopsTheRun(): void
    {
        $staffId = $this->newAdmin('57213');
        $jar = self::serverLogin(self::$port1, '57213', 'victim-deact');
        $this->setOllama(['mode' => 'tool_loop', 'sql' => 'UPDATE staff SET is_active = 0 WHERE id = ?', 'params' => [$staffId], 'trigger_call' => 2]);

        $res = $this->stream(self::$port1, $jar);

        $this->assertSame('authorization_revoked', $this->doneField($res, 'failure_category'));
        $this->assertSame(2, $this->hits('/api/chat'), 'a visszavonás után nincs újabb provider-hívás');
        $started = array_filter($res['events'], static fn ($e) => ($e['type'] ?? '') === 'tool_call_started');
        $this->assertCount(1, $started, 'csak a visszavonás előtti tool futott');
    }

    public function testAdminDemotedDuringStreamStopsTheRun(): void
    {
        $staffId = $this->newAdmin('68324');
        $jar = self::serverLogin(self::$port1, '68324', 'victim-demote');
        $this->setOllama(['mode' => 'tool_loop', 'sql' => "UPDATE staff SET role = 'cashier' WHERE id = ?", 'params' => [$staffId], 'trigger_call' => 2]);

        $res = $this->stream(self::$port1, $jar, 'copilot');

        $this->assertSame('authorization_revoked', $this->doneField($res, 'failure_category'));
        $this->assertSame(2, $this->hits('/api/chat'));
    }

    public function testClientSessionDeletedDuringStreamStopsTheRun(): void
    {
        $staffId = $this->newAdmin('79435');
        $jar = self::clientLogin('A', '79435');
        $this->setOllama(['mode' => 'tool_loop', 'sql' => 'DELETE FROM client_sessions WHERE staff_id = ?', 'params' => [$staffId], 'trigger_call' => 2]);

        $res = $this->stream(self::$clients['A']['port'], $jar);

        $this->assertSame('authorization_revoked', $this->doneField($res, 'failure_category'), $res['body']);
        $this->assertSame(2, $this->hits('/api/chat'));
    }

    /** Utolsó a sorban: a B kliens-gépet véglegesen visszavonja. */
    public function testZzClientRevokedDuringStreamStopsTheRun(): void
    {
        $jar = self::clientLogin('B', self::ADMIN_PIN);
        $this->setOllama(['mode' => 'tool_loop', 'sql' => "UPDATE registered_clients SET is_active = 0, revoked_at = datetime('now') WHERE id = ?", 'params' => [self::$clients['B']['registered_client_id']], 'trigger_call' => 2]);

        $res = $this->stream(self::$clients['B']['port'], $jar);

        $this->assertSame('authorization_revoked', $this->doneField($res, 'failure_category'), $res['body']);
        $this->assertSame(2, $this->hits('/api/chat'));
        $after = $this->stream(self::$clients['B']['port'], $jar);
        $this->assertSame(401, $after['status'], 'a visszavont kliens új kérése már a bootstrapben elbukik');
    }

    public function testClientDisconnectStopsTheRunBeforeAllIterations(): void
    {
        $this->setOllama(['mode' => 'tool_loop', 'sleep_ms' => 800]);
        $jar = self::serverLogin(self::$port1, self::ADMIN_PIN, 'cancel');

        // A kliens ~1.2 s után megszakítja a kapcsolatot (böngésző: Mégse / lap bezárása).
        $res = self::request(self::$port1, 'POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'Mi fogy ki?'], ['X-CSRF-Token' => self::csrf(self::$port1, $jar)], $jar, 1200);
        usleep(6_000_000); // bőven elég lenne mind az 5 iterációra (5 × 0.8 s)

        $this->assertLessThan(5, $this->hits('/api/chat'), 'a megszakítás után a futás nem folytatódik az ai_max_iterations végéig');
        // A slot felszabadult: ugyanaz a szereplő újra indíthat.
        $this->setOllama(['mode' => 'final']);
        $this->assertSame(200, $this->stream(self::$port1, $jar)['status']);
    }
}
