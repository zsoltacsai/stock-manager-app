<?php

require_once __DIR__ . '/InvoiceProviderInterface.php';
require_once __DIR__ . '/NavClient.php';
require_once __DIR__ . '/NavInvoiceXmlBuilder.php';
require_once __DIR__ . '/NavTokenCache.php';

/**
 * A NAV Online Számla InvoiceProviderInterface-implementációja —
 * ASZINKRON (isAsync()===true): enqueue() csak egy tartós `invoices`
 * queue-sort hoz létre (lásd Database::insertQueuedInvoice()), a
 * tényleges NAV-hívás (tokenExchange → manageInvoice → később
 * queryTransactionStatus) NEM itt, a kérés-kiszolgálás közben történik,
 * hanem egy külön cron-worker által (lásd NavInvoiceQueueWorker,
 * webroot/api/nav-queue-run.php) — ez garantálja, hogy egy NAV-leállás
 * SOSE lassítja/hiúsítja meg magát az eladás rögzítését.
 *
 * Ez az osztály felelős:
 *   - a számlázási payload összeállításáért (enqueue());
 *   - a tényleges NAV manageInvoice-beküldésért, transactionId
 *     eltárolásáért (submit());
 *   - a NAV feldolgozási állapotának lekérdezéséért (checkStatus());
 *   - a manageInvoice-timeout utáni, bizonytalan kimenetelű kérések
 *     queryTransactionList-alapú egyeztetéséért (recoverUncertain());
 *   - a NAV-hibák retryable/permanent/uncertain osztályozásáért
 *     (classifyFailure()).
 * A tényleges queue-mechanika (claim, backoff-időzítés, max attempts,
 * DB-állapotátmenetek alkalmazása) a NavInvoiceQueueWorker feladata — ez
 * az osztály csak a NAV-specifikus döntéseket hozza meg, maga NEM ír az
 * `invoices` táblába (az enqueue() kivételével).
 */
class NavInvoiceProvider implements InvoiceProviderInterface
{
    /**
     * A specifikáció "3.2 Technical error codes" táblázatában szereplő,
     * tartós konfigurációs/hitelesítési/validációs hibák — ezeket SOSE
     * szabad automatikusan újraküldeni: egy vak retry ugyanazzal a
     * (bizonyítottan hibás) kéréssel sose sikerülne, csak feleslegesen
     * terhelné a NAV-ot és elrejtené a valódi hibát az admin elől.
     * (INVALID_EXCHANGE_TOKEN szándékosan NINCS itt — lásd classifyFailure().)
     */
    private const NON_RETRYABLE_ERROR_CODES = [
        'INVALID_REQUEST', 'INVALID_REQUEST_SIGNATURE', 'INVALID_REQUEST_SIGNATURE_HASH_CRYPTO',
        'INVALID_SECURITY_USER', 'INVALID_USER_RELATION', 'INVALID_CUSTOMER', 'NOT_REGISTERED_CUSTOMER',
        'INVALID_PASSWORD_HASH_CRYPTO', 'INVALID_REQUEST_VERSION', 'INVALID_HEADER_VERSION',
        'FORBIDDEN', 'REQUEST_ID_NOT_UNIQUE', 'INVALID_TIMESTAMP', 'SCHEMA_VIOLATION',
        'INVALID_PREDECESSOR_TAX_NUMBER',
    ];

    /**
     * Felső korlát a recoverUncertain() queryTransactionList-lapozására —
     * lásd a metódus docblockját. 5 oldal (a NAV szerver-oldalon
     * meghatározott, itt nem ismert lapmérettel) bőséges tartalék egy
     * ritka, csak timeout után induló egyeztetéshez, anélkül hogy egy
     * szokatlanul aktív technikai felhasználónál korlátlan lekérés-
     * sorozatot indítana.
     */
    private const MAX_RECOVERY_PAGES = 5;

    private array $navConfig;
    private array $supplierConfig;
    private NavTokenCache $tokenCache;
    /** @var callable(): NavClient */
    private $clientFactory;

    /**
     * @param array $navConfig {nav_login, nav_password, nav_signer_key, nav_exchange_key, nav_tax_number, nav_test_mode}
     * @param array $supplierConfig {tax_number, name, zip, city, address, bank_account?} — lásd NavInvoiceXmlBuilder
     * @param callable(): NavClient|null $clientFactory teszteléshez cserélhető NavClient-előállító; alapértelmezetten valódi NavClient-et épít $navConfig-ból
     */
    public function __construct(array $navConfig, array $supplierConfig, NavTokenCache $tokenCache, ?callable $clientFactory = null)
    {
        $this->navConfig = $navConfig;
        $this->supplierConfig = $supplierConfig;
        $this->tokenCache = $tokenCache;
        $this->clientFactory = $clientFactory ?? fn () => new NavClient($this->navConfig);
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function issueSync(array $context): array
    {
        throw new RuntimeException('A NAV Online Számla szolgáltató aszinkron — issueSync() nem alkalmazható rá, lásd enqueue().');
    }

    /**
     * Csak a tartós queue-bejegyzést hozza létre — SOSE hív NAV API-t.
     * Race-safe és idempotens: lásd Database::insertQueuedInvoice()
     * docblockja (UNIQUE(sale_id, provider) + INSERT OR IGNORE/IGNORE).
     */
    public function enqueue(Database $db, int $saleId, array $context): void
    {
        $totals = $context['totals'] ?? [];
        $payload = [
            'buyer' => $context['buyer'],
            'items' => $context['items'],
            'payment_method' => $context['payment_method'] ?? null,
            'supplier' => $this->supplierConfig,
        ];

        $db->insertQueuedInvoice(
            $saleId,
            'nav',
            (float) ($totals['net'] ?? 0.0),
            (float) ($totals['vat'] ?? 0.0),
            (float) ($totals['gross'] ?? 0.0),
            (string) ($totals['currency'] ?? 'HUF'),
            $payload
        );
        // insertQueuedInvoice() null-t ad vissza, ha már létezik
        // queue-bejegyzés erre a sale_id+provider-re — ez SZÁNDÉKOSAN nem
        // hiba itt (idempotens no-op, lásd a metódus docblockját).
    }

    /**
     * A worker hívja egy claim-elt, 'queued' állapotú sorra: felépíti a
     * NAV-invoiceData XML-t, tokent kér (cache-elve), és beküldi
     * manageInvoice CREATE-tel. SOSE dob kifelé — mindig egy
     * {outcome, ...} alakú tömböt ad vissza, amiből a
     * NavInvoiceQueueWorker dönti el a konkrét DB-állapotátmenetet.
     *
     * outcome lehetséges értékei:
     *   'submitted' — sikeres manageInvoice, transaction_id ismert.
     *   'retry'     — átmeneti hiba (hálózat/timeout queryTransactionStatus-nál
     *                 NEM itt, hanem checkStatus()-nál; itt: HTTP 5xx, ismert
     *                 átmeneti NAV-hiba, vagy INVALID_EXCHANGE_TOKEN).
     *   'permanent' — tartós, NEM újrapróbálandó hiba (lásd NON_RETRYABLE_ERROR_CODES).
     *   'uncertain' — a manageInvoice HÍVÁS MAGA nem fejeződött be
     *                 (hálózat/timeout), NEM TUDJUK, a NAV megkapta-e —
     *                 SOSE automatikus újraküldés, lásd recoverUncertain().
     */
    public function submit(array $invoiceRow): array
    {
        $payload = json_decode((string) $invoiceRow['payload_json'], true) ?: [];

        try {
            $xml = NavInvoiceXmlBuilder::build([
                'invoice_number' => $invoiceRow['invoice_number'],
                'currency' => $invoiceRow['currency'],
                'supplier' => $payload['supplier'] ?? [],
                'buyer' => $payload['buyer'] ?? [],
                'items' => $payload['items'] ?? [],
                'payment_method' => $payload['payment_method'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Az XML-építés maga soha nem NAV-hálózati kérdés — ha itt
            // hibázik (pl. hiányos payload), az a payload/konfiguráció
            // hibája, NEM automatikusan újrapróbálandó.
            return ['outcome' => 'permanent', 'error' => 'Számla XML összeállítási hiba: ' . $e->getMessage()];
        }

        $client = ($this->clientFactory)();

        $tokenResult = $this->tokenCache->getOrRefresh(fn () => $client->tokenExchange());
        if (!$tokenResult['success']) {
            return $this->classifyFailure($tokenResult, allowUncertain: false);
        }

        $manageResult = $client->manageInvoiceCreate($tokenResult['token'], $xml, 1);
        if ($manageResult['success']) {
            return ['outcome' => 'submitted', 'transaction_id' => $manageResult['transaction_id']];
        }

        if (($manageResult['nav_error_code'] ?? null) === 'INVALID_EXCHANGE_TOKEN') {
            // A token érvénytelen/lejárt — ezt NEM a mi kérésünk hibája,
            // eldobjuk a cache-elt tokent, hogy a KÖVETKEZŐ próbálkozás
            // biztosan újat kérjen, és retryable-nek jelöljük.
            $this->tokenCache->invalidate();
            return ['outcome' => 'retry', 'error' => $manageResult['error']];
        }

        // A manageInvoice HÍVÁS MAGA nem fejeződött be (http_status===null
        // — a curl-hívás dobott, sose kaptunk választ) — ez az EGYETLEN
        // ág, ahol ténylegesen nem tudjuk, a NAV megkapta-e a kérést, mert
        // ennek van VALÓDI mellékhatása (egy NAV-nál esetleg már létező
        // számla). Ezért 'uncertain', SOSE vak retry.
        return $this->classifyFailure($manageResult, allowUncertain: true);
    }

    /**
     * A worker hívja egy claim-elt, 'submitted' állapotú sorra —
     * queryTransactionStatus-szal ellenőrzi a korábban beküldött
     * transactionId végleges állapotát. SOSE küld új manageInvoice-t.
     *
     * outcome lehetséges értékei:
     *   'done'    — a NAV véglegesen elfogadta (invoice_status===DONE).
     *   'failed'  — a NAV véglegesen elutasította (invoice_status===ABORTED).
     *   'pending' — a feldolgozás még folyamatban (RECEIVED/PROCESSING/SAVED,
     *               vagy a processingResults még nincs jelen) — a sor
     *               'submitted' marad, később újra ellenőrzendő.
     *   'retry'   — a lekérdezés HÍVÁSA hibázott (hálózat/timeout/5xx) —
     *               ennek NINCS mellékhatása, biztonságosan újrapróbálható,
     *               nem igényel 'uncertain' ágat.
     */
    public function checkStatus(array $invoiceRow): array
    {
        if (empty($invoiceRow['provider_ref'])) {
            return ['outcome' => 'permanent', 'error' => 'A számlához nincs eltárolt NAV transactionId, státusz nem lekérdezhető.'];
        }

        $client = ($this->clientFactory)();
        $statusResult = $client->queryTransactionStatus((string) $invoiceRow['provider_ref']);

        if (!$statusResult['success']) {
            $classified = $this->classifyFailure($statusResult, allowUncertain: false);
            // Egy queryTransactionStatus hívásnak nincs mellékhatása —
            // 'uncertain' itt sose indokolt, legfeljebb 'retry'/'permanent'.
            return $classified['outcome'] === 'uncertain' ? ['outcome' => 'retry', 'error' => $classified['error']] : $classified;
        }

        if ($statusResult['pending']) {
            return ['outcome' => 'pending'];
        }

        return match ($statusResult['invoice_status']) {
            'DONE' => ['outcome' => 'done'],
            'ABORTED' => ['outcome' => 'failed', 'error' => implode('; ', $statusResult['technical_messages']) ?: 'A NAV elutasította a számlát (ABORTED).'],
            // RECEIVED/PROCESSING/SAVED — még folyamatban.
            default => ['outcome' => 'pending'],
        };
    }

    /**
     * A worker hívja egy claim-elt, 'uncertain' állapotú sorra — a NAV
     * specifikáció saját ajánlása szerint ("1.6.6 Response time, timeout":
     * "The queryTransactionList operation can be used to track extreme
     * cases of not receiving a response") a queryTransactionList
     * műveletet használja annak eldöntésére, hogy egy korábbi,
     * timeout/hálózati hiba miatt bizonytalan kimenetelű manageInvoice
     * kérés ténylegesen megérkezett-e a NAV-hoz.
     *
     * Módszer: lekéri a tranzakciólistát abban az UTC időablakban, amikor
     * a bizonytalan kérés elindult (created_at/updated_at - 2 perc .. most
     * + 1 perc ráhagyás), majd MINDEN talált tranzakcióra
     * queryTransactionStatus(returnOriginalRequest: true) hívást tesz, és
     * az abban visszakapott, base64-kódolt EREDETI invoiceData XML
     * <invoiceNumber> mezőjét veti össze a SAJÁT, ismert
     * invoice_number-ünkkel — csak POZITÍV, tartalom-alapú egyezés esetén
     * fogadja el a találatot (nem csak azt, hogy "van egy tranzakció
     * ekkortájt" — ez tévesen egy MÁSIK, egyidejű beküldést is
     * "megtalálhatna").
     *
     * outcome lehetséges értékei:
     *   'submitted' — talált egyező transactionId-t, a sor 'submitted'-re
     *                 állítható (a következő checkStatus()-pass dönt majd
     *                 done/failed-ről).
     *   'still_uncertain' — nem talált egyértelmű egyezést (vagy a
     *                 lekérdezés maga hibázott) — a hívó felelőssége
     *                 eldönteni, hogy van-e még hátra recovery-próbálkozás,
     *                 vagy admin beavatkozás szükséges.
     *
     * LAPOZÁS: a NAV a queryTransactionList lapméretét MAGA határozza meg
     * — "The client cannot modify either the page size or the result
     * sorting parameters - these are always determined by the server"
     * (hivatalos specifikáció, 1.8.6). Mivel a technikai felhasználó
     * adószámához MÁS rendszerek/folyamatok is küldhetnek adatot
     * ugyanabban az időablakban (nem csak ez a Stock Manager példány), a
     * keresett tranzakció NEM garantáltan az 1. oldalon van — ez a
     * metódus emiatt a rendelkezésre álló oldalakon (availablePage)
     * végigmegy, de FELSŐ KORLÁTTAL (lásd MAX_RECOVERY_PAGES), hogy egy
     * szokatlanul nagy tranzakció-mennyiség se okozzon korlátlan
     * lekérés-sorozatot.
     */
    public function recoverUncertain(array $invoiceRow): array
    {
        $client = ($this->clientFactory)();

        $windowFrom = gmdate('Y-m-d\TH:i:s\Z', strtotime($invoiceRow['updated_at'] . ' UTC') - 120);
        $windowTo = gmdate('Y-m-d\TH:i:s\Z', time() + 60);

        $page = 1;
        $availablePage = 1;

        while ($page <= $availablePage && $page <= self::MAX_RECOVERY_PAGES) {
            $listResult = $client->queryTransactionList($windowFrom, $windowTo, $page);
            if (!$listResult['success']) {
                return ['outcome' => 'still_uncertain', 'error' => $listResult['error']];
            }

            foreach ($listResult['transactions'] as $tx) {
                if (empty($tx['transaction_id'])) {
                    continue;
                }
                $statusResult = $client->queryTransactionStatus($tx['transaction_id'], true);
                if (!$statusResult['success'] || empty($statusResult['original_request_base64'])) {
                    continue;
                }
                $originalXml = base64_decode($statusResult['original_request_base64'], true);
                if ($originalXml === false) {
                    continue;
                }
                if (str_contains($originalXml, '<invoiceNumber>' . $invoiceRow['invoice_number'] . '</invoiceNumber>')) {
                    return ['outcome' => 'submitted', 'transaction_id' => $tx['transaction_id']];
                }
            }

            $availablePage = max(1, (int) ($listResult['available_page'] ?? 1));
            $page++;
        }

        return ['outcome' => 'still_uncertain', 'error' => 'A queryTransactionList-ben (' . min($availablePage, self::MAX_RECOVERY_PAGES) . ' átvizsgált oldal) nem található, a saját invoiceNumber-ünkkel egyező tranzakció ebben az időablakban.'];
    }

    /**
     * Egy NavClient-hívás sikertelen eredményét osztályozza retry-able
     * kategóriákba. $allowUncertain csak a manageInvoice-hívásnál igaz
     * (lásd submit()) — máshol egy transport-szintű hiba egyszerű
     * 'retry', mert nincs mellékhatása.
     */
    private function classifyFailure(array $result, bool $allowUncertain): array
    {
        if ($result['http_status'] === null) {
            return $allowUncertain
                ? ['outcome' => 'uncertain', 'error' => $result['error']]
                : ['outcome' => 'retry', 'error' => $result['error']];
        }

        $errorCode = (string) ($result['nav_error_code'] ?? '');

        if (in_array($errorCode, self::NON_RETRYABLE_ERROR_CODES, true)) {
            return ['outcome' => 'permanent', 'error' => $result['error']];
        }

        if ($errorCode === 'MAINTENANCE_MODE' || (int) $result['http_status'] >= 500) {
            return ['outcome' => 'retry', 'error' => $result['error']];
        }

        // Ismeretlen 4xx-es hiba — óvatosságból nem automatikusan
        // újraküldendő, az admin kézzel megvizsgálhatja/újrapróbálhatja.
        return ['outcome' => 'permanent', 'error' => $result['error']];
    }
}
