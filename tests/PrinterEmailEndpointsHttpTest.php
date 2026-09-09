<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek a Phase 7 nyomtató/SMTP végpontjaira
 * (printer-test.php, smtp-test.php) és a settings.php-hoz hozzáadott
 * új mezőkre (printer_encoding validáció, smtp_password maszkolás) —
 * a tests/InvoiceEndpointsHttpTest.php pontos mintája (teljesen önálló,
 * ideiglenes másolatban futó PHP beépített-szerver). SOSE nyúl az éles
 * data/ mappához, SOSE indít valódi SMTP/nyomtató-kapcsolatot (minden
 * teszt vagy validáció-szintű 400-at vár, vagy egy garantáltan zárt
 * portra (127.0.0.1:1) próbál csatlakozni, ami gyorsan, hálózat nélkül
 * meghiúsul).
 */
final class PrinterEmailEndpointsHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_printer_email_http_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        self::copyDir($projectRoot . '/vendor', self::$root . '/vendor', []);
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
            'new_password' => 'teszt-jelszo-printer-email-http',
            'new_password_confirm' => 'teszt-jelszo-printer-email-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-printer-email-http'], [], self::$loggedInJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/InvoiceEndpointsHttpTest.php pontos mintája) --

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
            CURLOPT_TIMEOUT => 10,
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
        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $json = json_decode($body, true);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    private function csrfFor(string $jar): string
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        return $status['json']['csrf_token'];
    }

    // -----------------------------------------------------------------
    // printer-test.php
    // -----------------------------------------------------------------

    public function testPrinterTestRequiresLogin(): void
    {
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => '127.0.0.1'], [], self::cookieJar('fresh-printer'));
        $this->assertSame(401, $res['status']);
    }

    public function testPrinterTestRequiresCsrf(): void
    {
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => '127.0.0.1'], [], self::$loggedInJar);
        $this->assertSame(403, $res['status']);
    }

    public function testPrinterTestRejectsMissingIp(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => ''], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testPrinterTestRejectsGarbageIp(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => 'not a valid host!!', 'printer_port' => 9100], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testPrinterTestRejectsOutOfRangePort(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => '127.0.0.1', 'printer_port' => 999999], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testPrinterTestAcceptsValidHostnameAndFailsGracefullyOnUnreachablePort(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        // Formailag érvényes IP + garantáltan zárt port -- a kérésnek
        // TÚL kell jutnia a validáción, majd egy kapcsolódási hibával
        // (500) kell elhasalnia, NEM 400-cal.
        $res = self::request('POST', '/api/printer-test.php', ['printer_ip' => '127.0.0.1', 'printer_port' => 1], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(500, $res['status']);
        $this->assertNotEmpty($res['json']['error'] ?? null);
    }

    // -----------------------------------------------------------------
    // smtp-test.php
    // -----------------------------------------------------------------

    public function testSmtpTestRequiresLogin(): void
    {
        $res = self::request('POST', '/api/smtp-test.php', ['to_email' => 'x@example.com'], [], self::cookieJar('fresh-smtp'));
        $this->assertSame(401, $res['status']);
    }

    public function testSmtpTestRequiresCsrf(): void
    {
        $res = self::request('POST', '/api/smtp-test.php', ['to_email' => 'x@example.com'], [], self::$loggedInJar);
        $this->assertSame(403, $res['status']);
    }

    public function testSmtpTestRejectsInvalidToEmail(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/smtp-test.php', ['to_email' => 'not-an-email'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testSmtpTestRejectsMissingHost(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        // Ebben a teszt-környezetben SOSE lett SMTP host elmentve, tehát
        // host megadása nélkül a végpontnak egyértelmű 400-at kell adnia.
        $res = self::request('POST', '/api/smtp-test.php', ['to_email' => 'target@example.com'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testSmtpTestFailsGracefullyOnUnreachableHost(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/smtp-test.php', [
            'to_email' => 'target@example.com',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 1,
            'smtp_from_email' => 'sender@example.com',
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(500, $res['status']);
        $this->assertNotEmpty($res['json']['error'] ?? null);
    }

    public function testSmtpTestNeverLeaksPasswordInErrorResponse(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/smtp-test.php', [
            'to_email' => 'target@example.com',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 1,
            'smtp_password' => 'SUPER-SECRET-TEST-PW',
            'smtp_from_email' => 'sender@example.com',
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertStringNotContainsString('SUPER-SECRET-TEST-PW', $res['body']);
    }

    // -----------------------------------------------------------------
    // settings.php — smtp_password maszkolás + printer_encoding validáció
    // -----------------------------------------------------------------

    public function testSmtpPasswordIsMaskedInSettingsResponse(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        self::request('POST', '/api/settings.php', ['smtp_password' => 'another-secret-pw'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);

        $get = self::request('GET', '/api/settings.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $get['status']);
        $this->assertSame('', $get['json']['smtp_password'] ?? null, 'A nyers SMTP jelszó SOSE mehet ki a klienshez.');
        $this->assertTrue($get['json']['smtp_password_set'] ?? false);
        $this->assertStringNotContainsString('another-secret-pw', $get['body']);
    }

    public function testBlankSmtpPasswordOnSavePreservesExistingValue(): void
    {
        $csrf1 = $this->csrfFor(self::$loggedInJar);
        self::request('POST', '/api/settings.php', ['smtp_password' => 'preserved-secret'], ['X-CSRF-Token' => $csrf1], self::$loggedInJar);

        // Egy KÖVETKEZŐ mentés üres jelszó-mezővel NEM törölheti a
        // korábban elmentett SMTP jelszót (lásd webroot/api/settings.php
        // $secretFields — ugyanaz a minta, mint a többi titkos mezőnél).
        $csrf2 = $this->csrfFor(self::$loggedInJar);
        self::request('POST', '/api/settings.php', ['smtp_host' => 'smtp.example.com', 'smtp_password' => ''], ['X-CSRF-Token' => $csrf2], self::$loggedInJar);

        $get = self::request('GET', '/api/settings.php', null, [], self::$loggedInJar);
        $this->assertTrue($get['json']['smtp_password_set'] ?? false, 'Az üresen beküldött jelszó-mező NEM törölhette a korábban elmentett értéket.');
    }

    public function testInvalidPrinterEncodingIsIgnoredNotCrashed(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/settings.php', ['printer_encoding' => 'not-a-real-codepage'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $res['status'], 'Egy érvénytelen kódlap NEM okozhat szerverhibát, csak figyelmen kívül kell hagyni.');

        $get = self::request('GET', '/api/settings.php', null, [], self::$loggedInJar);
        $this->assertContains($get['json']['printer_encoding'] ?? null, ['cp852', 'cp1250', 'iso88592'], 'Egy érvénytelen kódlap-érték nem íródhat el — az utolsó ÉRVÉNYES érték kell maradjon.');
    }

    public function testValidPrinterEncodingIsSaved(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        self::request('POST', '/api/settings.php', ['printer_encoding' => 'cp1250'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);

        $get = self::request('GET', '/api/settings.php', null, [], self::$loggedInJar);
        $this->assertSame('cp1250', $get['json']['printer_encoding'] ?? null);
    }
}
