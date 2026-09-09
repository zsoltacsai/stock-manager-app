<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * InvoiceService/SzamlazzInvoiceProvider — a NAV Online Számla bevezetése
 * miatt új, közös számlázási absztrakció DB-szintű (Database osztályon
 * keresztüli, HTTP-réteg nélküli) tesztjei. A cél: bizonyítani, hogy a
 * korábban sale.php-ba/webshop-order-invoice.php-ba/
 * webshop-order-confirm.php-ba égetett Számlázz.hu-s folyamat (atomikus
 * foglalás, attachInvoiceToSale, hiba esetén is engedélyezett azonnali
 * újrapróbálkozás) VÁLTOZATLANUL működik az új rétegen keresztül is, és
 * hogy a (ebben a körben még be nem kötött) 'nav' szolgáltató-választás
 * SOSE dob kifelé, SOSE hiúsítja meg az eladást — csak egy egyértelmű,
 * olvasható hibát ad vissza a számla-eredményben.
 *
 * A Számlázz.hu-hívás ténylegesen sikeres kimenetét (valódi API-válasz)
 * ez a teszt-kör szándékosan NEM teszteli — ahhoz egy valós Számlázz.hu
 * fiók/API-kulcs kellene, amit ez a projekt korábban se használt
 * automatizált tesztben. A HIBA-útvonalat viszont igen: egy szándékosan
 * érvénytelen "endpoint" URL-lel a curl azonnal, hálózati kapcsolat
 * nélkül, gyorsan elbukik (CURLE_URL_MALFORMAT) — ez determinisztikusan
 * és gyorsan futtatja le a tryClaimInvoiceIssuance() -> (sikertelen
 * SzamlazzClient-hívás) -> attachInvoiceToSale('invoice_failed') ->
 * upsertInvoiceMirror(false, ...) teljes láncot, anélkül hogy a
 * teszt-csomag hálózati kapcsolatra vagy valós hitelesítő adatra
 * szorulna.
 */
final class InvoiceServiceTest extends TestCase
{
    private function fakeSzamlazzConfig(): array
    {
        // Szándékosan üres URL — a curl ezt AZONNAL, bármiféle hálózati
        // I/O (DNS-feloldás sem) nélkül elutasítja ("Malformed input to a
        // URL function"), tehát a teszt gyors és determinisztikus marad.
        // (Egy nem-üres, de érvénytelen host — pl. "not-a-valid-url" —
        // ezzel szemben ténylegesen megpróbálná feloldani a DNS-t, ami
        // pár másodperces, hálózat-függő késleltetést okozna.)
        return [
            'agent_key' => 'teszt', 'endpoint' => '',
            'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu',
            'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => ['nev' => 'x', 'irsz' => '0000', 'telepules' => 'x', 'cim' => 'x'],
            'pdf_dir' => sys_get_temp_dir() . '/sm_invoice_test_pdf_' . bin2hex(random_bytes(4)),
        ];
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

    public function testSzamlazzProviderClaimsAndRecordsFailureWhenUnreachable(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');

        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);
        $result = $service->processInvoice($this->sampleContext($db, $saleId));

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);

        // attachInvoiceToSale() lefutott: a foglalás felszabadult, a
        // sales.status 'invoice_failed'-re állt — ugyanaz a viselkedés,
        // mint közvetlen SzamlazzClient-hívás esetén korábban.
        $sale = $db->getSaleWithItems($saleId);
        $this->assertSame('invoice_failed', $sale['status']);
        $this->assertNull($sale['invoice_claim_at']);
        $this->assertNull($sale['szamlazz_invoice_number']);

        // upsertInvoiceMirror() lefutott: az egységes `invoices` táblában
        // is megjelenik egy 'failed' állapotú, 'szamlazz' szolgáltatójú sor.
        $mirror = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        $this->assertNotNull($mirror);
        $this->assertSame('failed', $mirror['status']);
        $this->assertSame(1000.0, (float) $mirror['net_total']);
        $this->assertSame(270.0, (float) $mirror['vat_total']);
        $this->assertSame(1270.0, (float) $mirror['gross_total']);
    }

    public function testFailedAttemptCanBeRetriedImmediatelyAndMirrorRowIsUpsertedNotDuplicated(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $service = new InvoiceService(['szamlazz' => $this->fakeSzamlazzConfig()], ['invoice_provider' => 'szamlazz']);

        $first = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertFalse($first['success']);

        // attachInvoiceToSale() a sikertelenség után is felszabadítja a
        // foglalást — egy azonnali második próbálkozásnak el kell jutnia
        // a (szintén sikertelen, de VALÓDI) SzamlazzClient-hívásig, nem
        // "már folyamatban van" hibát kell kapnia.
        $second = $service->processInvoice($this->sampleContext($db, $saleId));
        $this->assertFalse($second['success']);
        $this->assertNotSame('A számla kiállítása már folyamatban van.', $second['error']);

        // A UNIQUE(sale_id, provider) + upsert miatt a két próbálkozás
        // ellenére is pontosan EGY tükör-sor létezik.
        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, 'szamlazz']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
