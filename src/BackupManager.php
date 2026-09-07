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

        $this->backupSettingsSidecar($filename);

        return $filename;
    }

    /**
     * A data/settings.json-ban van minden integrációs hitelesítő adat
     * (WooCommerce, Számlázz.hu, NAV, Dropbox, Google) — enélkül egy
     * visszaállítás után minden ilyen kapcsolat csendben megszakadna, amíg
     * valaki manuálisan újra be nem írja őket. A DB-mentéssel megegyező
     * alapnevű, .settings.json kiterjesztésű "testvér" fájlba mentjük, hogy
     * a visszaállítás automatikusan megtalálja — lásd restoreFromFile().
     * (A feltöltött termékképek/logók külön fájlok, ezeket a mentés
     * szándékosan nem tartalmazza — lásd README.)
     */
    private function backupSettingsSidecar(string $dbFilename): void
    {
        $settingsPath = dirname($this->backupDir) . '/settings.json';
        if (is_file($settingsPath)) {
            @copy($settingsPath, $this->backupDir . '/' . $this->settingsSidecarName($dbFilename));
        }
    }

    private function settingsSidecarName(string $dbFilename): string
    {
        return preg_replace('/\.(sqlite|sql)$/', '', $dbFilename) . '.settings.json';
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
                $this->backupSettingsSidecar($filename);
                return $filename;
            }
        }

        $this->dumpMysqlWithPhp($destination);
        $this->backupSettingsSidecar($filename);
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
            @unlink($this->backupDir . '/' . $this->settingsSidecarName(basename($old)));
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

        $settingsRestored = $this->restoreSettingsSidecarIfPresent($sourcePath);

        return ['safety_backup' => $safetyBackup, 'settings_restored' => $settingsRestored];
    }

    private function restoreSqliteFromFile(string $sourcePath): void
    {
        try {
            $check = new PDO('sqlite:' . $sourcePath);
            $tables = $check->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            throw new RuntimeException('A fájl nem egy érvényes SQLite adatbázis: ' . $e->getMessage());
        }
        unset($check);

        // Ellenőrizzük, hogy ez ténylegesen egy Stock Manager mentés-e, ne
        // csak "bármilyen SQLite fájl" — enélkül egy véletlenül rossz fájl
        // kiválasztása (pl. a data/backups mappából egy nem idevaló .sqlite)
        // "sikeres" visszaállítás látszatával cserélné le az éles adatbázist.
        $requiredTables = ['products', 'sales', 'customers'];
        $missing = array_diff($requiredTables, $tables);
        if ($missing) {
            throw new RuntimeException('A fájl nem tűnik Stock Manager adatbázis-mentésnek (hiányzó tábla: ' . implode(', ', $missing) . ').');
        }

        $liveDbPath = $this->dbConfig['sqlite']['path'];

        // Az élő adatbázis WAL-módban fut — a legfrissebb írások egy része a
        // fő .sqlite fájl helyett még a -wal oldalfájlban lehet. Checkpoint
        // nélkül a lenti nyers fájlmásolás után az itt maradt régi -wal/-shm
        // a következő megnyitáskor részben visszakeverhetné a visszaállítás
        // ELŐTTI tranzakciókat a most beírt mentés fölé — legjobb erőfeszítés
        // jelleggel megpróbáljuk kiüríteni, mielőtt felülírnánk a fájlt.
        try {
            $live = new PDO('sqlite:' . $liveDbPath);
            $live->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            unset($live);
        } catch (Throwable $e) {
            // Nem blokkoljuk emiatt a visszaállítást — a lenti unlink még
            // mindig eltünteti a maradék -wal/-shm fájlokat.
        }

        if (!copy($sourcePath, $liveDbPath)) {
            throw new RuntimeException('Nem sikerült a fájlt a helyére másolni. Ellenőrizd a jogosultságokat.');
        }

        // A frissen visszaállított fájl legyen az egyetlen igazság forrása —
        // egy a visszaállítás ELŐTTről itt maradt -wal/-shm sose keveredjen
        // bele a következő megnyitásba.
        @unlink($liveDbPath . '-wal');
        @unlink($liveDbPath . '-shm');
    }

    private function restoreSettingsSidecarIfPresent(string $sourcePath): bool
    {
        $sidecar = dirname($sourcePath) . '/' . $this->settingsSidecarName(basename($sourcePath));
        if (!is_file($sidecar)) {
            return false;
        }
        $settingsPath = dirname($this->backupDir) . '/settings.json';
        if (is_file($settingsPath)) {
            @copy($settingsPath, $settingsPath . '.before-restore-' . date('Ymd_His'));
        }
        return @copy($sidecar, $settingsPath);
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

