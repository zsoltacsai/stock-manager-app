<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A kör 12. pontjának VALÓDI HTTP-n bizonyított követelménye Anthropicra:
 * a Kliens SOSE hoz létre AnthropicProvider-t, SOSE tartja/látja az
 * API-kulcsot, SOSE hívja az Anthropic-végpontot közvetlenül — az
 * AI-végpont a MEGLÉVŐ ClientProxy-n keresztül megy, a tényleges
 * agent-futás (és az AnthropicProvider-hívás) a Szerveren történik. Két
 * külön `php -S` folyamat, ugyanaz a minta, mint AiClientProxyHttpTest.php
 * (Ollama) — bizonyítva, hogy a _bootstrap.php node_role-elágazása
 * providerfüggetlenül, MINDEN AI-providerre védi a Klienst, nem csak
 * Ollamára (nincs új Kliens-oldali védő kód Anthropichoz, lásd a kör 12.
 * pontja indoklása).
 */
final class AiAnthropicClientProxyHttpTest extends TestCase
{
    private static string $serverRoot;
    private static int $serverPort;
    /** @var resource */
    private static $serverProcess;

    private static string $clientRoot;
    private static int $clientPort;
    /** @var resource */
    private static $clientProcess;

    private static string $anthropicRoot;
    private static int $anthropicPort;
    /** @var resource */
    private static $anthropicProcess;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        // --- Stub "Anthropic" — csak azt méri, hányszor hívták, és milyen
        // x-api-key fejléccel — semmilyen valódi kulcs sose ér ide, ha a
        // Kliens-védelem valóban működik. ---
        self::$anthropicRoot = sys_get_temp_dir() . '/sm_ai_anthropic_proxy_' . bin2hex(random_bytes(6));
        mkdir(self::$anthropicRoot, 0775, true);
        file_put_contents(self::$anthropicRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
file_put_contents(__DIR__ . '/hits.log', date('c') . " $path key=$apiKey\n", FILE_APPEND);
if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'claude-sonnet-5']]]);
} elseif ($path === '/v1/messages') {
    echo json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'Szerver-oldali Anthropic-válasz Kliens-kérésre.']],
        'model' => 'claude-sonnet-5', 'stop_reason' => 'end_turn',
    ]);
} else {
    http_response_code(404);
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

        // --- Szerver node — VALÓDI app-másolat, node_role='server', AI
        // bekapcsolva, Anthropic-providerre állítva, a stub Anthropicra
        // mutatva. Az anthropic_base_url loopback — a settings.php
        // SSRF-kapuja miatt közvetlenül a Settings-osztályon keresztül
        // írjuk (lásd AiInventoryEndpointAnthropicHttpTest.php azonos
        // indoklása). ---
        self::$serverRoot = sys_get_temp_dir() . '/sm_ai_anthropic_proxy_server_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$serverRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$serverRoot . '/src', []);
        copy($projectRoot . '/schema.sql', self::$serverRoot . '/schema.sql');
        mkdir(self::$serverRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$serverRoot . '/config/config.php');
        mkdir(self::$serverRoot . '/data', 0775, true);
        self::$serverPort = self::findFreePort();
        file_put_contents(self::$serverRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite'], 'mysql' => []],
            'node_role' => 'server',
        ], true) . ';');
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', self::$serverRoot . '/webroot'],
            [1 => ['file', self::$serverRoot . '/server.log', 'w'], 2 => ['file', self::$serverRoot . '/server.log', 'w']],
            $pipes
        );
        self::waitForReady(self::$serverPort);

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        $db->saveStaff(['name' => 'Anthropic Proxy Teszt Admin', 'pin' => '55223', 'role' => 'admin']);
        $client = $db->registerClient('Anthropic proxy teszt kliens');
        unset($db);

        require_once $projectRoot . '/src/Settings.php';
        $settings = new Settings(self::$serverRoot . '/data/settings.json');
        $settings->save(['anthropic_base_url' => 'http://127.0.0.1:' . self::$anthropicPort]);

        // Admin munkamenet közvetlenül a Szerveren, hogy beállíthassuk az AI-t.
        $adminJar = self::$serverRoot . '/admin-cookies.txt';
        self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar);
        $csrf = self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        self::directRequest(self::$serverPort, 'POST', '/api/staff-login.php', ['pin' => '55223'], ['X-CSRF-Token' => $csrf], $adminJar);
        $csrf2 = self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        $saveRes = self::directRequest(self::$serverPort, 'POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_provider' => 'anthropic',
            'anthropic_api_key' => 'sk-ant-proxy-teszt-szerver-oldali-titkos-kulcs',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
        ], ['X-CSRF-Token' => $csrf2], $adminJar);
        if ($saveRes['status'] !== 200) {
            self::fail('Szerver AI-beállítások mentése sikertelen: ' . $saveRes['body']);
        }

        // --- Kliens node — VALÓDI app-másolat, node_role='client'. NINCS
        // saját data/settings.json, NINCS saját AI-/Anthropic-beállítás,
        // NINCS saját API-kulcs. ---
        self::$clientRoot = sys_get_temp_dir() . '/sm_ai_anthropic_proxy_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        self::$clientPort = self::findFreePort();
        file_put_contents(self::$clientRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
            'node_role' => 'client',
            'client' => ['server_url' => 'http://127.0.0.1:' . self::$serverPort, 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']],
        ], true) . ';');
        self::$clientProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$clientPort, '-t', self::$clientRoot . '/webroot'],
            [1 => ['file', self::$clientRoot . '/client.log', 'w'], 2 => ['file', self::$clientRoot . '/client.log', 'w']],
            $pipes
        );
        self::waitForReady(self::$clientPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$clientProcess, self::$anthropicProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
        self::removeDir(self::$anthropicRoot);
    }

    // -- Segédfüggvények (azonos mintával, mint AiClientProxyHttpTest.php) --

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

    private static function directRequest(int $port, string $method, string $path, $jsonBody, array $headers, string $jarPath): array
    {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) { $hdrLines[] = "$k: $v"; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $jarPath, CURLOPT_COOKIEFILE => $jarPath, CURLOPT_TIMEOUT => 10,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $hdrLines[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrLines);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $raw];
    }

    public function testClientRequestIsProxiedAndAnsweredByServerThroughAnthropic(): void
    {
        $clientJar = self::$clientRoot . '/client-cookies.txt';
        self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar);
        $csrf = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];
        self::directRequest(self::$clientPort, 'POST', '/api/staff-login.php', ['pin' => '55223'], ['X-CSRF-Token' => $csrf], $clientJar);
        $csrf2 = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];

        $res = self::directRequest(self::$clientPort, 'POST', '/api/ai-inventory.php', ['message' => 'Rendben van a készlet?'], ['X-CSRF-Token' => $csrf2], $clientJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('Szerver-oldali Anthropic-válasz Kliens-kérésre.', $res['json']['answer']);
    }

    public function testAnthropicWasOnlyEverHitByTheServerWithTheServerOwnKeyNeverConfiguredOnTheClient(): void
    {
        // A Kliens saját data/settings.json-ja SOSE jött létre — nincs
        // helyi AI-/Anthropic-beállítás, amit egyáltalán fel tudna
        // használni, tehát SOSE tudná az API-kulcsot vagy a base URL-t.
        $this->assertFileDoesNotExist(self::$clientRoot . '/data/settings.json');
        $this->assertFileDoesNotExist(self::$clientRoot . '/data');

        // A stub Anthropic ténylegesen kapott hívásokat — de ezek
        // KIZÁRÓLAG a Szerver saját folyamatától jöhettek, a Szerver saját
        // API-kulcsával (a Kliens sose ismeri ezt az értéket, azt csak a
        // Szerver settings.json-ja tartalmazza).
        $hits = @file_get_contents(self::$anthropicRoot . '/hits.log');
        $this->assertNotEmpty($hits, 'A stub Anthropicnak kapnia kellett legalább egy hívást — a Szerver oldali agent-futástól.');
        $this->assertStringContainsString('/v1/messages', $hits);
        $this->assertStringContainsString('key=sk-ant-proxy-teszt-szerver-oldali-titkos-kulcs', $hits);
    }

    public function testClientProcessSourceNeverContainsTheServerApiKey(): void
    {
        // Végső, közvetlen bizonyíték: a Kliens teljes fájlrendszeri
        // másolatában (forráskód + config, mivel data/ sose jött létre)
        // SEHOL sincs jelen a Szerver Anthropic API-kulcsa.
        $this->assertDirectoryDoesNotContainString(self::$clientRoot, 'sk-ant-proxy-teszt-szerver-oldali-titkos-kulcs');
    }

    private function assertDirectoryDoesNotContainString(string $dir, string $needle): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $contents = @file_get_contents($file->getPathname());
                if ($contents !== false) {
                    $this->assertStringNotContainsString($needle, $contents, 'megtalálva: ' . $file->getPathname());
                }
            }
        }
    }
}
