<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * InvoiceService/SzamlazzInvoiceProvider — a NAV Online Számla bevezetése
 * miatt új, közös számlázási absztrakció DB-szintű (Database osztályon
 * keresztüli, HTTP-réteg nélküli) tesztjei. A cél: bizonyítani, hogy a
 * korábban sale.php-ba/webshop-order-invoice.php-ba/
 * webshop-order-confirm.php-ba égetett Számlázz.hu-s folyamat (atomikus
 * foglalás, attachInvoiceToSale) VÁLTOZATLANUL működik az új rétegen
 * keresztül is, és hogy a (ebben a körben még be nem kötött) 'nav'
 * szolgáltató-választás SOSE dob kifelé, SOSE hiúsítja meg az eladást —
 * csak egy egyértelmű, olvasható hibát ad vissza a számla-eredményben.
 *
 * P1-5 ÓTA két, EGYMÁSTÓL SZÁNDÉKOSAN MEGKÜLÖNBÖZTETETT hiba-útvonal van:
 *   - TRANSPORT-szintű hiba (a kérésre EGYÁLTALÁN nem érkezett válasz) —
 *     szimulálva egy szándékosan érvénytelen "endpoint" URL-lel, amit a
 *     curl AZONNAL, hálózati kapcsolat nélkül elutasít
 *     (CURLE_URL_MALFORMAT) — ez determinisztikusan és gyorsan futtatja
 *     le az 'invoice_uncertain' ágat, anélkül hogy a teszt-csomag valós
 *     hálózati kapcsolatra szorulna.
 *   - MEGERŐSÍTETT (a Számlázz.hu által ténylegesen megválaszolt) hiba —
 *     ehhez egy VALÓDI, helyi, loopback HTTP-stub szerver fut (lásd
 *     setUpBeforeClass()), ami a Számlázz.hu válasz-formátumát utánozza
 *     (szlahu_error fejléc, ill. "xmlagentresponse=DONE" törzs) —
 *     KIZÁRÓLAG 127.0.0.1-en, sose valós Számlázz.hu-hívás.
 */
final class InvoiceServiceTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_szamlazz_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/fake-szamlazz.php', <<<'PHP'
<?php
// Minimális stub, ami a Számlázz.hu Számla Agent XML API válasz-
// formátumát utánozza, KIZÁRÓLAG loopback teszt-célra.
$mode = $_GET['mode'] ?? 'success';
if ($mode === 'reject') {
    header('szlahu_error: ' . urlencode('Teszt: ervenytelen adoszam a vevo adataiban'));
    echo 'hiba';
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'xmlagentresponse=DONE;SZ-STUB-INVOICE-1';
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

    private function fakeSzamlazzConfig(): array
    {
        // Szándékosan üres URL — a curl ezt AZONNAL, bármiféle hálózati
        // I/O (DNS-feloldás sem) nélkül elutasítja ("Malformed input to a
        // URL function"), tehát a teszt gyors és determinisztikus marad,
        // és GARANTÁLTAN a transport-hiba (SzamlazzClient kivétel-dobó)
        // ágát futtatja — ez a P1-5 "uncertain" útvonal tesztágya.
        return [
            'agent_key' => 'teszt', 'endpoint' => '',
            'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu',
            'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'],
            'pdf_dir' => sys_get_temp_dir() . '/sm_invoice_test_pdf_' . bin2hex(random_bytes(4)),
        ];
    }

    /** A helyi stub-szerverre mutató konfiguráció — VALÓDI (loopback) HTTP-válasszal záruló hívásokhoz. */
    private function stubSzamlazzConfig(string $mode): array
    {
        $cfg = $this->fakeSzamlazzConfig();
        $cfg['endpoint'] = 'http://127.0.0.1:' . self::$stubPort . '/fake-szamlazz.php?mode=' . $mode;
        return $cfg;
    }

    private function sampleContext(Database $db, int $saleId): array
    {
        return [
            'db'             => $db,
            'sale_id'        => $saleId,
            'buyer'          => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'],
            'items'          => [['name' => 'Teszt tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'language'       => null,
            'payment_method' => 'Készpénz',
            'totals'         => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
        ];
    }

    public function testDefaultProviderKeyIsSzamlazz(): void
    {
        $service = new InvoiceService(['szamlazz' => []], []);
        $this->assertSame('szamlazz', $service->currentProviderKey());
    }

    public function testProviderKeyRecognizesNav(): void
    {
        $service = new InvoiceService(['szamlazz' => []], ['invoice_provider' => 'nav']);
        $this->assertSame('nav', $service->currentProviderKey());
    }

    public function testUnknownProviderValueFallsBackToSzamlazz(): void
    {
        // Az API-oldali beállítás-mentés (settings.php) egyébként is csak
        // 'szamlazz'/'nav' értéket enged bemenetként — ez a teszt a
        // defenzív fail-safe ágat fedi, arra az esetre, ha a settings.json
        // valahogy mégis mást tartalmazna (pl. kézi szerkesztés).
        $service = new InvoiceService(['szamlazz' => []], ['invoice_provider' => 'valami-ismeretlen']);
        $this->assertSame('szamlazz', $service->currentProviderKey());
    }

    public function testNavProviderReturnsGracefulErrorWithoutThrowingOrBlockingSale(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');

        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'nav']);
        $result = $service->processInvoice($this->sampleContext($db, $saleId));

        $this->assertFalse($result['success']);
        $this->assertNull($result['invoice_number']);
        $this->assertNull($result['pdf_path']);
        $this->assertNotEmpty($result['error'], 'A NAV-nak egyértelmű, olvasható hibaüzenetet kell adnia, nem csendben "sikertelen"-nek lennie.');

        // A legfontosabb garancia: a sale.php-beli hívó szemszögéből ez a
        // hívás sose blokkolja/hiúsítja meg magát az eladást — az eladás
        // (sales sor) itt már réges-régen, ettől függetlenül rögzítve van.
        $sale = $db->getSaleWithItems($saleId);
        $this->assertNotNull($sale);
    }

    // ------------------------------------------------------------------
    // P1-5: transport-szintű hiba (VÁLASZ NÉLKÜLI kérés) -> 'invoice_uncertain'
    // ------------------------------------------------------------------

    public function testTransportFailureMarksSaleUncertainNotImmediatelyRetryableFailed(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');

        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);
        $result = $service->processInvoice($this->sampleContext($db, $saleId));

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
        $this->assertTrue($result['uncertain'] ?? false, 'Egy transport-szintű (válasz nélküli) hibának "uncertain" jelzővel kell visszatérnie, NEM sima "invoice_failed"-ként.');

        // A P1-5 javítás lényege: NEM 'invoice_failed' (ami korábban
        // azonnali automatikus újrapróbálkozást engedett), hanem
        // 'invoice_uncertain' -- lásd Database::markSaleInvoiceUncertain().
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('invoice_uncertain', $sale['status']);
        $this->assertNull($sale['invoice_claim_at']);
        $this->assertNull($sale['szamlazz_invoice_number']);

        // A tükör-táblában 'uncertain_manual' állapotú sor jelenik meg
        // (ugyanaz a szóhasználat, mint a NAV kimerült-egyeztetésnél).
        $mirror = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertNotNull($mirror);
        $this->assertSame('uncertain_manual', $mirror['status']);
    }

    public function testRetryAfterUncertainStateIsBlockedAndNeverBlindlyCreatesASecondInvoice(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);

        $first = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertTrue($first['uncertain'] ?? false);

        // Egy AZONNALI (vagy akár egy 90+ másodperc múlva érkező, a régi
        // "elévült foglalás" ablakon túli) második kísérletnek IS blokkolva
        // kell maradnia — ez a P1-5 kulcs-garanciája: sose vak második CREATE.
        $second = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertFalse($second['success']);
        $this->assertTrue($second['uncertain'] ?? false, 'Egy uncertain sale-re irányuló újabb kísérletnek is "uncertain"-t kell adnia, NEM egy új Számlázz.hu-hívást indítania.');

        // A sale státusza változatlanul 'invoice_uncertain' marad -- pontosan
        // EGY tükör-sor létezik (nem duplázódott).
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('invoice_uncertain', $sale['status']);

        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, 'szamlazz']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ------------------------------------------------------------------
    // P1-5: admin kézi feloldása
    // ------------------------------------------------------------------

    public function testAdminManualResolutionConfirmingNoInvoiceCreatedAllowsRetry(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);
        $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertSame('invoice_uncertain', $db->getSaleWithItems($saleId)['status']);

        // Admin ellenőrizte a Számlázz.hu felületén: NEM készült számla.
        $resolved = $db->resolveUncertainSzamlazzInvoice($saleId, null);
        $this->assertTrue($resolved);

        $afterResolve = $db->getSaleWithItems($saleId);
        $this->assertSame('completed', $afterResolve['status']);
        $this->assertNull($afterResolve['szamlazz_invoice_number']);
        $this->assertNull($afterResolve['invoice_claim_at']);

        // A tükör-sor törlődött -- a sale ismét "friss, még nem
        // számlázott" állapotú, a "Kimenő számlák" nézet nem mutat rá
        // hamis "uncertain_manual" badge-et.
        $this->assertNull($db->findInvoiceBySaleAndProvider($saleId, 'szamlazz'));

        // Egy KÖVETKEZŐ (valós válaszú, sikeres) kísérlet ismét megpróbálhatja.
        $retryService = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);
        $retryResult = $retryService->processInvoice($this->sampleContext($db, $saleId));
        $this->assertTrue($retryResult['success']);
        $this->assertSame('SZ-STUB-INVOICE-1', $retryResult['invoice_number']);
    }

    public function testAdminManualResolutionRecordingFoundInvoiceNumberNeverRetriesRemotely(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);
        $service->processInvoice($this->sampleContext($db, $saleId));

        // Admin a Számlázz.hu felületén MEGTALÁLTA a ténylegesen kiállított számlát.
        $resolved = $db->resolveUncertainSzamlazzInvoice($saleId, 'SZ-2026-MANUALLY-FOUND');
        $this->assertTrue($resolved);

        $afterResolve = $db->getSaleWithItems($saleId);
        $this->assertSame('completed', $afterResolve['status']);
        $this->assertSame('SZ-2026-MANUALLY-FOUND', $afterResolve['szamlazz_invoice_number']);

        // A tükör-sor is a MEGTALÁLT számlaszámot tükrözi, 'done' állapotban
        // -- a "Kimenő számlák" nézet ettől kezdve rendesen kiállítottnak
        // mutatja, NEM "uncertain_manual"-nak.
        $mirror = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertNotNull($mirror);
        $this->assertSame('done', $mirror['status']);
        $this->assertSame('SZ-2026-MANUALLY-FOUND', $mirror['invoice_number']);

        // Mivel a sale-nek MÁR van szamlazz_invoice_number-je,
        // tryClaimInvoiceIssuance() (a WHERE feltétele szerint) ezután
        // SOSE engedne egy újabb kísérletet — bizonyítjuk, hogy a claim
        // ténylegesen elutasít.
        $claimed = $db->tryClaimInvoiceIssuance($saleId);
        $this->assertFalse($claimed, 'Egy már manuálisan rögzített számlaszámú sale-re NEM szabad újra claim-elni.');
    }

    public function testManualResolveRejectedWhenSaleIsNotInUncertainState(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        // A sale 'completed' állapotban van, sose volt uncertain.

        $resolved = $db->resolveUncertainSzamlazzInvoice($saleId, null);
        $this->assertFalse($resolved, 'Csak ténylegesen "invoice_uncertain" állapotú sale oldható fel.');
    }

    // ------------------------------------------------------------------
    // Megerősített (VALÓDI válaszú) Számlázz.hu-hiba — ettől VÁLTOZATLANUL
    // megkülönböztetve, lásd a fájl fejléc-docblokkját.
    // ------------------------------------------------------------------

    public function testConfirmedBusinessRejectionFromRealResponseRemainsImmediatelyRetryable(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('reject')], ['invoice_provider' => 'szamlazz']);

        $first = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertFalse($first['success']);
        $this->assertFalse($first['uncertain'] ?? false, 'Egy VALÓDI, a Számlázz.hu által ténylegesen megválaszolt elutasítás sose "uncertain".');
        $this->assertNotEmpty($first['error']);

        // Egy MEGERŐSÍTETT elutasítás után (ellentétben egy transport-
        // bizonytalansággal) a régi, változatlan viselkedés érvényes:
        // 'invoice_failed', ÉS a foglalás azonnal felszabadul.
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('invoice_failed', $sale['status']);
        $this->assertNull($sale['invoice_claim_at']);

        $mirror = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertSame('failed', $mirror['status']);

        // Egy AZONNALI második próbálkozásnak el kell jutnia a (szintén
        // valódi választ adó) SzamlazzClient-hívásig, nem "már folyamatban
        // van"/"uncertain" hibát kell kapnia.
        $second = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertFalse($second['success']);
        $this->assertFalse($second['uncertain'] ?? false);
        $this->assertNotSame('A számla kiállítása már folyamatban van.', $second['error']);

        // A UNIQUE(sale_id, provider) + upsert miatt a két próbálkozás
        // ellenére is pontosan EGY tükör-sor létezik.
        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, 'szamlazz']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSuccessfulInvoiceFromStubServerIsRecordedCorrectly(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertTrue($result['success']);
        $this->assertSame('SZ-STUB-INVOICE-1', $result['invoice_number']);

        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('completed', $sale['status']);
        $this->assertSame('SZ-STUB-INVOICE-1', $sale['szamlazz_invoice_number']);

        $mirror = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertSame('done', $mirror['status']);
    }

    // ------------------------------------------------------------------
    // 1.1.0: requestModification()/requestStorno() — a KÖZPONTI validáció
    // ------------------------------------------------------------------

    private function doneOriginalInvoice(Database $db, string $provider = 'szamlazz'): array
    {
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        // A payload_json (buyer/items) ITT is átadva — pontosan úgy, ahogy
        // egy VALÓDI Számlázz.hu CREATE (SzamlazzInvoiceProvider::issueSync())
        // 1.1.0 óta eltárolja — enélkül buildStornoContext() üres
        // tételsorral térne vissza (lásd testRequestStornoUsesOriginalItemsReversedNotUserInput()).
        $db->upsertInvoiceMirror($saleId, $provider, true, 'SZ-ORIG-1', null, null, 1000.0, 270.0, 1270.0, 'HUF', null, [
            'buyer' => ['nev' => 'Eredeti Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
            'items' => [['name' => 'Eredeti tétel', 'qty' => 2, 'unit_price_gross' => 635.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
        ]);
        return $db->findInvoiceBySaleAndProvider($saleId, $provider);
    }

    private function sampleModifyItems(): array
    {
        return [['name' => 'Javított tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']];
    }

    public function testRequestModificationRejectedWithoutAdmin(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'Készpénz', null, false);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('adminisztrátori', $result['error']);
    }

    public function testRequestModificationRejectedForNonexistentInvoice(): void
    {
        $db = tests_new_database();
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->requestModification($db, 999999, $this->sampleModifyItems(), [], null, null, true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nem található', $result['error']);
    }

    public function testRequestModificationRejectedWhenOriginalIsNotDoneYet(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->upsertInvoiceMirror($saleId, 'szamlazz', false, null, null, 'x hiba', 1000.0, 270.0, 1270.0, 'HUF');
        $original = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), [], null, null, true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('status=done', $result['error']);
    }

    public function testRequestModificationRejectedWhenTargetingAlreadyDerivedRow(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $first = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], 'Készpénz', 'attempt-1', true);
        $this->assertTrue($first['success']);
        $modifiedRow = $db->getInvoiceOperationsForOriginal((int) $original['id'])[0];

        // A módosító sorra hivatkozva (mint "eredeti") a MODIFY/STORNO
        // TILOS — a lánc mindig a gyökér eredetihez kötődik.
        $result = $service->requestModification($db, (int) $modifiedRow['id'], $this->sampleModifyItems(), [], null, null, true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('EREDETI', $result['error']);
    }

    public function testRequestModificationEndToEndSuccessViaStub(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->requestModification(
            $db,
            (int) $original['id'],
            $this->sampleModifyItems(),
            ['nev' => 'Javított Vevő', 'irsz' => '2222', 'telepules' => 'Debrecen', 'cim' => 'Fő tér 2.'],
            'Kártya',
            null,
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('SZ-STUB-INVOICE-1', $result['invoice_number']);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertCount(1, $ops);
        $this->assertSame('modification', $ops[0]['invoice_type']);
        $this->assertSame((int) $original['id'], (int) $ops[0]['original_invoice_id']);
    }

    public function testRequestStornoUsesOriginalItemsReversedNotUserInput(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $result = $service->requestStorno($db, (int) $original['id'], true);

        $this->assertTrue($result['success']);
        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertSame('storno', $ops[0]['invoice_type']);
        $this->assertLessThan(0, (float) $ops[0]['net_total'], 'A sztornó net_total-jának negatívnak kell lennie (teljes visszavonás).');

        // A payload_json-ban ténylegesen az EREDETI (upsertInvoiceMirror()-nél
        // eltárolt) tétel jelenik meg, NEGÁLT mennyiséggel — ez bizonyítja,
        // hogy buildStornoContext() a valódi eredeti adatból dolgozik, NEM
        // üres/hiányzó payloadból (lásd upsertInvoiceMirror() 1.1.0 payload
        // paramétere és annak indoklása).
        $payload = json_decode($ops[0]['payload_json'], true);
        $this->assertSame('Eredeti tétel', $payload['items'][0]['name']);
        $this->assertSame(-2.0, (float) $payload['items'][0]['qty']);
        $this->assertSame('Eredeti Vevő', $payload['buyer']['nev']);
    }

    public function testRequestModificationAndStornoBlockedAfterStornoAlreadyDone(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $storno = $service->requestStorno($db, (int) $original['id'], true);
        $this->assertTrue($storno['success']);

        $furtherModify = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), [], null, null, true);
        $this->assertFalse($furtherModify['success']);
        $this->assertStringContainsString('sztornó', $furtherModify['error']);

        $furtherStorno = $service->requestStorno($db, (int) $original['id'], true);
        $this->assertFalse($furtherStorno['success']);
        $this->assertStringContainsString('sztornó', $furtherStorno['error']);
    }

    /**
     * VALÓDI NAV sandbox hívással feltárt hiba regressziós tesztje: egy
     * sztornónak a LÁNC AKTUÁLIS (legutóbbi sikeres módosítás utáni)
     * állapotát kell visszavonnia, NEM mindig az eredeti számla tartalmát
     * — lásd InvoiceService::buildStornoContext() docblockja.
     */
    public function testStornoAfterSuccessfulModificationReversesLatestStateNotOriginal(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db); // 'Eredeti tétel', qty 2, 635 Ft/db (lásd doneOriginalInvoice())
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $modifiedItems = [['name' => 'Javított tétel', 'qty' => 3, 'unit_price_gross' => 1000.0, 'vat_rate' => '27']];
        $modifyResult = $service->requestModification(
            $db, (int) $original['id'], $modifiedItems,
            ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], null, 'uuid-latest-1', true
        );
        $this->assertTrue($modifyResult['success']);

        $stornoResult = $service->requestStorno($db, (int) $original['id'], true);
        $this->assertTrue($stornoResult['success']);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $stornoRow = end($ops);
        $this->assertSame('storno', $stornoRow['invoice_type']);

        $payload = json_decode($stornoRow['payload_json'], true);
        $this->assertSame('Javított tétel', $payload['items'][0]['name'], 'A sztornónak a LEGUTÓBBI módosítás tételét kell visszavonnia, nem az eredetit.');
        $this->assertSame(-3.0, (float) $payload['items'][0]['qty']);
        $this->assertSame(1000.0, (float) $payload['items'][0]['unit_price_gross']);
    }

    public function testMultipleModificationsAllowedBeforeStorno(): void
    {
        // A kör explicit példája: MODIFY→1, MODIFY→2, STORNO→3 mind
        // engedélyezett ugyanarra az eredetire, amíg sztornó még nem történt.
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);

        $m1 = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], null, 'uuid-1', true);
        $m2 = $service->requestModification($db, (int) $original['id'], $this->sampleModifyItems(), ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'], null, 'uuid-2', true);
        $storno = $service->requestStorno($db, (int) $original['id'], true);

        $this->assertTrue($m1['success']);
        $this->assertTrue($m2['success']);
        $this->assertTrue($storno['success']);

        $ops = $db->getInvoiceOperationsForOriginal((int) $original['id']);
        $this->assertCount(3, $ops);
        $this->assertSame([1, 2, 3], array_map(static fn (array $r) => (int) $r['modification_index'], $ops));
    }

    public function testRetrySzamlazzOperationRejectedWhenNotQueued(): void
    {
        $db = tests_new_database();
        $original = $this->doneOriginalInvoice($db);
        $service = new InvoiceService(['szamlazz' => $this->stubSzamlazzConfig('success')], ['invoice_provider' => 'szamlazz']);
        $service->requestStorno($db, (int) $original['id'], true);
        $doneOp = $db->getInvoiceOperationsForOriginal((int) $original['id'])[0];

        $result = $service->retrySzamlazzOperation($db, (int) $doneOp['id']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('queued', $result['error']);
    }
}
