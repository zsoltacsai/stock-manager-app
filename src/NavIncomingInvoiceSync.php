<?php

require_once __DIR__ . '/NavClient.php';
require_once __DIR__ . '/Database.php';

/**
 * A NAV Online Számla BEJÖVŐ (más adózók által kiállított, invoiceDirection
 * =INBOUND) számláinak szinkronizálási DÖNTÉSEit hozza meg — ablak-darabolás,
 * lapozás, dedup-mentés, hiba-osztályozás. A tényleges queue/claim/retry-
 * mechanikát (Phase 6 terv 10-12. pont szerint, a kimenő oldal
 * NavInvoiceProvider/NavInvoiceQueueWorker szereposztását követve) a
 * NavIncomingInvoiceSyncWorker végzi — ez az osztály önmagában NEM ismeri a
 * `incoming_invoice_sync` állapotgép claim/backoff logikáját, csak a NAV-
 * specifikus lekérdezést és a dedup-mentést.
 *
 * NAV API-forrás: invoiceApi.xsd `QueryInvoiceDigestRequestType`/
 * `InvoiceDigestType`/`QueryInvoiceDataRequestType` (lásd NavClient::
 * queryInvoiceDigest()/queryInvoiceData() docblockjai) — SEMMI nincs
 * kitalálva.
 */
class NavIncomingInvoiceSync
{
    private const PROVIDER = 'nav';

    /**
     * A NAV hivatalos specifikációja szerint ("the difference between the
     * times given cannot exceed 35 days (or 840 hours)") a queryInvoiceDigest
     * insDate-alapú ablaka legfeljebb ennyi nap lehet — ez a Phase 6 terv
     * 4-5. pontjának KÖZVETLEN alapja: egy hosszabb céltartományt kötelező
     * ennyi napos (vagy rövidebb) ablakokra darabolni.
     */
    private const MAX_WINDOW_DAYS = 35;

    /**
     * Felső korlát egyetlen ablak lapozására — a NAV a lapméretet MAGA
     * határozza meg (ugyanaz az elv, mint queryTransactionList-nél, lásd
     * NavInvoiceProvider::recoverUncertain() docblockja), ezért a lapszám
     * elméletileg tetszőlegesen nagy lehetne. Ez a korlát garantálja, hogy
     * SOSE alakulhasson végtelen ciklussá (hibás/szokatlan `availablePage`
     * esetén sem) — ha ennyi oldal után sincs vége, a sync 'failed'-ként
     * áll le, admin figyelmeztetéssel.
     */
    private const MAX_PAGES_PER_WINDOW = 50;

    /**
     * A specifikáció "3.2 Technical error codes" táblázatának tartós,
     * SOSE automatikusan újrapróbálandó hibakódjai — SZÁNDÉKOSAN egy KIS,
     * ÖNÁLLÓ másolata a NavInvoiceProvider::NON_RETRYABLE_ERROR_CODES-nak
     * (lásd Phase 6 terv 10-12. pont indoklása): a kimenő flow-hoz így
     * EGYÁLTALÁN nem kell hozzányúlni, még egy közös helper kiemelése
     * árán sem.
     */
    private const NON_RETRYABLE_ERROR_CODES = [
        'INVALID_REQUEST', 'INVALID_REQUEST_SIGNATURE', 'INVALID_REQUEST_SIGNATURE_HASH_CRYPTO',
        'INVALID_SECURITY_USER', 'INVALID_USER_RELATION', 'INVALID_CUSTOMER', 'NOT_REGISTERED_CUSTOMER',
        'INVALID_PASSWORD_HASH_CRYPTO', 'INVALID_REQUEST_VERSION', 'INVALID_HEADER_VERSION',
        'FORBIDDEN', 'REQUEST_ID_NOT_UNIQUE', 'INVALID_TIMESTAMP', 'SCHEMA_VIOLATION',
    ];

    private Database $db;
    /** @var callable(): NavClient */
    private $clientFactory;

    public function __construct(Database $db, callable $clientFactory)
    {
        $this->db = $db;
        $this->clientFactory = $clientFactory;
    }

    /**
     * $fromIso..$toIso (base:InvoiceTimestampType formátum, 'Y-m-d\TH:i:s\Z')
     * intervallumot legfeljebb $maxDays napos, egymást követő, hézagmentes
     * ablakokra bontja. Üres tömböt ad vissza, ha $fromIso >= $toIso (nincs
     * mit szinkronizálni — pl. a cursor már utoléte a jelent).
     *
     * @return list<array{from:string,to:string}>
     */
    public static function splitWindows(string $fromIso, string $toIso, int $maxDays = self::MAX_WINDOW_DAYS): array
    {
        $fromTs = strtotime($fromIso);
        $toTs = strtotime($toIso);
        if ($fromTs === false || $toTs === false || $fromTs >= $toTs) {
            return [];
        }

        $maxSeconds = $maxDays * 86400;
        $windows = [];
        $cursor = $fromTs;
        while ($cursor < $toTs) {
            $windowEnd = min($cursor + $maxSeconds, $toTs);
            $windows[] = ['from' => gmdate('Y-m-d\TH:i:s\Z', $cursor), 'to' => gmdate('Y-m-d\TH:i:s\Z', $windowEnd)];
            $cursor = $windowEnd;
        }

        return $windows;
    }

    /**
     * Legfeljebb $maxWindows db, ≤35-napos ablakot dolgoz fel $cursorInsDate
     * -tól $targetTo-ig — MINDEN sikeresen VÉGIGLAPOZOTT ablak után azonnal
     * előrehaladtja a sync cursor-t (Database::advanceIncomingInvoiceSyncCursor()),
     * hogy egy megszakadt/korlátozott futás ne dolgozza fel feleslegesen
     * újra a már kész ablakokat (lásd Phase 6 terv 4-5. pont).
     *
     * @return array{outcome:'success'|'retry'|'failed', windows_processed:int, new_cursor:string, has_more:bool, error:?string}
     */
    public function runOnce(string $cursorInsDate, string $targetTo, int $maxWindows): array
    {
        $windows = self::splitWindows($cursorInsDate, $targetTo, self::MAX_WINDOW_DAYS);
        if (empty($windows)) {
            return ['outcome' => 'success', 'windows_processed' => 0, 'new_cursor' => $cursorInsDate, 'has_more' => false, 'error' => null];
        }

        $processed = 0;
        $lastCursor = $cursorInsDate;

        foreach ($windows as $window) {
            if ($processed >= $maxWindows) {
                return ['outcome' => 'success', 'windows_processed' => $processed, 'new_cursor' => $lastCursor, 'has_more' => true, 'error' => null];
            }

            $result = $this->processWindow($window['from'], $window['to']);
            if ($result['outcome'] !== 'success') {
                return [
                    'outcome' => $result['outcome'],
                    'windows_processed' => $processed,
                    'new_cursor' => $lastCursor,
                    'has_more' => true,
                    'error' => $result['error'],
                ];
            }

            $this->db->advanceIncomingInvoiceSyncCursor(self::PROVIDER, $window['to']);
            $lastCursor = $window['to'];
            $processed++;
        }

        return ['outcome' => 'success', 'windows_processed' => $processed, 'new_cursor' => $lastCursor, 'has_more' => false, 'error' => null];
    }

    /**
     * Egyetlen ≤35-napos ablak TELJES lapozását végzi el — a NAV-szerver
     * saját maga határozza meg `availablePage`-et (nincs kliens-oldali
     * feltételezés a lapméretről). Minden oldal minden digest-bejegyzését
     * azonnal `INSERT OR IGNORE`-olja (Database::insertIncomingInvoiceDigestEntry()
     * — race-safe dedup, lásd ott).
     *
     * @return array{outcome:'success'|'retry'|'failed', error:?string}
     */
    private function processWindow(string $from, string $to): array
    {
        $client = ($this->clientFactory)();
        $page = 1;

        while (true) {
            if ($page > self::MAX_PAGES_PER_WINDOW) {
                return [
                    'outcome' => 'failed',
                    'error' => 'A queryInvoiceDigest lapozása túllépte a biztonsági korlátot (' . self::MAX_PAGES_PER_WINDOW . ' oldal) a ' . $from . '..' . $to . ' ablakban — admin ellenőrzés szükséges.',
                ];
            }

            $response = $client->queryInvoiceDigest($from, $to, 'INBOUND', $page);
            if (!$response['success']) {
                return $this->classifyFailure($response);
            }

            foreach ($response['invoices'] as $entry) {
                $this->db->insertIncomingInvoiceDigestEntry(self::mapDigestToEntry($entry));
            }

            // Ismeretlen/hibás availablePage (nem szám, vagy <=0) esetén
            // NEM feltételezünk további oldalakat — ez a NAV válasz
            // malformáltsága elleni védelem, nem generál végtelen ciklust.
            $availablePageRaw = $response['available_page'] ?? null;
            $availablePage = (is_numeric($availablePageRaw) && (int) $availablePageRaw > 0) ? (int) $availablePageRaw : 1;

            if ($page >= $availablePage) {
                return ['outcome' => 'success', 'error' => null];
            }
            $page++;
        }
    }

    /**
     * A NavClient::queryInvoiceDigest() egy digest-bejegyzését (a NAV
     * mezőnevek szerint elnevezve, lásd NavClient docblockja) a
     * Database::insertIncomingInvoiceDigestEntry() által várt oszlop-
     * elnevezésre fordítja (`transaction_id` → `nav_transaction_id`,
     * `invoice_net_amount`/`invoice_vat_amount` → `net_total`/`vat_total`,
     * `ins_date` → `nav_ins_date`) — a `incoming_invoices` séma
     * SZÁNDÉKOSAN csak a Phase 6 terv 2a. pontjában felsorolt, ténylegesen
     * felhasznált mezőket tárolja (pl. a HUF-devizás duplikátum összegeket,
     * `invoice_appearance`/`source`/`index`/`completeness_indicator`-t NEM).
     */
    private static function mapDigestToEntry(array $digest): array
    {
        return [
            'nav_transaction_id' => $digest['transaction_id'] ?? null,
            'invoice_number' => $digest['invoice_number'],
            'batch_index' => $digest['batch_index'] ?? 0,
            'supplier_tax_number' => $digest['supplier_tax_number'],
            'supplier_group_member_tax_number' => $digest['supplier_group_member_tax_number'] ?? null,
            'supplier_name' => $digest['supplier_name'],
            'customer_tax_number' => $digest['customer_tax_number'] ?? null,
            'customer_name' => $digest['customer_name'] ?? null,
            'invoice_operation' => $digest['invoice_operation'],
            'invoice_category' => $digest['invoice_category'] ?? null,
            'original_invoice_number' => $digest['original_invoice_number'] ?? null,
            'modification_index' => $digest['modification_index'] ?? null,
            'invoice_issue_date' => $digest['invoice_issue_date'] ?? null,
            'invoice_delivery_date' => $digest['invoice_delivery_date'] ?? null,
            'payment_date' => $digest['payment_date'] ?? null,
            'payment_method' => $digest['payment_method'] ?? null,
            'currency' => $digest['currency'] ?? 'HUF',
            'net_total' => $digest['invoice_net_amount'] ?? 0.0,
            'vat_total' => $digest['invoice_vat_amount'] ?? 0.0,
            'nav_ins_date' => $digest['ins_date'],
        ];
    }

    /**
     * LAZY részletnézet-lekérdezés — a worker/API endpoint hívja, amikor a
     * felhasználó ténylegesen megnyit egy olyan bejövő számlát, aminek még
     * nincs `detail_fetched_at`-ja. A NAV `queryInvoiceData` válaszának
     * base64-XML tartalmát (UGYANAZ az invoiceData.xsd séma, amit a
     * meglévő NavInvoiceXmlBuilder ír a kimenő oldalon) dekódolja,
     * strukturált tételsorokra és a szállító országára bontja, majd
     * elmenti (Database::saveIncomingInvoiceDetail()) — a nyers XML-t NEM
     * tárolja el (lásd Phase 6 terv 2a. pont indoklása).
     *
     * @param array $incomingInvoiceRow egy `incoming_invoices` sor (id, invoice_number, supplier_tax_number, batch_index)
     * @return array{outcome:'success'|'retry'|'failed'|'not_found', error:?string, items_count?:int}
     */
    public function fetchAndStoreDetail(array $incomingInvoiceRow): array
    {
        $client = ($this->clientFactory)();
        $batchIndex = (int) ($incomingInvoiceRow['batch_index'] ?? 0);

        $result = $client->queryInvoiceData(
            (string) $incomingInvoiceRow['invoice_number'],
            'INBOUND',
            (string) $incomingInvoiceRow['supplier_tax_number'],
            $batchIndex > 0 ? $batchIndex : null
        );

        if (!$result['success']) {
            return $this->classifyFailure($result);
        }

        if (!$result['found'] || empty($result['invoice_data_base64'])) {
            return ['outcome' => 'not_found', 'error' => 'A NAV nem talált adatot ehhez a számlához (queryInvoiceData üres eredményt adott).'];
        }

        $rawXml = base64_decode((string) $result['invoice_data_base64'], true);
        if ($rawXml === false) {
            return ['outcome' => 'failed', 'error' => 'A NAV válasz invoiceData mezője nem érvényes base64.'];
        }

        if (!empty($result['compressed'])) {
            $decompressed = @gzdecode($rawXml);
            if ($decompressed === false) {
                // A specifikáció szerint feltételezett struktúrától való
                // ELTÉRÉS — SZÁNDÉKOSAN nem workaroundoljuk csendben (lásd
                // a Phase 6 kérés explicit "ne workaroundold csendben"
                // pontja): a hiba pontosan leírva a last_error-ban jelenik
                // meg, admin figyelmét igényli.
                return [
                    'outcome' => 'failed',
                    'error' => 'A NAV compressedContentIndicator=true jelzést adott, de a tartalom nem dekódolható GZIP-ként — ez eltér a specifikáció alapján feltételezett struktúrától, manuális ellenőrzés szükséges.',
                ];
            }
            $rawXml = $decompressed;
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($rawXml);
        libxml_clear_errors();
        if ($doc === false) {
            return ['outcome' => 'failed', 'error' => 'A NAV invoiceData tartalma nem értelmezhető XML-ként.'];
        }

        $supplierInfoNodes = $doc->xpath("//*[local-name()='supplierInfo']");
        $supplierCountry = !empty($supplierInfoNodes) ? self::xmlExtract($supplierInfoNodes[0], 'countryCode') : null;

        $items = [];
        foreach ($doc->xpath("//*[local-name()='line']") as $line) {
            $lineNumber = self::xmlExtract($line, 'lineNumber');
            if ($lineNumber === null || !is_numeric($lineNumber)) {
                continue;
            }
            $items[] = [
                'line_number' => (int) $lineNumber,
                'description' => self::xmlExtract($line, 'lineDescription'),
                'quantity' => self::xmlExtract($line, 'quantity'),
                'unit_of_measure' => self::xmlExtract($line, 'unitOfMeasure'),
                'unit_net_price' => self::xmlExtract($line, 'unitPrice'),
                'vat_rate' => self::formatVatRate(self::xmlExtract($line, 'vatPercentage')),
                'net_amount' => self::xmlExtract($line, 'lineNetAmount'),
                'vat_amount' => self::xmlExtract($line, 'lineVatAmount'),
                'gross_amount' => self::xmlExtract($line, 'lineGrossAmountNormal'),
            ];
        }

        $this->db->saveIncomingInvoiceDetail((int) $incomingInvoiceRow['id'], $supplierCountry, $items);

        return ['outcome' => 'success', 'error' => null, 'items_count' => count($items)];
    }

    /**
     * Egy sikertelen NavClient-hívást osztályoz retry/failed kategóriába.
     * Szándékosan NINCS 'uncertain' ág (ellentétben NavInvoiceProvider::
     * classifyFailure()-jével) — queryInvoiceDigest/queryInvoiceData
     * TISZTÁN olvasó jellegű műveletek, nincs mellékhatásuk, egy hálózati
     * hiba után egyszerű retry mindig biztonságos.
     */
    private function classifyFailure(array $result): array
    {
        if ($result['http_status'] === null) {
            return ['outcome' => 'retry', 'error' => $result['error']];
        }

        $errorCode = (string) ($result['nav_error_code'] ?? '');

        if (in_array($errorCode, self::NON_RETRYABLE_ERROR_CODES, true)) {
            return ['outcome' => 'failed', 'error' => $result['error']];
        }

        if ($errorCode === 'MAINTENANCE_MODE' || (int) $result['http_status'] >= 500) {
            return ['outcome' => 'retry', 'error' => $result['error']];
        }

        return ['outcome' => 'failed', 'error' => $result['error']];
    }

    private static function xmlExtract(?SimpleXMLElement $context, string $localName): ?string
    {
        if ($context === null) {
            return null;
        }
        $nodes = $context->xpath(".//*[local-name()='" . $localName . "']");
        if (empty($nodes)) {
            return null;
        }
        $value = trim((string) $nodes[0]);
        return $value !== '' ? $value : null;
    }

    /**
     * A NAV a `vatPercentage`-t 0.XX törtként adja (pl. "0.2700" = 27%,
     * lásd NavInvoiceXmlBuilder::formatDecimal($vatPct, 4) a kimenő
     * oldalon, UGYANAZ a séma) — az app saját konvenciója szerint (lásd
     * Database `vat_rate` mezők, sale_items.vat_rate) ez egy string
     * SZÁZALÉK ("27", "18", "5", "0"), ide alakítja át.
     */
    private static function formatVatRate(?string $vatPercentage): ?string
    {
        if ($vatPercentage === null || !is_numeric($vatPercentage)) {
            return null;
        }
        $pct = ((float) $vatPercentage) * 100;
        $formatted = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
