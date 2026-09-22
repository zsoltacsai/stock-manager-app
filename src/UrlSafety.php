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
        return self::checkInternal($url, allowPrivateRange: false);
    }

    public static function isSafe(string $url): bool
    {
        return self::check($url)[0];
    }

    /**
     * A FountainTrade Kliens `server_url` beállításának ellenőrzésére — NEM
     * a fenti check()/isSafe() SSRF-védelmére. A két eset szándékosan
     * ELLENTÉTES elbírálást igényel ugyanarra a kérdésre ("privát/belső
     * IP-cím megengedett-e?"): a check() olyan, KLIENS/BEÁLLÍTÁS által
     * befolyásolt kimenő hívásokat véd (WooCommerce API, webhook), amik
     * SOSE mutathatnak a szerver saját belső hálózatára — ott egy privát
     * cél maga a támadás jele. A server_url viszont EGY VALÓDI FountainTrade
     * Szerver LAN-címe — jellemzően egy privát tartománybeli IP
     * (pl. 192.168.1.10) —, tehát ott a privát tartomány NEM elutasítandó,
     * hanem éppen a várt, normális eset (lásd a Fázis 2 tervdokumentum
     * hálózati modell szakaszát: a Szerver 0.0.0.0-n figyel, a Kliens ezt a
     * LAN-címet hívja).
     *
     * A közös szerkezeti ellenőrzéseket (érvényes séma/host, nincs
     * beágyazott hitelesítő adat, a host ténylegesen feloldható) ugyanaz a
     * belső metódus végzi, mint a check()-nél — nem egy párhuzamos, külön
     * validátor —, csak a privát-tartomány-elutasítás lépését hagyja ki.
     *
     * @return array{0: bool, 1: string, 2: ?string} [érvényes-e, hibaüzenet, feloldott IP]
     */
    public static function checkServerUrl(string $url): array
    {
        return self::checkInternal($url, allowPrivateRange: true);
    }

    private static function checkInternal(string $url, bool $allowPrivateRange): array
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
        if (!$allowPrivateRange && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            return [false, 'Belső/loopback cím nem engedélyezett.', null];
        }
        // A 'localhost' nem egy tényleges, élő DNS-en feloldható A-rekord —
        // hagyományosan a hosts-fájl oldja fel, amit a dns_get_record()
        // NEM kérdez le (az ténylegesen DNS-lekérdezést indít, nem OS-
        // szintű névfeloldást). server_url esetén (allowPrivateRange=true)
        // ugyanúgy explicit loopback-ként kezeljük, mint a '127.0.0.1'
        // literált — enélkül egy ugyanazon a gépen tesztelt Kliens/Szerver
        // pár 'http://localhost:PORT' server_url-je hamisan "nem oldható
        // fel" hibát adna.
        if ($allowPrivateRange && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            $ips = ['127.0.0.1'];
        } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
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

        if (!$allowPrivateRange) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return [false, 'Belső/nem nyilvános IP-cím nem engedélyezett (' . $ip . ').', null];
                }
            }
        }

        return [true, '', $ips[0]];
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
