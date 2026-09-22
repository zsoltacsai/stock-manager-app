<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Release-blocker javítás — HTTP-szintű, VALÓDI feltöltéssel bizonyított
 * tesztek a webroot/api/backup-restore.php végpontra, ugyanaz a teljesen
 * önálló, ideiglenes PHP beépített-szerver minta, mint tests/
 * CashSessionEndpointsHttpTest.php (KÜLÖN példány, saját data/ mappával —
 * SOSE nyúl az éles adatokhoz).
 *
 * Amit ez a fájl bizonyít, amit egy alacsonyabb szintű (BackupManagerTest)
 * teszt NEM tudna:
 *   - a titkosítás-felismerés VALÓBAN a feltöltött fájl TARTALMA alapján
 *     dönt, nem a $_FILES['file']['tmp_name'] (PHP-generált, sose '.enc'
 *     kiterjesztésű) nevéből — ez pontosan az eredeti hiba, amit csak egy
 *     VALÓDI HTTP-feltöltésen keresztül lehet reprodukálni/bizonyítani;
 *   - a végpont hibaválasza SOSE tartalmaz nyers kivétel-részletet,
 *     fájlrendszer-útvonalat vagy titkosítási kulcsot.
 */
final class BackupRestoreHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_backup_restore_http_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        self::$port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;

        $logFile = self::$root . '/server.log';
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes,
            self::$root
        );
        if (self::$serverProcess === false) {
            self::fail('Nem sikerült elindítani a PHP beépített szervert a teszthez.');
        }
        self::waitForServerReady();
        self::request('GET', '/api/auth-status.php');

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-backup-http',
            'new_password_confirm' => 'teszt-jelszo-backup-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-backup-http'], [], self::$loggedInJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/CashSessionEndpointsHttpTest.php pontos mintája) --

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
            if (is_dir($srcPath)) {
                self::copyDir($srcPath, $dstPath, $excludeDirNames);
            } else {
                copy($srcPath, $dstPath);
            }
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
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
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

    private static function waitForServerReady(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A teszt-webszerver nem indult el időben.');
    }

    private static function cookieJar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '.txt';
    }

    private static function request(string $method, string $path, ?array $jsonBody = null, array $extraHeaders = [], ?string $cookieJarPath = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $json = json_decode($body, true);

        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    /** Multipart feltöltés (VALÓDI CURLFile) a backup-restore.php-nak. */
    private static function uploadRestore(string $filePath, string $uploadedAsFilename): array
    {
        $csrfStatus = self::request('GET', '/api/auth-status.php', null, [], self::$loggedInJar);
        $csrf = $csrfStatus['json']['csrf_token'];

        $ch = curl_init(self::$baseUrl . '/api/backup-restore.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($filePath, 'application/octet-stream', $uploadedAsFilename),
            ],
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => self::$loggedInJar,
            CURLOPT_COOKIEFILE => self::$loggedInJar,
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

    private function freshCsrf(): string
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$loggedInJar);
        return $status['json']['csrf_token'];
    }

    private function livePdo(): PDO
    {
        return new PDO('sqlite:' . self::$root . '/data/stock.sqlite');
    }

    private function insertMarkerProduct(string $name): void
    {
        $this->livePdo()->prepare('INSERT INTO products (name) VALUES (?)')->execute([$name]);
    }

    private function productExists(string $name): bool
    {
        $stmt = $this->livePdo()->prepare('SELECT COUNT(*) FROM products WHERE name = ?');
        $stmt->execute([$name]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /** Egy VALÓDI, a teljes appal (Database bootstrap) létrehozott, sima (nem titkosított) SQLite-fájl a required-tábla-ellenőrzésnek is megfelelően. */
    private function buildPlainBackupFixture(string $markerProductName): string
    {
        $path = self::$root . '/plain-fixture-' . bin2hex(random_bytes(4)) . '.sqlite';
        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], self::$root);
        $db->pdo()->exec("INSERT INTO products (name) VALUES ('" . $markerProductName . "')");
        unset($db);
        return $path;
    }

    // -----------------------------------------------------------------
    // 1. Uploaded PLAIN (unencrypted) backup restore
    // -----------------------------------------------------------------

    public function testUploadedPlainUnencryptedBackupRestoresSuccessfully(): void
    {
        $marker = 'PLAIN-UPLOAD-MARKER-' . bin2hex(random_bytes(4));
        $fixturePath = $this->buildPlainBackupFixture($marker);

        // Szándékosan NEM '.enc'-re végződő feltöltött fájlnév — a régi,
        // hibás kód ezt mindig "nem titkosítottnak" vette (a tmp_name
        // sosem '.enc'), a JAVÍTOTT detectEncryption() a SQLITE_MAGIC
        // tartalom-aláírás alapján jut ugyanerre a (helyes) eredményre.
        $res = self::uploadRestore($fixturePath, 'sima-mentes.sqlite');

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['success'] ?? false, $res['body']);
        $this->assertTrue($this->productExists($marker), 'A sima (titkosítatlan) mentésből visszaállított terméknek meg kell jelennie az élő adatbázisban.');
    }

    // -----------------------------------------------------------------
    // 2. Uploaded ENCRYPTED backup restore, helyes kulccsal
    // -----------------------------------------------------------------

    public function testUploadedEncryptedBackupWithCorrectKeyRestoresSuccessfully(): void
    {
        $marker = 'ENCRYPTED-UPLOAD-MARKER-' . bin2hex(random_bytes(4));
        $this->insertMarkerProduct($marker);

        // VALÓDI titkosított mentés készítése a végponton keresztül — ez a
        // Szerver SAJÁT titkosítási kulcsával történik (nincs "helyes
        // kulcs" külön átadva — ez pontosan a "helyes kulccsal" eset: a
        // visszaállítás UGYANAZT a kulcsot használja, ami a mentést
        // készítette).
        $backupNow = self::request('POST', '/api/backup-now.php', [], ['X-CSRF-Token' => $this->freshCsrf()], self::$loggedInJar);
        $this->assertSame(200, $backupNow['status'], $backupNow['body']);
        $filename = $backupNow['json']['result']['filename'] ?? null;
        $this->assertIsString($filename);
        $encPath = self::$root . '/data/backups/' . $filename;
        $this->assertFileExists($encPath);

        // A markert TÖRÖLJÜK, hogy a visszaállítás hatása ténylegesen
        // megfigyelhető legyen (ne csak azért "legyen ott", mert sose lett
        // törölve).
        $this->livePdo()->exec("DELETE FROM products WHERE name = '$marker'");
        $this->assertFalse($this->productExists($marker));

        // A FELTÖLTÉSI útvonalon (nem a "válassz meglévő mentést" fájlnév-
        // választós útvonalon) küldjük vissza — ez pontosan az eredeti
        // hibát reprodukáló forgatókönyv: a $_FILES['file']['tmp_name']
        // sose '.enc' kiterjesztésű, a tartalom-alapú felismerésnek KELL
        // működnie.
        $res = self::uploadRestore($encPath, $filename);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['success'] ?? false, $res['body']);
        $this->assertTrue($this->productExists($marker), 'A feltöltött TITKOSÍTOTT mentésből is helyesen vissza kell állnia a terméknek — ez pontosan a release-blocker hiba javításának bizonyítéka.');
    }

    // -----------------------------------------------------------------
    // 3. Corrupted encrypted upload
    // -----------------------------------------------------------------

    public function testCorruptedEncryptedUploadFailsCleanlyWithoutTouchingLiveDatabase(): array
    {
        $marker = 'CORRUPT-TEST-SURVIVOR-MARKER-' . bin2hex(random_bytes(4));
        $this->insertMarkerProduct($marker);

        $backupNow = self::request('POST', '/api/backup-now.php', [], ['X-CSRF-Token' => $this->freshCsrf()], self::$loggedInJar);
        $filename = $backupNow['json']['result']['filename'] ?? null;
        $this->assertIsString($filename);
        $encPath = self::$root . '/data/backups/' . $filename;

        // A titkosított fájl VÉGÉT (a tényleges ciphertext-tartományt)
        // rontjuk el, a formátum-jelzőt (ENCRYPTED_MAGIC) és az IV/TAG-et
        // érintetlenül hagyva — így a felismerés helyesen "titkosítottnak"
        // azonosítja, de a GCM hitelesítés (tag-ellenőrzés) elbukik, ami a
        // VÁRT, biztonságos hibamód.
        $corruptPath = self::$root . '/corrupt-upload-' . bin2hex(random_bytes(4)) . '.sqlite.enc';
        $raw = file_get_contents($encPath);
        $raw = substr($raw, 0, -10) . str_repeat("\xFF", 10);
        file_put_contents($corruptPath, $raw);

        $res = self::uploadRestore($corruptPath, 'corrupt.sqlite.enc');

        $this->assertSame(500, $res['status'], $res['body']);
        $this->assertTrue($this->productExists($marker), 'Egy sikertelen (sérült) visszaállítás NEM módosíthatja az élő adatbázist.');

        return $res;
    }

    // -----------------------------------------------------------------
    // 4. Invalid upload (nem backup fájl)
    // -----------------------------------------------------------------

    public function testInvalidNonBackupUploadFailsCleanlyWithoutTouchingLiveDatabase(): array
    {
        $marker = 'INVALID-TEST-SURVIVOR-MARKER-' . bin2hex(random_bytes(4));
        $this->insertMarkerProduct($marker);

        $notABackupPath = self::$root . '/not-a-backup-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($notABackupPath, "Ez itt bizony sem nem SQLite adatbazis, sem nem titkositott mentes.\n");

        $res = self::uploadRestore($notABackupPath, 'random-file.txt');

        $this->assertSame(500, $res['status'], $res['body']);
        $this->assertTrue($this->productExists($marker), 'Egy sikertelen (érvénytelen fájl) visszaállítás NEM módosíthatja az élő adatbázist.');

        return $res;
    }

    // -----------------------------------------------------------------
    // 5. No encryption secret leakage
    // -----------------------------------------------------------------

    /**
     * @depends testCorruptedEncryptedUploadFailsCleanlyWithoutTouchingLiveDatabase
     */
    public function testFailedRestoreResponseNeverLeaksTheEncryptionKeyOrCryptographicDetail(array $corruptResponse): void
    {
        $keyPath = self::$root . '/data/.backup-encryption-key';
        $this->assertFileExists($keyPath, 'A tesztkörnyezetnek eddigre már létre kellett hoznia a titkosítási kulcsfájlt (backup-now.php hívás során).');
        $keyContent = trim((string) file_get_contents($keyPath));

        $body = $corruptResponse['body'];
        $this->assertStringNotContainsString($keyContent, $body, 'A titkosítási kulcs tartalma SOSE jelenhet meg egy API-válaszban.');
        $this->assertStringNotContainsStringIgnoringCase('aes-256-gcm', $body, 'A titkosítási algoritmus/részlet neve SOSE szivároghat ki a válaszban.');
        $this->assertStringNotContainsStringIgnoringCase('openssl', $body);
        $this->assertStringNotContainsString(self::$root, $body, 'A szerver fájlrendszer-útvonala (a tesztkörnyezet abszolút útvonala) SOSE jelenhet meg a válaszban.');
    }

    // -----------------------------------------------------------------
    // 6. No raw exception in JSON
    // -----------------------------------------------------------------

    /**
     * @depends testCorruptedEncryptedUploadFailsCleanlyWithoutTouchingLiveDatabase
     * @depends testInvalidNonBackupUploadFailsCleanlyWithoutTouchingLiveDatabase
     */
    public function testFailedRestoreResponsesContainOnlyTheGenericErrorMessage(array $corruptResponse, array $invalidResponse): void
    {
        $genericMessage = 'Váratlan szerverhiba történt. Próbáld újra, vagy értesítsd az üzemeltetőt.';

        foreach (['corrupt' => $corruptResponse, 'invalid' => $invalidResponse] as $label => $res) {
            $this->assertIsArray($res['json'], "$label válasz nem érvényes JSON: " . $res['body']);
            $this->assertSame($genericMessage, $res['json']['error'] ?? null, "$label válasz error mezőjének PONTOSAN az általános hibaüzenetnek kell lennie, semmi technikai részletnek.");

            $body = $res['body'];
            foreach (['RuntimeException', 'PDOException', 'SQLSTATE', 'Stack trace', '.php on line', 'openssl_decrypt'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "$label válasz nem tartalmazhatja ezt a nyers technikai részletet: $needle");
            }
        }
    }
}
