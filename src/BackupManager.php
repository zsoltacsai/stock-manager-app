<?php

require_once __DIR__ . '/DropboxProvider.php';
require_once __DIR__ . '/GoogleDriveProvider.php';

class BackupManager
{
    private array $dbConfig;
    private string $backupDir;
    private string $driver;

    public function __construct(array $dbConfig, string $backupDir)
    {
        $this->dbConfig = $dbConfig;
        $this->driver = $dbConfig['driver'] ?? 'sqlite';
        $this->backupDir = rtrim($backupDir, '/');
        @mkdir($this->backupDir, 0775, true);
    }

    private function extension(): string
    {
        return $this->driver === 'mysql' ? 'sql' : 'sqlite';
    }

    public function run(array $settings): array
    {
        $filename = $this->driver === 'mysql' ? $this->createMysqlSnapshot() : $this->createSqliteSnapshot();
        $this->pruneLocal((int) $settings['backup_retention_count']);

        $cloudResult = null;
        $provider = $this->buildProvider($settings);
        if ($provider) {
            $provider->upload($this->backupDir . '/' . $filename, $filename);
            $this->pruneCloud($provider, (int) $settings['backup_retention_count']);
            $cloudResult = ['provider' => $provider->name(), 'uploaded' => true];
        }

        return [
            'filename'    => $filename,
            'local_count' => count($this->listLocal()),
            'cloud'       => $cloudResult,
        ];
    }

    private function createSqliteSnapshot(): string
    {
        $filename = 'stockmanager_backup_' . date('Ymd_His') . '.sqlite';
        $destination = $this->backupDir . '/' . $filename;

        $pdo = new PDO('sqlite:' . $this->dbConfig['sqlite']['path']);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($destination));

        return $filename;
    }

    private function createMysqlSnapshot(): string
    {
        $filename = 'stockmanager_backup_' . date('Ymd_His') . '.sql';
        $destination = $this->backupDir . '/' . $filename;
        $m = $this->dbConfig['mysql'];

        if (function_exists('exec') && $this->commandExists('mysqldump')) {
            $cmd = sprintf(
                'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --quick %s > %s 2>&1',
                escapeshellarg($m['host']),
                (int) ($m['port'] ?? 3306),
                escapeshellarg($m['username']),
                escapeshellarg($m['password']),
                escapeshellarg($m['database']),
                escapeshellarg($destination)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode === 0 && is_file($destination) && filesize($destination) > 0) {
                return $filename;
            }
        }

        $this->dumpMysqlWithPhp($destination);
        return $filename;
    }

    private function commandExists(string $binary): bool
    {
        $which = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        return !empty(trim((string) $which));
    }

    private function dumpMysqlWithPhp(string $destination): void
    {
        $m = $this->dbConfig['mysql'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $m['host'], $m['port'] ?? 3306, $m['database'], $m['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $m['username'], $m['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);

        $out = fopen($destination, 'w');
        if (!$out) {
            throw new RuntimeException("Nem sikerült írni a mentési fájlt: $destination");
        }

        fwrite($out, "-- Stock Manager PHP-based MySQL dump (mysqldump not available)\n");
        fwrite($out, "-- Generated: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            fwrite($out, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($out, $createRow['Create Table'] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map(fn($c) => "`$c`", array_keys($row));
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, array_values($row));
                fwrite($out, "INSERT INTO `$table` (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }
            fwrite($out, "\n");
        }

        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    public function listLocal(): array
    {
        $files = glob($this->backupDir . '/stockmanager_backup_*.' . $this->extension()) ?: [];
        rsort($files);

        return array_map(fn($f) => [
            'filename'   => basename($f),
            'size'       => filesize($f),
            'created_at' => date('Y-m-d H:i:s', filemtime($f)),
        ], $files);
    }

    private function pruneLocal(int $keep): void
    {
        $files = glob($this->backupDir . '/stockmanager_backup_*.' . $this->extension()) ?: [];
        rsort($files);

        foreach (array_slice($files, max(0, $keep)) as $old) {
            @unlink($old);
        }
    }

    private function pruneCloud(CloudBackupProvider $provider, int $keep): void
    {
        $files = $provider->listBackups();
        usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));

        foreach (array_slice($files, max(0, $keep)) as $old) {
            $provider->delete($old['id']);
        }
    }

    public function buildProvider(array $settings): ?CloudBackupProvider
    {
        switch ($settings['backup_provider'] ?? 'none') {
            case 'dropbox':
                if (empty($settings['dropbox_access_token'])) {
                    return null;
                }
                return new DropboxProvider($settings['dropbox_access_token'], $settings['dropbox_folder'] ?? '/StockManagerBackups');

            case 'googledrive':
                if (empty($settings['google_client_id']) || empty($settings['google_client_secret']) || empty($settings['google_refresh_token'])) {
                    return null;
                }
                return new GoogleDriveProvider(
                    $settings['google_client_id'],
                    $settings['google_client_secret'],
                    $settings['google_refresh_token'],
                    $settings['google_folder_id'] ?? null
                );

            default:
                return null;
        }
    }

    public function restoreFromFile(string $sourcePath): array
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('A visszaállítandó fájl nem található.');
        }

        $safetyBackup = $this->driver === 'mysql' ? $this->createMysqlSnapshot() : $this->createSqliteSnapshot();

        if ($this->driver === 'mysql') {
            $this->restoreMysqlFromFile($sourcePath);
        } else {
            $this->restoreSqliteFromFile($sourcePath);
        }

        return ['safety_backup' => $safetyBackup];
    }

    private function restoreSqliteFromFile(string $sourcePath): void
    {
        try {
            $check = new PDO('sqlite:' . $sourcePath);
            $check->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
        } catch (Throwable $e) {
            throw new RuntimeException('A fájl nem egy érvényes SQLite adatbázis: ' . $e->getMessage());
        }
        unset($check);

        $liveDbPath = $this->dbConfig['sqlite']['path'];
        if (!copy($sourcePath, $liveDbPath)) {
            throw new RuntimeException('Nem sikerült a fájlt a helyére másolni. Ellenőrizd a jogosultságokat.');
        }
    }

    private function restoreMysqlFromFile(string $sourcePath): void
    {
        $m = $this->dbConfig['mysql'];

        if (function_exists('exec') && $this->commandExists('mysql')) {
            $cmd = sprintf(
                'mysql --host=%s --port=%d --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($m['host']),
                (int) ($m['port'] ?? 3306),
                escapeshellarg($m['username']),
                escapeshellarg($m['password']),
                escapeshellarg($m['database']),
                escapeshellarg($sourcePath)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode === 0) {
                return;
            }
            throw new RuntimeException('A mysql parancs sikertelen volt: ' . implode("\n", $output));
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $m['host'], $m['port'] ?? 3306, $m['database'], $m['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $m['username'], $m['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $sql = file_get_contents($sourcePath);
        if ($sql === false) {
            throw new RuntimeException('A mentési fájl nem olvasható.');
        }

        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $pdo->exec($statement);
        }
    }
}

