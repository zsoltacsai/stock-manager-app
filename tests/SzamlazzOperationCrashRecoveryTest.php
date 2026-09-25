<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N-4 (post-remediation audit) regresszió — Számlázz.hu MÓDOSÍTÓ/SZTORNÓ
 * művelet folyamat-összeomlás után. A B-07 (normál számla) bizonytalan-
 * állapot szemantikája, a meglévő `invoices` státuszokkal (queued →
 * processing → done | failed | uncertain_manual), párhuzamos állapotgép
 * nélkül.
 *
 * VALÓDI folyamat-összeomlás: egy gyerek PHP-folyamat a (loopback)
 * Számlázz.hu-stub SIKERES válasza után, a helyi rögzítés ELŐTT exit()-tel
 * kilép. A stub minden kérést fájlba ment — a hívásszám a duplikátum-védelem
 * közvetlen mérése. A tényleges Számlázz.hu-t SOSE hívja (nem élő teszt).
 */
final class SzamlazzOperationCrashRecoveryTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_n4_' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/calls', 0775, true);
        file_put_contents(self::$root . '/fake-szamlazz.php', <<<'PHP'
<?php
// Loopback Számlázz.hu-stub: minden kérést (bármely feltöltött XML-mezőt)
// elment, és a mode szerint sikeres kiállítást vagy elutasítást ad.
$dir = __DIR__ . '/calls/' . ($_GET['bucket'] ?? 'default');
@mkdir($dir, 0775, true);
$file = $_FILES ? reset($_FILES) : null;
$xml = $file ? file_get_contents($file['tmp_name']) : '';
file_put_contents($dir . '/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.xml', $xml);
if (($_GET['mode'] ?? 'success') === 'reject') {
    header('szlahu_error: ' . urlencode('Teszt: elutasitva'));
    echo 'hiba';
    exit;
}
header('Content-Type: text/plain; charset=UTF-8');
echo 'xmlagentresponse=DONE;SZ-STUB-' . count(glob($dir . '/*.xml'));
PHP);
        // A "meghaló" folyamat: 'after' → a stub sikeres válasza UTÁN, a
        // rögzítés ELŐTT; 'before' → a foglalás után, a külső hívás ELŐTT.
        file_put_contents(self::$root . '/crashing-operation.php', <<<'PHP'
<?php
[$_, $projectRoot, $dbPath, $endpoint, $originalId, $type, $uuid, $when] = $argv;
require $projectRoot . '/src/Database.php';
require $projectRoot . '/src/SzamlazzInvoiceProvider.php';
class CrashingClient extends SzamlazzClient {
    public static string $when = 'after';
    public function modifyInvoice(array $buyer, array $items, string $originalInvoiceNumber, string $externalId = '', ?string $languageOverride = null, ?string $paymentMethodOverride = null): array {
        if (self::$when === 'before') { exit(4); }
        parent::modifyInvoice($buyer, $items, $originalInvoiceNumber, $externalId, $languageOverride, $paymentMethodOverride);
        exit(3);
    }
    public function stornoInvoice(string $originalInvoiceNumber, string $externalId = ''): array {
        if (self::$when === 'before') { exit(4); }
        parent::stornoInvoice($originalInvoiceNumber, $externalId);
        exit(3);
    }
}
class CrashingProvider extends SzamlazzInvoiceProvider {
    public function __construct(private array $cfg) { parent::__construct($cfg); }
    protected function client(): SzamlazzClient { return new CrashingClient($this->cfg); }
}
CrashingClient::$when = $when;
$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
$cfg = ['agent_key' => 't', 'endpoint' => $endpoint, 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
    'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db',
    'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'pdf_dir' => sys_get_temp_dir()];
$original = $db->getInvoiceById((int) $originalId);
$context = [
    'buyer' => ['nev' => 'Javított Vevő', 'irsz' => '2222', 'telepules' => 'Debrecen', 'cim' => 'Fő tér 2.'],
    'items' => [['name' => 'Javított tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
    'payment_method' => 'Készpénz', 'operation_uuid' => $uuid,
    'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
];
$provider = new CrashingProvider($cfg);
$type === 'storno' ? $provider->requestStorno($db, $original, $context) : $provider->requestModification($db, $original, $context);
exit(0); // ide nem juthat el
PHP);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root], [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']], $pipes, self::$root);
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

    private function service(string $endpoint): InvoiceService
    {
        return new InvoiceService(['szamlazz' => [
            'agent_key' => 't', 'endpoint' => $endpoint, 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'pdf_dir' => sys_get_temp_dir(),
        ]], ['invoice_provider' => 'szamlazz']);
    }

    private function dbPath(Database $db): string
    {
        $prop = new ReflectionProperty(Database::class, 'dbConfig');
        $prop->setAccessible(true);
        return $prop->getValue($db)['sqlite']['path'];
    }

    private function doneOriginal(Database $db): array
    {
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->upsertInvoiceMirror($saleId, 'szamlazz', true, 'SZ-ORIG-' . $saleId, null, null, 1000.0, 270.0, 1270.0, 'HUF', null, [
            'buyer' => ['nev' => 'Eredeti Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
            'items' => [['name' => 'Eredeti tétel', 'qty' => 2, 'unit_price_gross' => 635.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
        ]);
        return $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
    }

    private function crash(Database $db, int $originalId, string $type, string $bucket, string $when = 'after', string $uuid = 'n4-uuid-1'): void
    {
        $cmd = [PHP_BINARY, self::$root . '/crashing-operation.php', dirname(__DIR__), $this->dbPath($db), $this->endpoint($bucket), (string) $originalId, $type, $uuid, $when];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame($when === 'after' ? 3 : 4, $exit, 'A gyerekfolyamatnak "meg kellett halnia": ' . $out);
    }

    private function operation(Database $db, int $originalId): array
    {
        $ops = $db->getInvoiceOperationsForOriginal($originalId);
        $this->assertCount(1, $ops, 'Pontosan egy művelet-sor.');
        return $ops[0];
    }

    private function ageLock(Database $db, int $opId, int $seconds): void
    {
        $at = date('Y-m-d H:i:s', time() - $seconds);
        $db->pdo()->prepare('UPDATE invoices SET locked_at = ?, updated_at = ? WHERE id = ?')->execute([$at, $at, $opId]);
    }

    private function newModification(InvoiceService $service, Database $db, int $originalId, string $uuid): array
    {
        return $service->requestModification($db, $originalId, [['name' => 'Új tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            ['nev' => 'Új Vevő', 'irsz' => '3333', 'telepules' => 'Szeged', 'cim' => 'Kárász u. 1.'], 'Készpénz', $uuid, true);
    }

    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function operationTypes(): array
    {
        return ['modification' => ['modification'], 'storno' => ['storno']];
    }

    /** @dataProvider operationTypes */
    public function testCrashAfterSuccessfulExternalCallIsNeverRetriedBlindly(string $type): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $originalId = (int) $original['id'];
        $bucket = 'crash-' . $type . '-' . bin2hex(random_bytes(4));
        $service = $this->service($this->endpoint($bucket));

        // 1) A Számlázz.hu kiállította a módosító/sztornó számlát, a folyamat
        //    a helyi rögzítés ELŐTT meghalt.
        $this->crash($db, $originalId, $type, $bucket);
        $this->assertSame(1, $this->calls($bucket), 'A külső számla létrejött.');
        $op = $this->operation($db, $originalId);
        $this->assertSame('processing', $op['status'], 'A külső hívás előtt tartósan rögzített foglalás.');
        $this->assertNull($op['invoice_number']);

        // 2) Azonnali újrapróbálás minden úton: elutasítva, NINCS második külső hívás.
        $this->assertFalse($this->newModification($service, $db, $originalId, 'n4-uuid-2')['success'], 'Új operation_uuid sem kerülheti meg.');
        $this->assertFalse($service->requestStorno($db, $originalId, true)['success']);
        $this->assertFalse($this->newModification($service, $db, $originalId, 'n4-uuid-1')['success'], 'Ugyanaz az operation_uuid.');
        $this->assertFalse($service->retrySzamlazzOperation($db, (int) $op['id'])['success'], 'A folyamatban lévő sor nem indítható újra.');
        $this->assertFalse($db->resetInvoiceForManualRetry((int) $op['id']), 'A folyamatban lévő sor nem állítható vissza várakozóra.');
        $this->assertSame(1, $this->calls($bucket));

        // 3) Az elévült foglalás (95 s) bizonytalanná válik — NEM újrapróbálható.
        $this->ageLock($db, (int) $op['id'], 95);
        $afterStale = $this->newModification($service, $db, $originalId, 'n4-uuid-3');
        $this->assertFalse($afterStale['success']);
        if ($type === 'storno') {
            // A (meglévő) folyamatban lévő sztornó-blokk előbb elutasít; az
            // elévült foglalást a lista/részletek megnyitása teszi bizonytalanná
            // (invoices-list.php / invoice-detail.php ugyanezt hívja).
            $this->assertStringContainsString('sztornó', $afterStale['error']);
            $this->assertSame(1, $db->markStaleSzamlazzOperationsUncertain());
        } else {
            $this->assertStringContainsString('bizonytalan', $afterStale['error']);
        }
        $this->assertSame('uncertain_manual', $db->getInvoiceById((int) $op['id'])['status']);
        $this->assertFalse($db->claimInvoiceOperationForExecution((int) $op['id']), 'Bizonytalan sor nem foglalható újra.');
        $this->assertFalse($service->retrySzamlazzOperation($db, (int) $op['id'])['success']);
        $this->assertFalse($service->requestStorno($db, $originalId, true)['success']);
        $this->assertSame(1, $this->calls($bucket), 'Egyetlen valós külső számla — nincs vak duplikátum.');

        // 4) Admin: a Számlázz.hu-n megtalált számlaszám rögzítése — új hívás nélkül.
        $this->assertTrue($db->resolveUncertainInvoiceOperation((int) $op['id'], 'SZ-STUB-1'));
        $resolved = $db->getInvoiceById((int) $op['id']);
        $this->assertSame(['done', 'SZ-STUB-1'], [$resolved['status'], $resolved['invoice_number']]);
        $this->assertFalse($db->resolveUncertainInvoiceOperation((int) $op['id'], 'SZ-OTHER'), 'A feloldás egyszeri.');
        $this->assertFalse($db->invoiceHasUnresolvedSzamlazzOperation($originalId));
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testCrashBeforeTheCallThenAdminConfirmsNotIssuedAndOnlyThenOneCallHappens(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $originalId = (int) $original['id'];
        $bucket = 'before-' . bin2hex(random_bytes(4));
        $service = $this->service($this->endpoint($bucket));

        $this->crash($db, $originalId, 'modification', $bucket, 'before');
        $this->assertSame(0, $this->calls($bucket));
        $op = $this->operation($db, $originalId);
        $this->ageLock($db, (int) $op['id'], 95);
        $this->assertSame(1, $db->markStaleSzamlazzOperationsUncertain());
        $this->assertSame(0, $db->markStaleSzamlazzOperationsUncertain(), 'Idempotens.');
        $this->assertSame('uncertain_manual', $db->getInvoiceById((int) $op['id'])['status'], 'Innen sem tudható, volt-e hívás — bizonytalan.');

        // Az admin a Számlázz.hu-n ellenőrizte: NEM készült számla → csak ekkor
        // (a szamlazz-operation-retry.php confirm_not_issued ágával azonos lépések).
        $this->assertTrue($db->resetInvoiceForManualRetry((int) $op['id']));
        $result = $service->retrySzamlazzOperation($db, (int) $op['id']);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(1, $this->calls($bucket));
        $this->assertSame('done', $db->getInvoiceById((int) $op['id'])['status']);
        $this->assertFalse($service->retrySzamlazzOperation($db, (int) $op['id'])['success'], 'Kész sor nem indítható újra.');
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testStuckQueuedOperationBecomesUncertainInsteadOfPendingForever(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $row = $db->createInvoiceOperation([
            'sale_id' => (int) $original['sale_id'], 'provider' => 'szamlazz', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':stuck',
            'net_total' => 1000.0, 'vat_total' => 270.0, 'gross_total' => 1270.0, 'currency' => 'HUF', 'payload' => ['original_invoice_number' => 'x'],
        ]);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(0, $db->markStaleSzamlazzOperationsUncertain(), 'Egy friss várakozó sor még nem bizonytalan.');
        $this->assertTrue($db->invoiceHasUnresolvedSzamlazzOperation((int) $original['id']));

        $db->pdo()->prepare('UPDATE invoices SET updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 95), (int) $row['id']]);
        $this->assertSame(1, $db->markStaleSzamlazzOperationsUncertain());
        $this->assertSame('uncertain_manual', $db->getInvoiceById((int) $row['id'])['status']);
        $this->assertFalse($db->claimInvoiceOperationForExecution((int) $row['id']));
    }

    public function testFreshProcessingOfARunningRequestIsNotConvertedToUncertain(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $row = $db->createInvoiceOperation([
            'sale_id' => (int) $original['sale_id'], 'provider' => 'szamlazz', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'storno:' . $original['id'],
            'net_total' => -1000.0, 'vat_total' => -270.0, 'gross_total' => -1270.0, 'currency' => 'HUF', 'payload' => ['original_invoice_number' => 'x'],
        ]);
        $this->assertTrue($db->claimInvoiceOperationForExecution((int) $row['id']));
        $this->assertFalse($db->claimInvoiceOperationForExecution((int) $row['id']), 'Második foglalás nem nyerhet.');
        $this->ageLock($db, (int) $row['id'], 30);
        $this->assertSame(0, $db->markStaleSzamlazzOperationsUncertain());
        $this->assertSame('processing', $db->getInvoiceById((int) $row['id'])['status']);
    }

    public function testConfirmedRejectionIsFailedAndSafelyRetryable(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $bucket = 'rej-' . bin2hex(random_bytes(4));

        $first = $this->newModification($this->service($this->endpoint($bucket, 'reject')), $db, (int) $original['id'], 'n4-rej-1');
        $this->assertFalse($first['success']);
        $this->assertArrayNotHasKey('uncertain', $first);
        $op = $this->operation($db, (int) $original['id']);
        $this->assertSame('failed', $op['status']);
        $this->assertFalse($db->invoiceHasUnresolvedSzamlazzOperation((int) $original['id']), 'Megerősített elutasítás: nem blokkol.');

        $this->assertTrue($db->resetInvoiceForManualRetry((int) $op['id']));
        $this->assertTrue($this->service($this->endpoint($bucket))->retrySzamlazzOperation($db, (int) $op['id'])['success']);
        $this->assertSame(2, $this->calls($bucket));
    }

    public function testNormalSuccessStillWorksAndReplayNeverCallsAgain(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $bucket = 'ok-' . bin2hex(random_bytes(4));
        $service = $this->service($this->endpoint($bucket));

        $result = $this->newModification($service, $db, (int) $original['id'], 'n4-ok');
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('done', $this->operation($db, (int) $original['id'])['status']);
        $this->assertFalse($this->newModification($service, $db, (int) $original['id'], 'n4-ok')['success'], 'Ugyanaz az operation_uuid: nincs második hívás.');
        $this->assertSame(1, $this->calls($bucket));
    }

    public function testConcurrentExecutionClaimsYieldExactlyOneWinnerAcrossProcesses(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginal($db);
        $row = $db->createInvoiceOperation([
            'sale_id' => (int) $original['sale_id'], 'provider' => 'szamlazz', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':race',
            'net_total' => 1000.0, 'vat_total' => 270.0, 'gross_total' => 1270.0, 'currency' => 'HUF', 'payload' => ['original_invoice_number' => 'x'],
        ]);
        $script = self::$root . '/claim-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/src/Database.php', true) . ';'
            . '$db = new Database(["driver" => "sqlite", "sqlite" => ["path" => $argv[1]]], ' . var_export(dirname(__DIR__), true) . ');'
            . 'for ($i = 0; $i < 50; $i++) { try { echo $db->claimInvoiceOperationForExecution((int) $argv[2]) ? "1" : "0"; exit; } catch (Throwable $e) { usleep(20000); } } echo "E";');
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, $this->dbPath($db), (string) $row['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $outputs = [];
        foreach ($procs as $i => $proc) {
            $outputs[] = trim(stream_get_contents($pipes[$i][1]));
            proc_close($proc);
        }
        $this->assertSame(1, count(array_keys($outputs, '1', true)), 'Pontosan egy folyamat indíthatja a külső hívást: ' . implode(',', $outputs));
        $this->assertNotContains('E', $outputs);
    }
}
