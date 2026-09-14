<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 1.1.1 — WooCommerceClient válasz-osztályozásának (retryable vs permanent,
 * lásd WooCommerceRequestException docblockja) VALÓDI HTTP-hívásokkal
 * bizonyított tesztje, egy KIZÁRÓLAG 127.0.0.1-en futó loopback stub-
 * szerver ellen (ugyanaz a minta, mint SzamlazzClientTest.php-ban).
 *
 * A UrlSafety::check() (lásd UrlSafetyTest.php) SZÁNDÉKOSAN elutasít
 * minden loopback/belső címet — ezért ez a teszt WooCommerceClient::request()
 * helyett a mögötte lévő, SSRF-kapun TÚLI executeRequest()-et hívja
 * KÖZVETLENÜL, Reflection-nel (lásd a metódus docblockja WooCommerceClient.php-
 * ban a teljes indoklásért) — az SSRF-védelem maga ÉRINTETLEN marad, csak a
 * válasz-osztályozási logikát teszteljük tőle függetlenül.
 */
final class WooCommerceClientTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_wc_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
// Minimális, útvonal-alapú stub WooCommerce válaszok szimulálására —
// KIZÁRÓLAG loopback teszt-célra.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
switch ($path) {
    case '/ok':
        http_response_code(200);
        echo json_encode(['id' => 1, 'stock_quantity' => 5]);
        break;
    case '/notfound':
        http_response_code(404);
        echo json_encode(['message' => 'Invalid product ID.']);
        break;
    case '/servererror':
        http_response_code(503);
        echo json_encode(['message' => 'Service temporarily unavailable.']);
        break;
    case '/malformed':
        http_response_code(200);
        echo 'ez nem { érvényes json';
        break;
    case '/slow':
        usleep(1500000);
        http_response_code(200);
        echo json_encode(['id' => 1]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['message' => 'unknown stub route']);
}
PHP);

        self::$stubPort = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$stubPort;
        $logFile = self::$stubRoot . '/server.log';
        self::$stubServerProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$stubPort, '-t', self::$stubRoot],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes,
            self::$stubRoot
        );
        if (self::$stubServerProcess === false) {
            self::fail('Nem sikerült elindítani a WooCommerce stub teszt-szervert.');
        }
        self::waitForStubReady();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$stubServerProcess !== null && is_resource(self::$stubServerProcess)) {
            proc_terminate(self::$stubServerProcess);
            proc_close(self::$stubServerProcess);
        }
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

    private static function waitForStubReady(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$stubPort, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A WooCommerce stub teszt-szerver nem indult el időben.');
    }

    /**
     * @return mixed a WooCommerceClient::executeRequest() visszatérési értéke
     */
    private function callExecuteRequest(string $path, int $timeout = 5)
    {
        $method = new ReflectionMethod(WooCommerceClient::class, 'executeRequest');
        $method->setAccessible(true);
        return $method->invoke(null, self::$baseUrl . $path, 'GET', null, $timeout, 'ck_test', 'cs_test', []);
    }

    public function testSuccessfulRequestReturnsDecodedBody(): void
    {
        $result = $this->callExecuteRequest('/ok');
        $this->assertSame(1, $result['id']);
        $this->assertSame(5, $result['stock_quantity']);
    }

    public function testHttp4xxIsClassifiedAsPermanentNotRetryable(): void
    {
        try {
            $this->callExecuteRequest('/notfound');
            $this->fail('WooCommerceRequestException-t vártunk.');
        } catch (WooCommerceRequestException $e) {
            $this->assertFalse($e->retryable, 'HTTP 4xx üzleti elutasítás — VÉGLEGES, nem újrapróbálandó.');
            $this->assertSame(404, $e->httpStatus);
            $this->assertStringContainsString('Invalid product ID', $e->getMessage());
        }
    }

    public function testHttp5xxIsClassifiedAsRetryable(): void
    {
        try {
            $this->callExecuteRequest('/servererror');
            $this->fail('WooCommerceRequestException-t vártunk.');
        } catch (WooCommerceRequestException $e) {
            $this->assertTrue($e->retryable, 'HTTP 5xx — ÁTMENETI szerverhiba, újrapróbálandó.');
            $this->assertSame(503, $e->httpStatus);
        }
    }

    public function testMalformedJsonOn2xxIsClassifiedAsRetryable(): void
    {
        try {
            $this->callExecuteRequest('/malformed');
            $this->fail('WooCommerceRequestException-t vártunk.');
        } catch (WooCommerceRequestException $e) {
            $this->assertTrue($e->retryable, 'Hibás JSON egy 2xx válaszon — nem tudható biztosan mi történt, ÁTMENETI hibaként kezelt, az updateStock() idempotens.');
        }
    }

    public function testConnectionFailureToClosedPortIsClassifiedAsRetryable(): void
    {
        // Egy szabad (senki által nem hallgatott) port — kapcsolódási hiba.
        $closedPort = self::findFreePort();
        try {
            $method = new ReflectionMethod(WooCommerceClient::class, 'executeRequest');
            $method->setAccessible(true);
            $method->invoke(null, 'http://127.0.0.1:' . $closedPort . '/x', 'GET', null, 2, 'ck', 'cs', []);
            $this->fail('WooCommerceRequestException-t vártunk.');
        } catch (WooCommerceRequestException $e) {
            $this->assertTrue($e->retryable, 'Kapcsolódási hiba — ÁTMENETI, újrapróbálandó.');
            $this->assertNull($e->httpStatus);
        }
    }

    public function testTimeoutIsClassifiedAsRetryable(): void
    {
        try {
            $this->callExecuteRequest('/slow', 1); // 1s timeout, a stub 1.5s-et alszik
            $this->fail('WooCommerceRequestException-t vártunk (időtúllépés).');
        } catch (WooCommerceRequestException $e) {
            $this->assertTrue($e->retryable, 'Időtúllépés — ÁTMENETI, újrapróbálandó.');
        }
    }
}
