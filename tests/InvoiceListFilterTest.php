<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Database::listInvoices() szűrő-logikájának célzott, DB-szintű tesztjei
 * — a NAV/Számlázz.hu megjelenés és a státusz-bucket szűrés HTTP-szinten
 * már bizonyított (lásd tests/InvoiceEndpointsHttpTest.php), itt a
 * date/id/query szűrők finomabb eseteire koncentrálunk.
 */
final class InvoiceListFilterTest extends TestCase
{
    private function seedInvoice(Database $db, string $provider, string $invoiceNumber, string $buyerName, string $createdAt): int
    {
        $saleId = $db->insertSale(1000.0, 'Készpénz');
        $db->pdo()->prepare('UPDATE sales SET buyer_name = ? WHERE id = ?')->execute([$buyerName, $saleId]);
        $db->pdo()->prepare("
            INSERT INTO invoices (sale_id, provider, status, invoice_number, net_total, vat_total, gross_total, currency, created_at, updated_at)
            VALUES (?, ?, 'done', ?, 800, 200, 1000, 'HUF', ?, ?)
        ")->execute([$saleId, $provider, $invoiceNumber, $createdAt, $createdAt]);
        return (int) $db->pdo()->lastInsertId();
    }

    public function testFilterByDate(): void
    {
        $db = tests_new_database();
        $this->seedInvoice($db, 'szamlazz', 'SZ-A', 'Vevő A', '2026-03-01 10:00:00');
        $this->seedInvoice($db, 'szamlazz', 'SZ-B', 'Vevő B', '2026-03-02 10:00:00');

        $result = $db->listInvoices(['date' => '2026-03-01']);

        $this->assertCount(1, $result);
        $this->assertSame('SZ-A', $result[0]['invoice_number']);
    }

    public function testFilterByIdMatchesInvoiceIdOrSaleId(): void
    {
        $db = tests_new_database();
        $invoiceId = $this->seedInvoice($db, 'nav', 'SM-NAV-X', 'Vevő X', '2026-03-01 10:00:00');
        $invoice = $db->getInvoiceById($invoiceId);
        $saleId = (int) $invoice['sale_id'];

        $byInvoiceId = $db->listInvoices(['id' => $invoiceId]);
        $this->assertCount(1, $byInvoiceId);

        $bySaleId = $db->listInvoices(['id' => $saleId]);
        $this->assertCount(1, $bySaleId, 'Az azonosító szűrőnek a kapcsolódó sale_id-re is illeszkednie kell.');
    }

    public function testQueryFilterMatchesBuyerNameOrInvoiceNumber(): void
    {
        $db = tests_new_database();
        $this->seedInvoice($db, 'szamlazz', 'SZ-UNIQUE-777', 'Kovács János', '2026-03-01 10:00:00');
        $this->seedInvoice($db, 'nav', 'SM-NAV-OTHER', 'Nagy Éva', '2026-03-01 10:00:00');

        $byBuyer = $db->listInvoices(['query' => 'Kovács']);
        $this->assertCount(1, $byBuyer);
        $this->assertSame('SZ-UNIQUE-777', $byBuyer[0]['invoice_number']);

        $byInvoiceNumber = $db->listInvoices(['query' => 'UNIQUE-777']);
        $this->assertCount(1, $byInvoiceNumber);
        $this->assertSame('Kovács János', $byInvoiceNumber[0]['sale_buyer_name']);
    }

    public function testUnknownStatusBucketIsIgnoredNotError(): void
    {
        $db = tests_new_database();
        $this->seedInvoice($db, 'szamlazz', 'SZ-Z', 'Vevő Z', '2026-03-01 10:00:00');

        // Egy nem létező bucket-név (pl. kézzel piszkált query string) NE
        // dobjon hibát, csak legyen figyelmen kívül hagyva (nincs szűrés).
        $result = $db->listInvoices(['status' => 'nem-letezo-bucket']);

        $this->assertCount(1, $result);
    }

    public function testOrderedByCreatedAtDescending(): void
    {
        $db = tests_new_database();
        $this->seedInvoice($db, 'szamlazz', 'SZ-OLD', 'Vevő', '2026-01-01 08:00:00');
        $this->seedInvoice($db, 'szamlazz', 'SZ-NEW', 'Vevő', '2026-06-01 08:00:00');

        $result = $db->listInvoices([]);

        $this->assertSame('SZ-NEW', $result[0]['invoice_number'], 'A legújabbnak kell elöl lennie.');
    }
}
