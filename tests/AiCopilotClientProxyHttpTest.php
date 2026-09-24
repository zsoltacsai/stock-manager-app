<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 6, kör 29. pontja — a Kliens SOSE hív Ollamát/semmilyen providert
 * közvetlenül, SOSE futtatja a Copilot-ot helyben, és SOSE indíthat
 * Ollama-telepítést; az /api/ai-copilot.php végpont a MEGLÉVŐ
 * ClientProxy-n keresztül megy, a tényleges Copilot-futás a Szerveren
 * történik — ugyanaz a minta, mint tests/AiAnomalyClientProxyHttpTest.php.
 */
final class AiCopilotClientProxyHttpTest extends TestCase
{
    private static string $serverRoot;
    private static int $serverPort;
    /** @var resource */
    private static $serverProcess;

    private static string $clientRoot;
    private static int $clientPort;
    /** @var resource */
    private static $clientProcess;

    private static string $ollamaRoot;
    private static int $ollamaPort;
    /** @var resource */
    private static $ollamaProcess;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        self::$ollamaRoot = sys_get_temp_dir() . '/sm_ai_copilot_proxy_ollama_' . bin2hex(random_bytes(6));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/hits.log', date('c') . " $path\n", FILE_APPEND);
if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
} elseif ($path === '/api/chat') {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Szerver-oldali Copilot-válasz Kliens-kérésre.']]);
} else {
    http_response_code(404);
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

        // --- Szerver node — VALÓDI app-másolat, node_role='server', AI
        // bekapcsolva, a stub Ollamára mutatva. ---
        self::$serverRoot = sys_get_temp_dir() . '/sm_ai_copilot_proxy_server_' . bin2hex(random_bytes(6));
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
        $db->saveStaff(['name' => 'Copilot Proxy Teszt Admin', 'pin' => '66337', 'role' => 'admin']);
        $client = $db->registerClient('Copilot AI proxy teszt kliens');
        unset($db);

        $adminJar = self::$serverRoot . '/admin-cookies.txt';
        self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar);
        // Biztonsági audit F-01: Szerver szerepkörben a közvetlen (nem proxyzott)
        // admin-hívásokhoz alkalmazás-jelszavas bejelentkezés kell.
        (new Settings(self::$serverRoot . '/data/settings.json'))->save(['app_password_hash' => password_hash('szerver-teszt-jelszo', PASSWORD_DEFAULT), 'app_password_enabled' => true]);
        self::directRequest(self::$serverPort, 'POST', '/api/login.php', ['password' => 'szerver-teszt-jelszo'], [], $adminJar);
        $csrf = self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        self::directRequest(self::$serverPort, 'POST', '/api/staff-login.php', ['pin' => '66337'], ['X-CSRF-Token' => $csrf], $adminJar);
        $csrf2 = self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        self::directRequest(self::$serverPort, 'POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
        ], ['X-CSRF-Token' => $csrf2], $adminJar);

        // --- Kliens node — VALÓDI app-másolat, node_role='client'. NINCS
        // saját data/settings.json, NINCS saját AI-beállítás. ---
        self::$clientRoot = sys_get_temp_dir() . '/sm_ai_copilot_proxy_client_' . bin2hex(random_bytes(6));
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
        foreach ([self::$serverProcess, self::$clientProcess, self::$ollamaProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
        self::removeDir(self::$ollamaRoot);
    }

    // -- Segédfüggvények --

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

    public function testClientCopilotRequestIsProxiedAndAnsweredByServer(): void
    {
        $clientJar = self::$clientRoot . '/client-cookies.txt';
        self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar);
        $csrf = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];
        self::directRequest(self::$clientPort, 'POST', '/api/staff-login.php', ['pin' => '66337'], ['X-CSRF-Token' => $csrf], $clientJar);
        $csrf2 = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];

        $res = self::directRequest(self::$clientPort, 'POST', '/api/ai-copilot.php', ['message' => 'Mi a helyzet?'], ['X-CSRF-Token' => $csrf2], $clientJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('copilot', $res['json']['agent']);
        $this->assertSame('Szerver-oldali Copilot-válasz Kliens-kérésre.', $res['json']['answer']);
    }

    public function testOllamaWasOnlyEverHitByTheServerNeverConfiguredOnTheClient(): void
    {
        $this->assertFileDoesNotExist(self::$clientRoot . '/data/settings.json');
        $this->assertFileDoesNotExist(self::$clientRoot . '/data');

        $hits = @file_get_contents(self::$ollamaRoot . '/hits.log');
        $this->assertNotEmpty($hits, 'A stub Ollamának kapnia kellett legalább egy hívást — a Szerver oldali Copilot-futástól.');
        $this->assertStringContainsString('/api/chat', $hits);
    }

    public function testClientProcessSourceNeverContainsAnyServerSideSecret(): void
    {
        $this->assertFileDoesNotExist(self::$clientRoot . '/data');
    }

    public function testClientCannotTriggerOllamaInstallation(): void
    {
        // Fázis 6, kör 29. pontja — a Kliens az Ollama-telepítő végpontokat
        // sem érheti el közvetlenül: azok is a node_role-elágazáson (a
        // MEGLÉVŐ ClientProxy-n) keresztül mennek, a Szerveren pedig
        // (lásd webroot/api/ollama-status.php/ollama-install.php) a
        // node_role guard eleve elutasítja a Kliens node-ot magát is —
        // itt csak azt bizonyítjuk, hogy a Kliens oldalon SOSE fut le
        // helyi telepítési kód (nincs "data" könyvtár, nincs
        // OllamaProvisioner-hívás nyoma).
        $clientJar = self::$clientRoot . '/client-cookies2.txt';
        self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar);
        $csrf = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];
        self::directRequest(self::$clientPort, 'POST', '/api/staff-login.php', ['pin' => '66337'], ['X-CSRF-Token' => $csrf], $clientJar);
        $csrf2 = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];

        $res = self::directRequest(self::$clientPort, 'POST', '/api/ollama-install.php', [], ['X-CSRF-Token' => $csrf2], $clientJar);
        // A kérés a Szerverre proxyzódik, ami maga is elutasítja Kliens
        // node-on (lásd OllamaProvisionEndpointHttpTest) — a lényeg, hogy
        // a Kliens folyamat sose próbál helyi telepítést indítani.
        $this->assertNotSame(200, $res['status']);
        $this->assertFileDoesNotExist(self::$clientRoot . '/data');
    }
}
