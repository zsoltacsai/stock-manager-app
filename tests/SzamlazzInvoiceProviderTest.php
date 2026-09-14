<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SzamlazzInvoiceProvider::requestModification()/requestStorno() (1.1.0)
 * — a DB-szintű claim/idempotencia ÉS a szinkron eredmény-visszaírás
 * (Database::updateInvoiceOperationResult()) helyességét ellenőrzi, egy
 * VALÓDI, loopback stub-szerveren keresztül (ugyanaz a minta, mint
 * InvoiceServiceTest.php-ban) — nem mockolt Számlázz.hu-válasszal.
 */
final class SzamlazzInvoiceProviderTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_szamlazz_opprovider_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/fake-szamlazz.php', <<<'PHP'
<?php
$mode = $_GET['mode'] ?? 'success';
if ($mode === 'reject') {
    header('szlahu_error: ' . urlencode('Teszt: erv√©nytelen adat'));
    echo 'hiba';
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'xmlagentresponse=DONE;SZ-STUB-OP-1';
}
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
            self::fail('Nem sikerült elindítani a Számlázz.hu-stub teszt-szervert.');
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
        self::fail('A Számlázz.hu-stub teszt-szerver nem indult el időben.');
    }

    private function stubConfig(string $mode = 'success'): array
    {
        return [
            'agent_key' => 'teszt', 'endpoint' => 'http://127.0.0.1:' . self::$stubPort . '/fake-szamlazz.php?mode=' . $mode,
            'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu',
            'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'],
            'pdf_dir' => sys_get_temp_dir() . '/sm_invoice_test_pdf_' . bin2hex(random_bytes(4)),
        ];
    }

    /** Szándékosan üres endpoint — a curl AZONNAL, hálózat nélkül elutasítja (transport-hiba, "uncertain" ág). */
    private function transportFailureConfig(): array
    {
        $cfg = $this->stubConfig();
        $cfg['endpoint'] = '';
        return $cfg;
    }

    private function sampleContext(): array
    {
        return [
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'],
            'items' => [['name' => 'Termék', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
        ];
    }

    private function createOriginalInvoiceRow(Database $db): array
    {
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->upsertInvoiceMirror($saleId, 'szamlazz', true, 'SZ-ORIG-0001', null, null, 1000.0, 270.0, 1270.0, 'HUF');
        return $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
    }

    // ---- supportsOperation() ----

    public function testSupportsOperationTrueForModifyAndStorno(): void
    {
        $provider = new SzamlazzInvoiceProvider($this->stubConfig());
        $this->assertTrue($provider->supportsOperation('modify'));
        $this->assertTrue($provider->supportsOperation('storno'));
        $this->assertFalse($provider->supportsOperation('teleport'));
    }

    // ---- requestModification() ----

    public function testRequestModificationSuccessUpdatesOperationRowToDone(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig('success'));

        $context = $this->sampleContext();
        $context['operation_uuid'] = 'attempt-1';
        $result = $provider->requestModification($db, $original, $context);

        $this->assertTrue($result['success']);
        $this->assertSame('SZ-STUB-OP-1', $result['invoice_number']);
        $this->assertFalse($result['pending']);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertCount(1, $ops);
        $this->assertSame('done', $ops[0]['status']);
        $this->assertSame('modification', $ops[0]['invoice_type']);
        $this->assertSame(1, (int) $ops[0]['modification_index']);
    }

    public function testRequestModificationWithoutOperationUuidThrows(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig());

        $this->expectException(InvalidArgumentException::class);
        $provider->requestModification($db, $original, $this->sampleContext());
    }

    public function testRequestModificationDuplicateOperationUuidIsRejectedAsAlreadyInProgress(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig('success'));

        $context = $this->sampleContext();
        $context['operation_uuid'] = 'same-attempt';
        $provider->requestModification($db, $original, $context);
        $second = $provider->requestModification($db, $original, $context);

        $this->assertFalse($second['success']);
        $this->assertTrue($second['already_in_progress'] ?? false);

        // Pontosan EGY módosító sor jött létre, NEM kettő.
        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertCount(1, $ops);
    }

    public function testRequestModificationConfirmedRejectionMarksOperationFailed(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig('reject'));

        $context = $this->sampleContext();
        $context['operation_uuid'] = 'attempt-reject';
        $result = $provider->requestModification($db, $original, $context);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['uncertain'] ?? false, 'Megerősített elutasítás sose "uncertain".');

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertSame('failed', $ops[0]['status']);
    }

    public function testRequestModificationTransportFailureMarksUncertainManual(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->transportFailureConfig());

        $context = $this->sampleContext();
        $context['operation_uuid'] = 'attempt-transport';
        $result = $provider->requestModification($db, $original, $context);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['uncertain'] ?? false);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertSame('uncertain_manual', $ops[0]['status']);
    }

    // ---- requestStorno() ----

    public function testRequestStornoSuccessUpdatesOperationRowToDone(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig('success'));

        $result = $provider->requestStorno($db, $original, $this->sampleContext());

        $this->assertTrue($result['success']);
        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertSame('storno', $ops[0]['invoice_type']);
        $this->assertSame('done', $ops[0]['status']);
    }

    public function testSecondStornoAttemptIsStructurallyBlockedByOperationKey(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $provider = new SzamlazzInvoiceProvider($this->stubConfig('success'));

        $provider->requestStorno($db, $original, $this->sampleContext());
        $second = $provider->requestStorno($db, $original, $this->sampleContext());

        $this->assertFalse($second['success']);
        $this->assertTrue($second['already_in_progress'] ?? false);
        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertCount(1, $ops, 'Pontosan EGY sztornó-sor jöhet létre, a második kísérlet nem duplikálhat.');
    }

    public function testStornoTransportFailureAllowsRecoveryViaExecuteAndRecordOperation(): void
    {
        $db = tests_new_database();
        $original = $this->createOriginalInvoiceRow($db);
        $failingProvider = new SzamlazzInvoiceProvider($this->transportFailureConfig());

        $first = $failingProvider->requestStorno($db, $original, $this->sampleContext());
        $this->assertTrue($first['uncertain'] ?? false);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertSame('uncertain_manual', $ops[0]['status']);
        $invoiceId = (int) $ops[0]['id'];

        // Admin kézi feloldása: resetInvoiceForManualRetry() 'queued'-ra
        // állítja a MEGLÉVŐ sort (NEM egy újat hoz létre — az
        // operation_key determinisztikus, 'storno:{id}', újra-létrehozás
        // ütközne) — ugyanaz a minta, mint a NAV-nál
        // (nav-invoice-retry.php), csak itt VALAKINEK ténylegesen újra is
        // el kell indítania a szinkron kísérletet.
        $this->assertTrue($db->resetInvoiceForManualRetry($invoiceId));

        $workingProvider = new SzamlazzInvoiceProvider($this->stubConfig('success'));
        $payload = json_decode($db->getInvoiceById($invoiceId)['payload_json'], true);
        $retryResult = $workingProvider->executeAndRecordOperation($db, $invoiceId, 'storno', $payload);

        $this->assertTrue($retryResult['success']);
        $this->assertSame('done', $db->getInvoiceById($invoiceId)['status']);
    }
}
