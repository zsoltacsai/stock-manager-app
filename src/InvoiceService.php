<?php

require_once __DIR__ . '/InvoiceProviderInterface.php';
require_once __DIR__ . '/SzamlazzInvoiceProvider.php';
require_once __DIR__ . '/NavInvoiceProvider.php';
require_once __DIR__ . '/NavTokenCache.php';

/**
 * Egyetlen belépési pont a számlázáshoz — sale.php / webshop-order-invoice.php /
 * webshop-order-confirm.php ezen keresztül szólítja meg a ténylegesen
 * kiválasztott szolgáltatót (Beállítások → 'invoice_provider'), sose
 * közvetlenül a SzamlazzClient-et vagy a NavClient-et.
 *
 * A NAV Online Számla szolgáltató (Phase 5B óta) ASZINKRON: processInvoice()
 * a 'nav' ág esetén csak egy tartós `invoices` queue-bejegyzést hoz létre
 * (lásd NavInvoiceProvider::enqueue()), a tényleges NAV-beküldést egy külön
 * cron-worker végzi (NavInvoiceQueueWorker, webroot/api/nav-queue-run.php)
 * — egy NAV-leállás emiatt SOSE lassítja/hiúsítja meg magát az eladást.
 */
class InvoiceService
{
    private array $config;
    private array $appSettings;
    private string $dataDir;

    public function __construct(array $config, array $appSettings, ?string $dataDir = null)
    {
        $this->config = $config;
        $this->appSettings = $appSettings;
        $this->dataDir = $dataDir ?? (__DIR__ . '/../data');
    }

    public function currentProviderKey(): string
    {
        $key = (string) ($this->appSettings['invoice_provider'] ?? 'szamlazz');
        return $key === 'nav' ? 'nav' : 'szamlazz';
    }

    private function resolveProvider(): InvoiceProviderInterface
    {
        switch ($this->currentProviderKey()) {
            case 'szamlazz':
                return new SzamlazzInvoiceProvider($this->config['szamlazz']);
            case 'nav':
                $navConfig = [
                    'nav_login' => $this->appSettings['nav_login'] ?? '',
                    'nav_password' => $this->appSettings['nav_password'] ?? '',
                    'nav_signer_key' => $this->appSettings['nav_signer_key'] ?? '',
                    'nav_exchange_key' => $this->appSettings['nav_exchange_key'] ?? '',
                    'nav_tax_number' => $this->appSettings['nav_tax_number'] ?? '',
                    'nav_test_mode' => !empty($this->appSettings['nav_test_mode']),
                ];
                $supplierConfig = [
                    'tax_number' => $this->appSettings['nav_tax_number'] ?? '',
                    'name' => $this->appSettings['nav_supplier_name'] ?? '',
                    'zip' => $this->appSettings['nav_supplier_zip'] ?? '',
                    'city' => $this->appSettings['nav_supplier_city'] ?? '',
                    'address' => $this->appSettings['nav_supplier_address'] ?? '',
                    'bank_account' => $this->appSettings['nav_supplier_bank_account'] ?? null,
                ];
                if (empty($navConfig['nav_login']) || empty($navConfig['nav_password']) || empty($navConfig['nav_tax_number'])) {
                    throw new RuntimeException('A NAV Online Számla nincs teljesen beállítva (technikai felhasználó / adószám hiányzik) — lásd Beállítások → Számlázás.');
                }
                $tokenCache = new NavTokenCache($this->dataDir . '/nav-token-cache.json');
                return new NavInvoiceProvider($navConfig, $supplierConfig, $tokenCache);
            default:
                throw new RuntimeException('Ismeretlen számlázási szolgáltató.');
        }
    }

    /**
     * $context várt kulcsai (ugyanazok, amiket eddig a
     * SzamlazzClient::createInvoice() közvetlen hívásai összeállítottak):
     * db (Database), sale_id (int), buyer (tömb), items (tömb),
     * language (?string), payment_method (?string), totals (tömb —
     * net/vat/gross/currency, a szolgáltató-független `invoices`
     * tükör-bejegyzéshez).
     *
     * Mindig egy ['success','invoice_number','pdf_path','error','pending']
     * alakú tömböt ad vissza, SOSE dob kifelé. Aszinkron szolgáltatónál
     * (NAV) 'success'=>false, DE 'pending'=>true ÉS 'error'=>null, ha a
     * queue-bejegyzés sikeresen létrejött — ez SZÁNDÉKOSAN nem "hiba", a
     * hívónak (sale.php → app.js) ezt "a számla beküldése folyamatban"
     * üzenetként kell mutatnia, NEM "a számla kiállítása sikertelen"-ként
     * (lásd app.js checkout-visszajelzés). Ha maga a queue-bejegyzés
     * létrehozása hiúsul meg (pl. adatbázis-hiba), az VALÓDI hiba —
     * ilyenkor 'pending'=>false ÉS 'error' kitöltött, mert a számlázási
     * feladatot semmilyen tartós formában nem sikerült megőrizni.
     */
    public function processInvoice(array $context): array
    {
        try {
            $provider = $this->resolveProvider();
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }

        if ($provider->isAsync()) {
            try {
                $provider->enqueue($context['db'], (int) $context['sale_id'], $context);
                return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => null, 'pending' => true];
            } catch (Throwable $e) {
                return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
            }
        }

        try {
            $result = $provider->issueSync($context);
            $result['pending'] = false;
            return $result;
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }
    }

    /**
     * Egy MEGLÉVŐ, 'normal'/'done' állapotú számla helyesbítő (módosító)
     * számlájának indítása — a KÖZPONTI validációs pont (lásd a kör 4.
     * pontja): a provider maga csak már validált, előkészített payloadot
     * kap (lásd InvoiceProviderInterface::requestModification()).
     *
     * @param int $originalInvoiceId a helyesbítendő EREDETI (invoice_type='normal') számla id-je
     * @param array $items a JAVÍTOTT tételsor (a felhasználó/admin adja meg — a MODIFY teljes,
     *   korrigált tartalmat küld, NEM egy diffet, lásd NavInvoiceXmlBuilder/SzamlazzClient docblockja)
     * @param array $buyer ugyanaz az alak, mint issueSync()/enqueue()-nál
     * @param ?string $paymentMethod
     * @param ?string $operationUuid a hívó (frontend) által generált, az adott módosítási
     *   KÍSÉRLETHEZ stabil azonosító — retry/dupla-kattintás esetén UGYANAZT kell újraküldeni,
     *   hogy az operation_key-alapú idempotencia ténylegesen védjen (lásd
     *   Database::createInvoiceOperation() 'modify:{id}:{uuid}' mintája). Ha a hívó nem ad
     *   meg, a szolgáltatás generál egyet — ez VÉDELEM, de ekkor egy ténylegesen megismételt
     *   HTTP-kérés (böngésző-retry) NEM ismerhető fel idempotensként, csak egy admin-
     *   kezdeményezett, tudatosan új próbálkozás.
     * @param bool $isAdmin a hívó (API-végpont) require_admin()-en már átment admin-e — ez itt
     *   egy MÁSODIK, védelmi-mélységi ellenőrzés, NEM helyettesíti a webroot/api rétegben
     *   kötelező require_admin()-t.
     */
    public function requestModification(Database $db, int $originalInvoiceId, array $items, array $buyer, ?string $paymentMethod, ?string $operationUuid, bool $isAdmin): array
    {
        [$original, $error] = $this->validateOperationRequest($db, $originalInvoiceId, $isAdmin, 'modify');
        if ($error !== null) {
            return $error;
        }

        try {
            $provider = $this->resolveProviderFor((string) $original['provider']);
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }

        $context = [
            'buyer' => $buyer,
            'items' => $items,
            'payment_method' => $paymentMethod,
            'totals' => self::computeTotals($items),
            'operation_uuid' => $operationUuid ?: bin2hex(random_bytes(16)),
        ];

        try {
            return $provider->requestModification($db, $original, $context);
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }
    }

    /**
     * Egy MEGLÉVŐ, 'normal'/'done' állapotú számla sztornózása. A
     * tételsor/vevő SZÁNDÉKOSAN NEM felhasználói bevitel — az EREDETI
     * számla tárolt payloadjából származik, előjel-fordítva (lásd
     * buildStornoContext()), hogy egy sztornó tartalmilag mindig
     * pontosan az eredeti számla teljes visszavonása legyen, ne egy
     * tetszőlegesen szerkeszthető új dokumentum.
     */
    public function requestStorno(Database $db, int $originalInvoiceId, bool $isAdmin): array
    {
        [$original, $error] = $this->validateOperationRequest($db, $originalInvoiceId, $isAdmin, 'storno');
        if ($error !== null) {
            return $error;
        }

        try {
            $provider = $this->resolveProviderFor((string) $original['provider']);
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }

        $context = $this->buildStornoContext($db, $original);

        try {
            return $provider->requestStorno($db, $original, $context);
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage(), 'pending' => false];
        }
    }

    /**
     * Admin-kezdeményezett kézi újrapróbálkozás EGY, korábban bizonytalan/
     * sikertelen kimenetelű, SZINKRON (Számlázz.hu) MODIFY/STORNO sorra —
     * a NAV-nál ehhez NINCS szükség külön metódusra, ott a meglévő
     * NavInvoiceQueueWorker automatikusan felveszi a 'queued'-ra
     * visszaállított sort (lásd webroot/api/nav-invoice-retry.php). A
     * Számlázz.hu SZINKRON, tehát a 'queued'-ra visszaállítást (lásd
     * Database::resetInvoiceForManualRetry()) itt VALAKINEK ténylegesen
     * újra el is kell indítania — ez a metódus végzi, ugyanazzal a
     * SzamlazzInvoiceProvider::executeAndRecordOperation()-nal, amit az
     * eredeti kísérlet is használt.
     */
    public function retrySzamlazzOperation(Database $db, int $invoiceId): array
    {
        $row = $db->getInvoiceById($invoiceId);
        if ($row === null || $row['provider'] !== 'szamlazz' || !in_array($row['invoice_type'], ['modification', 'storno'], true)) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => 'A számla-művelet nem található, vagy nem Számlázz.hu-s módosítás/sztornó.', 'pending' => false];
        }
        if ($row['status'] !== 'queued') {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => 'Csak a resetInvoiceForManualRetry() által \'queued\'-ra állított sor próbálható újra.', 'pending' => false];
        }

        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        $provider = new SzamlazzInvoiceProvider($this->config['szamlazz']);
        return $provider->executeAndRecordOperation($db, $invoiceId, (string) $row['invoice_type'], $payload);
    }

    /**
     * Közös központi validáció requestModification()/requestStorno()
     * alatt (lásd a kör 4/16. pontja) — a PROVIDER csak validált,
     * előkészített payloadot kap, a döntéseket MIND itt hozzuk meg.
     *
     * @return array{0: ?array, 1: ?array} [$original, $errorResult] — pontosan
     *   az egyik NULL: sikeres validáció esetén $errorResult===null és
     *   $original a friss DB-sor, hiba esetén fordítva.
     */
    private function validateOperationRequest(Database $db, int $originalInvoiceId, bool $isAdmin, string $operation): array
    {
        $fail = static fn (string $msg) => [null, ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $msg, 'pending' => false]];

        if (!$isAdmin) {
            return $fail('A számla módosítása/sztornózása kizárólag adminisztrátori jogosultsággal engedélyezett.');
        }

        $original = $db->getInvoiceById($originalInvoiceId);
        if ($original === null) {
            return $fail('A hivatkozott eredeti számla nem található.');
        }
        if ((string) $original['invoice_type'] !== 'normal') {
            return $fail('Módosítás/sztornó kizárólag EREDETI (invoice_type=normal) számlára indítható — egy már módosított/sztornózott sorra nem.');
        }
        if ((string) $original['status'] !== 'done') {
            return $fail('Módosítás/sztornó csak véglegesen kiállított (status=done) számlára indítható — jelenlegi állapot: ' . $original['status'] . '.');
        }
        if ($db->invoiceHasBlockingStorno($originalInvoiceId)) {
            return $fail('Ehhez a számlához már tartozik lezárt vagy folyamatban lévő sztornó — a számla életciklusa emiatt lezárt, további módosítás/sztornó nem indítható.');
        }

        try {
            $provider = $this->resolveProviderFor((string) $original['provider']);
        } catch (Throwable $e) {
            return $fail($e->getMessage());
        }
        if (!$provider->supportsOperation($operation)) {
            return $fail('A(z) "' . $original['provider'] . '" szolgáltató nem támogatja ezt a műveletet (' . $operation . ').');
        }

        return [$original, null];
    }

    /**
     * Ugyanaz a resolveProvider()-logika, mint a currentProviderKey()-alapú
     * ág — DE a MEGLÉVŐ SZÁMLA providerét használja ($provider paraméter),
     * NEM a Beállításokban JELENLEG kiválasztott szolgáltatót. Ez fontos:
     * ha időközben a Beállítások → 'invoice_provider' megváltozott (pl.
     * NAV-ról Számlázz.hu-ra), egy RÉGI, NAV-nál kiállított számla
     * módosítása/sztornója TOVÁBBRA IS a NAV-hoz kell menjen, sose a most
     * beállított, más szolgáltatóhoz.
     */
    private function resolveProviderFor(string $provider): InvoiceProviderInterface
    {
        switch ($provider) {
            case 'szamlazz':
                return new SzamlazzInvoiceProvider($this->config['szamlazz']);
            case 'nav':
                $navConfig = [
                    'nav_login' => $this->appSettings['nav_login'] ?? '',
                    'nav_password' => $this->appSettings['nav_password'] ?? '',
                    'nav_signer_key' => $this->appSettings['nav_signer_key'] ?? '',
                    'nav_exchange_key' => $this->appSettings['nav_exchange_key'] ?? '',
                    'nav_tax_number' => $this->appSettings['nav_tax_number'] ?? '',
                    'nav_test_mode' => !empty($this->appSettings['nav_test_mode']),
                ];
                $supplierConfig = [
                    'tax_number' => $this->appSettings['nav_tax_number'] ?? '',
                    'name' => $this->appSettings['nav_supplier_name'] ?? '',
                    'zip' => $this->appSettings['nav_supplier_zip'] ?? '',
                    'city' => $this->appSettings['nav_supplier_city'] ?? '',
                    'address' => $this->appSettings['nav_supplier_address'] ?? '',
                    'bank_account' => $this->appSettings['nav_supplier_bank_account'] ?? null,
                ];
                if (empty($navConfig['nav_login']) || empty($navConfig['nav_password']) || empty($navConfig['nav_tax_number'])) {
                    throw new RuntimeException('A NAV Online Számla nincs teljesen beállítva (technikai felhasználó / adószám hiányzik) — lásd Beállítások → Számlázás.');
                }
                $tokenCache = new NavTokenCache($this->dataDir . '/nav-token-cache.json');
                return new NavInvoiceProvider($navConfig, $supplierConfig, $tokenCache);
            default:
                throw new RuntimeException('Ismeretlen számlázási szolgáltató ("' . $provider . '") — a hivatkozott eredeti számla providere nem ismert.');
        }
    }

    /**
     * A LÁNC AKTUÁLIS állapotának (az EREDETI számla, VAGY — ha létezik
     * — a legutóbbi SIKERESEN kiállított módosítás) tárolt payloadjából
     * (payload_json — buyer/items) építi fel a sztornó kontextusát: a
     * tételek MENNYISÉGE előjelet vált (teljes visszavonás), a vevő/
     * fizetési mód VÁLTOZATLAN marad.
     *
     * VALÓDI NAV sandbox hívással igazolt indoklás (a kör 7/25. pontja
     * — korábban "nem bizonyított tény" volt, ADDIG lezárva): a NAV a
     * sztornó nettó/ÁFA összegét a LÁNC (eredeti + összes sikeres
     * módosítás) ÖSSZESÍTETT összegéhez viszonyítva ellenőrzi — egy,
     * KIZÁRÓLAG az eredeti számlát visszavonó sztornó, ha közben egy
     * módosítás MÁR megváltoztatta az árat, technikai figyelmeztető
     * üzenettel (nem nullázódó összesítés) tér vissza, még ha a NAV
     * végül DONE-nak is fogadja el. Emiatt ez a metódus MINDIG a lánc
     * legutóbbi, ténylegesen sikeres állapotát veszi alapul, nem
     * feltétlenül az eredetit.
     */
    private function buildStornoContext(Database $db, array $original): array
    {
        $baseRow = $original;
        foreach ($db->getInvoiceOperationsForOriginal((int) $original['id']) as $operation) {
            if ($operation['invoice_type'] === 'modification' && $operation['status'] === 'done') {
                $baseRow = $operation; // a lista modification_index szerint rendezett, tehát az utolsó találat a legutóbbi.
            }
        }

        $payload = json_decode((string) ($baseRow['payload_json'] ?? ''), true) ?: [];
        $baseItems = $payload['items'] ?? [];

        $reversedItems = array_map(static function (array $item): array {
            $item['qty'] = -abs((float) ($item['qty'] ?? 0));
            return $item;
        }, $baseItems);

        return [
            'buyer' => $payload['buyer'] ?? [],
            'items' => $reversedItems,
            'payment_method' => $payload['payment_method'] ?? null,
            'totals' => [
                'net' => -abs((float) $baseRow['net_total']),
                'vat' => -abs((float) $baseRow['vat_total']),
                'gross' => -abs((float) $baseRow['gross_total']),
                'currency' => $baseRow['currency'],
            ],
        ];
    }

    /**
     * A MÓDOSÍTÁS (nem sztornó) totals-ait a JAVÍTOTT tételsorból
     * számolja újra — bruttó-alapú, ugyanazzal a kerekítési elvvel, mint
     * NavInvoiceXmlBuilder/SzamlazzClient (nettó = bruttó/(1+áfa%), majd
     * a különbség adja az áfát) — ez KIZÁRÓLAG az `invoices` tábla saját,
     * szolgáltató-független net/vat/gross/currency oszlopaihoz kell (a
     * tényleges, számlázási dokumentumba kerülő összegeket maga a
     * NavInvoiceXmlBuilder / SzamlazzClient::buildInvoiceXml() számolja,
     * függetlenül, a saját XML-jébe).
     */
    private static function computeTotals(array $items): array
    {
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $vatRate = (string) ($item['vat_rate'] ?? '0');
            $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
            $grossUnit = (float) ($item['unit_price_gross'] ?? 0);
            $netUnit = round($grossUnit / (1 + $vatPct), 2);
            $lineNet = round($netUnit * $qty, 2);
            $lineGross = round($grossUnit * $qty, 2);
            $net += $lineNet;
            $gross += $lineGross;
            $vat += round($lineGross - $lineNet, 2);
        }
        return ['net' => $net, 'vat' => $vat, 'gross' => $gross, 'currency' => 'HUF'];
    }
}
