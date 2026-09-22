<?php

require_once __DIR__ . '/AppVersion.php';
require_once __DIR__ . '/GitHubReleaseClient.php';
require_once __DIR__ . '/UpdateVerifier.php';
require_once __DIR__ . '/UpdateLock.php';
require_once __DIR__ . '/UpdateState.php';
require_once __DIR__ . '/BackupManager.php';
require_once __DIR__ . '/Settings.php';

/**
 * A FountainTrade önfrissítő rendszerének fő vezérlője — a teljes
 * `checking → ... → completed|failed→rolling_back→rolled_back|
 * manual_recovery_required` állapotgépet (lásd UpdateState::STATES) EZ az
 * osztály futtatja végig, egyetlen `install()` híváson belül.
 *
 * FONTOS, SZÁNDÉKOS ARCHITEKTURÁLIS DÖNTÉS a fájl-telepítésről (lásd
 * README "Önfrissítés" szakasza a teljes indoklással): a dokumentált
 * production telepítés (telepites-tavoli-szerver.txt) nginx-et használ,
 * aminek a `root` direktívája KÖZVETLENÜL a `webroot/` almappára mutat, NEM
 * egy szimlinkelt `current/`-re — emiatt egy klasszikus, zéró-downtime
 * "releases/x.y.z + current symlink" atomikus csere NEM elérhető anélkül,
 * hogy az üzemeltető átállítaná az nginx-konfigurációt (ez dokumentálva
 * van, mint opcionális, ajánlott továbbfejlesztés, de NEM feltételezett
 * alapállapot). Ehelyett egy EGYENÉRTÉKŰ biztonsági garanciát adó,
 * másolás-alapú mechanizmust valósít meg: a kicsomagolt release-t egy
 * staging könyvtárban TELJESEN validáljuk, MIELŐTT egyetlen production
 * fájlhoz is hozzáérnénk; a telepítés MEGKEZDÉSE előtt a live kódfát
 * (pontosan ugyanazok az útvonalak, amiket a telepítés felülír)
 * VÁLTOZATLANUL lementjük egy rollback könyvtárba; bármilyen hiba esetén
 * ebből a mentésből állítjuk vissza a fájlokat — ez ugyanazt a végeredményt
 * adja (egy sikertelen frissítés sose hagyja az alkalmazást fél-frissített
 * állapotban), csak nem szimlink-cserével, hanem explicit másolással.
 *
 * A tényleges DB-migráció ÉS az "egészség-ellenőrzés" egy KÜLÖN,
 * frissen indított PHP CLI folyamatban fut (lásd runPostDeployCheck()) —
 * ez SZÁNDÉKOS: a jelenleg futó PHP-folyamat memóriájában MÁR be vannak
 * töltve a RÉGI kód osztálydefiníciói (PHP nem tölti újra őket futás
 * közben), tehát a frissen kimásolt, ÚJ Database.php/AppVersion.php stb.
 * fájlokat csak egy VALÓDI, új folyamat "látja" ténylegesen.
 */
final class UpdateInstaller
{
    private Database $db;
    private array $config;
    private string $appRoot;
    private Settings $settingsStore;
    private GitHubReleaseClient $githubClient;
    private UpdateVerifier $verifier;
    private BackupManager $backupManager;
    private UpdateLock $lock;
    private UpdateState $state;
    private string $stagingDir;
    private string $rollbackDir;
    private string $phpCliBinary;

    /** A release-archívumban ténylegesen szereplő, telepítendő gyökér-útvonalak — minden más (config/, data/, invoices/, .git stb.) figyelmen kívül marad, akkor is, ha valamiért benne lenne az archívumban. */
    private const DEPLOY_ROOTS = ['webroot', 'src', 'tools', 'tests', 'schema.sql', 'schema.mysql.sql', 'README.md', 'CHANGELOG.md', 'ROADMAP.md', 'install.txt', 'telepites-tavoli-szerver.txt'];

    /** Feltöltött, ügyfél-specifikus tartalom a webroot ALATT — SOSE íródik felül/törlődik, pontosan a .gitignore-ban is védett minták. */
    private const PROTECTED_WEBROOT_PREFIXES = ['assets/logo.', 'assets/print-logo.', 'assets/products/'];

    public function __construct(
        Database $db,
        array $config,
        string $appRoot,
        ?Settings $settingsStore = null,
        ?GitHubReleaseClient $githubClient = null,
        ?UpdateVerifier $verifier = null,
        ?BackupManager $backupManager = null,
        ?UpdateLock $lock = null,
        ?UpdateState $state = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->appRoot = rtrim($appRoot, '/');
        $this->settingsStore = $settingsStore ?? new Settings($this->appRoot . '/data/settings.json');

        $updateCfg = $config['update'] ?? [];
        $this->githubClient = $githubClient ?? new GitHubReleaseClient((string) ($updateCfg['repo_owner'] ?? ''), (string) ($updateCfg['repo_name'] ?? ''));
        $this->verifier = $verifier ?? new UpdateVerifier();
        $this->backupManager = $backupManager ?? new BackupManager($config['db'], $this->appRoot . '/data/backups');
        $this->lock = $lock ?? new UpdateLock($db);
        $this->state = $state ?? new UpdateState($db);

        $this->stagingDir = $this->appRoot . '/data/update-staging';
        $this->rollbackDir = $this->appRoot . '/data/update-rollback';
        $this->phpCliBinary = (string) ($updateCfg['php_cli_binary'] ?? PHP_BINARY);
    }

    /**
     * Csak ellenőriz — NEM tölt le/telepít semmit. Frissíti az update_state
     * `latest_*`/`latest_checked_at` mezőit, és `update_available`-re
     * váltja az állapotot, ha valóban van újabb, érvényes release. Olcsó
     * (néhány GitHub API-hívás), ezért HTTP-kérésből is biztonságosan
     * hívható (lásd webroot/api/update-check.php).
     */
    public function checkForUpdate(): array
    {
        $current = $this->state->current();
        if (in_array($current['state'], ['downloading', 'verifying', 'backing_up', 'staging', 'maintenance', 'installing', 'migrating', 'health_check', 'rolling_back'], true)) {
            return ['ok' => false, 'reason' => 'update_in_progress'];
        }

        $this->state->transitionTo('checking');
        try {
            [$release, $manifest] = $this->fetchAndValidateManifest();

            $isNewer = AppVersion::compare($manifest['version'], AppVersion::CURRENT) > 0;
            $this->state->update([
                'latest_version'       => $manifest['version'],
                'latest_release_tag'   => $release['tag_name'],
                'latest_commit_sha'    => $manifest['commit'],
                'latest_release_notes' => (string) ($release['body'] ?? ''),
                'latest_published_at'  => $release['published_at'] ?? null,
                'latest_checked_at'    => date('Y-m-d H:i:s'),
                'last_check_error'     => null,
            ]);
            $this->state->transitionTo($isNewer ? 'update_available' : 'idle');

            return ['ok' => true, 'update_available' => $isNewer, 'latest_version' => $manifest['version']];
        } catch (Throwable $e) {
            // 19. pont: a GitHub bármilyen hibája esetén az alkalmazás
            // TOVÁBBRA IS teljesen működőképes marad — csak logolunk és az
            // állapotot 'idle'-re visszaállítjuk (NEM 'failed'-re — ez itt
            // csak egy ELLENŐRZÉS volt, nem egy megkezdett telepítés).
            error_log('[fountaintrade-update] checkForUpdate hiba: ' . $e->getMessage());
            $this->state->update(['latest_checked_at' => date('Y-m-d H:i:s'), 'last_check_error' => $e->getMessage()]);
            $this->state->transitionTo('idle');
            return ['ok' => false, 'reason' => 'check_failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * A TELJES telepítési folyamat — lásd az osztály docblockját. Hosszú
     * ideig futhat (percekig); a hívónak (webroot/api/update-install.php
     * VAGY tools/update-install-cli.php) a saját felelőssége, hogy ez ne
     * egy oldalbetöltéshez kötött HTTP-kérés timeoutján belül fusson (lásd
     * ott a docblockokat).
     */
    public function install(string $triggerSource, ?string $actor): array
    {
        if (!$this->lock->acquire()) {
            return ['ok' => false, 'reason' => 'already_running'];
        }

        $historyId = null;
        $migrationRan = false;
        $filesDeployed = false;
        $backupReference = null;
        $snapshotDir = null;

        try {
            [$release, $manifest] = $this->fetchAndValidateManifest();
            $fromVersion = AppVersion::CURRENT;
            $toVersion = $manifest['version'];

            if (AppVersion::compare($toVersion, $fromVersion) === 0) {
                $this->state->transitionTo('idle');
                return ['ok' => true, 'already_up_to_date' => true, 'version' => $fromVersion];
            }

            $historyId = $this->state->recordHistoryStart($fromVersion, $toVersion, $triggerSource, $actor, $release['tag_name'], $manifest['commit']);

            $this->runPreflightChecks($manifest);

            $this->state->transitionTo('downloading');
            $artifactAsset = GitHubReleaseClient::findAsset($release, $manifest['artifact']);
            if (!$artifactAsset || empty($artifactAsset['browser_download_url'])) {
                throw new RuntimeException('A manifestben hivatkozott artifact ("' . $manifest['artifact'] . '") nem található a release csatolmányai között.');
            }
            @mkdir($this->stagingDir . '/downloads', 0775, true);
            $downloadPath = $this->stagingDir . '/downloads/' . bin2hex(random_bytes(8)) . '.zip';
            $this->githubClient->downloadAsset($artifactAsset['browser_download_url'], $downloadPath);

            $this->state->transitionTo('verifying');
            $this->verifier->verifyChecksum($downloadPath, $manifest['sha256']);
            $extractDir = $this->stagingDir . '/extracted/' . $toVersion . '_' . bin2hex(random_bytes(4));
            $this->verifier->extractArchiveSafely($downloadPath, $extractDir);
            $this->verifier->validateExtractedStructure($extractDir);
            @unlink($downloadPath);

            $this->state->transitionTo('backing_up');
            // 8. pont: HA A BACKUP SIKERTELEN, A FRISSÍTÉS NEM FOLYTATÓDHAT.
            // A BackupManager::run() kivétel esetén dob — itt SZÁNDÉKOSAN
            // nincs try/catch: a kivétel a külső catch-ig fut, ami 'failed'
            // állapotba teszi a folyamatot, MIELŐTT bármi production-fájlhoz
            // vagy a karbantartási módhoz hozzáértünk volna.
            $backupResult = $this->backupManager->run($this->settingsStore->read());
            $backupReference = $backupResult['filename'];
            $this->state->update(['progress_message' => 'Biztonsági mentés kész: ' . $backupReference]);

            $this->state->transitionTo('maintenance');
            $this->setMaintenanceMode(true);

            $this->state->transitionTo('installing');
            $snapshotDir = $this->rollbackDir . '/' . $fromVersion . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
            $this->snapshotLiveCode($snapshotDir);
            $this->deployExtractedCode($extractDir, $manifest['removed_files'] ?? []);
            $filesDeployed = true;

            $this->state->transitionTo('migrating');
            $migrationResult = $this->runPostDeployCheck();
            $migrationRan = true;
            if (!$migrationResult['success']) {
                throw new RuntimeException('A telepítés utáni migráció/egészség-ellenőrzés sikertelen: ' . ($migrationResult['error'] ?? 'ismeretlen hiba'));
            }

            $this->state->transitionTo('health_check');
            if (($migrationResult['version'] ?? null) !== $toVersion) {
                throw new RuntimeException('Az egészség-ellenőrzés a várttól eltérő verziót talált a telepítés után (' . ($migrationResult['version'] ?? '?') . ' a(z) ' . $toVersion . ' helyett).');
            }

            $this->setMaintenanceMode(false);
            $this->cleanupStaging($extractDir);
            $this->cleanupOldRollbackSnapshots();

            $now = date('Y-m-d H:i:s');
            $this->state->update([
                'current_version'                => $toVersion,
                'last_successful_update_at'       => $now,
                'last_successful_update_version'  => $toVersion,
                'progress_message'                => 'Frissítés sikeresen befejezve.',
            ]);
            $this->state->transitionTo('completed');
            $this->state->recordHistoryFinish($historyId, 'completed', null, $backupReference);

            return ['ok' => true, 'from_version' => $fromVersion, 'to_version' => $toVersion, 'backup_reference' => $backupReference];
        } catch (Throwable $e) {
            error_log('[fountaintrade-update] install hiba: ' . $e->getMessage());
            $rollbackState = null;

            if ($filesDeployed && $snapshotDir !== null) {
                $this->state->transitionTo('rolling_back');
                try {
                    $this->restoreSnapshot($snapshotDir);
                    if ($migrationRan && $backupReference !== null) {
                        // A DB-migráció (ha egyáltalán lefutott) esetleg NEM
                        // visszafordítható lépéseket is tartalmazhatott (lásd
                        // osztály docblockja) — a legbiztonságosabb, EGYETEMES
                        // válasz nem az egyes migrációs lépések egyenkénti
                        // visszavonása, hanem a MIGRÁCIÓ ELŐTTI teljes
                        // adatbázis-mentés visszaállítása, hogy a régi kód
                        // GARANTÁLTAN a régi sémával fusson újra együtt.
                        // KRITIKUS: lásd Database::closeForExternalFileReplacement()
                        // docblockja — a restoreFromFile() a live SQLite
                        // fájlt egy NYERS fájlmásolással írja felül; ha
                        // eközben a $this->db kapcsolat nyitva marad,
                        // VALÓS fájlsérülést okozhat (nem csak elavult
                        // kapcsolat-állapotot), amit egy utólagos
                        // reconnect() már nem tud orvosolni. A kapcsolatot
                        // ezért a másolás KÖRÜL kell teljesen le- majd
                        // újranyitni, nem csak utána lecserélni.
                        $this->db->closeForExternalFileReplacement();
                        try {
                            // A $backupReference a Szerver SAJÁT, a frissítés
                            // ELŐTT ugyanezen a gépen, ugyanezzel a kóddal
                            // készített biztonsági mentésére mutat (nem
                            // kliens/felhasználó által feltöltött fájl) —
                            // lásd BackupManager::detectEncryption() docblokkja.
                            $this->backupManager->restoreFromFile($this->appRoot . '/data/backups/' . $backupReference, true);
                        } finally {
                            $this->db->reconnect();
                        }
                    }
                    $this->setMaintenanceMode(false);
                    $rollbackState = 'success';
                    $this->state->transitionTo('rolled_back', ['progress_message' => 'Sikertelen frissítés — visszaállítva az előző verzióra.']);
                } catch (Throwable $rollbackError) {
                    error_log('[fountaintrade-update] ROLLBACK HIBA: ' . $rollbackError->getMessage());
                    $rollbackState = 'failed';
                    // Karbantartási mód SZÁNDÉKOSAN bekapcsolva marad — az
                    // alkalmazás állapota ismeretlen/inkonzisztens lehet,
                    // biztonságosabb a karbantartási üzenetet mutatni, mint
                    // egy esetlegesen törött állapotot élesben kiszolgálni.
                    $this->state->transitionTo('manual_recovery_required', [
                        'progress_message' => 'A visszaállítás sikertelen — kézi beavatkozás szükséges. Hiba: ' . $rollbackError->getMessage(),
                    ]);
                }
            } else {
                // A hiba MÉG A FÁJLOK ÍRÁSA ELŐTT történt (pl. letöltési/
                // ellenőrzési/backup hiba) — nincs mit visszaállítani, a
                // production kód/DB érintetlen maradt.
                if ($this->isMaintenanceModeActive()) {
                    $this->setMaintenanceMode(false);
                }
                $this->state->transitionTo('failed', ['progress_message' => 'Hiba: ' . $e->getMessage()]);
            }

            if ($historyId !== null) {
                $this->state->recordHistoryFinish(
                    $historyId,
                    $rollbackState === 'failed' ? 'manual_recovery_required' : ($filesDeployed ? 'rolled_back' : 'failed'),
                    $e->getMessage(),
                    $backupReference,
                    $rollbackState
                );
            }

            return ['ok' => false, 'error' => $e->getMessage(), 'rollback_state' => $rollbackState];
        } finally {
            $this->lock->release();
        }
    }

    // ---- Belső lépések ----

    /** @return array{0:array,1:array} [GitHub release JSON, validált manifest tömb] */
    private function fetchAndValidateManifest(): array
    {
        $release = $this->githubClient->fetchLatestRelease();
        $manifestAsset = GitHubReleaseClient::findAsset($release, 'manifest.json');
        if (!$manifestAsset || empty($manifestAsset['browser_download_url'])) {
            throw new RuntimeException('A release nem tartalmaz manifest.json csatolmányt — nem érvényes FountainTrade release.');
        }

        @mkdir($this->stagingDir . '/manifests', 0775, true);
        $manifestPath = $this->stagingDir . '/manifests/' . bin2hex(random_bytes(8)) . '.json';
        $this->githubClient->downloadAsset($manifestAsset['browser_download_url'], $manifestPath);
        $raw = file_get_contents($manifestPath);
        @unlink($manifestPath);

        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('A manifest.json nem érvényes JSON.');
        }

        $this->verifier->validateManifestStructure($manifest);
        $this->verifier->validateProduct($manifest);
        $this->verifier->validateVersionMatchesTag($manifest, $release['tag_name']);
        $resolvedSha = $this->githubClient->resolveTagCommitSha($release['tag_name']);
        $this->verifier->validateCommitMatches($manifest, $resolvedSha);
        $this->verifier->validateUpgradePath(AppVersion::CURRENT, $manifest);

        return [$release, $manifest];
    }

    /** @throws RuntimeException bármely kritikus előfeltétel hiánya esetén — ilyenkor a telepítés EL SEM KEZDŐDIK. */
    private function runPreflightChecks(array $manifest): void
    {
        if (!is_writable($this->appRoot . '/webroot') || !is_writable($this->appRoot . '/src')) {
            throw new RuntimeException('A webroot/src könyvtár nem írható a PHP-folyamat számára — a telepítés nem indítható.');
        }
        @mkdir($this->appRoot . '/data', 0775, true);
        if (!is_writable($this->appRoot . '/data')) {
            throw new RuntimeException('A data/ könyvtár nem írható — a telepítés nem indítható.');
        }

        $freeBytes = @disk_free_space($this->appRoot);
        $artifactSize = 0;
        // A manifestben nincs méret — a release JSON asset-objektumából
        // (fetchAndValidateManifest() hívója már ismeri) becsülhető lenne,
        // itt egy bőkezű, fix minimumot követelünk meg helyette, hogy ez a
        // metódus önmagában (a release-objektum nélkül is) hívható maradjon.
        $minRequiredBytes = 300 * 1024 * 1024;
        if ($freeBytes !== false && $freeBytes < $minRequiredBytes) {
            throw new RuntimeException('Nincs elegendő szabad lemezterület a frissítéshez (legalább ~300 MB szükséges).');
        }

        if (!empty($manifest['min_php_version']) && version_compare(PHP_VERSION, $manifest['min_php_version'], '<')) {
            throw new RuntimeException("A telepített PHP verzió (" . PHP_VERSION . ") régebbi, mint amit ez a release megkövetel ({$manifest['min_php_version']}).");
        }

        try {
            $this->db->pdo()->query('SELECT 1');
        } catch (Throwable $e) {
            throw new RuntimeException('Az adatbázis jelenleg nem elérhető — a telepítés nem indítható: ' . $e->getMessage());
        }

        $this->assertCliPhpBinaryWorks();
    }

    private function assertCliPhpBinaryWorks(): void
    {
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$this->phpCliBinary, '-r', 'echo PHP_SAPI;'], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("A konfigurált PHP CLI bináris (\"{$this->phpCliBinary}\") nem indítható — állítsd be a config/config.php 'update.php_cli_binary' értékét egy valódi CLI PHP futtatható elérési útjára.");
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || trim($output) !== 'cli') {
            throw new RuntimeException("A konfigurált PHP bináris (\"{$this->phpCliBinary}\") nem CLI módban fut (ez gyakran php-fpm alatt fordul elő) — a migráció/egészség-ellenőrzés ehhez egy VALÓDI CLI PHP futtatható szükséges. Állítsd be a config/config.php 'update.php_cli_binary' értékét, pl. '/usr/bin/php8.3'.");
        }
    }

    private function isProtectedWebrootPath(string $relativeUnderWebroot): bool
    {
        foreach (self::PROTECTED_WEBROOT_PREFIXES as $prefix) {
            if (str_starts_with($relativeUnderWebroot, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** A jelenleg élő kódfa (pontosan a DEPLOY_ROOTS útvonalak) változatlan másolata $snapshotDir-be — ez a rollback forrása hiba esetén. */
    private function snapshotLiveCode(string $snapshotDir): void
    {
        foreach (self::DEPLOY_ROOTS as $root) {
            $liveSource = $this->appRoot . '/' . $root;
            if (!file_exists($liveSource)) {
                continue;
            }
            $this->copyRecursively($liveSource, $snapshotDir . '/' . $root, $root === 'webroot');
        }
    }

    /** A staging könyvtárban validált, kicsomagolt release átmásolása az élő útvonalakra — védett (feltöltött) fájlokat sose ír felül. */
    private function deployExtractedCode(string $extractDir, array $removedFiles): void
    {
        foreach (self::DEPLOY_ROOTS as $root) {
            $source = $extractDir . '/' . $root;
            if (!file_exists($source)) {
                continue;
            }
            $this->copyRecursively($source, $this->appRoot . '/' . $root, $root === 'webroot');
        }

        // Opcionális, explicit törlési lista a manifestben (pl. egy régi
        // release-ben átnevezett/megszüntetett fájlhoz) — csak a
        // DEPLOY_ROOTS alá eső, nem védett, path-traversalt NEM tartalmazó
        // útvonalak fogadhatók el, ugyanazokkal az ellenőrzésekkel, mint a
        // ZIP-bejegyzéseknél (lásd UpdateVerifier::assertSafeZipEntryName()).
        foreach ($removedFiles as $relativePath) {
            if (!is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
                continue;
            }
            $topSegment = explode('/', $relativePath)[0];
            if (!in_array($topSegment, self::DEPLOY_ROOTS, true)) {
                continue;
            }
            if (str_starts_with($relativePath, 'webroot/') && $this->isProtectedWebrootPath(substr($relativePath, strlen('webroot/')))) {
                continue;
            }
            $target = $this->appRoot . '/' . $relativePath;
            if (is_file($target)) {
                @unlink($target);
            }
        }
    }

    /** Hiba esetén: az élő kódfa visszaállítása a telepítés ELŐTTI másolatból — a snapshot mindig teljes (nem diff), így törlést/hozzáadást is helyesen fordít vissza. */
    private function restoreSnapshot(string $snapshotDir): void
    {
        foreach (self::DEPLOY_ROOTS as $root) {
            $snapshotSource = $snapshotDir . '/' . $root;
            if (!file_exists($snapshotSource)) {
                continue;
            }
            $this->copyRecursively($snapshotSource, $this->appRoot . '/' . $root, $root === 'webroot');
        }
    }

    /**
     * Rekurzív fájlmásolás $from-ból $to-ba. $protectWebrootAssets=true
     * esetén (kizárólag a "webroot" gyökérnél) a feltöltött-tartalom
     * útvonalak (lásd PROTECTED_WEBROOT_PREFIXES) KIMARADNAK — sem a
     * telepítéskor, sem a snapshot-vételkor/visszaállításkor nem
     * érintjük őket, hogy egy éles feltöltött logó/termékkép SOSE
     * veszhessen el egy frissítés/rollback során.
     */
    private function copyRecursively(string $from, string $to, bool $protectWebrootAssets): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), 0775, true);
            copy($from, $to);
            return;
        }
        if (!is_dir($from)) {
            return;
        }
        @mkdir($to, 0775, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $relative = str_replace('\\', '/', $relative);

            if ($protectWebrootAssets && $this->isProtectedWebrootPath($relative)) {
                continue;
            }

            $destination = $to . '/' . $relative;
            if ($item->isDir()) {
                @mkdir($destination, 0775, true);
            } else {
                @mkdir(dirname($destination), 0775, true);
                copy($item->getPathname(), $destination);
            }
        }
    }

    /**
     * Egy VALÓDI, FRISSEN indított PHP CLI folyamatban futtatja a
     * `tools/update-post-deploy-check.php` szkriptet — lásd az osztály
     * docblockjának indoklását, miért nem elég ugyanebben a folyamatban
     * egyszerűen `new Database(...)`-t hívni. Maga a Database-konstruktor
     * (a friss folyamatban, a friss kóddal) futtatja le a tényleges
     * migrációt (ensureSchema()) — nincs külön "migrációs futtató", a
     * MEGLÉVŐ, éles migrációs rendszert használjuk újra, pontosan a 14.
     * pont előírása szerint.
     *
     * @return array{success:bool, version?:string, schema_version?:int, error?:string}
     */
    private function runPostDeployCheck(): array
    {
        $script = $this->appRoot . '/tools/update-post-deploy-check.php';
        if (!is_file($script)) {
            return ['success' => false, 'error' => 'A tools/update-post-deploy-check.php szkript hiányzik a telepített csomagból.'];
        }

        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$this->phpCliBinary, $script, $this->appRoot], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'A telepítés utáni ellenőrző folyamat nem indítható.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Az ellenőrző folyamat érvénytelen választ adott. Exit: ' . $exitCode . '. Stderr: ' . trim((string) $stderr)];
        }
        if ($exitCode !== 0 || empty($decoded['success'])) {
            return ['success' => false, 'error' => $decoded['error'] ?? ('Az ellenőrző folyamat sikertelen volt (exit ' . $exitCode . ').')];
        }
        return $decoded;
    }

    private function setMaintenanceMode(bool $active): void
    {
        $this->settingsStore->save([
            'maintenance_mode_active'  => $active,
            'maintenance_mode_message' => 'A FountainTrade frissítése folyamatban van.',
        ]);
    }

    private function isMaintenanceModeActive(): bool
    {
        return !empty($this->settingsStore->read()['maintenance_mode_active']);
    }

    private function cleanupStaging(string $extractDir): void
    {
        $this->deleteDirectory($extractDir);
    }

    /** A rollback-mentéseket a backup-rendszerhez hasonlóan időkorlátosan visszük — csak az utolsó néhányat tartjuk meg, hogy a lemez ne teljen meg végtelenül régi snapshot-okkal. */
    private function cleanupOldRollbackSnapshots(int $keep = 3): void
    {
        if (!is_dir($this->rollbackDir)) {
            return;
        }
        $entries = glob($this->rollbackDir . '/*', GLOB_ONLYDIR) ?: [];
        rsort($entries);
        foreach (array_slice($entries, max(0, $keep)) as $old) {
            $this->deleteDirectory($old);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
