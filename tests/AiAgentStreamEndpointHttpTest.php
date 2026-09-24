<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — a POST /api/ai-agent-stream.php végpont VALÓDI HTTP-n,
 * VALÓDI (kontrollált) stub-Ollamával bizonyított tesztje — ugyanaz a
 * teljesen önálló PHP beépített-szerver minta, mint tests/
 * AiInventoryEndpointHttpTest.php, kiegészítve a stub `stream:true`
 * (NDJSON) ágával.
 */
final class AiAgentStreamEndpointHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $ollamaRoot;
    private static int $ollamaPort;
    /** @var resource|null */
    private static $ollamaProcess;

    private static string $adminJar;
    private static string $staffJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ai_stream_endpoint_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        self::$ollamaRoot = sys_get_temp_dir() . '/sm_ai_stream_ollama_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/tags') {
    header('Content-Type: application/json');
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path !== '/api/chat') {
    http_response_code(404);
    echo json_encode(['error' => 'unknown']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$hasToolResult = false;
foreach ($body['messages'] ?? [] as $m) {
    if (($m['role'] ?? '') === 'tool') { $hasToolResult = true; }
}
$stream = !empty($body['stream']);

if (!$stream) {
    header('Content-Type: application/json');
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Nem-streamelt válasz.']]);
    exit;
}

header('Content-Type: application/x-ndjson');
while (ob_get_level() > 0) { ob_end_flush(); }
function emit($obj) { echo json_encode($obj) . "\n"; flush(); }

if ($hasToolResult) {
    emit(['message' => ['role' => 'assistant', 'content' => 'Kevés a készlet.']]);
    emit(['done' => true, 'prompt_eval_count' => 20, 'eval_count' => 8]);
    exit;
}

$mode = $body['messages'][1]['content'] ?? '';
if ($mode === 'trigger_tool_call') {
    emit(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
    ]]]);
    emit(['done' => true, 'prompt_eval_count' => 15, 'eval_count' => 5]);
} else {
    emit(['message' => ['role' => 'assistant', 'content' => 'Szia, ']]);
    emit(['message' => ['role' => 'assistant', 'content' => 'ez egy streamelt válasz.']]);
    emit(['done' => true, 'prompt_eval_count' => 10, 'eval_count' => 12]);
}
PHP);
        self::$ollamaPort = self::findFreePort();
        self::$ollamaProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$ollamaPort, '-t', self::$ollamaRoot],
            [1 => ['file', self::$ollamaRoot . '/log.txt', 'w'], 2 => ['file', self::$ollamaRoot . '/log.txt', 'w']],
            $pipes,
            self::$ollamaRoot
        );
        self::waitForReady(self::$ollamaPort);

        self::$port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']],
            $pipes,
            self::$root
        );
        self::waitForReady(self::$port);
        self::request('GET', '/api/auth-status.php');

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Stream Teszt Admin', 'pin' => '73412', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Stream Teszt Pénztáros', 'pin' => '28193', 'role' => 'staff']);
        $db->pdo()->exec("INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold) VALUES ('Stream teszt termék', 'db', 1000, 787, 1, 5)");
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'ai-stream-http-teszt-jelszo',
            'new_password_confirm' => 'ai-stream-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'ai-stream-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '73412'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        self::$staffJar = self::cookieJar('staff');
        self::request('POST', '/api/login.php', ['password' => 'ai-stream-http-teszt-jelszo'], [], self::$staffJar);
        $staffLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar)['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '28193'], ['X-CSRF-Token' => $staffLoginCsrf], self::$staffJar);

        $adminCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        self::request('POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
            'ai_min_seconds_between_requests' => 0,
        ], ['X-CSRF-Token' => $adminCsrf], self::$adminJar);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$ollamaProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$root);
        self::removeDir(self::$ollamaRoot);
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        if (!is_dir($from)) return;
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) continue;
            $s = "$from/$item"; $d = "$to/$item";
            is_dir($s) ? self::copyDir($s, $d, $exclude) : copy($s, $d);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) self::fail('Nem sikerült szabad portot találni: ' . $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100_000);
        }
        self::fail('A teszt-szerver nem indult el időben.');
    }

    private static function cookieJar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '.txt';
    }

    private static function request(string $method, string $path, ?array $jsonBody = null, array $extraHeaders = [], ?string $cookieJarPath = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) { $headers[] = "$k: $v"; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        if ($body === false) { self::fail('curl hiba: ' . curl_error($ch)); }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'body' => $body, 'content_type' => $contentType, 'json' => is_array($json) ? $json : null];
    }

    /** @return array<int,array{type:string,payload:array}> */
    private static function parseSseEvents(string $body): array
    {
        $events = [];
        foreach (explode("\n\n", $body) as $block) {
            $block = trim($block);
            if ($block === '' || !str_starts_with($block, 'data:')) {
                continue;
            }
            $json = trim(substr($block, 5));
            $decoded = json_decode($json, true);
            if (is_array($decoded) && isset($decoded['type'])) {
                $events[] = $decoded;
            }
        }
        return $events;
    }

    public function testStreamRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh-' . bin2hex(random_bytes(3)));
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'teszt'], [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testStreamRejectsNonAdminStaff(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar);
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'teszt'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testStreamRequiresCsrfToken(): void
    {
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'teszt'], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
    }

    public function testStreamRejectsInvalidAgent(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'nem_letezo_agent', 'message' => 'teszt'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testStreamReturnsSseContentTypeAndTextEvents(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'egyszerű kérdés'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertStringContainsString('text/event-stream', $res['content_type']);

        $events = self::parseSseEvents($res['body']);
        $types = array_column($events, 'type');
        $this->assertContains('agent_started', $types);
        $this->assertContains('text_delta', $types);
        $this->assertContains('final', $types);
        $this->assertContains('done', $types);

        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertTrue($done['payload']['success']);
        $this->assertTrue($done['payload']['streamed']);
        $this->assertSame(10, $done['payload']['usage']['input_tokens']);
        $this->assertSame(12, $done['payload']['usage']['output_tokens']);
    }

    public function testStreamWithToolCallShowsToolLifecycleEvents(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'trigger_tool_call'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $events = self::parseSseEvents($res['body']);
        $types = array_column($events, 'type');
        $this->assertContains('tool_call_started', $types);
        $this->assertContains('tool_call_completed', $types);

        $toolStarted = null;
        foreach ($events as $e) {
            if ($e['type'] === 'tool_call_started') { $toolStarted = $e; break; }
        }
        $this->assertNotNull($toolStarted);
        $this->assertSame('get_low_stock_products', $toolStarted['payload']['name']);
        $this->assertArrayNotHasKey('arguments', $toolStarted['payload'], 'A tool_call_started esemény SOSE tartalmazhatja a nyers argumentumokat.');
    }

    public function testStreamNeverLeaksProviderUrlOrCredentials(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $res = self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'egyszerű kérdés'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$adminJar);
        $this->assertStringNotContainsString((string) self::$ollamaPort, $res['body']);
        $this->assertStringNotContainsString('127.0.0.1:11434', strtolower($res['body']));
    }

    public function testHistoryEntryIsCreatedAfterStreamedRun(): void
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        self::request('POST', '/api/ai-agent-stream.php', ['agent' => 'inventory', 'message' => 'egyszerű kérdés'], ['X-CSRF-Token' => $status['json']['csrf_token']], self::$adminJar);

        $historyRes = self::request('GET', '/api/ai-history-list.php?agent=inventory', null, [], self::$adminJar);
        $historyJson = json_decode($historyRes['body'], true);
        $this->assertGreaterThanOrEqual(1, $historyJson['total']);
    }
}
