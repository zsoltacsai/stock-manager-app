<?php

require_once __DIR__ . '/DropboxProvider.php';
require_once __DIR__ . '/GoogleDriveProvider.php';

class BackupManager
{
    /**
     * Release-blocker javítás — formátum-jelző, ami MINDEN, ettől a
     * javítástól kezdve készülő titkosított mentés (encryptFileInPlace())
     * elejére kerül, a korábbi nyers IV||TAG||ciphertext elé. Korábban a
     * visszaállítás azt feltételezte, hogy egy titkosított mentés fájlneve
     * mindig '.enc'-re végződik — ez EGY FELTÖLTÖTT fájlnál hamis
     * feltevés volt: a $_FILES['file']['tmp_name'] egy PHP által
     * véletlenszerűen generált ideiglenes fájlnév, ami SOSE tartalmazza az
     * eredeti kiterjesztést, így egy feltöltött titkosított mentés
     * visszafejtés NÉLKÜL, nyers (még mindig titkosított) bájtokként lett
     * volna "SQLite adatbázisként" megnyitva — ez mindig hibával bukott
     * (lásd restoreSqliteFromFile()), nem csendben rossz eredménnyel, de a
     * visszaállítás magát sosem sikerült elvégezni. Lásd detectEncryption()
     * — a felismerés mostantól KIZÁRÓLAG a fájl TARTALMÁNAK (nem a
     * fájlnevének) egyértelmű, fix bájt-aláírásán alapul: ez a jelző saját
     * formátumunk, VAGY a SQLite fájlformátum saját, kötelező 16 bájtos
     * aláírása (lásd SQLITE_MAGIC) — egyik sem "találgatás", mindkettő egy
     * pontosan definiált, egyértelmű bájtsorozat ellenőrzése, ugyanúgy,
     * ahogy pl. egy PNG/JPEG feltöltésnél is a tényleges tartalom (finfo),
     * nem a kiterjesztés dönt.
     */
    private const ENCRYPTED_MAGIC = "FTBKENC1";

    /** A SQLite fájlformátum saját, kötelező aláírása — https://www.sqlite.org/fileformat.html */
    private const SQLITE_MAGIC = "SQLite format 3\x00";

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

    /**
     * A `date('Ymd_His')` mintájú fájlnév csak 1 másodperc felbontású — két
     * mentés (pl. egy visszaállítás előtti biztonsági mentés és a
     * ténylegesen visszaállítandó fájl) ugyanabban a másodpercben azonos
     * névre futhatott ki, és a később írt felülírta a korábbit, a
     * visszaállítás forrásfájlát is beleértve (empirikusan reprodukálva —
     * lásd restoreFromFile()). A backupDir-en belül egyedi, fájlrendszer-
     * biztonságos nevet ad, amit a hívó ELŐRE, még bármilyen írás előtt
     * elkérhet, hogy egy ütközést a tényleges mentés elkészítése ELŐTT
     * ki lehessen zárni (lásd restoreFromFile() explicit védelme).
     */
    private function generateBackupFilename(): string
    {
        return 'stockmanager_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $this->extension();
    }

    // ------------------------------------------------------------------
    // Titkosítás nyugalmi állapotban (encryption at rest). A mentés
    // TARTALMAZZA a data/settings.json-t (lásd backupSettingsSidecar()),
    // ami minden integrációs hitelesítő adatot (WooCommerce, Számlázz.hu,
    // NAV, Dropbox, Google) tartalmaz — egy ellopott/elveszett mentési
    // fájl enélkül azonnal olvasható lenne. A tényleges DB-mentés/
    // visszaállítás meglévő, tesztelt logikáját (createSqliteSnapshot(),
    // restoreSqliteFromFile() stb.) SZÁNDÉKOSAN változatlanul hagytuk —
    // azok továbbra is egy ideiglenes, sima (titkosítatlan) fájlt
    // hoznak létre/olvasnak, amit ez a réteg utólag titkosít, illetve
    // visszaállítás előtt előbb visszafejt egy ideiglenes fájlba.
    //
    // FONTOS, DOKUMENTÁLT KORLÁT: a titkosító kulcs (data/.backup-
    // encryption-key) UGYANAZON a szerveren van tárolva, mint maga a
    // mentés — ez a nyugalmi állapotú titkosítás megvéd egy ELKÜLÖNÍTVE
    // ellopott/elveszett/rosszul konfigurált felhő-tárhelyre feltöltött
    // mentési fájl ellen, de NEM helyettesít egy valódi kulcskezelő
    // szolgáltatást (KMS/HSM): aki teljes hozzáférést szerez magához a
    // szerverhez, a kulcsfájlt is megszerzi. Éles, nagyobb kockázatú
    // telepítésen érdemes a kulcsot egy külön kulcskezelő szolgáltatásban
    // tartani, és csak ideiglenesen, memóriában betölteni.
    // ------------------------------------------------------------------

    private function encryptionKeyPath(): string
    {
        return dirname($this->backupDir) . '/.backup-encryption-key';
    }

    private function encryptionKey(): string
    {
        $path = $this->encryptionKeyPath();
        if (!is_file($path)) {
            $key = random_bytes(32);
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, base64_encode($key));
            @chmod($path, 0600);
            return $key;
        }
        $key = base64_decode(trim((string) file_get_contents($path)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('A mentés-titkosítási kulcs (data/.backup-encryption-key) sérült vagy érvénytelen.');
        }
        return $key;
    }

    /** Titkosítja $plainPath tartalmát $encPath-ba (AES-256-GCM, hitelesített titkosítás), majd törli az eredeti sima fájlt. */
    private function encryptFileInPlace(string $plainPath, string $encPath): void
    {
        $plaintext = file_get_contents($plainPath);
        if ($plaintext === false) {
            throw new RuntimeException('A titkosítandó fájl nem olvasható: ' . $plainPath);
        }
        $key = $this->encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('A mentés titkosítása sikertelen.');
        }
        if (file_put_contents($encPath, self::ENCRYPTED_MAGIC . $iv . $tag . $ciphertext) === false) {
            throw new RuntimeException('A titkosított mentés írása sikertelen: ' . $encPath);
        }
        @chmod($encPath, 0600);
        @unlink($plainPath);
    }

    /** Visszafejti $encPath-ot egy ideiglenes fájlba, és visszaadja annak elérési útját — a hívó felelőssége törölni, ha végzett vele. */
    private function decryptToTempFile(string $encPath, string $suffix): string
    {
        $raw = file_get_contents($encPath);
        if ($raw === false) {
            throw new RuntimeException('A titkosított mentés fájlja sérült vagy nem olvasható: ' . $encPath);
        }
        // A formátum-jelző bevezetése (lásd ENCRYPTED_MAGIC docblokkja)
        // ELŐTT készült, régi .enc fájlok eleve IV||TAG||ciphertext-tel
        // kezdődnek, jelző nélkül — teljes visszafelé kompatibilitás
        // érdekében csak akkor vágjuk le, ha ténylegesen jelen van.
        if (str_starts_with($raw, self::ENCRYPTED_MAGIC)) {
            $raw = substr($raw, strlen(self::ENCRYPTED_MAGIC));
        }
        if (strlen($raw) < 12 + 16) {
            throw new RuntimeException('A titkosított mentés fájlja sérült vagy nem olvasható: ' . $encPath);
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('A titkosított mentés visszafejtése sikertelen (hibás kulcs, vagy a fájl sérült/módosított).');
        }
        $tmpPath = sys_get_temp_dir() . '/stockmanager_decrypt_' . bin2hex(random_bytes(8)) . $suffix;
        if (file_put_contents($tmpPath, $plaintext) === false) {
            throw new RuntimeException('Az ideiglenes visszafejtett fájl írása sikertelen.');
        }
        // A visszafejtett tartalom a teljes adatbázist (és a settings.json
        // sidecar esetén minden integrációs hitelesítő adatot) tartalmazza
        // — ugyanaz a jogosultság-szűkítés kell ide, mint a titkosított
        // fájl írásakor (lásd encryptFileInPlace()), nehogy egy osztott/
        // több-felhasználós szerveren más helyi felhasználó elolvashassa a
        // visszaállítás rövid időablakában. A chmod() sikertelensége (pl.
        // olyan fájlrendszer, ami nem támogatja a Unix-jogosultságokat)
        // szándékosan NEM hiúsítja meg a visszaállítást — a hívó a
        // finally blokkban ugyanígy törli a fájlt, akár sikerült a chmod,
        // akár nem.
        @chmod($tmpPath, 0600);
        return $tmpPath;
    }

    public function run(array $settings): array
    {
        $plainFilename = $this->driver === 'mysql' ? $this->createMysqlSnapshot() : $this->createSqliteSnapshot();
        $filename = $this->encryptSnapshotFiles($plainFilename);

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

    /** A frissen létrehozott (sima) DB-mentést és a beállítás-sidecart titkosítja; a titkosított fájlnevet adja vissza. */
    private function encryptSnapshotFiles(string $plainFilename): string
    {
        $plainPath = $this->backupDir . '/' . $plainFilename;
        $encFilename = $plainFilename . '.enc';
        $this->encryptFileInPlace($plainPath, $this->backupDir . '/' . $encFilename);

        $sidecarPlain = $this->backupDir . '/' . $this->settingsSidecarName($plainFilename);
        if (is_file($sidecarPlain)) {
            $this->encryptFileInPlace($sidecarPlain, $sidecarPlain . '.enc');
        }

        return $encFilename;
    }

    private function createSqliteSnapshot(?string $filename = null): string
    {
        $filename = $filename ?? $this->generateBackupFilename();
        $destination = $this->backupDir . '/' . $filename;

        $pdo = new PDO('sqlite:' . $this->dbConfig['sqlite']['path']);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($destination));
        // Rövid ideig (az encryptSnapshotFiles() lefutásáig) ez a fájl
        // sima szöveges adatbázis-mentés — ugyanaz a jogosultság-szűkítés
        // indokolt, mint a titkosított végeredménynél.
        @chmod($destination, 0600);

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
            $sidecarPath = $this->backupDir . '/' . $this->settingsSidecarName($dbFilename);
            @copy($settingsPath, $sidecarPath);
            @chmod($sidecarPath, 0600); // lásd createSqliteSnapshot() azonos megjegyzése
        }
    }

    private function settingsSidecarName(string $dbFilename): string
    {
        $base = preg_replace('/\.enc$/', '', $dbFilename);
        $base = preg_replace('/\.(sqlite|sql)$/', '', (string) $base);
        return $base . '.settings.json';
    }

    private function createMysqlSnapshot(?string $filename = null): string
    {
        $filename = $filename ?? $this->generateBackupFilename();
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
                @chmod($destination, 0600); // lásd createSqliteSnapshot() azonos megjegyzése
                $this->backupSettingsSidecar($filename);
                return $filename;
            }
        }

        $this->dumpMysqlWithPhp($destination);
        @chmod($destination, 0600);
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
        @chmod($destination, 0600); // rögtön a létrehozás után, még az írás megkezdése előtt

        fwrite($out, "-- FountainTrade PHP-based MySQL dump (mysqldump not available)\n");
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

    /**
     * Csak a titkosított (.enc) mentéseket listázza — minden ÚJ mentés
     * ilyen (lásd run()/encryptSnapshotFiles()). Egy erről a változtatásról
     * ("Priority 9") korábbi, még titkosítatlan mentés a lemezen marad
     * (nem törlődik), de innentől nem jelenik meg a listában/nem kerül
     * automatikusan törlésre a megőrzési szabály által — kézi
     * visszaállításra (feltöltéssel) restoreFromFile() továbbra is
     * elfogadja titkosítatlanul is, lásd ott.
     */
    public function listLocal(): array
    {
        $files = glob($this->backupDir . '/stockmanager_backup_*.' . $this->extension() . '.enc') ?: [];
        rsort($files);

        return array_map(fn($f) => [
            'filename'   => basename($f),
            'size'       => filesize($f),
            'created_at' => date('Y-m-d H:i:s', filemtime($f)),
        ], $files);
    }

    private function pruneLocal(int $keep): void
    {
        $files = glob($this->backupDir . '/stockmanager_backup_*.' . $this->extension() . '.enc') ?: [];
        rsort($files);

        foreach (array_slice($files, max(0, $keep)) as $old) {
            @unlink($old);
            @unlink($this->backupDir . '/' . $this->settingsSidecarName(basename($old)) . '.enc');
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

    /**
     * P0-2 explicit védelme: soha ne írjuk felül a visszaállítás
     * forrásfájlját a visszaállítás-előtti biztonsági mentéssel. Önálló,
     * mellékhatás-mentes metódus, hogy közvetlenül, determinisztikusan
     * tesztelhető legyen (lásd BackupManagerTest.php) — nem csak közvetve,
     * a teljes restoreFromFile()-on és a véletlen fájlnév-generáláson
     * keresztül.
     */
    private function ensureSafetyBackupFilenameDoesNotCollideWithSource(string $safetyBackupEncFilename, string $sourcePath): void
    {
        if ($safetyBackupEncFilename === basename($sourcePath)) {
            throw new RuntimeException(
                'Belső hiba: a biztonsági mentés fájlneve egybeesne a visszaállítandó forrásfájléval — ' .
                'a visszaállítás megszakítva, mielőtt bármi íródott volna a lemezre. Próbáld újra.'
            );
        }
    }

    /**
     * Release-blocker javítás — eldönti, hogy $sourcePath egy titkosított
     * mentés-e, KIZÁRÓLAG a fájl TARTALMA alapján, sose a nevéből/
     * kiterjesztéséből (lásd ENCRYPTED_MAGIC docblokkja a pontos indoklásért
     * — a korábbi, tmp_name-alapú hiba gyökere). A döntés minden ágon
     * egyértelmű, fix aláírás-ellenőrzés — SOSE a teljes tartalom
     * megbízhatatlan találgatása:
     *
     *   1. ENCRYPTED_MAGIC jelenléte  -> egyértelműen titkosított (új formátum).
     *   2. SQLITE_MAGIC jelenléte     -> egyértelműen sima SQLite.
     *   3. $sourceIsServerManagedFile -> a Szerver SAJÁT data/backups
     *      mappájából, fájlnév szerint (nem feltöltéssel) kiválasztott,
     *      régi (a jelző bevezetése ELŐTTI) .enc mentés — ez a konkrét,
     *      eredeti hiba (kliens által feltöltött tmp_name) itt eleve NEM
     *      állhat fenn, mert ez egy szerver-oldali, nem kliens-vezérelt
     *      tény (lásd backup-restore.php: basename()+is_file() egy fix
     *      könyvtáron belül) — ez az EGYETLEN hely, ahol a fájlNÉV egyáltalán
     *      szerepet kap, és csak azért, mert itt nem a kliens állítja elő.
     *   4. MySQL driver, egyik jelző sem található -> sima (titkosítatlan)
     *      mysqldump-szöveg — nincs univerzális bináris aláírása, de egy
     *      ténylegesen érvénytelen tartalom a mysql-importálásnál
     *      egyértelmű hibával bukik el (restoreMysqlFromFile()), nem
     *      csendben rosszul.
     *   5. Egyik feltétel sem teljesül (jellemzően: feltöltött fájl, ami
     *      sem a titkosított, sem a sima SQLite aláírással nem egyezik) ->
     *      EXPLICIT hiba, nem találgatás — a hívó egyértelmű útmutatást kap.
     */
    private function detectEncryption(string $sourcePath, bool $sourceIsServerManagedFile): bool
    {
        $header = (string) @file_get_contents($sourcePath, false, null, 0, 16);
        if ($header === '' && !is_readable($sourcePath)) {
            throw new RuntimeException('A visszaállítandó fájl nem olvasható: ' . $sourcePath);
        }
        if (str_starts_with($header, self::ENCRYPTED_MAGIC)) {
            return true;
        }
        if ($header === self::SQLITE_MAGIC) {
            return false;
        }
        if ($sourceIsServerManagedFile && str_ends_with(strtolower($sourcePath), '.enc')) {
            return true;
        }
        if ($this->driver === 'mysql') {
            return false;
        }
        throw new RuntimeException(
            'A fájl formátuma nem határozható meg egyértelműen (sem sima, sem titkosított ' .
            'FountainTrade adatbázis-mentésnek nem ismerhető fel). Ha egy RÉGEBBI, még a ' .
            'formátum-jelző bevezetése előtt készült titkosított mentést próbálsz visszaállítani, ' .
            'válaszd ki azt inkább a Szerveren tárolt, meglévő mentések listájából — ne feltöltéssel.'
        );
    }

    public function restoreFromFile(string $sourcePath, bool $sourceIsServerManagedFile = false): array
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('A visszaállítandó fájl nem található.');
        }

        // Explicit védelem: a biztonsági mentés végleges (titkosított) neve
        // MÉG BÁRMILYEN ÍRÁS ELŐTT előre generálódik, hogy egy esetleges
        // névütközést a visszaállítandó forrásfájllal itt, korán ki lehessen
        // zárni — a fenti generateBackupFilename() önmagában is gyakorlatilag
        // kizárja az ütközést (időbélyeg + véletlen utótag), de ez a
        // konkrét, explicit ellenőrzés akkor is megvédi a forrást, ha ez
        // valaha mégis megváltozna.
        $safetyBackupFilename = $this->generateBackupFilename();
        $safetyBackupEncFilename = $safetyBackupFilename . '.enc';
        $this->ensureSafetyBackupFilenameDoesNotCollideWithSource($safetyBackupEncFilename, $sourcePath);

        $safetyBackupPlain = $this->driver === 'mysql'
            ? $this->createMysqlSnapshot($safetyBackupFilename)
            : $this->createSqliteSnapshot($safetyBackupFilename);
        $safetyBackup = $this->encryptSnapshotFiles($safetyBackupPlain);

        // Visszafelé kompatibilis: a data/backups mappából kiválasztott
        // fájl mindig .enc (lásd listLocal()), de egy KÉZZEL feltöltött
        // fájl lehet egy még ebből a funkcióból ("Priority 9") származó
        // titkosítás előtti, sima mentés is. A felismerés mostantól a fájl
        // TARTALMA alapján történik — lásd detectEncryption() docblokkja
        // (release-blocker javítás: korábban itt egy $sourcePath-fájlnév-
        // alapú, feltöltésnél SOSE megbízható .enc-kiterjesztés-ellenőrzés
        // volt).
        $isEncrypted = $this->detectEncryption($sourcePath, $sourceIsServerManagedFile);
        $decryptedDbPath = null;
        $decryptedSidecarPath = null;
        try {
            $effectiveSourcePath = $sourcePath;
            if ($isEncrypted) {
                $decryptedDbPath = $this->decryptToTempFile($sourcePath, '.' . $this->extension());
                $effectiveSourcePath = $decryptedDbPath;
            }

            if ($this->driver === 'mysql') {
                $this->restoreMysqlFromFile($effectiveSourcePath);
            } else {
                $this->restoreSqliteFromFile($effectiveSourcePath);
            }

            $settingsRestored = false;
            if ($isEncrypted) {
                $encSidecar = dirname($sourcePath) . '/' . $this->settingsSidecarName(basename($sourcePath)) . '.enc';
                if (is_file($encSidecar)) {
                    $decryptedSidecarPath = $this->decryptToTempFile($encSidecar, '.settings.json');
                    $settingsRestored = $this->restoreSettingsSidecarIfPresent($decryptedSidecarPath);
                }
            } else {
                // Visszafelé kompatibilis eset: az eredeti (nem titkosított)
                // mentés melletti sima sidecar-fájl.
                $plainSidecar = dirname($sourcePath) . '/' . $this->settingsSidecarName(basename($sourcePath));
                $settingsRestored = $this->restoreSettingsSidecarIfPresent($plainSidecar);
            }

            return ['safety_backup' => $safetyBackup, 'settings_restored' => $settingsRestored];
        } finally {
            if ($decryptedDbPath !== null) {
                @unlink($decryptedDbPath);
            }
            if ($decryptedSidecarPath !== null) {
                @unlink($decryptedSidecarPath);
            }
        }
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

        // Ellenőrizzük, hogy ez ténylegesen egy FountainTrade mentés-e, ne
        // csak "bármilyen SQLite fájl" — enélkül egy véletlenül rossz fájl
        // kiválasztása (pl. a data/backups mappából egy nem idevaló .sqlite)
        // "sikeres" visszaállítás látszatával cserélné le az éles adatbázist.
        $requiredTables = ['products', 'sales', 'customers'];
        $missing = array_diff($requiredTables, $tables);
        if ($missing) {
            throw new RuntimeException('A fájl nem tűnik FountainTrade adatbázis-mentésnek (hiányzó tábla: ' . implode(', ', $missing) . ').');
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

        // Visszaállítás UTÁNI egészség-ellenőrzés: a bemásolt fájl a fenti
        // ellenőrzésen (várt táblák megléte) már átment, de csak a FORRÁS
        // fájlon — ez itt magán a immár ÉLESSÉ vált fájlon fut le, hogy egy
        // esetleges másolási hiba (lemez megtelt félúton, jogosultsági
        // probléma egy lapon) azonnal, még a válaszban kiderüljön, ne csak
        // az első valódi lekérdezésnél, észrevétlenül.
        try {
            $verify = new PDO('sqlite:' . $liveDbPath);
            $verify->query('SELECT COUNT(*) FROM products')->fetchColumn();
            $verify->query('SELECT COUNT(*) FROM sales')->fetchColumn();
            $verify->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        } catch (Throwable $e) {
            throw new RuntimeException('A visszaállítás után az adatbázis nem tűnik épnek (egészség-ellenőrzés sikertelen): ' . $e->getMessage());
        }
    }

    /** @param string $sidecarPath a ténylegesen olvasandó (már visszafejtett, ha titkosított volt) sidecar-fájl elérési útja. */
    private function restoreSettingsSidecarIfPresent(string $sidecarPath): bool
    {
        if (!is_file($sidecarPath)) {
            return false;
        }
        $settingsPath = dirname($this->backupDir) . '/settings.json';
        if (is_file($settingsPath)) {
            @copy($settingsPath, $settingsPath . '.before-restore-' . date('Ymd_His'));
        }
        return @copy($sidecarPath, $settingsPath);
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

