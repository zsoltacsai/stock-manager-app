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
}
