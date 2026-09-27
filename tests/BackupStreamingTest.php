<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-03 — a mentés/visszaállítás streamelt útvonalai: a MySQL-dump folyamként
 * darabolása és ellenőrzése (restoreMysqlFromFile()), valamint a felhő-feltöltés
 * (Dropbox, Google Drive) fájlból streamelt törzse. A darabolás viselkedését a
 * PERF-03 előtti, egész stringen dolgozó algoritmus másolatával (oracle)
 * vetjük össze, tetszőleges darabhatárokkal.
 */
final class BackupStreamingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_backup_stream_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** A PERF-03 előtti BackupManager::splitSqlStatements() változatlan másolata — referencia. */
    private static function referenceSplit(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            $startOfLineComment = ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))
                || $c === '#';
            if ($startOfLineComment) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $buf .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $statements[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $statements[] = trim($buf);
        }
        return $statements;
    }

    private static function streamSplit(string $sql, int $chunkSize): array
    {
        $offset = 0;
        $method = new ReflectionMethod(BackupManager::class, 'iterateSqlStatements');
        $gen = $method->invoke(null, function () use ($sql, &$offset, $chunkSize): string {
            $chunk = (string) substr($sql, $offset, $chunkSize);
            $offset += strlen($chunk);
            return $chunk;
        });
        return iterator_to_array($gen, false);
    }

    /** @return array<string, array{string}> */
    public static function sqlCorpus(): array
    {
        return [
            'mysqldump fejléc és feltételes kommentek' => ["-- MySQL dump 10.13\n/*!40101 SET @OLD=@@X */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\nDROP TABLE IF EXISTS `products`;\nCREATE TABLE `products` (\n  `id` int NOT NULL, -- megjegyzés\n  `name` text\n);\nINSERT INTO `products` VALUES (1,'a;b'),(2,'c\\'d');\n-- Dump completed on 2026-09-27\n"],
            'idézőjelek, escape, duplázás' => ["INSERT INTO t VALUES ('It''s ; fine', \"dq \\\" ; x\", `we;ird`, 'back\\\\slash');SELECT 1;"],
            'blokk-komment pontosvesszővel' => ["SELECT /* ; nem választ el */ 1; SELECT 2 /* záratlan"],
            'sorvégi kommentek' => ["# hash komment ; \nSELECT 1; -- záró ; komment\nSELECT '--nem komment';\nSELECT 3--4;\nSELECT 5 -- x"],
            'komment a fájl végén sortörés nélkül' => ["SELECT 1;\n-- vége"],
            'mínusz jel fájl végén' => ["SELECT 1 -"],
            'dupla mínusz a legvégén' => ["SELECT 1 --"],
            'backslash a legvégén idézőjelen belül' => ["SELECT 'abc\\"],
            'üres utasítások' => [";;  ;\n; SELECT 1 ;;"],
            'ékezetes, többbájtos tartalom' => ["INSERT INTO p VALUES ('Árvíztűrő tükörfúrógép ; ő');"],
            'üres bemenet' => [''],
        ];
    }

    /**
     * @dataProvider sqlCorpus
     */
    public function testStreamingSplitterMatchesPreviousWholeStringAlgorithmForEveryChunkSize(string $sql): void
    {
        $expected = self::referenceSplit($sql);
        $this->assertSame($expected, BackupManager::splitSqlStatements($sql), 'splitSqlStatements() viselkedése változott.');
        foreach ([1, 2, 3, 4, 5, 7, 13, 64] as $size) {
            $this->assertSame($expected, self::streamSplit($sql, $size), "Eltérés $size bájtos darabolásnál.");
        }
    }

    public function testStreamingSplitterMatchesReferenceOnRandomisedDumps(): void
    {
        mt_srand(3);
        $atoms = ["'", '"', '`', '\\', ';', '-', '--', '-- ', "--\n", '#', "\n", '/*', '*/', '/', '*', 'x', ' ', "''", 'SELECT ', '1'];
        for ($n = 0; $n < 300; $n++) {
            $sql = '';
            for ($k = mt_rand(1, 60); $k > 0; $k--) {
                $sql .= $atoms[mt_rand(0, count($atoms) - 1)];
            }
            $expected = self::referenceSplit($sql);
            foreach ([1, 2, 3, 5, 11] as $size) {
                $this->assertSame($expected, self::streamSplit($sql, $size), 'Eltérés: ' . json_encode($sql) . " ($size bájt)");
            }
        }
    }

    public function testFileValidationMatchesStringValidation(): void
    {
        $tables = "CREATE TABLE `products` (id int);\nCREATE TABLE `sales` (id int);\nCREATE TABLE customers (id int);\n";
        $cases = [
            'teljes PHP-dump' => $tables . "INSERT INTO products VALUES (1);\n" . BackupManager::PHP_DUMP_COMPLETED_MARKER . "\n",
            'régi PHP-dump' => $tables . "SET FOREIGN_KEY_CHECKS=1;\n",
            'mysqldump' => $tables . "-- Dump completed on 2026-09-27 10:00:00\n",
            'csonka' => $tables . "INSERT INTO products VALUES (1",
            'hiányzó tábla' => "CREATE TABLE `products` (id int);\n" . BackupManager::PHP_DUMP_COMPLETED_MARKER,
            'nincs utasítás' => "-- csak komment\n" . BackupManager::PHP_DUMP_COMPLETED_MARKER,
            'nagy záró whitespace' => $tables . BackupManager::PHP_DUMP_COMPLETED_MARKER . str_repeat("\n", 5000),
        ];
        foreach ($cases as $label => $sql) {
            $path = $this->dir . '/dump.sql';
            file_put_contents($path, $sql);
            $stringError = null;
            $fileError = null;
            try {
                BackupManager::validateMysqlDump($sql);
            } catch (RuntimeException $e) {
                $stringError = $e->getMessage();
            }
            try {
                BackupManager::validateMysqlDumpFile($path);
            } catch (RuntimeException $e) {
                $fileError = $e->getMessage();
            }
            $this->assertSame($stringError, $fileError, $label);
        }
    }

    /** Egy ~40 MB-os dump ellenőrzése és végigolvasása a méretétől független memóriával. */
    public function testLargeDumpIsValidatedAndIteratedWithBoundedMemory(): void
    {
        $path = $this->dir . '/big.sql';
        $fh = fopen($path, 'wb');
        fwrite($fh, "CREATE TABLE `products` (id int, name text);\nCREATE TABLE `sales` (id int);\nCREATE TABLE `customers` (id int);\n");
        $row = str_repeat('Árvíztűrő; \'idézett\' -- nem komment ', 30);
        for ($i = 0; $i < 40000; $i++) {
            fwrite($fh, "INSERT INTO `products` VALUES ($i, '" . str_replace("'", "''", $row) . "');\n");
        }
        fwrite($fh, BackupManager::PHP_DUMP_COMPLETED_MARKER . "\n");
        fclose($fh);
        $this->assertGreaterThan(40 * 1024 * 1024, filesize($path));

        memory_reset_peak_usage();
        $base = memory_get_usage();
        BackupManager::validateMysqlDumpFile($path);
        $count = 0;
        foreach (BackupManager::iterateSqlStatementsFromFile($path) as $statement) {
            $count++;
        }
        $this->assertSame(40003, $count);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $base, 'A dump feldolgozása nem töltheti be a teljes fájlt.');
    }

    // ------------------------------------------------------------------
    // Felhő-feltöltés: a törzs fájlból streamelve, bájtra azonosan
    // ------------------------------------------------------------------

    private function startEchoServer(): array
    {
        file_put_contents($this->dir . '/router.php', '<?php
            $in = fopen("php://input", "rb");
            $ctx = hash_init("sha256");
            $len = 0;
            while (!feof($in)) { $b = fread($in, 65536); $len += strlen($b); hash_update($ctx, $b); }
            $j = json_encode(["method" => $_SERVER["REQUEST_METHOD"], "type" => $_SERVER["CONTENT_TYPE"] ?? "", "length" => $len, "sha256" => hash_final($ctx), "arg" => $_SERVER["HTTP_DROPBOX_API_ARG"] ?? null, "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null]);
            file_put_contents(__DIR__ . "/last.json", $j);
            echo $j;
        ');
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $this->dir . '/router.php'], [['pipe', 'r'], ['file', $this->dir . '/server.log', 'a'], ['file', $this->dir . '/server.log', 'a']], $pipes);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) {
                fclose($c);
                break;
            }
            usleep(50000);
        }
        return [$proc, "http://127.0.0.1:$port/"];
    }

    public function testCloudUploadsStreamTheExactFileBytesWithoutLoadingTheFile(): void
    {
        [$proc, $url] = $this->startEchoServer();
        try {
            $file = $this->dir . '/backup.sqlite.enc';
            $fh = fopen($file, 'wb');
            for ($i = 0; $i < 24; $i++) {
                fwrite($fh, random_bytes(1048576));
            }
            fclose($fh);
            $size = filesize($file);

            $dropbox = new class ('tok', '/Mentesek', $url) extends DropboxProvider {
                public array $response = [];
                public function __construct(string $t, string $f, private string $url) { parent::__construct($t, $f); }
                protected function uploadUrl(): string { return $this->url; }
            };
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $dropbox->upload($file, 'backup.sqlite.enc');
            $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $base, 'A Dropbox-feltöltés nem olvashatja memóriába a 24 MB-os fájlt.');
            $seen = json_decode((string) file_get_contents($this->dir . '/last.json'), true);
            $this->assertSame('POST', $seen['method']);
            $this->assertSame('application/octet-stream', $seen['type']);
            $this->assertSame($size, $seen['length']);
            $this->assertSame(hash_file('sha256', $file), $seen['sha256']);
            $this->assertSame('Bearer tok', $seen['auth']);
            $this->assertSame('/Mentesek/backup.sqlite.enc', json_decode($seen['arg'], true)['path']);

            $drive = new class ('id', 'secret', 'refresh', 'folder', $url) extends GoogleDriveProvider {
                public function __construct(string $a, string $b, string $c, ?string $d, private string $url) { parent::__construct($a, $b, $c, $d); }
                protected function uploadUrl(): string { return $this->url; }
                protected function getAccessToken(): string { return 'gtok'; }
            };
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $drive->upload($file, 'backup.sqlite.enc');
            $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $base, 'A Google Drive-feltöltés nem olvashatja memóriába a 24 MB-os fájlt.');
            $seen = json_decode((string) file_get_contents($this->dir . '/last.json'), true);
            $this->assertSame('POST', $seen['method']);
            $this->assertMatchesRegularExpression('/^multipart\/related; boundary=(stockmanagerbackup[0-9a-f]{16})$/', $seen['type']);
            preg_match('/boundary=(.+)$/', $seen['type'], $m);
            $boundary = $m[1];
            // A PERF-03 előtti, memóriában összefűzött törzzsel bájtra azonos.
            $expectedBody = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                . json_encode(['name' => 'backup.sqlite.enc', 'parents' => ['folder']]) . "\r\n--$boundary\r\nContent-Type: application/x-sqlite3\r\n\r\n"
                . file_get_contents($file) . "\r\n--$boundary--";
            $this->assertSame(strlen($expectedBody), $seen['length']);
            $this->assertSame(hash('sha256', $expectedBody), $seen['sha256']);
            $this->assertSame('Bearer gtok', $seen['auth']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}
