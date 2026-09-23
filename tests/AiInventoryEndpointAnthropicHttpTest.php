<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A POST /api/ai-inventory.php végpont VALÓDI HTTP-n, Anthropic-providerre
 * állítva, egy stub "Anthropic" ellen — ugyanaz a minta, mint
 * AiInventoryEndpointHttpTest.php (Ollama), a Fázis 2 fő elfogadási
 * kritériumának bizonyítéka: UGYANAZ az InventoryAgent, UGYANAZON az
 * AgentRunner-en keresztül, most Anthropicra állítva, provider-specifikus
 * logika NÉLKÜL az agent/AgentRunner kódjában. Emellett itt teszteljük a
 * beállítás-mentés API-kulcs-maszkolási/perzisztencia-viselkedését is
 * (lásd a kör 13. pontja).
 */
final class AiInventoryEndpointAnthropicHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $anthropicRoot;
    private static int $anthropicPort;
    /** @var resource|null */
    private static $anthropicProcess;

    private static string $adminJar;
    private static int $lowStockProductId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ai_anthropic_endpoint_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        // --- Stub "Anthropic" — a Messages API dokumentált kontraktusát
        // szimulálja, egy control.json fájllal vezérelve. ---
        self::$anthropicRoot = sys_get_temp_dir() . '/sm_ai_anthropic_stub_ep_' . bin2hex(random_bytes(6));
        mkdir(self::$anthropicRoot, 0775, true);
        file_put_contents(self::$anthropicRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/hits.log', date('c') . " $path\n", FILE_APPEND);
$controlFile = __DIR__ . '/control.json';
$control = is_file($controlFile) ? json_decode(file_get_contents($controlFile), true) : ['mode' => 'final'];

if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'claude-sonnet-5']]]);
    exit;
}
if ($path !== '/v1/messages') {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'unknown']]);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$messages = $body['messages'] ?? [];
$last = end($messages);
$hasToolResult = is_array($last) && ($last['role'] ?? '') === 'user' && is_array($last['content'] ?? null)
    && isset($last['content'][0]['type']) && $last['content'][0]['type'] === 'tool_result';

if ($hasToolResult) {
    echo json_encode([
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'Kevés a készlet a lekérdezett termékből (Anthropic).']],
        'model' => $body['model'], 'stop_reason' => 'end_turn',
    ]);
    exit;
}
if (($control['mode'] ?? 'final') === 'tool_call') {
    echo json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_low_stock_products', 'input' => ['filter' => 'low']]],
        'model' => $body['model'], 'stop_reason' => 'tool_use',
    ]);
} else {
    echo json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'Rendben, minden készlet elegendő (Anthropic).']],
        'model' => $body['model'], 'stop_reason' => 'end_turn',
    ]);
}
PHP);
        self::$anthropicPort = self::findFreePort();
        self::$anthropicProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$anthropicPort, '-t', self::$anthropicRoot],
            [1 => ['file', self::$anthropicRoot . '/log.txt', 'w'], 2 => ['file', self::$anthropicRoot . '/log.txt', 'w']],
            $pipes,
            self::$anthropicRoot
        );
        self::waitForReady(self::$anthropicPort);
        self::setAnthropicMode('final');

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
        $db->saveStaff(['name' => 'Anthropic Teszt Admin', 'pin' => '71828', 'role' => 'admin']);
        $stmt = $db->pdo()->prepare('INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold) VALUES (?, "db", 1000, 787, 1, 5)');
        $stmt->execute(['Anthropic HTTP teszt alacsony készletű termék']);
        self::$lowStockProductId = (int) $db->pdo()->lastInsertId();
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'anthropic-http-teszt-jelszo',
            'new_password_confirm' => 'anthropic-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'anthropic-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '71828'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        // Az anthropic_base_url a settings.php végponton SZÁNDÉKOSAN átmegy
        // az UrlSafety SSRF-kapun (lásd a kör 3. pontja: ez egy valódi,
        // külső, nyilvános API, loopback cél normál esetben SOSE
        // legitim) — a teszt loopback stub-ja emiatt a valódi végponton
        // keresztül elutasításra kerülne. Ugyanazzal az indoklással, mint
        // amivel a settings.php saját docblokkja is szétválasztja a
        // "MENTÉSKOR" (végpont) és a nyers Settings-osztály felelősségét:
        // itt közvetlenül a Settings-osztályon keresztül írjuk be (az
        // SSRF-kapu előtt), a végpontot csak a TÖBBI mezőre használjuk.
        require_once $projectRoot . '/src/Settings.php';
        self::directSetAnthropicBaseUrl('http://127.0.0.1:' . self::$anthropicPort);

        $adminCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $saveRes = self::request('POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_provider' => 'anthropic',
            'anthropic_api_key' => 'sk-ant-http-teszt-titkos-kulcs',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
        ], ['X-CSRF-Token' => $adminCsrf], self::$adminJar);
        if ($saveRes['status'] !== 200) {
            self::fail('AI-beállítások mentése sikertelen a setup-ban: ' . $saveRes['body']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$anthropicProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$root);
        self::removeDir(self::$anthropicRoot);
    }

    private static function setAnthropicMode(string $mode): void
    {
        file_put_contents(self::$anthropicRoot . '/control.json', json_encode(['mode' => $mode]));
    }

    // Lásd a setUpBeforeClass() docblokkja: az anthropic_base_url a
    // settings.php végponton át SSRF-gátolt (helyesen, hiszen éles
    // esetben mindig egy valódi külső API-ra mutat) — a teszt loopback
    // stub-ját emiatt közvetlenül a Settings-osztályon keresztül írjuk,
    // megkerülve a végpont UrlSafety-kapuját, ugyanúgy, mint más HTTP-
    // tesztek a config/installer-generated.php-t írják közvetlenül.
    private static function directSetAnthropicBaseUrl(string $url): void
    {
        $settings = new Settings(self::$root . '/data/settings.json');
        $settings->save(['anthropic_base_url' => $url]);
    }

    // -- Segédfüggvények (azonos mintával, mint AiInventoryEndpointHttpTest.php) --

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
    // Fő elfogadási kritérium: UGYANAZ az InventoryAgent, Anthropicon át.
    // ------------------------------------------------------------------

    public function testSuccessfulEndToEndRunWithoutToolCallViaAnthropic(): void
    {
        self::setAnthropicMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'Minden rendben van a készlettel?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('inventory', $res['json']['agent']);
        $this->assertSame('Rendben, minden készlet elegendő (Anthropic).', $res['json']['answer']);
        $this->assertSame([], $res['json']['tools_used']);
    }

    public function testSuccessfulEndToEndRunWithRealToolCallViaAnthropic(): void
    {
        self::setAnthropicMode('tool_call');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'Melyik termékekből fogyunk ki hamarosan?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(['get_low_stock_products'], $res['json']['tools_used']);
        $this->assertStringContainsString('Kevés a készlet', $res['json']['answer']);

        // A products tábla valóban tartalmazza az alacsony-készletű terméket
        // — az eszköz ténylegesen a VALÓDI InventoryTools/Database rétegen
        // futott le, nem egy Anthropic-specifikus másolaton.
        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $this->assertTrue((bool) $db->findProductById(self::$lowStockProductId));

        self::setAnthropicMode('final');
    }

    public function testProviderUnavailableNeverLeaksRawDetailsOrApiKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        self::directSetAnthropicBaseUrl('http://127.0.0.1:1');

        $res = self::request('POST', '/api/ai-inventory.php', ['message' => 'kérdés amíg az Anthropic nem elérhető'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(502, $res['status']);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $res['json']['error']);
        $this->assertStringNotContainsString('curl', strtolower($res['body']));
        $this->assertStringNotContainsString(self::$root, $res['body']);
        $this->assertStringNotContainsString('sk-ant-http-teszt-titkos-kulcs', $res['body']);

        self::directSetAnthropicBaseUrl('http://127.0.0.1:' . self::$anthropicPort);
    }

    // ------------------------------------------------------------------
    // Beállítás-mentés: API-kulcs maszkolás/perzisztencia (kör 3/13. pontja)
    // ------------------------------------------------------------------

    public function testGetSettingsNeverLeaksTheRawAnthropicApiKey(): void
    {
        $res = self::request('GET', '/api/settings.php', null, [], self::$adminJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('', $res['json']['anthropic_api_key']);
        $this->assertTrue($res['json']['anthropic_api_key_set']);
        $this->assertStringNotContainsString('sk-ant-http-teszt-titkos-kulcs', $res['body']);
    }

    public function testEmptyApiKeyResubmissionDoesNotClearTheStoredKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        // Egy másik mező mentése, ÜRES anthropic_api_key mellett — a
        // korábban elmentett kulcsnak életben kell maradnia.
        $res = self::request('POST', '/api/settings.php', [
            'anthropic_api_key' => '',
            'anthropic_model' => 'claude-sonnet-5',
        ], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['anthropic_api_key_set']);

        // Egy VALÓDI (a stubon átmenő) hívás bizonyítja, hogy a kulcs
        // ténylegesen megmaradt a szerveren, nem csak a jelző.
        $csrf2 = $this->freshCsrf(self::$adminJar);
        $res2 = self::request('POST', '/api/ai-inventory.php', ['message' => 'még mindig működik?'], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(200, $res2['status'], $res2['body']);
        $this->assertTrue($res2['json']['ok']);
    }

    public function testNewApiKeySubmissionReplacesTheStoredKey(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['anthropic_api_key' => 'sk-ant-uj-csere-kulcs'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $res = self::request('GET', '/api/settings.php', null, [], self::$adminJar);
        $this->assertTrue($res['json']['anthropic_api_key_set']);
        $this->assertStringNotContainsString('sk-ant-uj-csere-kulcs', $res['body']);

        // Visszaállítás a többi teszthez.
        $csrfRestore = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['anthropic_api_key' => 'sk-ant-http-teszt-titkos-kulcs'], ['X-CSRF-Token' => $csrfRestore], self::$adminJar);
    }

    public function testInvalidAiProviderValueIsSilentlyIgnoredByStrictWhitelist(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/settings.php', ['ai_provider' => 'openai_not_supported'], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $res['status']);
        // A fehérlistán kívüli érték nem kerül be — a korábban elmentett
        // 'anthropic' marad érvényben.
        $this->assertSame('anthropic', $res['json']['ai_provider']);
    }

    public function testAnthropicApiKeyNeverAppearsInAuditOrSystemEventLogs(): void
    {
        self::setAnthropicMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/ai-inventory.php', ['message' => 'napló-ellenőrző kérdés'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $auditRows = $db->pdo()->query("SELECT details FROM audit_log WHERE action='ai_agent_run'")->fetchAll(PDO::FETCH_ASSOC);
        $eventRows = $db->pdo()->query("SELECT technical_detail FROM system_events WHERE category='ai'")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($auditRows as $row) {
            $this->assertStringNotContainsString('sk-ant-http-teszt-titkos-kulcs', (string) $row['details']);
        }
        foreach ($eventRows as $row) {
            $this->assertStringNotContainsString('sk-ant-http-teszt-titkos-kulcs', (string) $row['technical_detail']);
        }
        $this->assertNotEmpty($auditRows);
    }

    public function testAnthropicStubWasActuallyHitProvingRealProtocolTranslation(): void
    {
        $hits = @file_get_contents(self::$anthropicRoot . '/hits.log');
        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('/v1/messages', $hits);
    }
}
