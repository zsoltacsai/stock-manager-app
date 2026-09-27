<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/SqlCapturePdo.php';

/**
 * Phase 5 remediáció — DB-11 (P-B): a DB-be írt időbélyegek explicit
 * `Y-m-d H:i:s` (helyi idő, időzóna-eltolás nélkül) alakban mennek — nincs
 * MySQL-verziófüggő ISO 8601 / offset-feldolgozás, nincs implicit
 * időzóna-konverzió. Az alkalmazás időzónája nem változott.
 */
final class MysqlDatetimeSerializationTest extends TestCase
{
    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
    private const MYSQL_DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function testDatabaseLayerNeverSerializesIso8601Timestamps(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Database.php');
        $this->assertStringNotContainsString("date('c')", $source);
        $this->assertStringNotContainsString('DATE_ATOM', $source);
    }

    public function testMysqlPathBindsPlainDatetimeValues(): void
    {
        $pdo = new SqlCapturePdo();
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'mysql', 'pdo' => $pdo, 'dbConfig' => ['driver' => 'mysql']] as $k => $v) {
            $rp = new ReflectionProperty(Database::class, $k);
            $rp->setAccessible(true);
            $rp->setValue($db, $v);
        }
        $db->decrementStock(1, 1);
        $db->incrementStock(1, 1);
        $db->setStock(1, 5);
        $db->touchWcSyncedAt(1);
        $db->anonymizeCustomer(1);
        $db->bulkSetProductsDeleted([1, 2], true);

        $bound = [];
        foreach ($pdo->log as $entry) {
            foreach ($entry['params'] as $v) {
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                    $bound[] = $v;
                }
            }
        }
        $this->assertNotEmpty($bound);
        foreach ($bound as $v) {
            $this->assertDoesNotMatchRegularExpression(self::ISO_WITH_OFFSET, $v);
            $this->assertMatchesRegularExpression(self::MYSQL_DATETIME, $v);
        }
    }

    public function testSqliteStorageReadBackAndReportDateRange(): void
    {
        $db = tests_new_database();
        $pid = $db->saveProduct(['name' => 'DB11', 'barcode' => 'DB11-' . bin2hex(random_bytes(4)), 'price' => 10, 'net_price' => 7.87, 'vat_rate' => '27']);
        $db->setStock($pid, 3);
        $db->beginTransaction();
        $saleId = $db->insertSale(10.0);
        $db->insertSaleItem($saleId, ['product_id' => $pid, 'name' => 'DB11', 'qty' => 1, 'unit_price' => 10.0, 'vat_rate' => '27']);
        $db->decrementStock($pid, 1);
        $db->commit();

        $p = $db->findProductById($pid);
        $this->assertMatchesRegularExpression(self::MYSQL_DATETIME, (string) $p['updated_at']);
        $this->assertMatchesRegularExpression(self::MYSQL_DATETIME, (string) $p['wc_synced_at']);
        $this->assertSame(date('Y-m-d'), substr((string) $p['updated_at'], 0, 10), 'helyi dátum, időzóna-konverzió nélkül');
        $today = date('Y-m-d');
        $this->assertSame(1, $db->getSalesReportSummary($today, $today)['sales_count']);
    }

    public function testV37NormalizesLegacyIsoValuesToLocalTimeWithoutShifting(): void
    {
        $path = sys_get_temp_dir() . '/sm_db11_' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            foreach (['', '-wal', '-shm'] as $s) { @unlink($path . $s); }
        });
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $pid = $db->saveProduct(['name' => 'DB11 régi', 'barcode' => 'DB11R-' . bin2hex(random_bytes(4)), 'price' => 10, 'net_price' => 7.87, 'vat_rate' => '27']);
        $cid = $db->saveCustomer(['name' => 'DB11 vevő']);
        $db->pdo()->prepare("UPDATE products SET updated_at = '2026-09-27T00:21:06+02:00', wc_synced_at = '2026-01-02T03:04:05+01:00' WHERE id = ?")->execute([$pid]);
        $db->pdo()->prepare("UPDATE customers SET updated_at = '2026-03-04T05:06:07+01:00' WHERE id = ?")->execute([$cid]);
        $db->pdo()->exec('UPDATE schema_version SET version = 36');
        unset($db);

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $p = $db->findProductById($pid);
        $this->assertSame('2026-09-27 00:21:06', $p['updated_at']);
        $this->assertSame('2026-01-02 03:04:05', $p['wc_synced_at']);
        $this->assertSame('2026-03-04 05:06:07', $db->findCustomerById($cid)['updated_at']);
    }
}
