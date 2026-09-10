<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavInvoiceQueueWorker tesztek — a queue-MECHANIKÁT (claim, backoff,
 * max attempts, állapotátmenetek) ellenőrzik VALÓDI Database + VALÓDI
 * NavInvoiceProvider felett, csak a NAV HTTP-réteg mockolt (lásd
 * scriptedProvider() helper) — így a teszt a teljes, ténylegesen
 * összekapcsolt queue-folyamatot gyakorolja be, nem elszigetelt
 * egységeket. A VALÓS, több-folyamatos konkurrencia-tesztet lásd
 * DatabaseTest.php::testNavInvoiceQueueClaimIsAtomicAcrossRealConcurrentProcesses().
 */
final class NavInvoiceQueueWorkerTest extends TestCase
{
    private function fakeNavConfig(): array
    {
        return [
            'nav_login' => 'teszt', 'nav_password' => 'teszt', 'nav_signer_key' => 'teszt-signer-key-1234567890',
            'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true,
        ];
    }

    private function fakeSupplierConfig(): array
    {
        return ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'];
    }

    private function tokenExchangeSuccessBody(): string
    {
        $encoded = base64_encode(openssl_encrypt('token', 'aes-128-ecb', 'ABCDEFGHIJKLMNOP', OPENSSL_RAW_DATA));
        return '<?xml version="1.0"?><TokenExchangeResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . "<encodedExchangeToken>$encoded</encodedExchangeToken>"
            . '<tokenValidityFrom>2026-01-01T10:00:00.000Z</tokenValidityFrom>'
            . '<tokenValidityTo>2026-01-01T10:05:00.000Z</tokenValidityTo>'
            . '</TokenExchangeResponse>';
    }

    private function envelopeBody(string $funcCode, array $extra = [], ?string $errorCode = null): string
    {
        $body = "<funcCode>$funcCode</funcCode>";
        if ($errorCode !== null) $body .= "<errorCode>$errorCode</errorCode>";
        foreach ($extra as $tag => $value) $body .= "<$tag>$value</$tag>";
        return '<?xml version="1.0"?><Response xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api"><common:result>' . $body . '</common:result></Response>';
    }

    /** @param callable(string $url, string $xml): array{status:int,body:string} $manageOrStatusHandler tokenExchange-en kívüli hívásokat kezeli */
    private function providerWith(callable $manageOrStatusHandler): NavInvoiceProvider
    {
        $tokenBody = $this->tokenExchangeSuccessBody();
        $transport = function (string $url, string $xml) use ($tokenBody, $manageOrStatusHandler) {
            if (str_contains($url, 'tokenExchange')) {
                return ['status' => 200, 'body' => $tokenBody];
            }
            return $manageOrStatusHandler($url, $xml);
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        return new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $clientFactory);
    }

    private function sampleContext(): array
    {
        return [
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Kossuth utca 1.', 'adoszam' => null],
            'items' => [['name' => 'Termék', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
        ];
    }

    private function enqueueSale(Database $db, NavInvoiceProvider $provider): array
    {
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $provider->enqueue($db, $saleId, $this->sampleContext());
        return $db->findInvoiceBySaleAndProvider($saleId, 'nav');
    }

    // ---- Sikeres teljes út ----

    public function testSuccessfulSubmissionThenStatusCheckReachesDone(): void
    {
        $db = tests_new_database();
        $submitProvider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['transactionId' => 'TXN1'])]);
        $row = $this->enqueueSale($db, $submitProvider);

        $worker = new NavInvoiceQueueWorker($db, $submitProvider);
        $summary = $worker->processDueSubmissions();
        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(1, $summary['submitted']);

        $submitted = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('submitted', $submitted['status']);
        $this->assertSame('TXN1', $submitted['provider_ref']);

        $statusProvider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['invoiceStatus' => 'DONE'])]);
        $worker2 = new NavInvoiceQueueWorker($db, $statusProvider);
        // A pollozási időablak miatt (STATUS_CHECK_INTERVAL_SECONDS a jövőben) a
        // sor most még nem esedékes -- szimuláljuk, hogy eltelt az idő.
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $statusSummary = $worker2->processDueStatusChecks();

        $this->assertSame(1, $statusSummary['claimed']);
        $this->assertSame(1, $statusSummary['done']);

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('done', $final['status']);
        $this->assertNotNull($final['issued_at']);
    }

    // ---- Retry / backoff ----

    public function testTransientFailureSchedulesRetryWithBackoff(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 500, 'body' => $this->envelopeBody('ERROR', [], 'INTERNAL')]);
        $row = $this->enqueueSale($db, $provider);

        $worker = new NavInvoiceQueueWorker($db, $provider);
        $summary = $worker->processDueSubmissions();

        $this->assertSame(1, $summary['retried']);
        $updated = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('queued', $updated['status']);
        $this->assertSame(1, (int) $updated['attempts']);
        $this->assertNotNull($updated['next_attempt_at']);
        // Az első backoff 60 másodperc -- a next_attempt_at-nak a jövőben kell lennie.
        $this->assertGreaterThan(time(), strtotime($updated['next_attempt_at'] . ' UTC') - 5);
    }

    public function testRowNotDueForRetryIsNotClaimedAgainImmediately(): void
    {
        $db = tests_new_database();
        $failProvider = $this->providerWith(fn () => ['status' => 500, 'body' => $this->envelopeBody('ERROR', [], 'INTERNAL')]);
        $this->enqueueSale($db, $failProvider);

        $worker = new NavInvoiceQueueWorker($db, $failProvider);
        $worker->processDueSubmissions();

        // Azonnal újra lefuttatva a workert -- a sor MÉG NEM esedékes (60s backoff), tehát nem claim-elhető.
        $second = $worker->processDueSubmissions();
        $this->assertSame(0, $second['claimed']);
    }

    public function testMaxAttemptsExhaustedGoesToDeadLetter(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 500, 'body' => $this->envelopeBody('ERROR', [], 'INTERNAL')]);
        $row = $this->enqueueSale($db, $provider);
        $worker = new NavInvoiceQueueWorker($db, $provider);

        // Az összes 9 backoff-késleltetés kimerítése -- 10 próbálkozás
        // összesen (1. + 9 retry), minden körben "esedékessé" tesszük a sort.
        for ($i = 0; $i < 10; $i++) {
            $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
            $worker->processDueSubmissions();
        }

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('dead_letter', $final['status']);
        $this->assertNull($final['next_attempt_at']);
    }

    public function testPermanentBusinessErrorGoesStraightToFailedNotRetry(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('ERROR', [], 'SCHEMA_VIOLATION')]);
        $row = $this->enqueueSale($db, $provider);

        $worker = new NavInvoiceQueueWorker($db, $provider);
        $summary = $worker->processDueSubmissions();

        $this->assertSame(1, $summary['permanent_failures']);
        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('failed', $final['status']);
        $this->assertSame(0, (int) $final['attempts'], 'Végleges hibánál nincs retry-számláló-növelés.');
    }

    // ---- Stale lock recovery ----

    public function testStaleProcessingLockCanBeReclaimed(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['transactionId' => 'TXN1'])]);
        $row = $this->enqueueSale($db, $provider);

        // Szimulálunk egy összeomlott workert: a sor 'processing', a zár RÉGI.
        $db->pdo()->exec("UPDATE invoices SET status = 'processing', locked_at = datetime('now', '-20 minutes') WHERE id = " . (int) $row['id']);

        $worker = new NavInvoiceQueueWorker($db, $provider);
        $summary = $worker->processDueSubmissions();

        $this->assertSame(1, $summary['claimed'], 'Egy 10 percnél régebbi (elavult) processing-zárat újra claim-elhetőnek kell tekinteni.');
        $this->assertSame(1, $summary['submitted']);
    }

    public function testFreshProcessingLockIsNotReclaimed(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['transactionId' => 'TXN1'])]);
        $row = $this->enqueueSale($db, $provider);

        // Egy MÉG legitim, aktív feldolgozás -- frissen zárolva.
        $db->pdo()->exec("UPDATE invoices SET status = 'processing', locked_at = datetime('now') WHERE id = " . (int) $row['id']);

        $worker = new NavInvoiceQueueWorker($db, $provider);
        $summary = $worker->processDueSubmissions();

        $this->assertSame(0, $summary['claimed'], 'Egy friss processing-zárat NEM szabad újra claim-elni.');
    }

    // ---- Uncertain (timeout) ----

    public function testUncertainSubmissionIsNeverAutomaticallyResubmitted(): void
    {
        $db = tests_new_database();
        $tokenBody = $this->tokenExchangeSuccessBody();
        $callCount = 0;
        $transport = function (string $url, string $xml) use ($tokenBody, &$callCount) {
            if (str_contains($url, 'tokenExchange')) return ['status' => 200, 'body' => $tokenBody];
            $callCount++;
            throw new RuntimeException('NAV kapcsolati hiba: timeout');
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $clientFactory);

        $row = $this->enqueueSale($db, $provider);
        $worker = new NavInvoiceQueueWorker($db, $provider);
        $summary = $worker->processDueSubmissions();

        $this->assertSame(1, $summary['uncertain']);
        $this->assertSame(1, $callCount, 'Egyetlen manageInvoice-próbálkozás történt -- az uncertain ág NEM indít azonnali automatikus újraküldést.');

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('uncertain', $final['status']);

        // Egy azonnali második worker-futás se küld új CREATE-et, mert a
        // sor MÁR NEM 'queued' (hanem 'uncertain'), a submission-claim
        // ('queued' állapotokra szűkítve) nem is találja meg.
        $secondSummary = $worker->processDueSubmissions();
        $this->assertSame(0, $secondSummary['claimed']);
        $this->assertSame(1, $callCount, 'A queued-claim kör nem nyúlhat uncertain sorokhoz.');
    }

    public function testUncertainRecoveryFindsMatchingTransactionAndMovesToSubmitted(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bootstrapProvider = $this->providerWith(fn () => ['status' => 200, 'body' => '']);
        $bootstrapProvider->enqueue($db, $saleId, $this->sampleContext());
        $row = $db->findInvoiceBySaleAndProvider($saleId, 'nav');
        $db->pdo()->exec("UPDATE invoices SET status = 'uncertain', next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);

        $originalXml = '<InvoiceData><invoiceNumber>' . $row['invoice_number'] . '</invoiceNumber></InvoiceData>';
        $listBody = '<?xml version="1.0"?><QueryTransactionListResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<transactionListResult><currentPage>1</currentPage><availablePage>1</availablePage>'
            . '<transaction><insDate>2026-01-01T10:00:00Z</insDate><transactionId>TXN-RECOVERED</transactionId><requestStatus>DONE</requestStatus></transaction>'
            . '</transactionListResult></QueryTransactionListResponse>';
        $statusBody = '<?xml version="1.0"?><QueryTransactionStatusResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<processingResults><processingResult><index>1</index><invoiceStatus>DONE</invoiceStatus><compressedContentIndicator>false</compressedContentIndicator>'
            . '<originalRequest>' . base64_encode($originalXml) . '</originalRequest></processingResult></processingResults>'
            . '</QueryTransactionStatusResponse>';

        $tokenBody = $this->tokenExchangeSuccessBody();
        $transport = function (string $url) use ($tokenBody, $listBody, $statusBody) {
            if (str_contains($url, 'tokenExchange')) return ['status' => 200, 'body' => $tokenBody];
            if (str_contains($url, 'queryTransactionList')) return ['status' => 200, 'body' => $listBody];
            if (str_contains($url, 'queryTransactionStatus')) return ['status' => 200, 'body' => $statusBody];
            return ['status' => 500, 'body' => ''];
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $recoveryProvider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $clientFactory);

        $worker = new NavInvoiceQueueWorker($db, $recoveryProvider);
        $summary = $worker->processDueUncertainRecovery();

        $this->assertSame(1, $summary['recovered']);
        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('submitted', $final['status']);
        $this->assertSame('TXN-RECOVERED', $final['provider_ref']);
    }

    // ---- Completed jobs are never resubmitted ----

    public function testCompletedInvoiceIsNeverReclaimedForSubmissionOrStatusCheck(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => '']);
        $row = $this->enqueueSale($db, $provider);
        $db->markInvoiceDone((int) $row['id'], date('Y-m-d H:i:s'));

        $worker = new NavInvoiceQueueWorker($db, $provider);
        $submissions = $worker->processDueSubmissions();
        $statusChecks = $worker->processDueStatusChecks();

        $this->assertSame(0, $submissions['claimed']);
        $this->assertSame(0, $statusChecks['claimed']);
    }

    // ---- P0-3: uncertain-recovery kimerülés -> VALÓDI terminális állapot ----

    /**
     * A P0-3 hiba reprodukciója és javításának bizonyítása: korábban a
     * kimerült egyeztetési kísérletek után a sor 'uncertain' maradt, NULL
     * next_attempt_at-tal, amit a claim-lekérdezés "azonnal esedékes"-ként
     * értelmezett — a sor emiatt MINDEN további worker-futásban újra
     * claim-elődött és újra feldolgozódott, korlátlan NAV API-forgalmat
     * okozva. Ez a teszt pontosan a MAX_UNCERTAIN_RECOVERY_ATTEMPTS (3)
     * kimerítéséig futtatja a recovery-t, majd bizonyítja, hogy: a sor
     * VALÓDI terminális állapotba ('uncertain_manual') kerül, a
     * next_attempt_at NULL marad (de a claim ettől függetlenül sem találja
     * meg, mert a státusz maga nincs a jogosult-listában), a last_error
     * megmarad, PONTOSAN 3 queryTransactionList-hívás történt (egy sem a
     * kimerülés UTÁN, sem ugyanabban, sem egy KÖVETKEZŐ worker-futásban),
     * és admin kézi újrapróbálkozással a sor visszaállítható.
     */
    public function testUncertainRecoveryExhaustionBecomesTerminalAndIsNeverReclaimedAgain(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $bootstrapProvider = $this->providerWith(fn () => ['status' => 200, 'body' => '']);
        $bootstrapProvider->enqueue($db, $saleId, $this->sampleContext());
        $row = $db->findInvoiceBySaleAndProvider($saleId, 'nav');
        $db->pdo()->exec("UPDATE invoices SET status = 'uncertain', attempts = 0, next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);

        $listCallCount = 0;
        $tokenBody = $this->tokenExchangeSuccessBody();
        // Egy queryTransactionList-válasz, ami SOSE talál egyező tranzakciót
        // -- az egyeztetés minden alkalommal 'still_uncertain'-t ad vissza.
        $emptyListBody = '<?xml version="1.0"?><QueryTransactionListResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<transactionListResult><currentPage>1</currentPage><availablePage>1</availablePage></transactionListResult>'
            . '</QueryTransactionListResponse>';
        $transport = function (string $url) use ($tokenBody, $emptyListBody, &$listCallCount) {
            if (str_contains($url, 'tokenExchange')) return ['status' => 200, 'body' => $tokenBody];
            if (str_contains($url, 'queryTransactionList')) { $listCallCount++; return ['status' => 200, 'body' => $emptyListBody]; }
            return ['status' => 500, 'body' => ''];
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $clientFactory);
        $worker = new NavInvoiceQueueWorker($db, $provider);

        // 1. és 2. próbálkozás -- MÉG marad 'uncertain' (a MAX=3 alatt).
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $s1 = $worker->processDueUncertainRecovery();
        $this->assertSame(1, $s1['still_uncertain']);
        $mid1 = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('uncertain', $mid1['status']);
        $this->assertSame(1, (int) $mid1['attempts']);

        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $s2 = $worker->processDueUncertainRecovery();
        $this->assertSame(1, $s2['still_uncertain']);
        $mid2 = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('uncertain', $mid2['status']);
        $this->assertSame(2, (int) $mid2['attempts']);

        // 3. próbálkozás -- a bounded kísérletek KIMERÜLNEK.
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $s3 = $worker->processDueUncertainRecovery();
        $this->assertSame(1, $s3['gave_up']);

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('uncertain_manual', $final['status'], 'A kimerült egyeztetésnek VALÓDI terminális állapotba kell kerülnie.');
        $this->assertSame(3, (int) $final['attempts']);
        $this->assertNull($final['next_attempt_at']);
        $this->assertNotEmpty($final['last_error'], 'A diagnosztikai hibaüzenetnek meg kell maradnia admin számára.');
        $this->assertSame(3, $listCallCount, 'Pontosan 3 queryTransactionList-hívás -- egy sem a kimerülés UTÁN.');

        // UGYANEBBEN a worker-példányban egy azonnali újabb hívás NEM
        // claim-eli újra ugyanazt a sort (a mai cron-tick nem ismétli).
        $sameRunAgain = $worker->processDueUncertainRecovery();
        $this->assertSame(0, $sameRunAgain['claimed'], 'A kimerült sor ugyanabban a futásban se claim-elhető újra.');
        $this->assertSame(3, $listCallCount, 'A kimerülés utáni claim-kísérlet nem indíthat újabb NAV-hívást.');

        // Egy KÖVETKEZŐ cron-hívás (új worker-példány) se nyúl hozzá -- ez
        // volt pontosan a P0-3 végtelen ciklusa.
        $nextCronWorker = new NavInvoiceQueueWorker($db, $provider);
        $nextRun = $nextCronWorker->processDueUncertainRecovery();
        $this->assertSame(0, $nextRun['claimed'], 'A következő cron-futás se claim-elheti újra a kimerült sort.');
        $this->assertSame(3, $listCallCount, 'A "következő cron-futás" se indíthat újabb NAV queryTransactionList-hívást.');

        // Admin kézi újrapróbálkozása visszaállítja 'queued'-ra.
        $manualReset = $db->resetInvoiceForManualRetry((int) $row['id']);
        $this->assertTrue($manualReset, 'Adminnak kézzel újra kell tudnia próbálni egy kimerült uncertain_manual sort.');
        $afterReset = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('queued', $afterReset['status']);
        $this->assertSame(0, (int) $afterReset['attempts']);
    }

    // ---- P1-1: végleges hiba a státusz-ellenőrzés SORÁN ----

    /**
     * A P1-1 hiba reprodukciója és javításának bizonyítása: korábban egy
     * queryTransactionStatus-hívás VÉGLEGES (nem-újrapróbálandó) hibája
     * (pl. időközben visszavont NAV hitelesítő adat) csendben visszakerült
     * 'submitted'-re, a last_error-t NULL-ra törölve — a számla ezután egy
     * teljesen egészséges, feldolgozás alatt álló számlától
     * megkülönböztethetetlennek TŰNT, örökké pollozva, admin számára
     * láthatatlanul.
     */
    public function testPermanentStatusCheckFailurePreservesErrorAndStopsPolling(): void
    {
        $db = tests_new_database();
        $submitProvider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['transactionId' => 'TXN1'])]);
        $row = $this->enqueueSale($db, $submitProvider);

        $worker = new NavInvoiceQueueWorker($db, $submitProvider);
        $worker->processDueSubmissions();
        $submitted = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('submitted', $submitted['status']);

        // A státusz-ellenőrzés VÉGLEGES, nem-újrapróbálandó hibát ad
        // vissza (pl. időközben visszavont hitelesítő adat).
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $permanentProvider = $this->providerWith(fn () => ['status' => 401, 'body' => $this->envelopeBody('ERROR', [], 'INVALID_SECURITY_USER')]);
        $worker2 = new NavInvoiceQueueWorker($db, $permanentProvider);
        $summary = $worker2->processDueStatusChecks();

        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(1, $summary['failed'], 'Egy végleges státusz-ellenőrzési hibának a "failed" számlálót kell növelnie, NEM a "retried"-et.');
        $this->assertSame(0, $summary['retried']);

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('failed', $final['status'], 'Terminális "failed" állapotba kell kerülnie, NEM csendben visszakerülnie "submitted"-re.');
        $this->assertNotEmpty($final['last_error'], 'A last_error-nak MEG KELL maradnia -- ez volt pontosan a P1-1 hibája.');

        // A soron TÖBBÉ NEM fut le automatikus pollozás -- a claim
        // ('submitted' állapotokra szűkítve) nem is találja meg.
        $again = $worker2->processDueStatusChecks();
        $this->assertSame(0, $again['claimed'], 'Egy terminálisan "failed" sort a státusz-ellenőrzés claim-je nem szabad újra megtalálnia.');
    }

    // ---- Manual retry ----

    public function testManualRetryResetsFailedInvoiceToQueued(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => '']);
        $row = $this->enqueueSale($db, $provider);
        $db->markInvoiceFailed((int) $row['id'], 'végleges hiba');

        $reset = $db->resetInvoiceForManualRetry((int) $row['id']);

        $this->assertTrue($reset);
        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('queued', $final['status']);
        $this->assertSame(0, (int) $final['attempts']);
    }

    public function testManualRetryRefusedForNonTerminalState(): void
    {
        $db = tests_new_database();
        $provider = $this->providerWith(fn () => ['status' => 200, 'body' => '']);
        $row = $this->enqueueSale($db, $provider); // 'queued', nem terminális

        $reset = $db->resetInvoiceForManualRetry((int) $row['id']);

        $this->assertFalse($reset);
    }

    // ---- NAV leállás szimuláció (Phase 5B pont 21) ----

    /**
     * A teljes elvárt életciklus egyetlen, koherens tesztben: az eladás
     * mindvégig sikeres marad, a queue-bejegyzés 'pending' (queued) marad
     * egy szimulált NAV-leállás (timeout, majd HTTP 503) alatt, backoff
     * szerint újraütemeződik, majd — amint a NAV "visszatér" (a mockolt
     * transport a HARMADIK próbálkozásnál sikeres választ ad) — eljut a
     * 'submitted', végül 'done' állapotig. Egyetlen ponton sem indul
     * automatikus, bizonytalan kimenetelű duplikált beküldés.
     */
    public function testSaleSucceedsQueueSurvivesSimulatedNavOutageThenCompletesOnRecovery(): void
    {
        $db = tests_new_database();

        // 1. eladás — a NAV-tól teljesen függetlenül sikeres (ezt itt csak
        // az insertSale() ténye reprezentálja, a valós sale.php flow-t
        // InvoiceServiceTest.php fedi).
        $saleId = $db->insertSale(1270.0, 'Készpénz');

        // 2. NAV "leállás" szimulálása: 1. próbálkozás — hálózati timeout.
        // A tokenExchange maga is a transporton megy át -- itt direkt a
        // tokenExchange-et buktatjuk, hogy tisztán a "retry" ágat
        // gyakoroljuk (mellékhatás nélküli hiba, hiszen manageInvoice-ig
        // el se jut).
        $downTransport = function () {
            throw new RuntimeException('NAV kapcsolati hiba: connection refused');
        };
        $downClientFactory = fn () => new NavClient($this->fakeNavConfig(), $downTransport);
        $downProvider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $downClientFactory);

        $downProvider->enqueue($db, $saleId, $this->sampleContext());
        $row = $db->findInvoiceBySaleAndProvider($saleId, 'nav');

        $worker = new NavInvoiceQueueWorker($db, $downProvider);
        $attempt1 = $worker->processDueSubmissions();
        $this->assertSame(1, $attempt1['retried'], 'A NAV-hívás hálózati hibája miatt retry-t kell ütemezni, NEM uncertain-t (tokenExchange-nek nincs mellékhatása).');

        $afterAttempt1 = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('queued', $afterAttempt1['status'], 'A sale/queue-bejegyzés a NAV-leállás alatt is megmarad, "pending" jelleggel (queued + backoff).');
        $this->assertSame(1, (int) $afterAttempt1['attempts']);

        // 2. próbálkozás — a NAV MÉG mindig áll (HTTP 503).
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $stillDownTransport = fn () => throw new RuntimeException('NAV kapcsolati hiba: 503 Service Unavailable');
        $stillDownClientFactory = fn () => new NavClient($this->fakeNavConfig(), $stillDownTransport);
        $stillDownProvider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json'), $stillDownClientFactory);
        $worker2 = new NavInvoiceQueueWorker($db, $stillDownProvider);
        $attempt2 = $worker2->processDueSubmissions();
        $this->assertSame(1, $attempt2['retried']);
        $afterAttempt2 = $db->getInvoiceById((int) $row['id']);
        $this->assertSame(2, (int) $afterAttempt2['attempts']);

        // 3. próbálkozás — "a NAV visszatér": sikeres tokenExchange +
        // manageInvoice.
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $recoveredProvider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['transactionId' => 'TXN-RECOVERED'])]);
        $worker3 = new NavInvoiceQueueWorker($db, $recoveredProvider);
        $attempt3 = $worker3->processDueSubmissions();
        $this->assertSame(1, $attempt3['submitted']);

        $afterAttempt3 = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('submitted', $afterAttempt3['status']);
        $this->assertSame('TXN-RECOVERED', $afterAttempt3['provider_ref']);

        // 4. a NAV véglegesen feldolgozta -> 'done'.
        $db->pdo()->exec("UPDATE invoices SET next_attempt_at = datetime('now', '-1 minute') WHERE id = " . (int) $row['id']);
        $statusProvider = $this->providerWith(fn () => ['status' => 200, 'body' => $this->envelopeBody('OK', ['invoiceStatus' => 'DONE'])]);
        $worker4 = new NavInvoiceQueueWorker($db, $statusProvider);
        $attempt4 = $worker4->processDueStatusChecks();
        $this->assertSame(1, $attempt4['done']);

        $final = $db->getInvoiceById((int) $row['id']);
        $this->assertSame('done', $final['status'], 'A teljes NAV-leállás -> helyreállás ciklus végén a számlának véglegesen "done" állapotban kell lennie, PONTOSAN EGY sikeres manageInvoice CREATE-tel.');
    }
}
