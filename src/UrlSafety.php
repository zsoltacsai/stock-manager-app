<?php

/**
 * Központi SSRF-védelem minden szerver-oldalról induló, kliens/beállítás
 * által befolyásolt kimenő HTTP-hívásra (WooCommerce API, alacsony-készlet
 * webhook, kapcsolat-teszt). Két helyen kell alkalmazni:
 *   1) MENTÉSKOR (settings.php) — hogy egy nyilvánvalóan belső/nem-publikus
 *      URL be se kerülhessen elmentve a beállításokba.
 *   2) HASZNÁLATKOR (WooCommerceClient, LowStockNotifier) — védelmi
 *      mélységként, arra az esetre, ha a settings.json-t valaki közvetlenül
 *      (a mentési validáció megkerülésével) szerkesztette, vagy egy régebbi,
 *      még nem validált mentésből származik az érték.
 */
final class UrlSafety
{
    /**
     * @return array{0: bool, 1: string, 2: ?string} [biztonságos-e, hibaüzenet, feloldott IP (pinneléshez)]
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return [false, 'Érvénytelen URL.', null];
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return [false, 'Csak http(s) URL engedélyezett.', null];
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return [false, 'Az URL nem tartalmazhat beágyazott hitelesítő adatot.', null];
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return [false, 'Belső/loopback cím nem engedélyezett.', null];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = array_values(array_filter(array_merge(
                array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            )));
        }
        if (empty($ips)) {
            return [false, 'A megadott host nem oldható fel.', null];
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [false, 'Belső/nem nyilvános IP-cím nem engedélyezett (' . $ip . ').', null];
            }
        }

        return [true, '', $ips[0]];
    }

    public static function isSafe(string $url): bool
    {
        return self::check($url)[0];
    }

    /**
     * DNS-rebinding elleni védelemmel ellátott curl-beállítások: a hívó
     * MÁR ellenőrizte (self::check()) a host-hoz tartozó IP-t — ezt a
     * VALIDÁLT IP-t "pinneljük" a kapcsolathoz CURLOPT_RESOLVE-val, hogy a
     * tényleges TCP-kapcsolat ne oldja fel újra a DNS-t (ami a validáció
     * és a tényleges kapcsolódás közötti pillanatban már egy belső címre
     * mutathatna — "DNS rebinding"). A Host fejléc/SNI emiatt is helyesen
     * a hostname-re mutat, csak a ténylegesen felkeresett IP van rögzítve.
     * Emellett explicit letiltja az átirányítás-követést, hogy egy eleinte
     * biztonságos URL válasza se irányíthasson át belső célra.
     */
    public static function pinnedCurlOptions(string $url, string $resolvedIp): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($host !== '' && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $bracketed = str_contains($resolvedIp, ':') ? "[$resolvedIp]" : $resolvedIp;
            $opts[CURLOPT_RESOLVE] = ["$host:$port:$bracketed"];
        }
        return $opts;
    }
}
