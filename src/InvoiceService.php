<?php

require_once __DIR__ . '/InvoiceProviderInterface.php';
require_once __DIR__ . '/SzamlazzInvoiceProvider.php';

/**
 * Egyetlen belépési pont a számlázáshoz — sale.php / webshop-order-invoice.php /
 * webshop-order-confirm.php ezen keresztül szólítja meg a ténylegesen
 * kiválasztott szolgáltatót (Beállítások → 'invoice_provider'), sose
 * közvetlenül a SzamlazzClient-et vagy egy leendő NAV-klienst.
 *
 * FONTOS, JELENLEGI KORLÁT: a NAV Online Számla szolgáltató még nincs
 * bekötve ebben a verzióban — a keretrendszer (ez az osztály, az
 * interface, a Számlázz.hu-bekötés, az `invoices` tábla) már készen áll
 * rá, de a tényleges NAV-beküldés egy KÉSŐBBI fejlesztési körben kerül
 * implementálásra (lásd a projekt fejlesztési tervét). Ha az üzemeltető
 * mégis 'nav'-ra állítja a szolgáltatót ebben a verzióban, az eladás
 * ATTÓL FÜGGETLENÜL sikeres marad — processInvoice() sose dob kifelé,
 * csak egy egyértelmű, olvasható hibaüzenetet ad vissza a számla-
 * eredményben, ahelyett hogy összeomlana, vagy csendben úgy tenne,
 * mintha a számla ténylegesen kiállításra került volna.
 */
class InvoiceService
{
    private array $config;
    private array $appSettings;

    public function __construct(array $config, array $appSettings)
    {
        $this->config = $config;
        $this->appSettings = $appSettings;
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
                // Szándékos, egyértelmű hiba — lásd a fenti osztály-docblockot.
                throw new RuntimeException('A NAV Online Számla kiküldés ebben a verzióban még nincs bekötve — válaszd a Számlázz.hu szolgáltatót a Beállításokban, vagy várd meg a következő fejlesztési kört.');
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
     * Mindig egy ['success','invoice_number','pdf_path','error'] alakú
     * tömböt ad vissza (aszinkron szolgáltatónál 'error' => null, de
     * 'success' => false marad, amíg a háttér-worker ténylegesen le nem
     * zárja a beküldést — ez korrekt, mert a hívás pillanatában
     * ténylegesen még nincs kiállított számla), SOSE dob kifelé — a
     * hívónak ez pontosan úgy kell működjön, mint korábban egy
     * try/catch-be csomagolt SzamlazzClient::createInvoice()-hívás.
     */
    public function processInvoice(array $context): array
    {
        try {
            $provider = $this->resolveProvider();
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage()];
        }

        if ($provider->isAsync()) {
            try {
                $provider->enqueue($context['db'], (int) $context['sale_id'], $context);
                return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => null];
            } catch (Throwable $e) {
                return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage()];
            }
        }

        try {
            return $provider->issueSync($context);
        } catch (Throwable $e) {
            return ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage()];
        }
    }
}
