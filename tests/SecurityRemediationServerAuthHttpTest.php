<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Biztonsági audit remediáció — F-01 (Szerver szerepkörben a közvetlen API-
 * forgalom sose névtelen), F-05 (inaktivált admin MEGLÉVŐ direkt sessionje)
 * és F-03 (return-create.php ismételt sale_item_id-jű sorai). Három VALÓDI
 * app-másolat `php -S` folyamatként:
 *   - server:    node_role='server', gyári (jelszó nélküli, 'local') settings
 *   - server_pw: node_role='server', beállított app-jelszóval
 *   - standalone: node_role nélkül (változatlan viselkedés)
 */
final class SecurityRemediationServerAuthHttpTest extends TestCase
{
    private const APP_PASSWORD = 'szerver-jelszo-f01-teszt';

    /** @var array<string,string> */
    private static array $roots = [];
    /** @var array<string,int> */
    private static array $ports = [];
    /** @var array<string,resource> */
    private static array $procs = [];

    public static function setUpBeforeClass(): void
    {
        self::setUpCopy('server', ['node_role' => 'server'], null);
        self::setUpCopy('server_pw', ['node_role' => 'server'], self::APP_PASSWORD);
        self::setUpCopy('standalone', [], null);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        foreach (self::$roots as $root) {
            self::removeDir($root);
        }
    }

    // -- Segédfüggvények --

    private static function setUpCopy(string $name, array $configExtra, ?string $appPassword): void
    {
        $projectRoot = dirname(__DIR__);
        $root = sys_get_temp_dir() . '/sm_remed_auth_' . $name . '_' . bin2hex(random_bytes(5));
        self::$roots[$name] = $root;
        self::copyDir($projectRoot . '/webroot', $root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', $root . '/src', []);
        copy($projectRoot . '/schema.sql', $root . '/schema.sql');
        mkdir($root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', $root . '/config/config.php');
        mkdir($root . '/data', 0775, true);
        mkdir($root . '/invoices', 0775, true);
        file_put_contents($root . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $root . '/data/stock.sqlite'], 'mysql' => []],
        ] + $configExtra, true) . ';');
        if ($appPassword !== null) {
            (new Settings($root . '/data/settings.json'))->save([
                'app_password_hash' => password_hash($appPassword, PASSWORD_DEFAULT),
                'app_password_enabled' => true,
            ]);
        }
        new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $root . '/data/stock.sqlite']], $root);

        $port = self::findFreePort();
        self::$ports[$name] = $port;
        self::$procs[$name] = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/webroot'],
            [1 => ['file', $root . '/server.log', 'w'], 2 => ['file', $root . '/server.log', 'w']],
            $pipes,
            $root
        );
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail("A(z) $name teszt-webszerver nem indult el időben.");
    }

    private static function db(string $name): Database
    {
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$roots[$name] . '/data/stock.sqlite']], self::$roots[$name]);
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $sockName = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($sockName, strrpos($sockName, ':') + 1);
    }

    /** @param array<string,string> $headers */
    private static function request(string $name, string $method, string $path, ?array $json = null, array $headers = [], ?string $jar = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$ports[$name] . $path);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        if ($json !== null) {
            $lines[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        $raw = (string) curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $respHeaders = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $decoded = json_decode(substr($raw, $headerSize), true);
        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null, 'headers' => $respHeaders, 'body' => substr($raw, $headerSize)];
    }

    private function jar(string $label): string
    {
        return sys_get_temp_dir() . '/sm_remed_auth_jar_' . $label . '_' . bin2hex(random_bytes(4)) . '.txt';
    }

    private function csrf(string $name, string $jar): string
    {
        return (string) self::request($name, 'GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
    }

    /** App-jelszavas bejelentkezés + (opcionálisan) dolgozói PIN. */
    private function loggedInJar(string $name, ?string $pin = null): string
    {
        $jar = $this->jar($name);
        self::request($name, 'GET', '/api/auth-status.php', null, [], $jar);
        $login = self::request($name, 'POST', '/api/login.php', ['password' => self::APP_PASSWORD], [], $jar);
        $this->assertSame(200, $login['status'], $login['body']);
        if ($pin !== null) {
            $staff = self::request($name, 'POST', '/api/staff-login.php', ['pin' => $pin], ['X-CSRF-Token' => $this->csrf($name, $jar)], $jar);
            $this->assertSame(200, $staff['status'], $staff['body']);
        }
        return $jar;
    }

    // ------------------------------------------------------------------
    // F-01
    // ------------------------------------------------------------------

    public function testServerWithFactorySettingsRejectsEveryUnauthenticatedDirectApiRequest(): void
    {
        $status = self::request('server', 'GET', '/api/auth-status.php');
        $this->assertTrue($status['json']['enabled'], 'Szerver szerepkörben a hitelesítés a settings.json-tól függetlenül kötelező.');
        $this->assertFalse($status['json']['logged_in']);

        foreach (['customers-list.php', 'export-sales-csv.php', 'staff-list.php', 'settings.php', 'products.php', 'clients-list.php', 'sales-list.php', 'system-status.php'] as $script) {
            $res = self::request('server', 'GET', '/api/' . $script);
            $this->assertSame(401, $res['status'], "GET $script");
            $this->assertTrue($res['json']['auth_required'] ?? false, "GET $script");
        }

        $jar = $this->jar('server_anon');
        $csrf = $this->csrf('server', $jar);
        foreach ([
            'product-save.php' => ['name' => 'Névtelen termék', 'gross_price' => 1000, 'vat_rate' => '27'],
            'purchase-save.php' => ['items' => [['product_id' => 1, 'qty' => 500, 'unit_cost_net' => 1]]],
            'client-register.php' => ['label' => 'Névtelen kliens'],
            'staff-save.php' => ['name' => 'Névtelen admin', 'pin' => '1234', 'role' => 'admin'],
            'staff-login.php' => ['pin' => '0000'],
        ] as $script => $body) {
            $res = self::request('server', 'POST', '/api/' . $script, $body, ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(401, $res['status'], "POST $script: " . $res['body']);
        }
        $this->assertSame([], self::db('server')->listStaff(true));
        $this->assertSame([], self::db('server')->listRegisteredClients());
    }

    public function testServerWithoutConfiguredPasswordFailsClosedOnLogin(): void
    {
        $jar = $this->jar('server_nopw');
        foreach (['', 'barmi', self::APP_PASSWORD] as $password) {
            $login = self::request('server', 'POST', '/api/login.php', ['password' => $password], [], $jar);
            $this->assertNotSame(200, $login['status']);
        }
        $this->assertSame(401, self::request('server', 'GET', '/api/customers-list.php', null, [], $jar)['status']);
    }

    public function testAlternateServerPathsDoNotBypassAuthentication(): void
    {
        // Útvonal-variánsok ugyanarra a végpont-fájlra (PATH_INFO, dupla perjel, query).
        foreach (['/api/customers-list.php/extra', '/api//customers-list.php', '/api/customers-list.php?x=1'] as $path) {
            $this->assertSame(401, self::request('server', 'GET', $path)['status'], $path);
        }
        // Egy hamis X-Client-Id a HMAC-ágra tereli a kérést — ott elbukik.
        $this->assertSame(401, self::request('server', 'GET', '/api/customers-list.php', null, ['X-Client-Id' => 'cl_hamis', 'X-Client-Timestamp' => (string) time(), 'X-Client-Nonce' => str_repeat('a', 32), 'X-Client-Signature' => str_repeat('b', 64)])['status']);
        // A bejelentkezés nélkül is elérhető végpontok nem adnak ki adatot.
        $this->assertSame(401, self::request('server', 'GET', '/api/receipt-detail.php?sale_id=1')['status']);
        $this->assertSame(401, self::request('server', 'POST', '/api/webhook.php', ['line_items' => [1]])['status']);
        $this->assertSame(401, self::request('server', 'GET', '/api/auto-backup-run.php')['status']);
        // A page-template-ek is a login oldalra irányítanak.
        $page = self::request('server', 'GET', '/dashboard.php');
        $this->assertSame(302, $page['status']);
        $this->assertStringContainsString('login.html', $page['headers']['location'] ?? '');
    }

    public function testAuthenticatedServerRequestsWorkAndAdminEndpointsStillRequireAdmin(): void
    {
        $db = self::db('server_pw');
        $db->saveStaff(['name' => 'F01 Admin', 'pin' => '582047', 'role' => 'admin']);
        $db->saveStaff(['name' => 'F01 Pénztáros', 'pin' => '370915', 'role' => 'cashier']);

        $noPin = $this->loggedInJar('server_pw');
        $this->assertSame(200, self::request('server_pw', 'GET', '/api/customers-list.php', null, [], $noPin)['status']);
        $this->assertSame(403, self::request('server_pw', 'GET', '/api/clients-list.php', null, [], $noPin)['status']);

        $cashier = $this->loggedInJar('server_pw', '370915');
        $this->assertSame(200, self::request('server_pw', 'POST', '/api/product-save.php', ['name' => 'Hitelesített termék', 'gross_price' => 1000, 'vat_rate' => '27'], ['X-CSRF-Token' => $this->csrf('server_pw', $cashier)], $cashier)['status']);
        $this->assertSame(403, self::request('server_pw', 'GET', '/api/clients-list.php', null, [], $cashier)['status']);

        $admin = $this->loggedInJar('server_pw', '582047');
        $this->assertSame(200, self::request('server_pw', 'GET', '/api/clients-list.php', null, [], $admin)['status']);
    }

    public function testStandaloneBehaviorIsUnchanged(): void
    {
        $status = self::request('standalone', 'GET', '/api/auth-status.php');
        $this->assertFalse($status['json']['enabled']);
        $this->assertTrue($status['json']['logged_in']);
        $this->assertSame(200, self::request('standalone', 'GET', '/api/customers-list.php')['status']);
    }

    // ------------------------------------------------------------------
    // F-05 — direkt (böngésző) session
    // ------------------------------------------------------------------

    public function testDeactivatedAdminLosesAuthorityInExistingDirectSessionAndReactivationRestoresIt(): void
    {
        $db = self::db('server_pw');
        $ownerId = $db->saveStaff(['name' => 'F05 Owner', 'pin' => '640218', 'role' => 'admin']);
        $db->saveStaff(['name' => 'F05 Admin2', 'pin' => '851736', 'role' => 'admin']);

        $ownerJar = $this->loggedInJar('server_pw', '640218');
        $this->assertSame(200, self::request('server_pw', 'GET', '/api/clients-list.php', null, [], $ownerJar)['status']);

        $admin2Jar = $this->loggedInJar('server_pw', '851736');
        $deactivate = self::request('server_pw', 'POST', '/api/staff-save.php', ['id' => $ownerId, 'name' => 'F05 Owner', 'role' => 'admin', 'is_active' => false], ['X-CSRF-Token' => $this->csrf('server_pw', $admin2Jar)], $admin2Jar);
        $this->assertSame(200, $deactivate['status'], $deactivate['body']);

        // Ugyanaz a session — több különböző admin-végponton is.
        foreach (['clients-list.php', 'backup-list.php', 'audit-log.php'] as $script) {
            $this->assertSame(403, self::request('server_pw', 'GET', '/api/' . $script, null, [], $ownerJar)['status'], $script);
        }
        $this->assertSame(403, self::request('server_pw', 'POST', '/api/client-register.php', ['label' => 'deaktivált admin'], ['X-CSRF-Token' => $this->csrf('server_pw', $ownerJar)], $ownerJar)['status']);

        $fresh = $this->jar('f05_fresh');
        self::request('server_pw', 'GET', '/api/auth-status.php', null, [], $fresh);
        self::request('server_pw', 'POST', '/api/login.php', ['password' => self::APP_PASSWORD], [], $fresh);
        $this->assertSame(401, self::request('server_pw', 'POST', '/api/staff-login.php', ['pin' => '640218'], ['X-CSRF-Token' => $this->csrf('server_pw', $fresh)], $fresh)['status']);

        self::request('server_pw', 'POST', '/api/staff-save.php', ['id' => $ownerId, 'name' => 'F05 Owner', 'role' => 'admin', 'is_active' => true], ['X-CSRF-Token' => $this->csrf('server_pw', $admin2Jar)], $admin2Jar);
        $reactivated = $this->loggedInJar('server_pw', '640218');
        $this->assertSame(200, self::request('server_pw', 'GET', '/api/clients-list.php', null, [], $reactivated)['status']);
    }

    // ------------------------------------------------------------------
    // F-03 — return-create.php
    // ------------------------------------------------------------------

    private function saleOfOne(): array
    {
        $db = self::db('standalone');
        $productId = $db->saveProduct(['name' => 'F03 termék', 'barcode' => 'F03-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787.4, 'vat_rate' => 27]);
        $db->setStock($productId, 10);
        $sale = self::request('standalone', 'POST', '/api/sale.php', ['items' => [['product_id' => $productId, 'qty' => 1]]], ['X-CSRF-Token' => $this->csrf('standalone', $jar = $this->jar('f03'))], $jar);
        $this->assertSame(200, $sale['status'], $sale['body']);
        $saleId = (int) $sale['json']['sale_id'];
        $saleItemId = (int) $db->getSaleWithItems($saleId)['items'][0]['id'];
        return [$saleId, $saleItemId, $productId, $jar];
    }

    public function testReturnRequestWithRepeatedSaleItemIsRejectedWithoutRefundOrStockChange(): void
    {
        [$saleId, $saleItemId, $productId, $jar] = $this->saleOfOne();
        $db = self::db('standalone');
        $csrf = $this->csrf('standalone', $jar);

        foreach ([
            'háromszoros' => [['sale_item_id' => $saleItemId, 'qty' => 1], ['sale_item_id' => $saleItemId, 'qty' => 1], ['sale_item_id' => $saleItemId, 'qty' => 1]],
            'eltérő metaadat' => [['sale_item_id' => $saleItemId, 'qty' => 1, 'unit_price' => 1], ['sale_item_id' => $saleItemId, 'qty' => 1, 'unit_price' => 99999]],
            'sorrend + nulla sor' => [['sale_item_id' => $saleItemId, 'qty' => 0], ['sale_item_id' => $saleItemId, 'qty' => 1], ['sale_item_id' => (string) $saleItemId, 'qty' => '1']],
        ] as $label => $items) {
            $res = self::request('standalone', 'POST', '/api/return-create.php', ['sale_id' => $saleId, 'items' => $items], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(400, $res['status'], "$label: " . $res['body']);
        }
        $this->assertSame([], $db->getReturnedQuantitiesForSale($saleId));
        $this->assertSame(9, (int) $db->findProductById($productId)['stock_qty']);

        $valid = self::request('standalone', 'POST', '/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 1]]], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $valid['status'], $valid['body']);
        $this->assertEquals(1000, $valid['json']['total_refund']);
        $this->assertSame(10, (int) $db->findProductById($productId)['stock_qty']);

        $again = self::request('standalone', 'POST', '/api/return-create.php', ['sale_id' => $saleId, 'items' => [['sale_item_id' => $saleItemId, 'qty' => 1]]], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $again['status']);
        $this->assertSame(10, (int) $db->findProductById($productId)['stock_qty'], 'A készlet sose haladhatja meg a jogosan visszavett mennyiséget.');
    }
}
