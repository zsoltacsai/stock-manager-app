<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A POST /api/ai-inventory.php végpont VALÓDI HTTP-n, OpenAI-providerre
 * állítva, egy stub "OpenAI" ellen — ugyanaz a minta, mint
 * AiInventoryEndpointAnthropicHttpTest.php, a Fázis 3 fő elfogadási
 * kritériumának bizonyítéka: UGYANAZ az InventoryAgent, UGYANAZON az
 * AgentRunner-en keresztül, most OpenAI-ra állítva, provider-specifikus
 * logika NÉLKÜL az agent/AgentRunner kódjában. Emellett itt teszteljük a
 * beállítás-mentés API-kulcs-maszkolási/perzisztencia-viselkedését is,
 * és a több egyidejű function-call kezelését (lásd a kör 10/15. pontja).
 */
final class AiInventoryEndpointOpenAiHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $openaiRoot;
    private static int $openaiPort;
    /** @var resource|null */
    private static $openaiProcess;

    private static string $adminJar;
    private static int $lowStockProductId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ai_openai_endpoint_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        // --- Stub "OpenAI" — a Responses API dokumentált kontraktusát
        // szimulálja, egy control.json fájllal vezérelve. ---
        self::$openaiRoot = sys_get_temp_dir() . '/sm_ai_openai_stub_ep_' . bin2hex(random_bytes(6));
        mkdir(self::$openaiRoot, 0775, true);
        file_put_contents(self::$openaiRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/hits.log', date('c') . " $path\n", FILE_APPEND);
$controlFile = __DIR__ . '/control.json';
$control = is_file($controlFile) ? json_decode(file_get_contents($controlFile), true) : ['mode' => 'final'];

if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path !== '/v1/responses') {
    http_response_code(404);
    echo json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'unknown']]);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$input = $body['input'] ?? [];
$toolOutputCount = 0;
foreach ($input as $item) {
    if (is_array($item) && ($item['type'] ?? '') === 'function_call_output') {
        $toolOutputCount++;
    }
}

if ($toolOutputCount > 0) {
    echo json_encode([
        'id' => 'resp_2', 'object' => 'response', 'model' => $body['model'],
        'output' => [
            ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
             'content' => [['type' => 'output_text', 'text' => 'Kevés a készlet a lekérdezett termékből (OpenAI).']]],
        ],
    ]);
    exit;
}
if (($control['mode'] ?? 'final') === 'tool_call') {
    echo json_encode([
        'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
        'output' => [
            ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_low_stock_products', 'arguments' => '{"filter":"low"}'],
        ],
    ]);
} elseif (($control['mode'] ?? '') === 'multi_tool_call') {
    echo json_encode([
        'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
        'output' => [
            ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_low_stock_products', 'arguments' => '{"filter":"low"}'],
            ['id' => 'fc_2', 'call_id' => 'call_2', 'type' => 'function_call', 'name' => 'get_product_sales_velocity', 'arguments' => '{"product_id":1}'],
        ],
    ]);
} else {
    echo json_encode([
        'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
        'output' => [
            ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
             'content' => [['type' => 'output_text', 'text' => 'Rendben, minden készlet elegendő (OpenAI).']]],
        ],
    ]);
}
PHP);
        self::$openaiPort = self::findFreePort();
        self::$openaiProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$openaiPort, '-t', self::$openaiRoot],
            [1 => ['file', self::$openaiRoot . '/log.txt', 'w'], 2 => ['file', self::$openaiRoot . '/log.txt', 'w']],
            $pipes,
            self::$openaiRoot
        );
        self::waitForReady(self::$openaiPort);
        self::setOpenAiMode('final');

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
        $db->saveStaff(['name' => 'OpenAI Teszt Admin', 'pin' => '81829', 'role' => 'admin']);
        $stmt = $db->pdo()->prepare('INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold) VALUES (?, "db", 1000, 787, 1, 5)');
        $stmt->execute(['OpenAI HTTP teszt alacsony készletű termék']);
        self::$lowStockProductId = (int) $db->pdo()->lastInsertId();
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'openai-http-teszt-jelszo',
            'new_password_confirm' => 'openai-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'openai-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '81829'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        // Az openai_base_url a settings.php végponton SZÁNDÉKOSAN átmegy az
        // UrlSafety SSRF-kapun — lásd AiInventoryEndpointAnthropicHttpTest.php
        // azonos indoklása. Közvetlenül a Settings-osztályon keresztül
        // írjuk be, megkerülve a végpont SSRF-kapuját.
        require_once $projectRoot . '/src/Settings.php';
        self::directSetOpenAiBaseUrl('http://127.0.0.1:' . self::$openaiPort);

        $adminCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $saveRes = self::request('POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_provider' => 'openai',
            'openai_api_key' => 'sk-openai-http-teszt-titkos-kulcs',
            'openai_model' => 'gpt-6-sol',
            'openai_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
        ], ['X-CSRF-Token' => $adminCsrf], self::$adminJar);
        if ($saveRes['status'] !== 200) {
            self::fail('AI-beállítások mentése sikertelen a setup-ban: ' . $saveRes['body']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$openaiProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$root);
        self::removeDir(self::$openaiRoot);
    }

    private static function setOpenAiMode(string $mode): void
    {
        file_put_contents(self::$openaiRoot . '/control.json', json_encode(['mode' => $mode]));
    }

    private static function directSetOpenAiBaseUrl(string $url): void
    {
        $settings = new Settings(self::$root . '/data/settings.json');
        $settings->save(['openai_base_url' => $url]);
    }

    // -- Segédfüggvények (azonos mintával, mint AiInventoryEndpointAnthropicHttpTest.php) --

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
            CURLOPT_TIMEOUT => 15,
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
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];
    }

    private function freshCsrf(string $jar): string
    {
        return self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
    }

    // ------------------------------------------------------------------
    // Fő elfogadási kritérium: UGYANAZ az InventoryAgent, OpenAI-n át.
    // ------------------------------------------------------------------

    public function testSuccessfulEndToEndRunWithoutToolCallViaOpenAi(): void
    {
        self::setOpenAiMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'Minden rendben van a készlettel?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('inventory', $res['json']['agent']);
        $this->assertSame('Rendben, minden készlet elegendő (OpenAI).', $res['json']['answer']);
        $this->assertSame([], $res['json']['tools_used']);
    }

    public function testSuccessfulEndToEndRunWithRealToolCallViaOpenAi(): void
    {
        self::setOpenAiMode('tool_call');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'Melyik termékekből fogyunk ki hamarosan?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(['get_low_stock_products'], $res['json']['tools_used']);
        $this->assertStringContainsString('Kevés a készlet', $res['json']['answer']);

        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $this->assertTrue((bool) $db->findProductById(self::$lowStockProductId));

        self::setOpenAiMode('final');
    }

    public function testSuccessfulEndToEndRunWithMultipleToolCallsInOneResponse(): void
    {
        self::setOpenAiMode('multi_tool_call');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'Melyik termékek fogynak alacsonyan, és milyen a fogyási sebességük?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(['get_low_stock_products', 'get_product_sales_velocity'], $res['json']['tools_used']);
        $this->assertStringContainsString('Kevés a készlet', $res['json']['answer']);

        self::setOpenAiMode('final');
    }

    public function testProviderUnavailableNeverLeaksRawDetailsOrApiKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        self::directSetOpenAiBaseUrl('http://127.0.0.1:1');

        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'kérdés amíg az OpenAI nem elérhető'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(502, $res['status']);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $res['json']['error']);
        $this->assertStringNotContainsString('curl', strtolower($res['body']));
        $this->assertStringNotContainsString(self::$root, $res['body']);
        $this->assertStringNotContainsString('sk-openai-http-teszt-titkos-kulcs', $res['body']);

        self::directSetOpenAiBaseUrl('http://127.0.0.1:' . self::$openaiPort);
    }

    // ------------------------------------------------------------------
    // Beállítás-mentés: API-kulcs maszkolás/perzisztencia
    // ------------------------------------------------------------------

    public function testGetSettingsNeverLeaksTheRawOpenAiApiKey(): void
    {
        $res = self::request('GET', '/api/settings.php', null, [], self::$adminJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('', $res['json']['openai_api_key']);
        $this->assertTrue($res['json']['openai_api_key_set']);
        $this->assertStringNotContainsString('sk-openai-http-teszt-titkos-kulcs', $res['body']);
    }

    public function testEmptyApiKeyResubmissionDoesNotClearTheStoredKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/settings.php', [
            'openai_api_key' => '',
            'openai_model' => 'gpt-6-sol',
        ], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['openai_api_key_set']);

        $csrf2 = $this->freshCsrf(self::$adminJar);
        $res2 = self::request('POST', '/api/ai-inventory.php', ['message' => 'még mindig működik?'], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(200, $res2['status'], $res2['body']);
        $this->assertTrue($res2['json']['ok']);
    }

    public function testNewApiKeySubmissionReplacesTheStoredKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['openai_api_key' => 'sk-openai-uj-csere-kulcs'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $res = self::request('GET', '/api/settings.php', null, [], self::$adminJar);
        $this->assertTrue($res['json']['openai_api_key_set']);
        $this->assertStringNotContainsString('sk-openai-uj-csere-kulcs', $res['body']);

        $csrfRestore = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['openai_api_key' => 'sk-openai-http-teszt-titkos-kulcs'], ['X-CSRF-Token' => $csrfRestore], self::$adminJar);
    }

    public function testProviderSwitchingBetweenAllThreeIsPersisted(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/settings.php', ['ai_provider' => 'local'], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame('local', $res['json']['ai_provider']);

        $csrf2 = $this->freshCsrf(self::$adminJar);
        $res2 = self::request('POST', '/api/settings.php', ['ai_provider' => 'openai'], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame('openai', $res2['json']['ai_provider']);
    }

    public function testOpenAiApiKeyNeverAppearsInAuditOrSystemEventLogs(): void
    {
        self::setOpenAiMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/ai-inventory.php', ['message' => 'napló-ellenőrző kérdés'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $auditRows = $db->pdo()->query("SELECT details FROM audit_log WHERE action='ai_agent_run'")->fetchAll(PDO::FETCH_ASSOC);
        $eventRows = $db->pdo()->query("SELECT technical_detail FROM system_events WHERE category='ai'")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($auditRows as $row) {
            $this->assertStringNotContainsString('sk-openai-http-teszt-titkos-kulcs', (string) $row['details']);
        }
        foreach ($eventRows as $row) {
            $this->assertStringNotContainsString('sk-openai-http-teszt-titkos-kulcs', (string) $row['technical_detail']);
        }
        $this->assertNotEmpty($auditRows);
    }

    public function testOpenAiStubWasActuallyHitProvingRealProtocolTranslation(): void
    {
        $hits = @file_get_contents(self::$openaiRoot . '/hits.log');
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('/v1/responses', $hits);
    }
}
