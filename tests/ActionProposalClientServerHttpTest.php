<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — a kör 27/33. pontja: a Kliens SOSE hoz létre/perzisztál
 * javaslatot helyben, SOSE hajt végre jóváhagyási logikát helyben — a
 * kérés a MEGLÉVŐ ClientProxy-n keresztül a Szerverre megy, ott dől el
 * minden. UGYANAZ a valódi Kliens+Szerver két-folyamatos minta, mint
 * tests/AiClientProxyHttpTest.php (nincs szükség AI-providerre, ezek a
 * végpontok LLM-hívás nélkül dolgoznak).
 */
final class ActionProposalClientServerHttpTest extends TestCase
{
    private static string $serverRoot;
    private static int $serverPort;
    /** @var resource */
    private static $serverProcess;

    private static string $clientRoot;
    private static int $clientPort;
    /** @var resource */
    private static $clientProcess;

    private static int $pendingProposalId;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        self::$serverRoot = sys_get_temp_dir() . '/sm_proposal_proxy_server_' . bin2hex(random_bytes(6));
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
        require_once $projectRoot . '/src/Ai/ActionProposal.php';
        require_once $projectRoot . '/src/Ai/ActionProposalService.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        $db->saveStaff(['name' => 'Proxy Proposal Admin', 'pin' => '55221', 'role' => 'admin']);
        $client = $db->registerClient('Proposal proxy teszt kliens');

        $productId = $db->saveProduct(['name' => 'Proxy teszt termék', 'barcode' => 'PX-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787, 'vat_rate' => 27]);
        $db->setStock($productId, 8);
        $service = new ActionProposalService($db, []);
        $row = $service->createFromFinding([
            'type' => 'low_stock_elevated_sales', 'severity' => 'high', 'entity_type' => 'product',
            'entity_id' => $productId, 'entity_name' => 'Proxy teszt termék', 'metric' => 'qty',
            'current_value' => 12.0, 'baseline_value' => 6.0, 'change_percent' => 100.0,
            'reason_code' => 'low_stock_with_sales_uplift',
        ], 'daily_intelligence', null, null, null);
        self::$pendingProposalId = (int) $row['id'];
        unset($db);

        self::$clientRoot = sys_get_temp_dir() . '/sm_proposal_proxy_client_' . bin2hex(random_bytes(6));
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
        foreach ([self::$serverProcess, self::$clientProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
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

    private function loginOnClient(): string
    {
        $clientJar = self::$clientRoot . '/client-cookies-' . bin2hex(random_bytes(3)) . '.txt';
        self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar);
        $csrf = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $clientJar)['json']['csrf_token'];
        self::directRequest(self::$clientPort, 'POST', '/api/staff-login.php', ['pin' => '55221'], ['X-CSRF-Token' => $csrf], $clientJar);
        return $clientJar;
    }

    public function testClientListIsProxiedAndReturnsServerSideProposal(): void
    {
        $jar = $this->loginOnClient();
        $res = self::directRequest(self::$clientPort, 'GET', '/api/ai-action-proposals-list.php', null, [], $jar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame(1, $res['json']['total']);
        $this->assertSame(self::$pendingProposalId, $res['json']['proposals'][0]['id']);
    }

    public function testClientApproveIsProxiedAndAppliedOnServer(): void
    {
        $jar = $this->loginOnClient();
        $csrf = self::directRequest(self::$clientPort, 'GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];

        $res = self::directRequest(self::$clientPort, 'POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], ['X-CSRF-Token' => $csrf], $jar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('approved', $res['json']['status']);

        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        $proposal = $db->getActionProposal(self::$pendingProposalId);
        $this->assertSame('approved', $proposal['status'], 'A Szerveren TÉNYLEGESEN jóváhagyottnak kell lennie — a Kliens csak továbbította a kérést.');
    }

    public function testClientNeverHasLocalDataDirectoryOrDatabase(): void
    {
        // A Kliensnek SOSE volt saját data/ könyvtára/adatbázisa — minden
        // állapot (beleértve a javaslatokat is) KIZÁRÓLAG a Szerveren él.
        $this->assertFileDoesNotExist(self::$clientRoot . '/data');
    }
}
