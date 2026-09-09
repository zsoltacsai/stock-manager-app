<?php

/**
 * NAV Online Számla v3 API kliens — POST /tokenExchange, POST /manageInvoice
 * (CREATE), POST /queryTransactionStatus. Minden mező, endpoint-elérési út,
 * aláírás-számítási képlet és válasz-struktúra a NAV hivatalos v3
 * specifikációja (invoiceApi.xsd, invoiceData.xsd, a NAV publikus
 * minta-XML-jei és a hivatalos "Online Invoice System 3.0 Interface
 * Specification" PDF, github.com/nav-gov-hu/Online-Invoice) alapján
 * igazolt — semmi nincs kitalálva.
 *
 * ÁLLAPOT (Phase 5A): ez a kliens ténylegesen hívhat éles NAV sandbox API-t
 * (api-test.onlineszamla.nav.gov.hu). Ez a kör kifejezetten a kapcsolat, a
 * hitelesítés és egy teszt-számla beküldésének BIZONYÍTÁSÁRA szolgál — a
 * queue-alapú, retry-képes, hibatűrő NavInvoiceProvider (ami ezt majd az
 * éles eladási folyamathoz köti) a Phase 5B feladata. Ez a kliens
 * ÖNMAGÁBAN NINCS bekötve sale.php-ba / InvoiceService-be.
 *
 * Aláírás-számítás (a specifikáció "1.5 Calculating requestSignature"
 * szakasza alapján — KRITIKUS, hogy tokenExchange/queryTransactionStatus
 * és manageInvoice ESETÉN NEM ugyanaz a képlet, ellentétben azzal, amit a
 * meglévő NavTaxpayerLookup.php egyetlen, mindenhol újrahasznált
 * simpleSignature()-je sugallna):
 *   - tokenExchange / queryTransactionStatus / queryTaxpayer ("1.5.2" eset):
 *     requestSignature = SHA3-512(requestId . timestampYYYYMMDDhhmmss .
 *     signerKey), nagybetűsen.
 *   - manageInvoice / manageAnnulment ("1.5.1" eset): ugyanaz a "partial
 *     authentication" alap (requestId.timestamp.signerKey), DE utána MINDEN
 *     egyes invoiceOperation-höz tartozik egy külön "index hash" =
 *     SHA3-512(invoiceOperation-literál . base64(invoiceData)) (nagybetűs
 *     hex), ezeket sorrendben a partial authentication alap UTÁN kell
 *     fűzni, és a TELJES így kapott string SHA3-512 hash-e (nagybetűsen)
 *     adja a végső requestSignature-t. Ezt a képletet a specifikáció saját
 *     fiktív számpéldájával byte-pontosan leellenőriztem, mielőtt éles NAV
 *     hívásban használtam volna (lásd tests/NavClientTest.php).
 *
 * A tokenExchange válaszban kapott encodedExchangeToken AES-128 ECB
 * dekódolásának kulcsa a technikai felhasználó "csere kulcsa"
 * (nav_exchange_key) — NEM az aláíró kulcs (nav_signer_key). A
 * specifikáció ezt "replacement key"-nek nevezi; ez a projekt "csere
 * kulcs" mezője.
 *
 * HTTP-válasz értelmezés: a NAV szinte minden esetben HTTP 200-at ad
 * vissza még hibás feldolgozás esetén is (a hiba a válasz XML-jében,
 * funcCode=ERROR formájában jelenik meg) — csak a valóban technikai szintű
 * hibák (hibás aláírás, ismeretlen felhasználó, stb.) járnak nem-200
 * HTTP státusszal, és ilyenkor a válasz gyakran csak egy csupasz
 * GeneralErrorResponse/GeneralExceptionResponse (header/software nélkül).
 * Emiatt a válaszfeldolgozás névtér-független, local-name() alapú
 * XPath-tal keresi a funcCode/errorCode/message/stb. mezőket — ez mindkét
 * válasz-alakra működik, HTTP-státusztól függetlenül.
 */
class NavClient
{
    private const REQUEST_VERSION = '3.0';
    private const HEADER_VERSION = '1.0';
    // A NAV a softwareId mezőt PONTOSAN 18 karakter hosszúra írja elő
    // ([0-9A-Z\-]{18} minta) — élő NAV sandbox hívással igazolt
    // SCHEMA_VIOLATION hiba derítette ki, hogy a korábbi, 19 karakteres
    // "STOCKMANAGER0000001" érték (amit a NavTaxpayerLookup.php is
    // örökölt) sérti ezt a korlátot. Az érték önmagában választható
    // (nincs NAV-nál előre regisztrálva), csak stabilnak kell maradnia.
    private const SOFTWARE_ID = 'STOCKMANAGER000001';

    private string $login;
    private string $password;
    private string $signerKey;
    private string $exchangeKey;
    private string $taxNumber;
    private string $baseUrl;

    /** @var callable(string $url, string $xml): array{status:int, body:string} */
    private $httpTransport;

    public function __construct(array $cfg, ?callable $httpTransport = null)
    {
        $this->login       = (string) ($cfg['nav_login'] ?? '');
        $this->password    = (string) ($cfg['nav_password'] ?? '');
        $this->signerKey   = (string) ($cfg['nav_signer_key'] ?? '');
        $this->exchangeKey = (string) ($cfg['nav_exchange_key'] ?? '');
        // A NAV a user/taxNumber mezőben az adószám ELSŐ 8 számjegyét várja
        // (UserHeaderType leírása a specifikációban) — ugyanaz a minta,
        // mint amit NavTaxpayerLookup::lookup() a LEKÉRDEZETT adószámra
        // már alkalmaz, itt a SAJÁT (technikai felhasználóhoz tartozó)
        // adószámra.
        $this->taxNumber = substr(preg_replace('/[^0-9]/', '', (string) ($cfg['nav_tax_number'] ?? '')), 0, 8);
        $this->baseUrl = !empty($cfg['nav_test_mode'])
            ? 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3'
            : 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3';
        $this->httpTransport = $httpTransport ?? [$this, 'defaultHttpPost'];
    }

    /**
     * Biztonságos kapcsolat-teszt: egy tényleges tokenExchange hívás,
     * hitelesítő adatok elárulása nélkül — csak azt jelzi vissza, hogy a
     * NAV elfogadta-e a hitelesítést (endpoint, technikai felhasználó,
     * jelszó, adószám, aláíró kulcs, aláírás-számítás).
     */
    public function testConnection(): array
    {
        $result = $this->tokenExchange();

        return [
            'success'        => $result['success'],
            'http_status'    => $result['http_status'],
            'nav_error_code' => $result['nav_error_code'],
            'error'          => $result['error'],
        ];
    }

    /**
     * POST /tokenExchange — a manageInvoice/manageAnnulment előfeltétele.
     * Sikeres válasz esetén a dekódolt (AES-128 ECB, a "csere kulccsal")
     * exchange tokent adja vissza — ezt kell a manageInvoiceCreate()
     * hívásnak átadni. A token élettartama rövid (lásd tokenValidityTo);
     * ez a kliens SZÁNDÉKOSAN nem cache-eli — ebben a körben minden
     * teszt-flow egyetlen PHP-folyamaton belül kér és használ fel egy
     * tokent, tartós cache-elés csak a Phase 5B queue-jában indokolt,
     * ahol a manageInvoice hívás ténylegesen elválhat a tokenExchange-től.
     */
    public function tokenExchange(): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = $this->simpleSignature($requestId, $tsForSig);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<TokenExchangeRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . '</TokenExchangeRequest>';

        try {
            $response = $this->post('/tokenExchange', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'token' => null, 'valid_from' => null, 'valid_to' => null, 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'token' => null, 'valid_from' => null, 'valid_to' => null,
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $encoded = $this->extractValue($doc, 'encodedExchangeToken');
        if ($encoded === null) {
            return [
                'success' => false, 'token' => null, 'valid_from' => null, 'valid_to' => null,
                'http_status' => $response['status'], 'nav_error_code' => null,
                'error' => 'A NAV válasza sikeresnek (funcCode=OK) jelezte a tokenExchange-t, de nem tartalmazott encodedExchangeToken mezőt.',
            ];
        }

        $decoded = @openssl_decrypt((string) base64_decode($encoded, true), 'aes-128-ecb', $this->exchangeKey, OPENSSL_RAW_DATA);
        if ($decoded === false || $decoded === '') {
            return [
                'success' => false, 'token' => null, 'valid_from' => null, 'valid_to' => null,
                'http_status' => $response['status'], 'nav_error_code' => null,
                'error' => 'A NAV tokenExchange válasza sikeres volt, de a token AES-128 dekódolása sikertelen — ellenőrizd a beállított "csere kulcsot" (nav_exchange_key).',
            ];
        }

        return [
            'success' => true, 'token' => $decoded,
            'valid_from' => $this->extractValue($doc, 'tokenValidityFrom'),
            'valid_to' => $this->extractValue($doc, 'tokenValidityTo'),
            'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null,
        ];
    }

    /**
     * POST /manageInvoice, egyetlen CREATE művelettel. $invoiceDataXml a
     * NAV invoiceData.xsd szerinti, MÁR KÉSZ XML (lásd NavInvoiceXmlBuilder)
     * — ez a metódus csak base64-kódolja, becsomagolja és a manageInvoice-
     * specifikus képlettel aláírja a kérést. A NAV feldolgozás ASZINKRON —
     * a sikeres válasz CSAK egy transactionId-t ad, nem jelenti azt, hogy a
     * számla ténylegesen elfogadásra került (lásd queryTransactionStatus()).
     */
    public function manageInvoiceCreate(string $exchangeToken, string $invoiceDataXml, int $index = 1): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $dataBase64 = base64_encode($invoiceDataXml);
        $signature = $this->manageInvoiceSignature($requestId, $tsForSig, [
            ['operation' => 'CREATE', 'data_base64' => $dataBase64],
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ManageInvoiceRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . '<exchangeToken>' . htmlspecialchars($exchangeToken, ENT_XML1) . '</exchangeToken>'
            . '<invoiceOperations>'
            . '<compressedContent>false</compressedContent>'
            . '<invoiceOperation>'
            . '<index>' . $index . '</index>'
            . '<invoiceOperation>CREATE</invoiceOperation>'
            . '<invoiceData>' . $dataBase64 . '</invoiceData>'
            . '</invoiceOperation>'
            . '</invoiceOperations>'
            . '</ManageInvoiceRequest>';

        try {
            $response = $this->post('/manageInvoice', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'transaction_id' => null, 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'transaction_id' => null,
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $transactionId = $this->extractValue($doc, 'transactionId');
        if ($transactionId === null) {
            return [
                'success' => false, 'transaction_id' => null,
                'http_status' => $response['status'], 'nav_error_code' => null,
                'error' => 'A NAV válasza sikeresnek (funcCode=OK) jelezte a manageInvoice-t, de nem tartalmazott transactionId mezőt.',
            ];
        }

        return ['success' => true, 'transaction_id' => $transactionId, 'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null];
    }

    /**
     * POST /queryTransactionStatus — a manageInvoice hívás UTÁN, később
     * (a feldolgozás aszinkron) lekérdezi egy tranzakció végleges
     * állapotát. A NAV InvoiceStatusType enumja szerint: RECEIVED
     * (befogadva) → PROCESSING (feldolgozás alatt) → SAVED (elmentve) →
     * DONE (kész) | ABORTED (kihagyva/elutasítva). A processingResults
     * mező HIÁNYA nem hiba — azt jelenti, a feldolgozás még túl korai
     * fázisban van (lásd 'pending' a visszatérési tömbben).
     */
    public function queryTransactionStatus(string $transactionId, bool $returnOriginalRequest = false): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = $this->simpleSignature($requestId, $tsForSig);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryTransactionStatusRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . '<transactionId>' . htmlspecialchars($transactionId, ENT_XML1) . '</transactionId>'
            . '<returnOriginalRequest>' . ($returnOriginalRequest ? 'true' : 'false') . '</returnOriginalRequest>'
            . '</QueryTransactionStatusRequest>';

        try {
            $response = $this->post('/queryTransactionStatus', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'pending' => false, 'invoice_status' => null, 'technical_messages' => [], 'business_messages' => [], 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'pending' => false, 'invoice_status' => null, 'technical_messages' => [], 'business_messages' => [],
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $invoiceStatus = $this->extractValue($doc, 'invoiceStatus');
        $technicalMessages = $this->extractAllValues($doc, 'message');
        $originalRequestBase64 = $returnOriginalRequest ? $this->extractValue($doc, 'originalRequest') : null;

        return [
            'success' => true,
            'pending' => $invoiceStatus === null,
            'invoice_status' => $invoiceStatus,
            'technical_messages' => $technicalMessages,
            'business_messages' => [],
            'original_request_base64' => $originalRequestBase64,
            'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null,
        ];
    }

    /**
     * POST /queryTransactionList — a specifikáció saját "1.6.6 Response
     * time, timeout" szakasza által kifejezetten AJÁNLOTT mechanizmus arra
     * az esetre, ha egy manageInvoice hívás válasza timeout/hálózati hiba
     * miatt elveszett, és emiatt nem ismert transactionId: "The
     * queryTransactionList operation can be used to track extreme cases of
     * not receiving a response (timeout)." Egy $insDateFrom..$insDateTo
     * UTC időablakban listázza a technikai felhasználóhoz beérkezett
     * tranzakciókat (transactionId + requestStatus, DE a tényleges számla-
     * tartalmat NEM) — lásd NavInvoiceProvider::recoverUncertainInvoice(),
     * ami minden találtat egy queryTransactionStatus(returnOriginalRequest:
     * true) hívással vet össze a saját, ismert invoiceNumber-ünkkel, hogy
     * pozitívan azonosítsa, a bizonytalan kimenetelű kérésünk ténylegesen
     * megérkezett-e a NAV-hoz.
     */
    public function queryTransactionList(string $insDateFrom, string $insDateTo, int $page = 1): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = $this->simpleSignature($requestId, $tsForSig);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryTransactionListRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . '<page>' . $page . '</page>'
            . '<insDate>'
            . '<dateTimeFrom>' . htmlspecialchars($insDateFrom, ENT_XML1) . '</dateTimeFrom>'
            . '<dateTimeTo>' . htmlspecialchars($insDateTo, ENT_XML1) . '</dateTimeTo>'
            . '</insDate>'
            . '</QueryTransactionListRequest>';

        try {
            $response = $this->post('/queryTransactionList', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'transactions' => [], 'current_page' => null, 'available_page' => null, 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'transactions' => [], 'current_page' => null, 'available_page' => null,
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $transactions = [];
        if ($doc !== null) {
            foreach ($doc->xpath("//*[local-name()='transaction']") as $tx) {
                $transactions[] = [
                    'transaction_id' => $this->extractValueRelative($tx, 'transactionId'),
                    'ins_date' => $this->extractValueRelative($tx, 'insDate'),
                    'request_status' => $this->extractValueRelative($tx, 'requestStatus'),
                ];
            }
        }

        return [
            'success' => true,
            'transactions' => $transactions,
            'current_page' => $this->extractValue($doc, 'currentPage'),
            'available_page' => $this->extractValue($doc, 'availablePage'),
            'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null,
        ];
    }

    /**
     * POST /queryInvoiceDigest — bejövő (vagy kimenő) számlák kivonatos
     * listázása egy `insDate` (a NAV saját, monoton feldolgozási
     * időbélyege) alapú UTC időablakban, lapozva. Mező- és
     * struktúra-forrás: invoiceApi.xsd `QueryInvoiceDigestRequestType` /
     * `QueryInvoiceDigestResponseType` / `InvoiceDigestType` — SEMMI nincs
     * kitalálva. A `MandatoryQueryParamsType` az `invoiceIssueDate` /
     * `insDate` / `originalInvoiceNumber` HÁROM lehetősége közül egy
     * xs:choice — ez a metódus szándékosan mindig az `insDate`-et
     * használja (lásd Phase 6 terv indoklása: ez a NAV saját feldolgozási
     * időbélyege, ideális magas-vízjel az inkrementális synchez).
     *
     * A specifikáció szerint (1.6.x, "the difference between the times
     * given cannot exceed 35 days (or 840 hours)") az $insDateFrom..
     * $insDateTo közti különbség NEM haladhatja meg a 35 napot — ezt a
     * HÍVÓ (NavIncomingInvoiceSync) felelőssége betartani, ez a metódus
     * önmagában nem validálja (ugyanúgy, ahogy a meglévő
     * queryTransactionList() sem).
     *
     * Nincs exchange token — ugyanaz az "1.5.2" aláírás-eset, mint
     * queryTransactionList()/queryTransactionStatus() esetén (a
     * BasicOnlineInvoiceRequestType nem igényel külön hitelesítést a
     * manageInvoice-hoz képest).
     */
    public function queryInvoiceDigest(string $insDateFrom, string $insDateTo, string $invoiceDirection, int $page = 1): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = $this->simpleSignature($requestId, $tsForSig);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryInvoiceDigestRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . '<page>' . $page . '</page>'
            . '<invoiceDirection>' . htmlspecialchars($invoiceDirection, ENT_XML1) . '</invoiceDirection>'
            . '<invoiceQueryParams>'
            . '<mandatoryQueryParams>'
            . '<insDate>'
            . '<dateTimeFrom>' . htmlspecialchars($insDateFrom, ENT_XML1) . '</dateTimeFrom>'
            . '<dateTimeTo>' . htmlspecialchars($insDateTo, ENT_XML1) . '</dateTimeTo>'
            . '</insDate>'
            . '</mandatoryQueryParams>'
            . '</invoiceQueryParams>'
            . '</QueryInvoiceDigestRequest>';

        try {
            $response = $this->post('/queryInvoiceDigest', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'invoices' => [], 'current_page' => null, 'available_page' => null, 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'invoices' => [], 'current_page' => null, 'available_page' => null,
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $invoices = [];
        if ($doc !== null) {
            foreach ($doc->xpath("//*[local-name()='invoiceDigest']") as $digest) {
                $invoices[] = [
                    'invoice_number' => $this->extractValueRelative($digest, 'invoiceNumber'),
                    'batch_index' => $this->extractValueRelative($digest, 'batchIndex'),
                    'invoice_operation' => $this->extractValueRelative($digest, 'invoiceOperation'),
                    'invoice_category' => $this->extractValueRelative($digest, 'invoiceCategory'),
                    'invoice_issue_date' => $this->extractValueRelative($digest, 'invoiceIssueDate'),
                    'supplier_tax_number' => $this->extractValueRelative($digest, 'supplierTaxNumber'),
                    'supplier_group_member_tax_number' => $this->extractValueRelative($digest, 'supplierGroupMemberTaxNumber'),
                    'supplier_name' => $this->extractValueRelative($digest, 'supplierName'),
                    'customer_tax_number' => $this->extractValueRelative($digest, 'customerTaxNumber'),
                    'customer_group_member_tax_number' => $this->extractValueRelative($digest, 'customerGroupMemberTaxNumber'),
                    'customer_name' => $this->extractValueRelative($digest, 'customerName'),
                    'payment_method' => $this->extractValueRelative($digest, 'paymentMethod'),
                    'payment_date' => $this->extractValueRelative($digest, 'paymentDate'),
                    'invoice_appearance' => $this->extractValueRelative($digest, 'invoiceAppearance'),
                    'source' => $this->extractValueRelative($digest, 'source'),
                    'invoice_delivery_date' => $this->extractValueRelative($digest, 'invoiceDeliveryDate'),
                    'currency' => $this->extractValueRelative($digest, 'currency'),
                    'invoice_net_amount' => $this->extractValueRelative($digest, 'invoiceNetAmount'),
                    'invoice_net_amount_huf' => $this->extractValueRelative($digest, 'invoiceNetAmountHUF'),
                    'invoice_vat_amount' => $this->extractValueRelative($digest, 'invoiceVatAmount'),
                    'invoice_vat_amount_huf' => $this->extractValueRelative($digest, 'invoiceVatAmountHUF'),
                    'transaction_id' => $this->extractValueRelative($digest, 'transactionId'),
                    'index' => $this->extractValueRelative($digest, 'index'),
                    'original_invoice_number' => $this->extractValueRelative($digest, 'originalInvoiceNumber'),
                    'modification_index' => $this->extractValueRelative($digest, 'modificationIndex'),
                    'ins_date' => $this->extractValueRelative($digest, 'insDate'),
                    'completeness_indicator' => $this->extractValueRelative($digest, 'completenessIndicator'),
                ];
            }
        }

        return [
            'success' => true,
            'invoices' => $invoices,
            'current_page' => $this->extractValue($doc, 'currentPage'),
            'available_page' => $this->extractValue($doc, 'availablePage'),
            'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null,
        ];
    }

    /**
     * POST /queryInvoiceData — egy adott, már ismert számlaszámhoz tartozó
     * TELJES számla-adattartalom lekérdezése (LAZY, csak részletnézet
     * megnyitásakor hívandó — lásd Phase 6 terv 2b. pont indoklása).
     * Struktúra-forrás: invoiceApi.xsd `QueryInvoiceDataRequestType` /
     * `InvoiceNumberQueryType` / `QueryInvoiceDataResponseType` /
     * `InvoiceDataResultType` — SEMMI nincs kitalálva.
     *
     * A válasz `invoiceData` mezője BASE64-kódolt XML, UGYANABBAN a
     * `invoiceData.xsd` sémában, amit a meglévő NavInvoiceXmlBuilder a
     * KIMENŐ oldalon épít — ennek a base64-dekódolt tartalomnak a
     * feldolgozása (tételsorok, supplierAddress stb. kinyerése) a HÍVÓ
     * (NavIncomingInvoiceSync) felelőssége, ez a metódus csak a
     * dekódolatlan base64 tartalmat adja vissza.
     */
    public function queryInvoiceData(string $invoiceNumber, string $invoiceDirection, ?string $supplierTaxNumber = null, ?int $batchIndex = null): array
    {
        [$requestId, $timestamp, $tsForSig] = $this->buildHeader();
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = $this->simpleSignature($requestId, $tsForSig);

        $invoiceNumberQuery = '<invoiceNumberQuery>'
            . '<invoiceNumber>' . htmlspecialchars($invoiceNumber, ENT_XML1) . '</invoiceNumber>'
            . '<invoiceDirection>' . htmlspecialchars($invoiceDirection, ENT_XML1) . '</invoiceDirection>';
        if ($batchIndex !== null) {
            $invoiceNumberQuery .= '<batchIndex>' . $batchIndex . '</batchIndex>';
        }
        if ($supplierTaxNumber !== null && $supplierTaxNumber !== '') {
            $invoiceNumberQuery .= '<supplierTaxNumber>' . htmlspecialchars($supplierTaxNumber, ENT_XML1) . '</supplierTaxNumber>';
        }
        $invoiceNumberQuery .= '</invoiceNumberQuery>';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryInvoiceDataRequest xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . $this->headerXml($requestId, $timestamp)
            . $this->userXml($passwordHash, $signature)
            . $this->softwareXml()
            . $invoiceNumberQuery
            . '</QueryInvoiceDataRequest>';

        try {
            $response = $this->post('/queryInvoiceData', $xml);
        } catch (Throwable $e) {
            return ['success' => false, 'found' => false, 'invoice_data_base64' => null, 'compressed' => false, 'http_status' => null, 'nav_error_code' => null, 'error' => $e->getMessage()];
        }

        $doc = $this->parseXml($response['body']);
        $funcCode = $this->extractValue($doc, 'funcCode');
        if ($funcCode !== 'OK') {
            $errorCode = $this->extractValue($doc, 'errorCode');
            $message = $this->extractValue($doc, 'message');
            return [
                'success' => false, 'found' => false, 'invoice_data_base64' => null, 'compressed' => false,
                'http_status' => $response['status'], 'nav_error_code' => $errorCode,
                'error' => $message ?? $errorCode ?? 'Ismeretlen NAV hiba (a válasz nem tartalmazott funcCode=OK jelzést).',
            ];
        }

        $invoiceDataBase64 = $this->extractValue($doc, 'invoiceData');
        $compressed = $this->extractValue($doc, 'compressedContentIndicator');

        return [
            'success' => true,
            'found' => $invoiceDataBase64 !== null,
            'invoice_data_base64' => $invoiceDataBase64,
            'compressed' => $compressed === 'true',
            'http_status' => $response['status'], 'nav_error_code' => null, 'error' => null,
        ];
    }

    // ---- Aláírás-számítás ----

    /**
     * "1.5.2" eset — tokenExchange, queryTransactionStatus, queryTaxpayer.
     */
    private function simpleSignature(string $requestId, string $tsForSig): string
    {
        return strtoupper(hash('sha3-512', $requestId . $tsForSig . $this->signerKey));
    }

    /**
     * "1.5.1" eset — manageInvoice, manageAnnulment. $operations minden
     * eleme ['operation' => 'CREATE'|'MODIFY'|'STORNO', 'data_base64' =>
     * string] alakú, a kérésbeli invoiceOperation-ök sorrendjében.
     */
    private function manageInvoiceSignature(string $requestId, string $tsForSig, array $operations): string
    {
        $base = $requestId . $tsForSig . $this->signerKey;
        foreach ($operations as $op) {
            $base .= strtoupper(hash('sha3-512', $op['operation'] . $op['data_base64']));
        }
        return strtoupper(hash('sha3-512', $base));
    }

    // ---- Kérés-építés ----

    private function buildHeader(): array
    {
        $requestId = 'SM' . date('YmdHis') . random_int(1000, 9999);
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $tsForSig = gmdate('YmdHis');
        return [$requestId, $timestamp, $tsForSig];
    }

    private function headerXml(string $requestId, string $timestamp): string
    {
        return '<common:header>'
            . '<common:requestId>' . htmlspecialchars($requestId, ENT_XML1) . '</common:requestId>'
            . '<common:timestamp>' . $timestamp . '</common:timestamp>'
            . '<common:requestVersion>' . self::REQUEST_VERSION . '</common:requestVersion>'
            . '<common:headerVersion>' . self::HEADER_VERSION . '</common:headerVersion>'
            . '</common:header>';
    }

    private function userXml(string $passwordHash, string $signature): string
    {
        return '<common:user>'
            . '<common:login>' . htmlspecialchars($this->login, ENT_XML1) . '</common:login>'
            . '<common:passwordHash cryptoType="SHA-512">' . $passwordHash . '</common:passwordHash>'
            . '<common:taxNumber>' . htmlspecialchars($this->taxNumber, ENT_XML1) . '</common:taxNumber>'
            . '<common:requestSignature cryptoType="SHA3-512">' . $signature . '</common:requestSignature>'
            . '</common:user>';
    }

    /**
     * A <software> blokk a NAV api NÉVTERÉBEN van, NEM a common névtérben
     * — ellentétben azzal, ahogy a meglévő NavTaxpayerLookup.php
     * (<common:software>-ot használva) build-eli. Ez a NAV publikus
     * minta-XML-jeivel (queryTaxpayer.xml, tokenExchange.xml,
     * manageInvoice.xml — mindegyik prefix nélküli <software>-t használ) és
     * az invoiceApi.xsd BasicOnlineInvoiceRequestType definíciójával
     * igazolt eltérés — lásd a NavTaxpayerLookup.php-ban is elvégzett,
     * ugyanezen hiba javítását.
     */
    private function softwareXml(): string
    {
        return '<software>'
            . '<softwareId>' . self::SOFTWARE_ID . '</softwareId>'
            . '<softwareName>StockManager</softwareName>'
            . '<softwareOperation>LOCAL_SOFTWARE</softwareOperation>'
            . '<softwareMainVersion>1.0</softwareMainVersion>'
            . '<softwareDevName>Fountainbridge</softwareDevName>'
            . '<softwareDevContact>info@example.com</softwareDevContact>'
            . '</software>';
    }

    // ---- HTTP ----

    private function post(string $path, string $xml): array
    {
        return ($this->httpTransport)($this->baseUrl . $path, $xml);
    }

    /**
     * @return array{status:int, body:string}
     */
    private function defaultHttpPost(string $url, string $xml): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml', 'Accept: application/xml'],
            // A specifikáció szerint a NAV tipikus válaszideje <200ms, a
            // szinkron hívások blokkoló időkorlátja 5000ms — 20s bőven elég
            // tartalék, anélkül hogy indokolatlanul sokáig lógna a hívás.
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("NAV kapcsolati hiba: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    // ---- Válasz-értelmezés ----

    private function parseXml(string $body): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        return $xml !== false ? $xml : null;
    }

    private function extractValue(?SimpleXMLElement $xml, string $localName): ?string
    {
        if ($xml === null) {
            return null;
        }
        $nodes = $xml->xpath("//*[local-name()='" . $localName . "']");
        if (empty($nodes)) {
            return null;
        }
        $value = trim((string) $nodes[0]);
        return $value !== '' ? $value : null;
    }

    /**
     * Ugyanaz, mint extractValue(), de a `.//` relatív XPath-tal a MEGADOTT
     * elemen belülre szűkítve — SimpleXMLElement::xpath()-nál a sima `//`
     * MINDIG a teljes dokumentum gyökeréből indul, függetlenül attól, melyik
     * elemen hívjuk (jól ismert PHP-csapda) — emiatt egy ismétlődő elem
     * (pl. több <transaction>) EGYES példányain belüli mezőket csakis ezzel
     * a relatív változattal lehet helyesen, egymástól elkülönítve kiolvasni.
     */
    private function extractValueRelative(SimpleXMLElement $context, string $localName): ?string
    {
        $nodes = $context->xpath(".//*[local-name()='" . $localName . "']");
        if (empty($nodes)) {
            return null;
        }
        $value = trim((string) $nodes[0]);
        return $value !== '' ? $value : null;
    }

    /**
     * @return string[]
     */
    private function extractAllValues(?SimpleXMLElement $xml, string $localName): array
    {
        if ($xml === null) {
            return [];
        }
        $nodes = $xml->xpath("//*[local-name()='" . $localName . "']");
        $values = [];
        foreach ($nodes as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }
}
