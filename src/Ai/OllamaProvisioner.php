<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderException.php';

/**
 * Fázis 6, Rész B — Ollama-telepítés/-ellenőrzés Standalone/Szerver
 * node-okon. Ez az osztály SOSE a LocalProvider (API-kliens) helyett/
 * mellett fut az agent-válaszadás útjában — kizárólag a Beállítások
 * oldal admin-felületéről és a hozzá tartozó végpontokból hívott,
 * KÜLÖN telepítési/karbantartási művelet (lásd a kör 17. pontja:
 * "Do not put installation logic inside LocalProvider").
 *
 * HIVATALOS TELEPÍTÉSI MECHANIZMUS (a kör 15. pontja — élőben,
 * 2026-09-23-án ellenőrizve, NEM a képzési adatokból):
 * - A Windows-telepítő "OllamaSetup.exe", a GitHub Releases API-n
 *   keresztül (https://api.github.com/repos/ollama/ollama/releases/latest)
 *   szerezhető be — ez adja vissza a MINDENKORI legújabb kiadás
 *   pontos "browser_download_url"-ját (github.com/ollama/ollama/
 *   releases/download/...) ÉS egy GitHub-infrastruktúra által SZÁMÍTOTT
 *   SHA-256 "digest" mezőt minden egyes eszközhöz — ez a valódi,
 *   ellenőrizhető integritás-mechanizmus, amit self::downloadAndVerifyInstaller()
 *   használ (a projekt NEM talál ki/publikál saját checksumot).
 * - Az ollama/ollama forráskód app/ollama.iss fájlja (Inno Setup
 *   szkript) igazolja: "PrivilegesRequired=lowest" — az Ollama Windows-
 *   telepítője NEM igényel Rendszergazdai/UAC-jogosultságot, a
 *   felhasználó saját "%LOCALAPPDATA%\Programs\Ollama" mappájába
 *   települ (ugyanezt írja a docs.ollama.com/windows hivatalos oldal
 *   is: "The Ollama install does not require Administrator"). Emiatt
 *   ez a projekt SOSE valósít meg UAC-megkerülést vagy külön
 *   emelt jogú segédfolyamatot — a telepítő UGYANAZZAL a jogosultsággal
 *   futtatható, mint amivel a FountainTrade Szerver-folyamat fut.
 * - Az Inno Setup STANDARD, dokumentált csendes-telepítési kapcsolói
 *   (jrsoftware.org/ishelp) érvényesek, semmilyen egyedi felülírás
 *   nélkül: "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART" — a docs.ollama.com
 *   oldal ÖNÁLLÓAN is megerősíti a "/DIR=" kapcsoló meglétét
 *   ("OllamaSetup.exe /DIR=\"d:\some\location\""), ami UGYANAZ az
 *   Inno Setup szabvány-kapcsoló, tehát a fenti azonosítás (Inno Setup,
 *   nem NSIS/MSI) két FÜGGETLEN forrásból is alátámasztott.
 * - Az Ollama API alapértelmezetten KIZÁRÓLAG 127.0.0.1:11434-en
 *   figyel (a kör 19. pontja) — ezt az osztály SOSE módosítja (nincs
 *   OLLAMA_HOST beállítás sehol ebben a fájlban), tehát a biztonságos
 *   alapértelmezés érintetlen marad.
 * - Modell-letöltés: POST /api/pull {"model":..., "stream":false} →
 *   végleges válasz {"status":"success"} (vagy hiba-JSON) — ugyanaz a
 *   REST-konvenció, mint a LocalProvider MÁR használt /api/chat, /api/tags
 *   végpontjai.
 *
 * BIZTONSÁGI ALAPELV (a kör 16. pontja): NINCS tetszőleges URL-letöltés
 * (a letöltési cím MINDIG a github.com/ollama/ollama/releases/download/
 * előtaggal kell kezdődjön — self::isDownloadUrlTrusted()), NINCS
 * tetszőleges végrehajtás (KIZÁRÓLAG a MI MAGUNK letöltött és SHA-256-
 * tal ellenőrzött fájlt futtatjuk, sose egy külső/felhasználói
 * útvonalat), NINCS shell-interpretált parancssor (proc_open mindig
 * tömb-alakú argv-t kap, sose összefűzött string-et).
 */
final class OllamaProvisioner
{
    public const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/ollama/ollama/releases/latest';
    public const TRUSTED_ASSET_URL_PREFIX = 'https://github.com/ollama/ollama/releases/download/';
    private const INSTALLER_ASSET_NAME = 'OllamaSetup.exe';

    private const GITHUB_API_TIMEOUT_SECONDS = 10;
    private const DOWNLOAD_TIMEOUT_SECONDS = 300;
    private const INSTALL_TIMEOUT_SECONDS = 180;
    public const PULL_TIMEOUT_SECONDS = 1800;
    private const OLLAMA_API_TIMEOUT_SECONDS = 5;

    // ------------------------------------------------------------------
    // Telepítettség-/verzió-/API-detektálás
    // ------------------------------------------------------------------

    /** A Windows-telepítő alapértelmezett célkönyvtára — lásd docs.ollama.com/windows. */
    public static function installedBinaryPath(?string $localAppData = null): ?string
    {
        $localAppData = $localAppData ?? (string) getenv('LOCALAPPDATA');
        if ($localAppData === '') {
            return null;
        }
        $path = rtrim($localAppData, '\\/') . '\\Programs\\Ollama\\ollama.exe';
        return is_file($path) ? $path : null;
    }

    public static function isInstalled(?string $localAppData = null): bool
    {
        return self::installedBinaryPath($localAppData) !== null;
    }

    /**
     * CLI-alapú verzió-lekérdezés ("ollama.exe --version") — akkor is
     * működik, ha az Ollama szerver-folyamat éppen NEM fut (az API
     * ilyenkor nem érhető el). $exePath/$timeoutSeconds injektálhatók
     * teszteléshez (lásd tests/OllamaProvisionerTest.php — SOSE a
     * valódi ollama.exe-t futtatjuk PHPUnit alatt).
     */
    public static function detectCliVersion(string $exePath, int $timeoutSeconds = 5): ?string
    {
        if (!is_file($exePath)) {
            return null;
        }
        $result = self::runProcess([$exePath, '--version'], $timeoutSeconds);
        if (!$result['ok']) {
            return null;
        }
        if (preg_match('/(\d+\.\d+\.\d+)/', $result['stdout'] . ' ' . $result['stderr'], $m)) {
            return $m[1];
        }
        return null;
    }

    /** API-alapú verzió-lekérdezés — GET /api/version, {"version": "0.34.3"}. */
    public static function detectApiVersion(string $baseUrl, int $timeoutSeconds = self::OLLAMA_API_TIMEOUT_SECONDS): ?string
    {
        try {
            $decoded = self::httpGetJson(rtrim($baseUrl, '/') . '/api/version', $timeoutSeconds);
        } catch (AiProviderException $e) {
            return null;
        }
        return is_array($decoded) && isset($decoded['version']) ? (string) $decoded['version'] : null;
    }

    public static function isApiAvailable(string $baseUrl, int $timeoutSeconds = self::OLLAMA_API_TIMEOUT_SECONDS): bool
    {
        return self::detectApiVersion($baseUrl, $timeoutSeconds) !== null;
    }

    /**
     * Egy adott modell letöltve van-e — ugyanaz az egyezési logika
     * (":latest" utótag-tolerancia), mint LocalProvider::checkAvailability().
     */
    public static function isModelInstalled(string $baseUrl, string $model, int $timeoutSeconds = self::OLLAMA_API_TIMEOUT_SECONDS): bool
    {
        try {
            $decoded = self::httpGetJson(rtrim($baseUrl, '/') . '/api/tags', $timeoutSeconds);
        } catch (AiProviderException $e) {
            return false;
        }
        if (!is_array($decoded) || !isset($decoded['models']) || !is_array($decoded['models'])) {
            return false;
        }
        $wanted = strtolower($model);
        foreach ($decoded['models'] as $m) {
            $name = strtolower((string) ($m['name'] ?? $m['model'] ?? ''));
            if ($name === $wanted || str_starts_with($name, $wanted . ':')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Összesített, a Beállítások-felület számára közvetlenül
     * felhasználható állapot (a kör 20. pontja) — EGYETLEN híváson
     * belül fut le minden detektálás, hogy a végpont (ollama-status.php)
     * TTL-cache mögé tehesse (a kör 31. pontja: "Do not check
     * installation status on every page").
     *
     * @return array{installed:bool,binary_path:?string,version:?string,
     *   api_available:bool,configured_model:string,model_installed:bool}
     */
    public static function detectStatus(string $baseUrl, string $model, ?string $localAppData = null): array
    {
        $binaryPath = self::installedBinaryPath($localAppData);
        $apiVersion = self::detectApiVersion($baseUrl);
        $version = $apiVersion ?? ($binaryPath !== null ? self::detectCliVersion($binaryPath) : null);

        return [
            'installed' => $binaryPath !== null,
            'binary_path' => $binaryPath,
            'version' => $version,
            'api_available' => $apiVersion !== null,
            'configured_model' => $model,
            'model_installed' => $apiVersion !== null && self::isModelInstalled($baseUrl, $model),
        ];
    }

    private const STATUS_CACHE_TTL_SECONDS = 30;

    /**
     * self::detectStatus() rövid életű, gép-helyi cache mögött — UGYANAZ
     * a minta/indoklás, mint OllamaHealth (a kör 31. pontja: "Do not
     * check installation status on every page"). Kizárólag a
     * ollama-status.php végpont hívja.
     */
    public static function detectStatusCached(string $baseUrl, string $model, bool $forceRefresh = false, ?string $localAppData = null): array
    {
        $cacheFile = self::statusCacheFile($baseUrl, $model);
        if (!$forceRefresh) {
            $cached = self::readStatusCache($cacheFile);
            if ($cached !== null) {
                return $cached;
            }
        }
        $status = self::detectStatus($baseUrl, $model, $localAppData);
        self::writeStatusCache($cacheFile, $status);
        return $status;
    }

    private static function statusCacheFile(string $baseUrl, string $model): string
    {
        $dir = sys_get_temp_dir() . '/stockmanager-ollama-provision';
        @mkdir($dir, 0775, true);
        return $dir . '/' . hash('sha256', $baseUrl . '|' . $model) . '.json';
    }

    private static function readStatusCache(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['_cached_at_unix'], $data['status']) || !is_array($data['status'])) {
            return null;
        }
        if ((time() - (int) $data['_cached_at_unix']) > self::STATUS_CACHE_TTL_SECONDS) {
            return null;
        }
        return $data['status'];
    }

    private static function writeStatusCache(string $path, array $status): void
    {
        $payload = json_encode(['status' => $status, '_cached_at_unix' => time()], JSON_UNESCAPED_UNICODE);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $payload);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    // ------------------------------------------------------------------
    // Telepítés
    // ------------------------------------------------------------------

    /**
     * TISZTA függvény — a ténylegesen futtatandó parancssort építi fel,
     * mellékhatás/folyamatindítás NÉLKÜL. Külön tesztelhető (lásd
     * tests/OllamaProvisionerTest.php "installation command
     * construction" tesztje) attól, hogy ténylegesen lefut-e egy
     * folyamat.
     *
     * @return string[]
     */
    public static function buildInstallerCommand(string $exePath): array
    {
        return [$exePath, '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART'];
    }

    /**
     * A GitHub Releases API-ból a MINDENKORI legújabb "OllamaSetup.exe"
     * kiadás letöltése + SHA-256 ellenőrzése — SOSE futtatja a
     * letöltött fájlt (lásd self::runSilentInstaller() külön hívása).
     * $releaseApiUrl/$trustedUrlPrefix injektálható teszteléshez (lásd
     * a kör 26. pontja — SOSE a valódi github.com-ot hívjuk PHPUnit
     * alatt), production-ban mindig a valódi, hivatalos forrás.
     *
     * @return array{status:string,message?:string,path?:string,version?:string,size?:int}
     */
    public static function downloadAndVerifyInstaller(
        ?string $destDir = null,
        string $releaseApiUrl = self::GITHUB_LATEST_RELEASE_URL,
        string $trustedUrlPrefix = self::TRUSTED_ASSET_URL_PREFIX
    ): array {
        $destDir = $destDir ?? sys_get_temp_dir();

        try {
            $release = self::httpGetJson($releaseApiUrl, self::GITHUB_API_TIMEOUT_SECONDS, true);
        } catch (AiProviderException $e) {
            return ['status' => 'error', 'message' => 'Nem sikerült lekérdezni a hivatalos Ollama kiadási információt.'];
        }

        if (!is_array($release) || !isset($release['assets']) || !is_array($release['assets'])) {
            return ['status' => 'error', 'message' => 'A kiadási információ váratlan szerkezetű.'];
        }

        $asset = null;
        foreach ($release['assets'] as $a) {
            if (is_array($a) && ($a['name'] ?? '') === self::INSTALLER_ASSET_NAME) {
                $asset = $a;
                break;
            }
        }
        if ($asset === null) {
            return ['status' => 'error', 'message' => 'A legújabb kiadás nem tartalmaz Windows-telepítőt.'];
        }

        $downloadUrl = (string) ($asset['browser_download_url'] ?? '');
        $digest = (string) ($asset['digest'] ?? '');

        // Bizalmi kapu — a kör 16. pontja: a letöltési cím MINDIG a
        // hivatalos GitHub Releases előtaggal kell kezdődjön, MINDIG
        // HTTPS. Ha ez nem teljesül (pl. egy manipulált API-válasz),
        // a letöltés SOSE indul el. A HTTPS-kényszer kizárólag a
        // PRODUCTION alapértelmezett előtagra vonatkozik — teszteléskor
        // (lásd tests/OllamaProvisionerTest.php) a hívó SAJÁT, explicit
        // megbízott loopback stub-előtagot ad át, ami maga a bizalmi
        // határ, HTTPS nélkül is (helyi, kontrollált teszt-HTTP-szerver).
        if ($trustedUrlPrefix === self::TRUSTED_ASSET_URL_PREFIX && !str_starts_with($downloadUrl, 'https://')) {
            return ['status' => 'error', 'message' => 'A letöltési cím nem HTTPS — a telepítés megszakítva.'];
        }
        if (!str_starts_with($downloadUrl, $trustedUrlPrefix)) {
            return ['status' => 'error', 'message' => 'A letöltési cím nem a megbízható forrásból származik — a telepítés megszakítva.'];
        }
        if ($digest === '') {
            // Ha nincs ellenőrizhető checksum, NEM telepítünk vakon —
            // lásd a kör 16. pontja: "If no robust published checksum
            // exists, explicitly document the actual trust mechanism".
            return ['status' => 'error', 'message' => 'A kiadáshoz nem tartozik ellenőrizhető SHA-256 checksum — a telepítés megszakítva.'];
        }

        $destPath = rtrim($destDir, '\\/') . '/OllamaSetup_' . bin2hex(random_bytes(6)) . '.exe';
        $downloaded = self::downloadFile($downloadUrl, $destPath, self::DOWNLOAD_TIMEOUT_SECONDS);
        if (!$downloaded) {
            return ['status' => 'error', 'message' => 'A telepítő letöltése sikertelen.'];
        }

        $expected = strtolower(preg_replace('/^sha256:/i', '', $digest));
        $actual = hash_file('sha256', $destPath);
        if (!hash_equals($expected, (string) $actual)) {
            @unlink($destPath);
            return ['status' => 'error', 'message' => 'A letöltött telepítő SHA-256 ellenőrzése sikertelen — a fájl sérült vagy módosított lehet.'];
        }

        return [
            'status' => 'ok',
            'path' => $destPath,
            'version' => (string) ($release['tag_name'] ?? ''),
            'size' => (int) filesize($destPath),
        ];
    }

    /**
     * TETSZŐLEGES, MÁR biztonságosan összeállított parancssort futtat
     * (lásd buildInstallerCommand()), és bekorlátozott ideig várja a
     * kilépést. SOSE shell-interpretált — proc_open mindig tömb-alakú
     * argv-t kap. Teszteléshez (lásd tests/OllamaProvisionerTest.php)
     * a hívó biztonságos, ártalmatlan parancsokat (pl. a PHP CLI-t
     * magát) adhat át — ez a metódus SOSE tud különbséget tenni "valódi
     * telepítő" és "teszt-parancs" között, a hívó felelőssége, hogy
     * PHPUnit alatt sose adjon át valódi telepítőt (a kör 26. pontja).
     *
     * @param string[] $command
     * @return array{status:string,exit_code?:int,message?:string}
     */
    public static function runSilentInstaller(array $command, int $timeoutSeconds = self::INSTALL_TIMEOUT_SECONDS): array
    {
        if (!isset($command[0]) || !is_file($command[0])) {
            return ['status' => 'error', 'message' => 'A telepítőfájl nem található.'];
        }
        $result = self::runProcess($command, $timeoutSeconds);
        if (!$result['ok']) {
            return ['status' => 'error', 'message' => $result['timed_out'] ? 'A telepítés túllépte az időkorlátot.' : 'A telepítő futtatása sikertelen.'];
        }
        if ($result['exit_code'] !== 0) {
            return ['status' => 'error', 'message' => 'A telepítő hibakóddal tért vissza.', 'exit_code' => $result['exit_code']];
        }
        return ['status' => 'ok', 'exit_code' => 0];
    }

    /**
     * A teljes telepítési folyamat összekapcsolása — letöltés+ellenőrzés,
     * majd (csak sikeres ellenőrzés esetén) a MÁR letöltött, MÁR
     * ellenőrzött fájl csendes futtatása. Ez az egyetlen metódus, amit
     * a valódi ollama-install.php végpont ténylegesen hív — PHPUnit
     * ezt SOSE hívja végponttól-végpontig valódi paraméterekkel (lásd
     * a kör 26. pontja), a két al-lépést KÜLÖN, kontrollált
     * paraméterekkel teszteli.
     *
     * @return array{status:string,message?:string,version?:string}
     */
    public static function install(?string $destDir = null): array
    {
        $downloaded = self::downloadAndVerifyInstaller($destDir);
        if ($downloaded['status'] !== 'ok') {
            return $downloaded;
        }

        $ranResult = self::runSilentInstaller(self::buildInstallerCommand($downloaded['path']));
        @unlink($downloaded['path']);

        if ($ranResult['status'] !== 'ok') {
            return $ranResult;
        }

        return ['status' => 'ok', 'message' => 'Az Ollama sikeresen települt.', 'version' => $downloaded['version'] ?? null];
    }

    // ------------------------------------------------------------------
    // Indítás/ellenőrzés, modell-letöltés
    // ------------------------------------------------------------------

    /**
     * Ha az API már elérhető, nincs teendő. Ha nem, és a bináris
     * ismert, megpróbálja elindítani a tálca-alkalmazást (ami saját
     * maga indítja a szerver-folyamatot is, lásd ollama.iss [Run]
     * szakasza) — NEM várja meg szinkron módon a teljes indulást
     * (a hívó a MEGLÉVŐ OllamaHealth-hez hasonló, cache-elt
     * állapot-lekérdezéssel ellenőrizheti újra egy pillanat múlva).
     *
     * @return array{status:string,message:string}
     */
    public static function startIfNotRunning(string $baseUrl, ?string $localAppData = null): array
    {
        if (self::isApiAvailable($baseUrl)) {
            return ['status' => 'ok', 'message' => 'Az Ollama már fut.'];
        }
        $binaryPath = self::installedBinaryPath($localAppData);
        if ($binaryPath === null) {
            return ['status' => 'error', 'message' => 'Az Ollama nincs telepítve.'];
        }
        $appExe = dirname($binaryPath) . '\\ollama app.exe';
        $launchTarget = is_file($appExe) ? $appExe : $binaryPath;
        $launchArgs = $launchTarget === $binaryPath ? [$binaryPath, 'serve'] : [$launchTarget];

        $proc = @proc_open($launchArgs, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($proc === false) {
            return ['status' => 'error', 'message' => 'Az Ollama indítása sikertelen.'];
        }
        // Szándékosan NEM szinkron várakozás — a tálca-alkalmazás/szerver
        // indulása másodperceket vehet igénybe, ezt a hívó a MEGLÉVŐ
        // OllamaHealth-en keresztül, egy KÜLÖN kérésben ellenőrizheti.
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        return ['status' => 'ok', 'message' => 'Az Ollama indítása elindult, néhány másodperc múlva ellenőrizd újra az állapotot.'];
    }

    /**
     * POST /api/pull {"model":..., "stream": false} — a hivatalos,
     * nem-streamelt lekérdezési alak (lásd a kör 15. pontja kutatása),
     * a végleges válasz {"status": "success"} vagy egy hiba-JSON.
     * Szinkron, hosszú (akár több perces) HTTP-hívás — lásd a README
     * "Ismert korlátok" szakasza a streamelt progress-sáv hiányáról.
     *
     * @return array{status:string,message:string}
     */
    public static function pullModel(string $baseUrl, string $model, int $timeoutSeconds = self::PULL_TIMEOUT_SECONDS): array
    {
        if (!preg_match('/^[a-zA-Z0-9._:\/-]+$/', $model)) {
            return ['status' => 'error', 'message' => 'Érvénytelen modellnév.'];
        }
        try {
            $decoded = self::httpPostJson(rtrim($baseUrl, '/') . '/api/pull', ['model' => $model, 'stream' => false], $timeoutSeconds);
        } catch (AiProviderException $e) {
            return ['status' => 'error', 'message' => match ($e->kind) {
                'timeout' => 'A modell letöltése túllépte az időkorlátot.',
                default => 'Az Ollama nem érhető el a modell letöltéséhez.',
            }];
        }
        if (!is_array($decoded) || !isset($decoded['status'])) {
            return ['status' => 'error', 'message' => 'Az Ollama válasza váratlan szerkezetű.'];
        }
        if ($decoded['status'] !== 'success') {
            return ['status' => 'error', 'message' => 'A modell letöltése sikertelen: ' . mb_substr((string) $decoded['status'], 0, 200)];
        }
        return ['status' => 'ok', 'message' => 'A modell sikeresen letöltve.'];
    }

    // ------------------------------------------------------------------
    // Belső segédfüggvények — HTTP és folyamat-indítás
    // ------------------------------------------------------------------

    private static function httpGetJson(string $url, int $timeoutSeconds, bool $githubApi = false)
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($githubApi) {
            $headers[] = 'User-Agent: FountainTrade-OllamaProvisioner';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new AiProviderException('Kapcsolódási hiba.', $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable');
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new AiProviderException("HTTP hiba ($status).", 'http_error');
        }
        $decoded = json_decode((string) $response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('A válasz nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }

    private static function httpPostJson(string $url, array $body, int $timeoutSeconds)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new AiProviderException('Kapcsolódási hiba.', $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable');
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new AiProviderException("HTTP hiba ($status).", 'http_error');
        }
        $decoded = json_decode((string) $response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('A válasz nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }

    private static function downloadFile(string $url, string $destPath, int $timeoutSeconds): bool
    {
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        $fp = @fopen($destPath, 'wb');
        if ($fp === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $status >= 400) {
            @unlink($destPath);
            return false;
        }
        return true;
    }

    /**
     * @param string[] $command
     * @return array{ok:bool,exit_code:?int,stdout:string,stderr:string,timed_out:bool}
     */
    private static function runProcess(array $command, int $timeoutSeconds): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $descriptors, $pipes);
        if ($proc === false) {
            return ['ok' => false, 'exit_code' => null, 'stdout' => '', 'stderr' => '', 'timed_out' => false];
        }

        // SZÁNDÉKOSAN NEM olvassuk a pipe-okat a várakozási ciklus közben
        // — Windows-on a proc_open pipe-jai stream_set_blocking(false)
        // ELLENÉRE is blokkolva maradnak (ismert PHP/Windows-korlát),
        // tehát egy korai stream_get_contents() hívás a GYERMEKFOLYAMAT
        // teljes kilépéséig blokkolna, meghiúsítva magát az időkorlát-
        // ellenőrzést (élőben, ténylegesen megfigyelt hiba, nem elméleti
        // — lásd a kör 26. pontja "network timeout"/robusztussági
        // elvárása). Ehelyett KIZÁRÓLAG proc_get_status()-t figyeljük a
        // cikluson belül, a pipe-okat csak AZUTÁN olvassuk, hogy a
        // folyamat MÁR bizonyítottan leállt (nem időtúllépés esetén) —
        // ekkor a pipe-ok olvasása biztonságosan, azonnal visszatér.
        $start = microtime(true);
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($proc);
                break;
            }
            usleep(100_000);
        }

        if ($timedOut) {
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return ['ok' => false, 'exit_code' => null, 'stdout' => '', 'stderr' => '', 'timed_out' => true];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [
            'ok' => true,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => false,
        ];
    }
}
