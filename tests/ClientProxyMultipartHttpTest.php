<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — multipart/form-data (fájlfeltöltés) proxyzás
 * VALÓDI HTTP-n, VALÓDI Kliens+Szerver app-másolattal, VALÓDI bináris
 * képfájlokkal bizonyítva (lásd ClientProxy::buildMultipartBody() —
 * korábban dokumentált ismert korlát, ebben a körben feloldva). A teszt
 * böngésző→Kliens hopja is VALÓDI multipart POST (CURLFile-lal), tehát a
 * teljes lánc (böngésző -> Kliens $_FILES -> ClientProxy újraépítés ->
 * Szerver $_FILES -> tényleges képfeldolgozás) végig valódi.
 */
final class ClientProxyMultipartHttpTest extends TestCase
{
    private static string $serverRoot;
    private static int $serverPort;
    /** @var resource */
    private static $serverProcess;

    private static string $clientRoot;
    private static int $clientPort;
    /** @var resource */
    private static $clientProcess;

    private static array $client;
    private static string $adminJar;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        self::$serverRoot = sys_get_temp_dir() . '/sm_multipart_server_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$serverRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$serverRoot . '/src', []);
        copy($projectRoot . '/schema.sql', self::$serverRoot . '/schema.sql');
        mkdir(self::$serverRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$serverRoot . '/config/config.php');
        file_put_contents(self::$serverRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite'], 'mysql' => []],
            'node_role' => 'server',
        ], true) . ';');
        mkdir(self::$serverRoot . '/data', 0775, true);

        self::$serverPort = self::findFreePort();
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', self::$serverRoot . '/webroot'],
            [1 => ['file', self::$serverRoot . '/server.log', 'w'], 2 => ['file', self::$serverRoot . '/server.log', 'w']],
            $pipes
        );
        self::waitForServerReady(self::$serverPort);

        // Admin böngésző-session a Szerveren (a logo-upload.php require_admin()-t kér).
        self::$adminJar = sys_get_temp_dir() . '/sm_multipart_admin_' . bin2hex(random_bytes(6)) . '.txt';
        $status = self::directRequest(self::$serverPort, 'GET', '/api/auth-status.php', null, [], self::$adminJar);
        $csrf = $status['json']['csrf_token'];
        self::directRequest(self::$serverPort, 'POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true, 'new_password' => 'multipart-teszt-jelszo', 'new_password_confirm' => 'multipart-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], self::$adminJar);
        self::directRequest(self::$serverPort, 'POST', '/api/login.php', ['password' => 'multipart-teszt-jelszo'], [], self::$adminJar);

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        self::$client = $db->registerClient('Multipart HTTP teszt kliens');

        self::$clientRoot = sys_get_temp_dir() . '/sm_multipart_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        file_put_contents(self::$clientRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
            'node_role' => 'client',
            'client' => ['server_url' => 'http://127.0.0.1:' . self::$serverPort, 'client_id' => self::$client['client_id'], 'client_secret' => self::$client['client_secret']],
        ], true) . ';');

        self::$clientPort = self::findFreePort();
        self::$clientProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$clientPort, '-t', self::$clientRoot . '/webroot'],
            [1 => ['file', self::$clientRoot . '/client.log', 'w'], 2 => ['file', self::$clientRoot . '/client.log', 'w']],
            $pipes2
        );
        self::waitForServerReady(self::$clientPort);

        // Kliens-oldali staff-login (admin PIN) — a proxyzott logo-upload.php-nak
        // is dolgozói munkamenet + CSRF kell (require_admin() a Szerveren).
        self::establishClientStaffSession($db);
    }

    private static function establishClientStaffSession(Database $db): void
    {
        $adminId = $db->saveStaff(['name' => 'Multipart Admin', 'pin' => '95173', 'role' => 'admin']);

        $client = self::$client;
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body = json_encode(['pin' => '95173']);
        $canonical = ClientHmac::canonicalString('POST', '/api/staff-login.php', $timestamp, $nonce, $body);
        $signature = ClientHmac::sign($canonical, ClientHmac::deriveSigningKey($client['client_secret']));

        $ch = curl_init('http://127.0.0.1:' . self::$clientPort . '/api/staff-login.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_COOKIEJAR => self::$clientRoot . '/client-staff-cookies.txt',
            CURLOPT_COOKIEFILE => self::$clientRoot . '/client-staff-cookies.txt',
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) {
            self::fail('A Kliens staff-login előkészítése sikertelen: ' . $res);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$clientProcess] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
        self::removeDir(sys_get_temp_dir() . '/stockmanager-client-health');
        @unlink(self::$adminJar);
    }

    private static function copyDir(string $from, string $to, array $excludeDirNames): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $excludeDirNames, true)) {
                continue;
            }
            $srcPath = $from . '/' . $item;
            $dstPath = $to . '/' . $item;
            is_dir($srcPath) ? self::copyDir($srcPath, $dstPath, $excludeDirNames) : copy($srcPath, $dstPath);
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
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::fail('Nem sikerült szabad portot találni: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForServerReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A teszt-webszerver nem indult el időben.');
    }

    private static function directRequest(int $port, string $method, string $path, $jsonBody, array $headers, string $jarPath): array
    {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "$k: $v";
        }
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

    /** Valódi, minimális, de VALÓDI GD-vel dekódolható bináris képfájl előállítása. */
    private static function makeTestImage(string $format): string
    {
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, 12, 200, 90));
        $path = tempnam(sys_get_temp_dir(), 'ftmp_') . '.' . $format;
        if ($format === 'png') {
            imagepng($img, $path);
        } elseif ($format === 'gif') {
            imagegif($img, $path);
        } else {
            imagejpeg($img, $path, 90);
        }
        imagedestroy($img);
        return $path;
    }

    private static function multipartRequestToClient(string $path, array $fields, string $cookieJar): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$clientPort . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields, // tömb -> curl SAJÁT multipart-ot épít (ez a böngésző<->Kliens hop, itt nem kritikus a pontos boundary)
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $raw];
    }

    public function testLogoUploadSucceedsThroughTheFullClientServerChain(): void
    {
        $imgPath = self::makeTestImage('png');
        try {
            $res = self::multipartRequestToClient('/api/logo-upload.php', [
                'logo' => new CURLFile($imgPath, 'image/png', 'test-logo.png'),
            ], self::$clientRoot . '/client-staff-cookies.txt');

            $this->assertSame(200, $res['status'], $res['body']);
            $this->assertNotEmpty($res['json']['logo_url'] ?? null);

            // A ténylegesen elmentett fájl a SZERVEREN jött létre — a
            // Kliens gépen SOSE (nincs saját webroot/assets-adatállománya
            // az architektúra szerint, csak a Szerver rendelkezik valós
            // állapottal).
            $savedFiles = glob(self::$serverRoot . '/webroot/assets/logo.*');
            $this->assertNotEmpty($savedFiles, 'A logónak a SZERVER assets mappájában kellett létrejönnie.');
            $this->assertGreaterThan(0, filesize($savedFiles[0]), 'A mentett logó-fájl NEM lehet üres/csonkolt.');

            // Bináris integritás — a Szerveren mentett fájlnak VALÓDI,
            // dekódolható PNG-nek kell lennie (nem csonkolt/sérült bájtfolyam).
            $decoded = @imagecreatefrompng($savedFiles[0]);
            $this->assertNotFalse($decoded, 'A Szerveren mentett képnek érvényes, dekódolható PNG-nek kell lennie.');
            if ($decoded !== false) {
                imagedestroy($decoded);
            }
        } finally {
            @unlink($imgPath);
        }
    }

    public function testProductImageUploadSucceedsThroughTheFullClientServerChainWithGif(): void
    {
        $imgPath = self::makeTestImage('gif');
        try {
            $res = self::multipartRequestToClient('/api/product-image-upload.php', [
                'image' => new CURLFile($imgPath, 'image/gif', 'test-product.gif'),
            ], self::$clientRoot . '/client-staff-cookies.txt');

            $this->assertSame(200, $res['status'], $res['body']);
            $filename = $res['json']['image_filename'] ?? null;
            $this->assertNotEmpty($filename);

            $savedPath = self::$serverRoot . '/webroot/assets/products/' . $filename;
            $this->assertFileExists($savedPath, 'A feldolgozott terméki képnek a SZERVEREN kell létrejönnie.');
            $decoded = @imagecreatefromwebp($savedPath);
            $this->assertNotFalse($decoded, 'A Szerver a GIF-et érvényes WEBP-re dolgozza fel — ennek dekódolhatónak kell lennie.');
            if ($decoded !== false) {
                $this->assertGreaterThan(0, imagesx($decoded));
                imagedestroy($decoded);
            }
        } finally {
            @unlink($imgPath);
        }
    }

    public function testUploadWithoutAFileIsRejectedJustLikeDirectTraffic(): void
    {
        // Csak szöveges mezőt küldünk, fájl NÉLKÜL — a Kliens
        // buildMultipartBody()-jának a hiányzó $_FILES-kulcsot helyesen kell
        // kezelnie (nem hibázhat el csak azért, mert nincs fájl).
        $res = self::multipartRequestToClient('/api/product-image-upload.php', [
            'unrelated_field' => 'x',
        ], self::$clientRoot . '/client-staff-cookies.txt');
        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('Nem érkezett feltöltött fájl', $res['json']['error'] ?? '');
    }
}
