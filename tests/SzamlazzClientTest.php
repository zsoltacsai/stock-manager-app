<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SzamlazzClient::modifyInvoice()/stornoInvoice() (1.1.0) — a HTTP réteg
 * itt is egy VALÓDI, KIZÁRÓLAG 127.0.0.1-en futó loopback stub-szerver
 * (ugyanaz a minta, mint InvoiceServiceTest.php-ban), ami a ténylegesen
 * beküldött POST mezőnevet és a nyers XML-tartalmat egy fájlba menti —
 * ez teszi lehetővé a POST mezőnév (action-xmlagentxmlfile vs
 * action-szamla_agent_st) és a pontos XML-mezősorrend ellenőrzését,
 * NEM csak a válasz-feldolgozást (amit InvoiceServiceTest.php már fed).
 */
final class SzamlazzClientTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_szamlazz_capture_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/capture.php', <<<'PHP'
<?php
// Minimális stub: elmenti, MELYIK POST mezőnéven és MILYEN nyers XML-
// tartalommal érkezett a kérés, majd egy sikeres Számlázz.hu Agent
// választ ad vissza — KIZÁRÓLAG loopback teszt-célra.
$captureFile = $_GET['capture_file'];
$fieldName = null;
$xml = null;
foreach ($_FILES as $name => $file) {
    $fieldName = $name;
    $xml = file_get_contents($file['tmp_name']);
}
file_put_contents($captureFile, json_encode(['field' => $fieldName, 'xml' => $xml]));
header('Content-Type: text/plain; charset=UTF-8');
echo 'xmlagentresponse=DONE;SZ-STUB-CAPTURE-1';
PHP);

        self::$stubPort = self::findFreePort();
        $logFile = self::$stubRoot . '/server.log';
        self::$stubServerProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$stubPort, '-t', self::$stubRoot],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes,
            self::$stubRoot
        );
        if (self::$stubServerProcess === false) {
            self::fail('Nem sikerült elindítani a Számlázz.hu capture-stub teszt-szervert.');
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
        self::fail('A Számlázz.hu capture-stub teszt-szerver nem indult el időben.');
    }

    private function fakeConfig(string $captureFile): array
    {
        return [
            'agent_key' => 'teszt',
            'endpoint' => 'http://127.0.0.1:' . self::$stubPort . '/capture.php?capture_file=' . urlencode($captureFile),
            'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu',
            'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'],
            'pdf_dir' => sys_get_temp_dir() . '/sm_invoice_test_pdf_' . bin2hex(random_bytes(4)),
        ];
    }

    private function captureAndDecode(): array
    {
        $captureFile = sys_get_temp_dir() . '/sm_szamlazz_capture_' . bin2hex(random_bytes(6)) . '.json';
        return [$captureFile, $this->fakeConfig($captureFile)];
    }

    private function readCapture(string $captureFile): array
    {
        $this->assertFileExists($captureFile, 'A stub-szervernek el kellett volna mentenie a kérést.');
        $decoded = json_decode((string) file_get_contents($captureFile), true);
        @unlink($captureFile);
        return $decoded;
    }

    private function sampleBuyer(): array
    {
        return ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'];
    }

    private function sampleItems(): array
    {
        return [['name' => 'Termék', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']];
    }

    // ---- modifyInvoice() ----

    public function testModifyInvoiceUsesSameFieldNameAsCreate(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $result = $client->modifyInvoice($this->sampleBuyer(), $this->sampleItems(), 'SZ-ORIG-0001', 'ext-1');

        $this->assertTrue($result['success']);
        $capture = $this->readCapture($captureFile);
        $this->assertSame('action-xmlagentxmlfile', $capture['field'], 'A MODIFY ugyanazt a POST mezőnevet használja, mint a CREATE.');
    }

    public function testModifyInvoiceSetsHelyesbitoszamlaTrueWithOriginalNumberRightAfter(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $client->modifyInvoice($this->sampleBuyer(), $this->sampleItems(), 'SZ-ORIG-0001', 'ext-1');

        $xml = $this->readCapture($captureFile)['xml'];
        $this->assertStringContainsString('<helyesbitoszamla>true</helyesbitoszamla><helyesbitettSzamlaszam>SZ-ORIG-0001</helyesbitettSzamlaszam>', $xml, 'A helyesbitettSzamlaszam-nak KÖZVETLENÜL a helyesbitoszamla UTÁN kell állnia.');
    }

    public function testModifyInvoiceFieldOrderMatchesFejlecSequence(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $client->modifyInvoice($this->sampleBuyer(), $this->sampleItems(), 'SZ-ORIG-0001', 'ext-1');

        $xml = $this->readCapture($captureFile)['xml'];
        $fejlecStart = strpos($xml, '<fejlec>');
        $fejlecEnd = strpos($xml, '</fejlec>');
        $fejlec = substr($xml, $fejlecStart, $fejlecEnd - $fejlecStart);

        $expectedOrder = ['keltDatum', 'teljesitesDatum', 'fizetesiHataridoDatum', 'fizmod', 'penznem', 'szamlaNyelve', 'megjegyzes', 'rendelesSzam', 'elolegszamla', 'vegszamla', 'helyesbitoszamla', 'helyesbitettSzamlaszam', 'dijbekero'];
        $lastPos = -1;
        foreach ($expectedOrder as $tag) {
            $pos = strpos($fejlec, "<$tag>");
            $this->assertNotFalse($pos, "Hiányzó mező a fejlecben: $tag");
            $this->assertGreaterThan($lastPos, $pos, "A(z) $tag mezőnek a várt XSD-sorrendben kell szerepelnie.");
            $lastPos = $pos;
        }
    }

    public function testCreateInvoiceStillOmitsHelyesbitettSzamlaszam(): void
    {
        // Regressziós bizonyíték: a MODIFY-hoz hozzáadott mező a sima
        // CREATE-nél TOVÁBBRA IS teljesen hiányzik, a helyesbitoszamla
        // pedig változatlanul 'false'.
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $client->createInvoice($this->sampleBuyer(), $this->sampleItems(), 'ext-1');

        $xml = $this->readCapture($captureFile)['xml'];
        $this->assertStringContainsString('<helyesbitoszamla>false</helyesbitoszamla>', $xml);
        $this->assertStringNotContainsString('helyesbitettSzamlaszam', $xml);
    }

    // ---- stornoInvoice() ----

    public function testStornoInvoiceUsesDedicatedPostFieldName(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $result = $client->stornoInvoice('SZ-ORIG-0001', 'ext-storno-1');

        $this->assertTrue($result['success']);
        $capture = $this->readCapture($captureFile);
        $this->assertSame('action-szamla_agent_st', $capture['field'], 'A STORNO KÜLÖN POST mezőnevet használ, NEM action-xmlagentxmlfile-t.');
    }

    public function testStornoInvoiceUsesXmlszamlastRootAndCarriesOriginalInvoiceNumber(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $client->stornoInvoice('SZ-ORIG-0001', 'ext-storno-1');

        $xml = $this->readCapture($captureFile)['xml'];
        $this->assertStringContainsString('<xmlszamlast', $xml, 'A STORNO gyökéreleme xmlszamlast, NEM xmlszamla.');
        $this->assertStringContainsString('<szamlaszam>SZ-ORIG-0001</szamlaszam>', $xml);
        $this->assertStringNotContainsString('<tetelek>', $xml, 'A STORNO kérésnek NINCS tetel-blokkja (nem egy új számla, hanem egy meglévő érvénytelenítése).');
    }

    public function testStornoInvoiceFejlecPrecedesNothingElseRequired(): void
    {
        [$captureFile, $cfg] = $this->captureAndDecode();
        $client = new SzamlazzClient($cfg);

        $client->stornoInvoice('SZ-ORIG-0001', 'ext-storno-1');

        $xml = $this->readCapture($captureFile)['xml'];
        $beallitasokPos = strpos($xml, '<beallitasok>');
        $fejlecPos = strpos($xml, '<fejlec>');
        $this->assertNotFalse($beallitasokPos);
        $this->assertNotFalse($fejlecPos);
        $this->assertLessThan($fejlecPos, $beallitasokPos, 'A beallitasok blokknak meg kell előznie a fejlecet.');
    }
}
