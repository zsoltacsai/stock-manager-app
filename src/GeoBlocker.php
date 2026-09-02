<?php

/**
 * IP-cím és ország alapú hozzáférés-korlátozás (Beállítások → Biztonság).
 * IPv4-et és IPv6-ot egyaránt kezel. Az ország-felismerés egy külső,
 * kulcs nélküli lekérdezéssel (ip-api.com) történik, az eredményt pedig
 * helyben, a data/geoip-cache.json fájlban gyorsítótárazza, hogy ugyanarra
 * a látogató IP-re ne kelljen minden kérésnél újra lekérdezni.
 */
class GeoBlocker
{
    private const CACHE_TTL_SECONDS = 30 * 24 * 60 * 60;
    private const CACHE_MAX_ENTRIES = 5000;

    public static function resolveClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        // Csak akkor hihetünk a proxy által beállított fejlécnek, ha a
        // közvetlen TCP-kapcsolat magáról a gépről jön (127.0.0.1 / ::1) —
        // ez a dokumentált telepítési forma (Nginx ugyanazon a hoszton, mint
        // a PHP-FPM). Bármilyen más privát tartomány (Docker híd-hálózat,
        // VPN, tágabb LAN) esetén a kliens saját maga is beállíthatná ezt a
        // fejlécet a korlátozás megkerülésére, ezért ott nem bízunk benne.
        if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
            return $remote;
        }

        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            if (!empty($_SERVER[$header])) {
                $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * A jelenlegi látogató IP-címe és országa, a be/ki kapcsolt állapottól
     * függetlenül — ezt a Beállítások felület használja, hogy a tulajdonos
     * bekapcsolás előtt lássa, melyik országot/IP-t érdemes felvennie a
     * listára (különben véletlenül kizárhatná saját magát).
     */
    public static function currentInfo(?string $ip = null): array
    {
        $ip = $ip ?? self::resolveClientIp();
        if (!self::isPublicIp($ip)) {
            return ['ip' => $ip, 'country' => null];
        }
        return ['ip' => $ip, 'country' => self::lookupCountry($ip)];
    }

    public static function check(array $settings, ?string $ip = null): array
    {
        $ip = $ip ?? self::resolveClientIp();
        $result = ['allowed' => true, 'ip' => $ip, 'country' => null, 'reason' => 'kikapcsolva'];

        if (empty($settings['geo_block_enabled'])) {
            return $result;
        }

        if (!self::isPublicIp($ip)) {
            $result['reason'] = 'helyi/privát cím, nem korlátozott';
            return $result;
        }

        if (self::ipInAllowList($ip, (string) ($settings['geo_block_allow_ips'] ?? ''))) {
            $result['reason'] = 'engedélyezési listán szereplő IP';
            return $result;
        }

        $allowedCountries = array_filter(array_map(
            'trim',
            explode(',', strtoupper((string) ($settings['geo_block_countries'] ?? '')))
        ));
        if (empty($allowedCountries)) {
            $result['reason'] = 'nincs ország megadva a listán, mindenki átengedve';
            return $result;
        }

        $country = self::lookupCountry($ip);
        $result['country'] = $country;

        if ($country === null) {
            $result['reason'] = 'ország-lekérdezés sikertelen, óvatosságból átengedve';
            return $result;
        }

        if (in_array($country, $allowedCountries, true)) {
            $result['reason'] = 'engedélyezett ország: ' . $country;
            return $result;
        }

        $result['allowed'] = false;
        $result['reason'] = 'nem engedélyezett ország: ' . $country;
        return $result;
    }

    public static function enforce(array $settings): void
    {
        $result = self::check($settings);
        if ($result['allowed']) {
            return;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8">'
            . '<title>Hozzáférés megtagadva</title><style>'
            . 'body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'
            . 'text-align:center;padding:20px;}'
            . '.box{max-width:420px;}h1{font-size:22px;margin-bottom:8px;}p{color:#94a3b8;}'
            . '</style></head><body><div class="box">'
            . '<h1>Hozzáférés megtagadva</h1>'
            . '<p>Ez a rendszer az Ön jelenlegi IP-címéről/országából nincs engedélyezve.</p>'
            . '</div></body></html>';
        exit;
    }

    private static function ipInAllowList(string $ip, string $listCsv): bool
    {
        foreach (explode(',', $listCsv) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && self::ipInCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            $subnetBin = @inet_pton($cidr);
            return $subnetBin !== false && $ipBin === $subnetBin;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maskBits = (int) $maskBits;
        $maxBits = strlen($ipBin) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);
        return (substr($ipBin, $fullBytes, 1) & $mask) === (substr($subnetBin, $fullBytes, 1) & $mask);
    }

    private static function lookupCountry(string $ip): ?string
    {
        $cachePath = __DIR__ . '/../data/geoip-cache.json';
        $handle = fopen($cachePath, 'c+');
        if ($handle === false) {
            return self::fetchCountryFromApi($ip);
        }

        flock($handle, LOCK_EX);

        $raw = stream_get_contents($handle);
        $cache = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }

        if (isset($cache[$ip]) && (time() - ($cache[$ip]['ts'] ?? 0)) < self::CACHE_TTL_SECONDS) {
            $country = $cache[$ip]['country'] ?: null;
            flock($handle, LOCK_UN);
            fclose($handle);
            return $country;
        }

        $country = self::fetchCountryFromApi($ip);
        $cache[$ip] = ['country' => $country, 'ts' => time()];

        if (count($cache) > self::CACHE_MAX_ENTRIES) {
            uasort($cache, fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
            $cache = array_slice($cache, -2000, null, true);
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($cache, JSON_UNESCAPED_UNICODE));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $country;
    }

    private static function fetchCountryFromApi(string $ip): ?string
    {
        $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $failed = curl_errno($ch) !== 0;
        curl_close($ch);

        if ($failed || $response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }
        return $data['countryCode'] ?? null;
    }
}
