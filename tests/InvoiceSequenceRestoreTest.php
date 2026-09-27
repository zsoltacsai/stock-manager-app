<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/BackupManager.php';

/**
 * Phase 5 remediáció — DB-06: egy régebbi mentés visszaállítása után sem
 * osztható ki újra egy helyben már kiadott számlaszám.
 *   - visszaállításkor a sorozat a visszaállítás ELŐTTI élő értékre emelkedik
 *     (BackupManager::reconcileInvoiceSequences());
 *   - az allokáció egy már az invoices táblában szereplő számlaszámot átlép
 *     (duplikátum-felismerés, évfüggetlenül).
 * Valódi BackupManager + SQLite; a MySQL-ág: MysqlBackupRestoreIntegrityTest.
 */
final class InvoiceSequenceRestoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_db06_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/backups', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testRestoringAnOlderBackupDoesNotReissueAlreadyAllocatedNumbers(): void
    {
        $cfg = ['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/stock.sqlite']];
        $db = new Database($cfg, dirname(__DIR__));
        $this->assertSame(1, $db->allocateInvoiceNumber('nav'));
        $bm = new BackupManager($cfg, $this->dir . '/backups');
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => '']);
        $this->assertSame(2, $db->allocateInvoiceNumber('nav'));
        $this->assertSame(3, $db->allocateInvoiceNumber('nav'));

        $db->closeForExternalFileReplacement();
        $result = $bm->restoreFromFile($this->dir . '/backups/' . $backup['filename'], true);
        $db = new Database($cfg, dirname(__DIR__));

        $this->assertTrue($result['invoice_sequences_reconciled']);
        $this->assertSame(4, $db->allocateInvoiceNumber('nav'), 'a 2 és a 3 már kiadott — nem osztható ki újra');
    }

    public function testRestoreKeepsAHigherSequenceFromTheBackupItself(): void
    {
        $cfg = ['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/stock.sqlite']];
        $db = new Database($cfg, dirname(__DIR__));
        foreach (range(1, 5) as $_) {
            $db->allocateInvoiceNumber('nav');
        }
        $bm = new BackupManager($cfg, $this->dir . '/backups');
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => '']);
        $db->pdo()->exec("UPDATE invoice_sequences SET last_allocated_number = 2 WHERE provider = 'nav'");

        $db->closeForExternalFileReplacement();
        $bm->restoreFromFile($this->dir . '/backups/' . $backup['filename'], true);
        $db = new Database($cfg, dirname(__DIR__));

        $this->assertSame(6, $db->allocateInvoiceNumber('nav'), 'az egyeztetés csak felfelé emel');
    }

    public function testAllocationSkipsANumberAlreadyPresentInInvoicesEvenFromAnotherYear(): void
    {
        $db = tests_new_database();
        $db->beginTransaction();
        $saleId = $db->insertSale(10.0);
        $db->commit();
        $db->pdo()->prepare("INSERT INTO invoices (sale_id, provider, status, invoice_number, operation_key, created_at, updated_at) VALUES (?, 'nav', 'done', 'FT-NAV-2025-000001', 'legacy:1', ?, ?)")
            ->execute([$saleId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        $this->assertSame(2, $db->allocateInvoiceNumber('nav'), 'az 1-es sorszám már szerepel (2025-ös évvel) — duplikátum nem keletkezhet');
        $this->assertSame(3, $db->allocateInvoiceNumber('nav'));
    }
}
