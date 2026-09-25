<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-07 (correctness audit) regresszió — a Számlázz.hu-számla állapotgépe
 * folyamat-összeomlás után.
 *
 * Az audit reprodukciója: claim → a folyamat a Számlázz.hu-hívás UTÁN, az
 * eredmény rögzítése ELŐTT meghal → az azonnali újrapróbálás helyesen
 * elutasított → 95 s után a claim ÚJRA megszerezhető volt, és a retry a
 * Számlázz.hu-t másodszor is meghívta volna (valós duplikált számla).
 *
 * A teszt VALÓDI folyamat-összeomlást szimulál: egy gyerek PHP-folyamat a
 * (loopback) Számlázz.hu-stub sikeres válasza után exit()-tel kilép. A
 * stub minden kérést fájlba ment — a hívásszám a duplikátum-védelem
 * közvetlen mérése. A tényleges Számlázz.hu-t SOSE hívja.
 */
final class InvoiceClaimStateMachineTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_claim_sm_' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/calls', 0775, true);
        file_put_contents(self::$root . '/fake-szamlazz.php', <<<'PHP'
<?php
// Loopback Számlázz.hu-stub: minden kérést elment (hívásszámláló), és a
// mode szerint sikeres kiállítást vagy megerősített elutasítást ad.
$dir = __DIR__ . '/calls/' . ($_GET['bucket'] ?? 'default');
@mkdir($dir, 0775, true);
$xml = isset($_FILES['action-xmlagentxmlfile']) ? file_get_contents($_FILES['action-xmlagentxmlfile']['tmp_name']) : '';
file_put_contents($dir . '/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.xml', $xml);
if (($_GET['mode'] ?? 'success') === 'reject') {
    header('szlahu_error: ' . urlencode('Teszt: elutasitva'));
    echo 'hiba';
    exit;
}
header('Content-Type: text/plain; charset=UTF-8');
echo 'xmlagentresponse=DONE;SZ-STUB-' . count(glob($dir . '/*.xml'));
PHP);
        // A "meghaló" folyamat: a valódi (stub) hívás UTÁN, az eredmény
        // rögzítése ELŐTT lép ki.
        file_put_contents(self::$root . '/crashing-invoice.php', <<<'PHP'
<?php
[$_, $projectRoot, $dbPath, $endpoint, $saleId] = $argv;
require $projectRoot . '/src/Database.php';
require $projectRoot . '/src/SzamlazzInvoiceProvider.php';
class CrashAfterCallClient extends SzamlazzClient {
    public function createInvoice(array $buyer, array $items, string $externalId = '', ?string $languageOverride = null, ?string $paymentMethodOverride = null): array {
        parent::createInvoice($buyer, $items, $externalId, $languageOverride, $paymentMethodOverride);
        exit(3); // folyamat-összeomlás a Számlázz.hu sikeres válasza után
    }
}
class CrashingProvider extends SzamlazzInvoiceProvider {
    public function __construct(private array $cfg) { parent::__construct($cfg); }
    protected function client(): SzamlazzClient { return new CrashAfterCallClient($this->cfg); }
}
$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
$cfg = ['agent_key' => 't', 'endpoint' => $endpoint, 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
    'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db',
    'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'pdf_dir' => sys_get_temp_dir()];
(new CrashingProvider($cfg))->issueSync([
    'db' => $db, 'sale_id' => (int) $saleId,
    'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'],
    'items' => [['name' => 'Tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
    'language' => null, 'payment_method' => 'Készpénz',
    'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
]);
exit(0); // ide nem juthat el
PHP);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root],
            [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']],
            $pipes,
            self::$root
        );
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A Számlázz.hu-stub nem indult el.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function endpoint(string $bucket, string $mode = 'success'): string
    {
        return 'http://127.0.0.1:' . self::$port . '/fake-szamlazz.php?mode=' . $mode . '&bucket=' . $bucket;
    }

    private function calls(string $bucket): int
    {
        return count(glob(self::$root . '/calls/' . $bucket . '/*.xml') ?: []);
    }

    private function config(string $endpoint): array
    {
        return [
            'agent_key' => 't', 'endpoint' => $endpoint, 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'pdf_dir' => sys_get_temp_dir(),
        ];
    }

    private function invoice(Database $db, int $saleId, string $endpoint): array
    {
        $service = new InvoiceService(['szamlazz' => $this->config($endpoint)], ['invoice_provider' => 'szamlazz']);
        return $service->processInvoice([
            'db' => $db, 'sale_id' => $saleId,
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'],
            'items' => [['name' => 'Tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'language' => null, 'payment_method' => 'Készpénz',
            'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
        ]);
    }

    private function dbPath(Database $db): string
    {
        $prop = new ReflectionProperty(Database::class, 'dbConfig');
        $prop->setAccessible(true);
        return $prop->getValue($db)['sqlite']['path'];
    }

    /** Valódi gyerekfolyamat, ami a Számlázz.hu-hívás után, rögzítés előtt meghal. */
    private function crashDuringInvoicing(Database $db, int $saleId, string $bucket): void
    {
        $cmd = [PHP_BINARY, self::$root . '/crashing-invoice.php', dirname(__DIR__), $this->dbPath($db), $this->endpoint($bucket), (string) $saleId];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(3, $exit, 'A gyerekfolyamatnak a hívás után kellett "meghalnia": ' . $out);
    }

    private function ageClaim(Database $db, int $saleId, int $seconds): void
    {
        $db->pdo()->prepare('UPDATE sales SET invoice_claim_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - $seconds), $saleId]);
    }

    private function mirrorStatus(Database $db, int $saleId): ?string
    {
        return $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz')['status'] ?? null;
    }

    // ------------------------------------------------------------------

    public function testNormalSuccessRecordsInvoiceAndMirror(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bucket = 'ok' . $saleId . bin2hex(random_bytes(3));

        $result = $this->invoice($db, $saleId, $this->endpoint($bucket));

        $this->assertTrue($result['success']);
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('SZ-STUB-1', $sale['szamlazz_invoice_number']);
        $this->assertNull($sale['invoice_claim_at']);
        $this->assertSame('done', $this->mirrorStatus($db, $saleId));
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testProviderRejectionIsDefinitiveAndSafelyRetryable(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bucket = 'rej' . bin2hex(random_bytes(4));

        $first = $this->invoice($db, $saleId, $this->endpoint($bucket, 'reject'));
        $this->assertFalse($first['success']);
        $this->assertArrayNotHasKey('uncertain', $first);
        $this->assertSame('invoice_failed', $db->getSaleWithItems($saleId)['status']);
        $this->assertSame('failed', $this->mirrorStatus($db, $saleId));

        // A Számlázz.hu megerősítette, hogy nem készült számla → azonnal újrapróbálható.
        $second = $this->invoice($db, $saleId, $this->endpoint($bucket));
        $this->assertTrue($second['success']);
        $this->assertSame(2, $this->calls($bucket));
    }

    public function testCrashAfterExternalCallIsNeverBlindlyRetried(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bucket = 'crash' . bin2hex(random_bytes(4));

        // 1) A folyamat a Számlázz.hu sikeres kiállítása UTÁN meghal.
        $this->crashDuringInvoicing($db, $saleId, $bucket);
        $this->assertSame(1, $this->calls($bucket), 'A külső számla létrejött.');
        $sale = $db->getSaleWithItems($saleId);
        $this->assertNull($sale['szamlazz_invoice_number'], 'A helyi rögzítés elmaradt.');
        $this->assertNotNull($sale['invoice_claim_at'], 'A foglalás ("folyamatban") megmaradt.');
        $this->assertSame('processing', $this->mirrorStatus($db, $saleId), 'A külső hívás előtt tartósan rögzített "folyamatban" tükör-sor.');

        // 2) Azonnali újrapróbálás: helyesen elutasítva.
        $immediate = $this->invoice($db, $saleId, $this->endpoint($bucket));
        $this->assertFalse($immediate['success']);
        $this->assertTrue($immediate['already_in_progress'] ?? false);
        $this->assertSame(1, $this->calls($bucket));

        // 3) Az audit pontos forgatókönyve: 95 s múlva — korábban újra
        //    megszerezhető volt. Most bizonytalan, NEM újrapróbálható.
        $this->ageClaim($db, $saleId, 95);
        $afterStale = $this->invoice($db, $saleId, $this->endpoint($bucket));
        $this->assertFalse($afterStale['success']);
        $this->assertTrue($afterStale['uncertain'] ?? false);
        $this->assertSame(1, $this->calls($bucket), 'Az elévült foglalás után sem hívható meg másodszor a Számlázz.hu.');
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('invoice_uncertain', $sale['status']);
        $this->assertNull($sale['invoice_claim_at']);
        $this->assertSame('uncertain_manual', $this->mirrorStatus($db, $saleId));

        // 4) Bizonytalan állapot: minden további próbálkozás és a claim is elutasított.
        $this->assertFalse($db->tryClaimInvoiceIssuance($saleId));
        $this->invoice($db, $saleId, $this->endpoint($bucket));
        $this->assertSame(1, $this->calls($bucket));

        // 5) Admin-helyreállítás: a Számlázz.hu-n megtalált számla rögzítése — új hívás nélkül.
        $this->assertTrue($db->resolveUncertainSzamlazzInvoice($saleId, 'SZ-STUB-1'));
        $this->assertSame('SZ-STUB-1', $db->getSaleWithItems($saleId)['szamlazz_invoice_number']);
        $this->assertSame('done', $this->mirrorStatus($db, $saleId));
        $this->assertFalse($this->invoice($db, $saleId, $this->endpoint($bucket))['success']);
        $this->assertSame(1, $this->calls($bucket), 'Egyetlen valós számla a teljes folyamat alatt.');
    }

    public function testAdminConfirmingNoInvoiceWasCreatedIsTheOnlyPathBackToRetry(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bucket = 'noinv' . bin2hex(random_bytes(4));
        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId)); // a folyamat a hívás ELŐTT meghalt
        $this->ageClaim($db, $saleId, 95);

        $this->assertSame(1, $db->markStaleInvoiceClaimsUncertain());
        $this->assertSame(0, $db->markStaleInvoiceClaimsUncertain(), 'Idempotens.');
        $this->assertSame('invoice_uncertain', $db->getSaleWithItems($saleId)['status']);
        $this->assertSame('uncertain_manual', $this->mirrorStatus($db, $saleId), 'Tükör-sor nélküli (régi) kísérletnél is látható az admin nézetben.');

        $this->assertTrue($db->resolveUncertainSzamlazzInvoice($saleId, null));
        $this->assertTrue($this->invoice($db, $saleId, $this->endpoint($bucket))['success']);
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testFreshClaimOfARunningRequestIsNotConvertedToUncertain(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId));
        $this->ageClaim($db, $saleId, 30); // egy még futó (HTTP-időkorláton belüli) kérés

        $this->assertFalse($db->tryClaimInvoiceIssuance($saleId));
        $this->assertSame(0, $db->markStaleInvoiceClaimsUncertain());
        $this->assertSame('completed', $db->getSaleWithItems($saleId)['status']);
    }

    public function testLateSuccessOfTheOriginalRequestStillWins(): void
    {
        // Egy lassú, de élő kérés a bizonytalanná tétel UTÁN mégis befejeződik:
        // a definitív siker rögzül, a bizonytalanság feloldódik.
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $this->assertTrue($db->tryClaimInvoiceIssuance($saleId));
        $this->ageClaim($db, $saleId, 95);
        $db->markStaleInvoiceClaimsUncertain();

        $db->attachInvoiceToSale($saleId, 'SZ-LATE-1', null, 'completed');
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame(['completed', 'SZ-LATE-1'], [$sale['status'], $sale['szamlazz_invoice_number']]);
    }

    public function testSaleRollbackLeavesNothingToInvoice(): void
    {
        $db = tests_new_database();
        $db->beginTransaction();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->rollBack();

        $this->assertFalse($db->tryClaimInvoiceIssuance($saleId), 'Visszagörgetett eladásra nem indulhat számlázás.');
        $this->assertNull($db->getSaleWithItems($saleId));
        $this->assertSame(0, $db->markStaleInvoiceClaimsUncertain());
    }

    public function testIdempotentReplayOfInvoicingAfterSuccessNeverCallsAgain(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bucket = 'replay' . bin2hex(random_bytes(4));
        $this->assertTrue($this->invoice($db, $saleId, $this->endpoint($bucket))['success']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->invoice($db, $saleId, $this->endpoint($bucket))['success']);
        }
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testConcurrentClaimsYieldExactlyOneWinner(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $path = $this->dbPath($db);
        $script = self::$root . '/claim-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';'
            . '$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');'
            . 'for ($i = 0; $i < 50; $i++) { try { echo $db->tryClaimInvoiceIssuance((int) $argv[2]) ? "1" : "0"; exit; } catch (Throwable $e) { usleep(20000); } } echo "E";');

        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, $path, (string) $saleId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $outputs = [];
        foreach ($procs as $i => $proc) {
            $outputs[] = trim(stream_get_contents($pipes[$i][1]));
            proc_close($proc);
        }
        $this->assertSame(1, count(array_keys($outputs, '1', true)), 'Pontosan egy folyamat foglalhat: ' . implode(',', $outputs));
        $this->assertNotContains('E', $outputs);
    }
}
