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

    /**
     * PERF-03 — darabolt (streamelt) titkosítási formátum, minden új mentés ez.
     * A korábbi formátum (ENCRYPTED_MAGIC, ill. jelző nélküli régi .enc) a teljes
     * fájlt egyben olvasta és titkosította: a PHP-memória ~3× az adatbázis
     * mérete volt, és ~42 MB-os adatbázis felett a 128 MB-os korláton a mentés
     * elbukott. Felépítés:
     *
     *   "FTBKENC2" || prefix(8) || rekord*
     *   rekord = flag(1: 0x00 köztes, 0x01 utolsó) || len(4, big-endian) || tag(16) || ciphertext(len)
     *
     * Minden rekord külön AES-256-GCM hitelesítéssel; nonce = prefix || index(4),
     * AAD = jelző || prefix || index || flag. Az index és az „utolsó” jelző a
     * hitelesített adat része, így a rekordok cseréje, elhagyása, a fájl csonkolása
     * vagy utólagos bővítése is visszafejtési hibát ad — ugyanúgy, mint a korábbi
     * egyetlen GCM-tag. A régi formátumok visszafejtése változatlanul támogatott.
     */
    private const ENCRYPTED_MAGIC_V2 = "FTBKENC2";

    /** A darabolt formátum egy rekordjának mérete (1 MiB) — ennyi a memóriaigény is. */
    private const ENCRYPTION_CHUNK_BYTES = 1048576;

    /** Egy rekord megengedett legnagyobb hossza visszafejtéskor (sérült/rosszindulatú hossz-mező elleni korlát). */
    private const ENCRYPTION_MAX_RECORD_BYTES = 16777216;

    /** A SQLite fájlformátum saját, kötelező aláírása — https://www.sqlite.org/fileformat.html */
    private const SQLITE_MAGIC = "SQLite format 3\x00";

    /** DB-08: a PHP-s MySQL dump teljességét jelző záró sor — a restore enélkül nem indul el (csonka fájl). */
    public const PHP_DUMP_COMPLETED_MARKER = '-- FountainTrade dump completed';

    private array $dbConfig;
    private string $backupDir;
    private string $driver;
    /** MySQL-kapcsolat gyártó — alapból valódi PDO; tesztekben SQL-rögzítő/szimuláló PDO adható (nincs helyi MySQL). */
    private ?Closure $mysqlPdoFactory = null;
    /** A mysql/mysqldump CLI elérhetőségének felülírása (null = felderítés, lásd commandExists()). */
    private ?bool $mysqlCliOverride = null;
    /** DB-01: igaz, amint a visszaállítás az ÉLŐ adatbázist ténylegesen módosítani kezdte — ekkor hiba esetén kompenzálni kell. */
    private bool $restoreTouchedLive = false;

    public function __construct(array $dbConfig, string $backupDir)
    {
        $this->dbConfig = $dbConfig;
        $this->driver = $dbConfig['driver'] ?? 'sqlite';
        $this->backupDir = rtrim($backupDir, '/');
        @mkdir($this->backupDir, 0775, true);
    }

    public function setMysqlPdoFactory(?Closure $factory): void
    {
        $this->mysqlPdoFactory = $factory;
    }

    public function setMysqlCliAvailable(?bool $available): void
    {
        $this->mysqlCliOverride = $available;
    }

    private function mysqlPdo(bool $unbuffered = false): PDO
    {
        if ($this->mysqlPdoFactory !== null) {
            return ($this->mysqlPdoFactory)($unbuffered);
        }
        $m = $this->dbConfig['mysql'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $m['host'], $m['port'] ?? 3306, $m['database'], $m['charset'] ?? 'utf8mb4');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($unbuffered) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        return new PDO($dsn, $m['username'], $m['password'], $options);
    }

    private function mysqlCliAvailable(string $binary): bool
    {
        if ($this->mysqlCliOverride !== null) {
            return $this->mysqlCliOverride;
        }
        return function_exists('exec') && $this->commandExists($binary);
    }

    /**
     * A MySQL-jelszó egy ideiglenes, csak a tulajdonos által olvasható
     * option-fájlba kerül (nem a parancssorba): nem jelenik meg a folyamat-
     * listában, és a kliens nem ír "Using a password on the command line"
     * figyelmeztetést — ez korábban a `> dump 2>&1` átirányítással a mentési
     * fájl ELEJÉRE került, és a restore az első során elbukott.
     */
    private function writeMysqlDefaultsFile(): string
    {
        $m = $this->dbConfig['mysql'];
        $path = sys_get_temp_dir() . '/stockmanager_mycnf_' . bin2hex(random_bytes(8)) . '.cnf';
        $password = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $m['password']);
        file_put_contents($path, "[client]\npassword=\"$password\"\n");
        @chmod($path, 0600);
        return $path;
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

    /**
     * Titkosítja $plainPath tartalmát $encPath-ba (AES-256-GCM, hitelesített
     * titkosítás, darabolt formátum — lásd ENCRYPTED_MAGIC_V2), majd törli az
     * eredeti sima fájlt. PERF-03: fájlból fájlba, rekordonként dolgozik — a
     * memóriaigény egy-két rekord, nem a fájl mérete.
     */
    private function encryptFileInPlace(string $plainPath, string $encPath): void
    {
        $in = @fopen($plainPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('A titkosítandó fájl nem olvasható: ' . $plainPath);
        }
        $key = $this->encryptionKey();
        $out = @fopen($encPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('A titkosított mentés írása sikertelen: ' . $encPath);
        }
        @chmod($encPath, 0600);
        $ok = false;
        try {
            $prefix = random_bytes(8);
            self::writeAll($out, self::ENCRYPTED_MAGIC_V2 . $prefix, $encPath);
            $index = 0;
            $chunk = self::readChunk($in, $plainPath);
            do {
                $next = $chunk === '' ? '' : self::readChunk($in, $plainPath);
                $flag = $next === '' ? "\x01" : "\x00";
                $tag = '';
                $ciphertext = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $index), $tag, self::ENCRYPTED_MAGIC_V2 . $prefix . pack('N', $index) . $flag);
                if ($ciphertext === false || $tag === '') {
                    throw new RuntimeException('A mentés titkosítása sikertelen.');
                }
                self::writeAll($out, $flag . pack('N', strlen($ciphertext)) . $tag . $ciphertext, $encPath);
                $chunk = $next;
                $index++;
            } while ($flag === "\x00");
            $ok = true;
        } finally {
            fclose($in);
            fclose($out);
            if (!$ok) {
                @unlink($encPath);
            }
        }
        @unlink($plainPath);
    }

    /** Egy teljes rekordnyi (vagy a fájl végén kevesebb) bájt olvasása; '' = fájl vége. */
    private static function readChunk($handle, string $path): string
    {
        $data = '';
        while (strlen($data) < self::ENCRYPTION_CHUNK_BYTES && !feof($handle)) {
            $part = fread($handle, self::ENCRYPTION_CHUNK_BYTES - strlen($data));
            if ($part === false) {
                throw new RuntimeException('A titkosítandó fájl nem olvasható: ' . $path);
            }
            if ($part === '') {
                break;
            }
            $data .= $part;
        }
        return $data;
    }

    private static function readExactly($handle, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        return strlen($data) === $length ? $data : null;
    }

    private static function writeAll($handle, string $data, string $path): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $n = fwrite($handle, $written === 0 ? $data : substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('A titkosított mentés írása sikertelen: ' . $path);
            }
            $written += $n;
        }
    }

    /** Visszafejti $encPath-ot egy ideiglenes fájlba, és visszaadja annak elérési útját — a hívó felelőssége törölni, ha végzett vele. */
    private function decryptToTempFile(string $encPath, string $suffix): string
    {
        $header = (string) @file_get_contents($encPath, false, null, 0, strlen(self::ENCRYPTED_MAGIC_V2));
        if ($header === self::ENCRYPTED_MAGIC_V2) {
            return $this->decryptChunkedToTempFile($encPath, $suffix);
        }

        // Régi (egyben titkosított) formátum — csak a PERF-03 előtt készült
        // mentéseknél; ezek visszafejtése továbbra is a teljes fájlt olvassa.
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

    /**
     * PERF-03 — a darabolt formátum (ENCRYPTED_MAGIC_V2) visszafejtése
     * rekordonként egy ideiglenes fájlba. Bármely rekord hitelesítési hibája,
     * hiányzó „utolsó” rekord (csonkolás) vagy az utolsó rekord utáni adat
     * esetén az ideiglenes fájl törlődik, és ugyanaz a hiba jön, mint a régi
     * formátumnál — részlegesen visszafejtett tartalom sosem kerül a hívóhoz.
     */
    private function decryptChunkedToTempFile(string $encPath, string $suffix): string
    {
        $failure = 'A titkosított mentés visszafejtése sikertelen (hibás kulcs, vagy a fájl sérült/módosított).';
        $in = @fopen($encPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('A titkosított mentés fájlja sérült vagy nem olvasható: ' . $encPath);
        }
        $tmpPath = sys_get_temp_dir() . '/stockmanager_decrypt_' . bin2hex(random_bytes(8)) . $suffix;
        $out = @fopen($tmpPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Az ideiglenes visszafejtett fájl írása sikertelen.');
        }
        @chmod($tmpPath, 0600); // lásd decryptToTempFile() azonos megjegyzése
        $ok = false;
        try {
            $key = $this->encryptionKey();
            $header = self::readExactly($in, strlen(self::ENCRYPTED_MAGIC_V2) + 8);
            if ($header === null) {
                throw new RuntimeException('A titkosított mentés fájlja sérült vagy nem olvasható: ' . $encPath);
            }
            $prefix = substr($header, strlen(self::ENCRYPTED_MAGIC_V2));
            $index = 0;
            while (true) {
                $recordHeader = self::readExactly($in, 1 + 4 + 16);
                if ($recordHeader === null) {
                    throw new RuntimeException($failure); // csonka: hiányzik az utolsó rekord
                }
                $flag = $recordHeader[0];
                $length = unpack('N', substr($recordHeader, 1, 4))[1];
                if (($flag !== "\x00" && $flag !== "\x01") || $length > self::ENCRYPTION_MAX_RECORD_BYTES || $index > 0xFFFFFFFF) {
                    throw new RuntimeException($failure);
                }
                $ciphertext = $length === 0 ? '' : self::readExactly($in, $length);
                if ($ciphertext === null) {
                    throw new RuntimeException($failure);
                }
                $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $index), substr($recordHeader, 5, 16), self::ENCRYPTED_MAGIC_V2 . $prefix . pack('N', $index) . $flag);
                if ($plaintext === false) {
                    throw new RuntimeException($failure);
                }
                self::writeAll($out, $plaintext, $tmpPath);
                $index++;
                if ($flag === "\x01") {
                    break;
                }
            }
            if (fread($in, 1) !== '') {
                throw new RuntimeException($failure); // az utolsó rekord után nem lehet adat
            }
            $ok = true;
        } finally {
            fclose($in);
            fclose($out);
            if (!$ok) {
                @unlink($tmpPath);
            }
        }
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

        if ($this->mysqlCliAvailable('mysqldump')) {
            // --single-transaction: InnoDB-n egyetlen konzisztens pillanatkép
            // (DB-08). --result-file: a dump KIZÁRÓLAG a fájlba kerül, a
            // kliens hibái/figyelmeztetései a $output-ba (nem a mentésbe).
            $defaultsFile = $this->writeMysqlDefaultsFile();
            try {
                $cmd = sprintf(
                    'mysqldump --defaults-extra-file=%s --host=%s --port=%d --user=%s --single-transaction --quick --result-file=%s %s 2>&1',
                    escapeshellarg($defaultsFile),
                    escapeshellarg($m['host']),
                    (int) ($m['port'] ?? 3306),
                    escapeshellarg($m['username']),
                    escapeshellarg($destination),
                    escapeshellarg($m['database'])
                );
                exec($cmd, $output, $exitCode);
            } finally {
                @unlink($defaultsFile);
            }
            if ($exitCode === 0 && is_file($destination) && filesize($destination) > 0) {
                @chmod($destination, 0600); // lásd createSqliteSnapshot() azonos megjegyzése
                $this->backupSettingsSidecar($filename);
                return $filename;
            }
            @unlink($destination);
        }

        $this->dumpMysqlWithPhp($destination);
        @chmod($destination, 0600);
        $this->backupSettingsSidecar($filename);
        return $filename;
    }

    /**
     * DB-01: Windows cmd-ben nincs `command -v` — ott a `where` a megfelelője.
     * Korábban Windows-on ez MINDIG hamisat adott, és a mentés/visszaállítás
     * akkor is a PHP-s tartalékra esett, ha a mysql/mysqldump elérhető volt.
     */
    private function commandExists(string $binary): bool
    {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        $which = @shell_exec($cmd);
        return !empty(trim((string) $which));
    }

    /**
     * PHP-s MySQL dump (ha a mysqldump nem érhető el). DB-08: az összes tábla
     * EGYETLEN konzisztens InnoDB-pillanatképből (REPEATABLE READ + START
     * TRANSACTION WITH CONSISTENT SNAPSHOT — a `mysqldump --single-transaction`
     * megfelelője, táblazár nélkül); korábban táblánként külön autocommit
     * SELECT futott, és a dump közben commitolt eladás egyes tábláiban
     * szerepelt, másokban nem. A fájl a restore által ellenőrzött záró sorral
     * végződik (csonka dump nem állítható vissza).
     */
    private function dumpMysqlWithPhp(string $destination): void
    {
        $pdo = $this->mysqlPdo(true);

        $out = fopen($destination, 'w');
        if (!$out) {
            throw new RuntimeException("Nem sikerült írni a mentési fájlt: $destination");
        }
        @chmod($destination, 0600); // rögtön a létrehozás után, még az írás megkezdése előtt

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            fwrite($out, "-- FountainTrade PHP-based MySQL dump (mysqldump not available)\n");
            fwrite($out, "-- Generated: " . date('Y-m-d H:i:s') . " (consistent snapshot)\n\n");
            fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n\n");

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
            fwrite($out, self::PHP_DUMP_COMPLETED_MARKER . "\n");
        } finally {
            $pdo->exec('COMMIT');
            fclose($out);
        }
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
        if (str_starts_with($header, self::ENCRYPTED_MAGIC_V2) || str_starts_with($header, self::ENCRYPTED_MAGIC)) {
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

            // DB-06: a visszaállítás ELŐTTI élő számlaszám-sorozatok (a helyben
            // valaha kiadott legmagasabb sorszámok) — a visszaállított,
            // régebbi állapot sorozata ezek alá nem eshet.
            $sequenceFloors = $this->captureInvoiceSequenceFloors();

            $this->restoreTouchedLive = false;
            try {
                if ($this->driver === 'mysql') {
                    $this->restoreMysqlFromFile($effectiveSourcePath);
                } else {
                    $this->restoreSqliteFromFile($effectiveSourcePath);
                }
            } catch (Throwable $restoreError) {
                $this->compensateFailedRestore($restoreError, $safetyBackup);
            }

            $sequencesReconciled = $sequenceFloors !== null ? $this->reconcileInvoiceSequences($sequenceFloors) : false;

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

            return ['safety_backup' => $safetyBackup, 'settings_restored' => $settingsRestored, 'invoice_sequences_reconciled' => $sequencesReconciled];
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

        $this->restoreTouchedLive = true;
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

    /**
     * DB-01 — MySQL-visszaállítás. Korábban a PHP-s út a `;\n` mentén darabolt,
     * és minden `--`-szal kezdődő darabot kihagyott: az első darab a fejléc-
     * kommentekkel kezdődött, így a `SET FOREIGN_KEY_CHECKS=0` sosem futott le,
     * és az első, gyerektábla által hivatkozott szülőtábla eldobásánál (MySQL
     * 3730) a restore félúton megállt — kevert (részben visszaállított)
     * adatbázist hagyva. Most:
     *   1. a dump az élő adatbázis ÉRINTÉSE ELŐTT ellenőrzött (teljesség-
     *      jelző, FountainTrade-táblák, értelmezhető utasítások);
     *   2. az FK-ellenőrzés EXPLICIT ki van kapcsolva ugyanazon a kapcsolaton
     *      (CLI: --init-command; PHP: SET FOREIGN_KEY_CHECKS=0), és a végén —
     *      hiba esetén is — visszakapcsol;
     *   3. az utasítások darabolása idézőjel- és kommenttudatos
     *      (splitSqlStatements());
     *   4. ha a módosítás megkezdése után bármi elbukik, a hívó
     *      (restoreFromFile()) a visszaállítás előtti biztonsági mentést
     *      állítja vissza ugyanezzel a mechanizmussal, és hibát jelez.
     * (MySQL-en a DDL implicit commitol, ezért az atomicitás tranzakcióval
     * nem érhető el — ezt a kompenzáló visszaállítás pótolja.)
     */
    private function restoreMysqlFromFile(string $sourcePath): void
    {
        // PERF-03: az ellenőrzés és a végrehajtás is streamelve olvassa a dumpot
        // (korábban file_get_contents + a teljes utasítás-tömb: ~2–3× a dump mérete).
        self::validateMysqlDumpFile($sourcePath);

        if ($this->mysqlCliAvailable('mysql')) {
            $m = $this->dbConfig['mysql'];
            $defaultsFile = $this->writeMysqlDefaultsFile();
            try {
                $cmd = sprintf(
                    'mysql --defaults-extra-file=%s --host=%s --port=%d --user=%s --init-command=%s %s < %s 2>&1',
                    escapeshellarg($defaultsFile),
                    escapeshellarg($m['host']),
                    (int) ($m['port'] ?? 3306),
                    escapeshellarg($m['username']),
                    escapeshellarg('SET FOREIGN_KEY_CHECKS=0'),
                    escapeshellarg($m['database']),
                    escapeshellarg($sourcePath)
                );
                $this->restoreTouchedLive = true;
                exec($cmd, $output, $exitCode);
            } finally {
                @unlink($defaultsFile);
            }
            if ($exitCode === 0) {
                return;
            }
            throw new RuntimeException('A mysql parancs sikertelen volt: ' . implode("\n", $output));
        }

        $pdo = $this->mysqlPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->restoreTouchedLive = true;
            foreach (self::iterateSqlStatementsFromFile($sourcePath) as $statement) {
                if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*1$/i', $statement)) {
                    continue; // a visszakapcsolás a finally-ben, a teljes lefutás után
                }
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * A MySQL-dump ellenőrzése az élő adatbázis érintése ELŐTT.
     *
     * @return list<string> a végrehajtandó utasítások
     */
    public static function validateMysqlDump(string $sql): array
    {
        $trimmed = rtrim($sql);
        $complete = str_ends_with($trimmed, self::PHP_DUMP_COMPLETED_MARKER)   // PHP-s dump (DB-08 óta)
            || preg_match('/SET FOREIGN_KEY_CHECKS=1;$/', $trimmed)             // korábbi PHP-s dump
            || str_contains(substr($trimmed, -300), '-- Dump completed');         // mysqldump
        if (!$complete) {
            throw new RuntimeException('A MySQL-mentés csonka vagy sérült (hiányzik a teljességet jelző zárás) — a visszaállítás nem indult el.');
        }
        $statements = self::splitSqlStatements($sql);
        if (!$statements) {
            throw new RuntimeException('A MySQL-mentés nem tartalmaz végrehajtható utasítást.');
        }
        foreach (['products', 'sales', 'customers'] as $table) {
            if (!preg_match('/CREATE TABLE `?' . $table . '`?\s*\(/i', $sql)) {
                throw new RuntimeException('A fájl nem tűnik FountainTrade adatbázis-mentésnek (hiányzó tábla: ' . $table . ').');
            }
        }
        return $statements;
    }

    /**
     * Idézőjel- és kommenttudatos SQL-darabolás: a `;` csak idézőjelen,
     * backtick-en és kommenten KÍVÜL választ el; a `-- …` és `# …` sorvégi
     * kommentek kimaradnak; a `/* … *\/` blokk (a MySQL feltételes
     * `/*! … *\/` alakja is) az utasítás része marad, a szerver értelmezi.
     *
     * @return list<string>
     */
    public static function splitSqlStatements(string $sql): array
    {
        $done = false;
        return iterator_to_array(self::iterateSqlStatements(function () use ($sql, &$done): string {
            if ($done) {
                return '';
            }
            $done = true;
            return $sql;
        }), false);
    }

    /**
     * PERF-03 — a MySQL-dump ellenőrzése fájlból, streamelve (a validateMysqlDump()
     * ugyanazon szabályai, a teljes fájl memóriába olvasása nélkül). Az élő
     * adatbázis érintése ELŐTT fut (DB-01).
     */
    public static function validateMysqlDumpFile(string $path): void
    {
        $size = @filesize($path);
        $fh = $size === false ? false : @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('A mentési fájl nem olvasható.');
        }
        try {
            fseek($fh, -min($size, 65536), SEEK_END);
            $trimmed = rtrim((string) stream_get_contents($fh));
        } finally {
            fclose($fh);
        }
        $complete = str_ends_with($trimmed, self::PHP_DUMP_COMPLETED_MARKER)
            || preg_match('/SET FOREIGN_KEY_CHECKS=1;$/', $trimmed)
            || str_contains(substr($trimmed, -300), '-- Dump completed');
        if (!$complete) {
            throw new RuntimeException('A MySQL-mentés csonka vagy sérült (hiányzik a teljességet jelző zárás) — a visszaállítás nem indult el.');
        }
        $count = 0;
        $missingTables = ['products' => true, 'sales' => true, 'customers' => true];
        foreach (self::iterateSqlStatementsFromFile($path) as $statement) {
            $count++;
            foreach (array_keys($missingTables) as $table) {
                if (preg_match('/CREATE TABLE `?' . $table . '`?\s*\(/i', $statement)) {
                    unset($missingTables[$table]);
                }
            }
        }
        if ($count === 0) {
            throw new RuntimeException('A MySQL-mentés nem tartalmaz végrehajtható utasítást.');
        }
        foreach (array_keys($missingTables) as $table) {
            throw new RuntimeException('A fájl nem tűnik FountainTrade adatbázis-mentésnek (hiányzó tábla: ' . $table . ').');
        }
    }

    /** @return Generator<int, string> a fájl utasításai, 1 MiB-os darabokban olvasva */
    public static function iterateSqlStatementsFromFile(string $path): Generator
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('A mentési fájl nem olvasható.');
        }
        try {
            yield from self::iterateSqlStatements(function () use ($fh): string {
                $chunk = fread($fh, 1048576);
                return $chunk === false ? '' : $chunk;
            });
        } finally {
            fclose($fh);
        }
    }

    /**
     * A splitSqlStatements() darabolási szabályai folyamként: $read a következő
     * darabot adja ('' = vége). Egy konstrukció (idézőjeles szakasz, komment)
     * darabhatáron is átnyúlhat — ilyenkor a feldolgozás a következő darab
     * beolvasása után ugyanonnan folytatódik.
     *
     * @return Generator<int, string>
     */
    private static function iterateSqlStatements(callable $read): Generator
    {
        $data = '';
        $i = 0;
        $eof = false;
        $buf = '';
        $quote = null;
        $fill = function () use (&$data, &$i, &$eof, $read): void {
            $chunk = $read();
            if ($chunk === '') {
                $eof = true;
                return;
            }
            $data = substr($data, $i) . $chunk;
            $i = 0;
        };
        while (true) {
            $len = strlen($data);
            if (!$eof && $len - $i < 3) {
                $fill(); // legalább 2 bájt előretekintés a darabhatáron is
                continue;
            }
            if ($i >= $len) {
                break;
            }
            if ($quote !== null) {
                $n = strcspn($data, $quote === '`' ? '`' : $quote . '\\', $i);
                if ($n > 0) {
                    $buf .= substr($data, $i, $n);
                    $i += $n;
                    continue;
                }
                $c = $data[$i];
                $buf .= $c;
                if ($c === '\\') {
                    if ($i + 1 < $len) {
                        $buf .= $data[$i + 1];
                        $i += 2;
                    } else {
                        $i++;
                    }
                } elseif ($i + 1 < $len && $data[$i + 1] === $quote) {
                    $buf .= $data[$i + 1]; // duplázott idézőjel
                    $i += 2;
                } else {
                    $quote = null;
                    $i++;
                }
                continue;
            }
            $n = strcspn($data, "'\"`-#/;", $i);
            if ($n > 0) {
                $buf .= substr($data, $i, $n);
                $i += $n;
                continue;
            }
            $c = $data[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                $i++;
                continue;
            }
            if ($c === '#' || ($c === '-' && $i + 1 < $len && $data[$i + 1] === '-' && ($i + 2 >= $len || ctype_space($data[$i + 2])))) {
                $nl = strpos($data, "\n", $i);
                if ($nl === false && !$eof) {
                    $fill();
                    continue;
                }
                $i = $nl === false ? $len : $nl + 1;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $data[$i + 1] === '*') {
                $end = strpos($data, '*/', $i + 2);
                if ($end === false && !$eof) {
                    $fill();
                    continue;
                }
                $end = $end === false ? $len : $end + 2;
                $buf .= substr($data, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    yield trim($buf);
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim($buf) !== '') {
            yield trim($buf);
        }
    }

    /**
     * DB-01 — ha a visszaállítás az élő adatbázis módosítása KÖZBEN bukott el,
     * a visszaállítás előtti biztonsági mentést állítja vissza (ugyanazzal a
     * javított mechanizmussal), majd MINDENKÉPP hibát dob — egy félbemaradt
     * visszaállítás sosem jelenhet meg sikeresként.
     */
    private function compensateFailedRestore(Throwable $restoreError, string $safetyBackupFilename): never
    {
        if (!$this->restoreTouchedLive) {
            throw $restoreError; // az élő adatbázis érintetlen maradt
        }
        $tmp = null;
        try {
            $tmp = $this->decryptToTempFile($this->backupDir . '/' . $safetyBackupFilename, '.' . $this->extension());
            if ($this->driver === 'mysql') {
                $this->restoreMysqlFromFile($tmp);
            } else {
                $this->restoreSqliteFromFile($tmp);
            }
        } catch (Throwable $compensationError) {
            throw new RuntimeException(
                'A visszaállítás félbeszakadt, és a visszaállítás előtti állapot automatikus helyreállítása is sikertelen — KÉZI HELYREÁLLÍTÁS SZÜKSÉGES a(z) '
                . $safetyBackupFilename . ' biztonsági mentésből. Hiba: ' . $restoreError->getMessage() . ' / ' . $compensationError->getMessage(),
                0,
                $restoreError
            );
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
        throw new RuntimeException(
            'A visszaállítás sikertelen volt, az adatbázis a visszaállítás előtti állapotra állt vissza (' . $safetyBackupFilename . '). Hiba: ' . $restoreError->getMessage(),
            0,
            $restoreError
        );
    }

    private function sequencePdo(): ?PDO
    {
        if ($this->driver === 'mysql') {
            return $this->mysqlPdo();
        }
        $path = $this->dbConfig['sqlite']['path'];
        if (!is_file($path)) {
            return null;
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    /**
     * DB-06 — az élő adatbázis számlaszám-sorozatai (NAV-sorozat és a NAV
     * modificationIndex) a visszaállítás ELŐTT. null, ha nem olvashatók (pl.
     * nincs még élő adatbázis) — ekkor nincs mihez igazítani.
     *
     * @return array{invoice: array<string,int>, modification: array<int,int>}|null
     */
    private function captureInvoiceSequenceFloors(): ?array
    {
        try {
            $pdo = $this->sequencePdo();
            if ($pdo === null) {
                return null;
            }
            $floors = ['invoice' => [], 'modification' => []];
            foreach ($pdo->query('SELECT provider, last_allocated_number FROM invoice_sequences')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $floors['invoice'][(string) $row['provider']] = (int) $row['last_allocated_number'];
            }
            foreach ($pdo->query('SELECT original_invoice_id, last_allocated_index FROM invoice_modification_sequences')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $floors['modification'][(int) $row['original_invoice_id']] = (int) $row['last_allocated_index'];
            }
            return $floors;
        } catch (Throwable $e) {
            error_log('[fountaintrade] A számlaszám-sorozatok nem olvashatók a visszaállítás előtt: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * DB-06 — a visszaállított adatbázis sorozatai nem eshetnek a visszaállítás
     * előtti élő érték alá: egy régebbi mentés visszaállítása után sem osztható
     * ki újra egy helyben már kiadott (esetleg a NAV-hoz beküldött) sorszám.
     * A kulcsmódosítás csak felfelé emel (a visszaállított érték, ha nagyobb,
     * megmarad). Egy modificationIndex-sorozat csak akkor, ha az eredeti számla
     * a visszaállított adatbázisban is létezik.
     */
    private function reconcileInvoiceSequences(array $floors): bool
    {
        try {
            $pdo = $this->sequencePdo();
            if ($pdo === null) {
                return false;
            }
            $now = date('Y-m-d H:i:s');
            $ignore = $this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
            foreach ($floors['invoice'] as $provider => $floor) {
                $pdo->prepare("$ignore INTO invoice_sequences (provider, last_allocated_number, updated_at) VALUES (?, 0, ?)")->execute([$provider, $now]);
                $pdo->prepare('UPDATE invoice_sequences SET last_allocated_number = ?, updated_at = ? WHERE provider = ? AND last_allocated_number < ?')
                    ->execute([$floor, $now, $provider, $floor]);
            }
            $exists = $pdo->prepare('SELECT 1 FROM invoices WHERE id = ?');
            foreach ($floors['modification'] as $originalId => $floor) {
                $exists->execute([$originalId]);
                $found = $exists->fetchColumn() !== false;
                $exists->closeCursor();
                if (!$found) {
                    continue;
                }
                $pdo->prepare("$ignore INTO invoice_modification_sequences (original_invoice_id, last_allocated_index, updated_at) VALUES (?, 0, ?)")->execute([$originalId, $now]);
                $pdo->prepare('UPDATE invoice_modification_sequences SET last_allocated_index = ?, updated_at = ? WHERE original_invoice_id = ? AND last_allocated_index < ?')
                    ->execute([$floor, $now, $originalId, $floor]);
            }
            return true;
        } catch (Throwable $e) {
            // Pl. egy a sorozat-tábla (1.1.0) előtti mentés — a hívó az
            // eredményben látja, hogy az egyeztetés nem történt meg.
            error_log('[fountaintrade] A számlaszám-sorozatok egyeztetése a visszaállítás után sikertelen: ' . $e->getMessage());
            return false;
        }
    }
}

