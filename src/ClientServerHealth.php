<?php

declare(strict_types=1);

require_once __DIR__ . '/AppVersion.php';

/**
 * Fázis 2, Checkpoint 4 — a Kliens SAJÁT connectivity/health állapota a
 * konfigurált távoli Szerver felé. Ez SZÁNDÉKOSAN teljesen KÜLÖN a meglévő
 * HealthMonitor-tól (a Szerver 8 saját komponensének — backup/WooCommerce/
 * NAV/nyomtató/SMTP stb. — állapotát figyeli, azt itt NEM módosítjuk és NEM
 * használjuk fel) — ez itt egyetlen, más jellegű kérdésre válaszol: "el
 * tudja-e érni ÉS hitelesíteni tudja-e magát EZ a Kliens gép a Szerveren".
 *
 * A Kliens SOSE futtat közvetlen szerver-oldali health checket (nincs is
 * hozzáférése a Szerver saját komponenseihez) — kizárólag a meglévő,
 * hitelesítés NÉLKÜLI server-ping.php-t hívja (reachability + verzió), a
 * "authenticated" mező pedig a VALÓDI, üzleti forgalom (ClientProxy által
 * ténylegesen továbbított, HMAC-hitelesített kérések) tényleges kimeneteléből
 * származik (lásd recordRequestOutcome()) — NEM egy külön, szintetikus
 * hitelesített próba-hívásból. Ez pontosan megfelel a "ne fusson minden
 * API-kéréshez külön ping" elvárásnak: a ping csak TTL-lejáratkor fut,
 * az "authenticated" állapotot pedig a már amúgy is megtörténő forgalom
 * frissíti, ingyen.
 *
 * Az állapot egy host-local, ideiglenes JSON-fájlban él (ugyanaz az elv,
 * mint ClientNonceStore.php — a Kliensnek NINCS saját adatbázisa, ahova ezt
 * írhatná), a konfigurált server_url+client_id párra kulcsolva (ha ezek
 * megváltoznak — pl. egy kliens-titok cserét vagy szerver-cím módosítást
 * követően —, a régi cache-bejegyzés egyszerűen irreleváns marad, nem
 * kevert össze az újjal).
 */
final class ClientServerHealth
{
    private const TTL_SECONDS = 30;
    private const PING_TIMEOUT_SECONDS = 3;

    /**
     * @return array{server_reachable:bool,api_reachable:bool,authenticated:?bool,server_version:?string,compatible:?bool,last_success:?string,last_error:?string,checked_at:?string}
     */
    public static function check(array $clientConfig, bool $forceRefresh = false): array
    {
        $serverUrl = trim((string) ($clientConfig['server_url'] ?? ''));
        if ($serverUrl === '') {
            return self::emptyState('A Kliens nincs Szerver-címmel konfigurálva.');
        }

        $file = self::cacheFile($clientConfig);
        $cached = self::readCache($file);
        if (!$forceRefresh && $cached !== null && isset($cached['_checked_at_unix']) && (time() - (int) $cached['_checked_at_unix']) < self::TTL_SECONDS) {
            return self::publicView($cached);
        }

        return self::publicView(self::refresh($serverUrl, $file, $cached));
    }

    /**
     * A ClientProxy hívja MINDEN ténylegesen továbbított (HMAC-hitelesített)
     * üzleti kérés UTÁN — a "authenticated"/"last_success"/"last_error"
     * mezőt a VALÓDI forgalomból frissíti, a reachability/verzió/
     * kompatibilitás mezőket ÉRINTETLENÜL hagyva (azokat KIZÁRÓLAG a
     * server-ping.php-hívás frissítheti, lásd refresh()).
     */
    public static function recordRequestOutcome(array $clientConfig, bool $success, ?string $sanitizedError = null): void
    {
        $serverUrl = trim((string) ($clientConfig['server_url'] ?? ''));
        if ($serverUrl === '') {
            return;
        }
        $file = self::cacheFile($clientConfig);
        self::withLockedFile($file, function (array $state) use ($success, $sanitizedError): array {
            $state['authenticated'] = $success;
            if ($success) {
                $state['last_success'] = date('c');
                $state['last_error'] = null;
            } else {
                $state['last_error'] = $sanitizedError;
            }
            return $state;
        });
    }

    private static function refresh(string $serverUrl, string $file, ?array $previous): array
    {
        $state = $previous ?? self::defaultState();

        $ch = curl_init(rtrim($serverUrl, '/') . '/api/server-ping.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::PING_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::PING_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $curlErrno !== 0) {
            $state['server_reachable'] = false;
            $state['api_reachable'] = false;
            $state['server_version'] = null;
            $state['compatible'] = null;
            $state['last_error'] = 'A Szerver nem érhető el a hálózaton.';
            return self::writeCache($file, $state);
        }
        $state['server_reachable'] = true;

        $json = json_decode((string) $body, true);
        if ($httpStatus !== 200 || !is_array($json) || empty($json['success']) || ($json['app'] ?? null) !== AppVersion::PRODUCT || !is_string($json['version'] ?? null)) {
            $state['api_reachable'] = false;
            $state['server_version'] = null;
            $state['compatible'] = null;
            $state['last_error'] = 'A Szerver válasza érvénytelen — lehet, hogy nem FountainTrade fut ezen a címen.';
            return self::writeCache($file, $state);
        }
        $state['api_reachable'] = true;
        $serverVersion = $json['version'];
        $state['server_version'] = $serverVersion;

        if (!AppVersion::isValidSemver($serverVersion)) {
            $state['compatible'] = false;
            $state['last_error'] = 'A Szerver verziószáma nem értelmezhető.';
            return self::writeCache($file, $state);
        }
        $state['compatible'] = AppVersion::isMajorMinorCompatible(AppVersion::CURRENT, $serverVersion);
        if ($state['compatible']) {
            $state['last_success'] = date('c');
            $state['last_error'] = null;
        } else {
            $state['last_error'] = 'A Kliens (' . AppVersion::CURRENT . ') és a Szerver (' . $serverVersion . ') verziója nem kompatibilis.';
        }

        return self::writeCache($file, $state);
    }

    private static function defaultState(): array
    {
        return [
            'server_reachable' => false,
            'api_reachable' => false,
            'authenticated' => null,
            'server_version' => null,
            'compatible' => null,
            'last_success' => null,
            'last_error' => null,
        ];
    }

    private static function emptyState(string $error): array
    {
        $state = self::defaultState();
        $state['last_error'] = $error;
        return $state;
    }

    /** @return array<string,mixed> a nyilvánosan visszaadható mezők (a belső "_checked_at_unix" könyvelési mező nélkül, helyette ISO "checked_at"-ként). */
    private static function publicView(array $state): array
    {
        $view = self::defaultState();
        foreach (array_keys($view) as $key) {
            $view[$key] = $state[$key] ?? $view[$key];
        }
        $view['checked_at'] = isset($state['_checked_at_unix']) ? date('c', (int) $state['_checked_at_unix']) : null;
        return $view;
    }

    private static function writeCache(string $file, array $state): array
    {
        $state['_checked_at_unix'] = time();
        return self::withLockedFile($file, static fn (array $current): array => array_merge($current, $state));
    }

    private static function withLockedFile(string $file, callable $mutator): array
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return $mutator(self::defaultState());
        }
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $current = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($current)) {
            $current = self::defaultState();
        }
        $updated = $mutator($current);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($updated));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $updated;
    }

    private static function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function cacheFile(array $clientConfig): string
    {
        $key = hash('sha256', ($clientConfig['server_url'] ?? '') . '|' . ($clientConfig['client_id'] ?? ''));
        $dir = sys_get_temp_dir() . '/stockmanager-client-health';
        return $dir . '/' . $key . '.json';
    }
}
