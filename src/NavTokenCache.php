<?php

/**
 * A NAV tokenExchange által kiadott, rövid élettartamú (jellemzően kb.
 * 5 perc, lásd tokenValidityFrom/tokenValidityTo) exchange token
 * cache-elése — Phase 5A-ban SZÁNDÉKOSAN nem volt ilyen (izolált,
 * egyszeri teszt-hívás), a queue worker viszont sok, egymást gyorsan
 * követő manageInvoice/queryTransactionStatus hívást indíthat, aminél
 * felesleges (és a NAV oldalán is indokolatlan terhelés) minden egyes
 * híváshoz külön tokenExchange-et kérni.
 *
 * A cache egyetlen, git alá NEM tartozó JSON fájlban él (lásd
 * .gitignore) — NEM a Settings/data/settings.json-ban, mert a
 * settings.php API a teljes settings.json-t visszaadja a kliensnek
 * (maszkolt kivétellel csak az explicit felsorolt titkos mezőknél) —
 * egy itt tárolt nyers token emiatt kiszivárogna egy sima GET
 * /api/settings.php hívással.
 *
 * A több workerre vonatkozó verseny-elkerülést egy sima fájl-zár
 * (flock LOCK_EX) oldja meg: a kritikus szakasz (ellenőrzés + esetleges
 * frissítés) a zár birtokában fut, így két egyidejűleg induló worker
 * közül a második a zár felszabadulása után MÁR a frissen cache-elt
 * tokent látja, nem indít felesleges második tokenExchange-et. Ez nem
 * egy elosztott/több-szerveres megoldás — erre ennek a projektnek
 * (egyetlen kis üzlet, egyetlen szerver) nincs szüksége, "ne
 * optimalizálj előre".
 */
class NavTokenCache
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Visszaadja az érvényes, cache-elt tokent, vagy — ha nincs
     * ilyen/lejárt — a $fetchNewToken callable-lel (jellemzően
     * NavClient::tokenExchange()) kér egy újat, és cache-eli sikeres
     * válasz esetén. A $fetchNewToken pontosan a NavClient::tokenExchange()
     * visszatérési alakját adja vissza — ezt a metódus VÁLTOZATLANUL adja
     * tovább hívónak, csak a "van-e még érvényes cache-elt token"
     * döntést és a cache-írást egészíti ki.
     *
     * @param callable(): array{success:bool,token:?string,valid_from:?string,valid_to:?string,http_status:?int,nav_error_code:?string,error:?string} $fetchNewToken
     * @return array{success:bool,token:?string,valid_from:?string,valid_to:?string,http_status:?int,nav_error_code:?string,error:?string}
     */
    public function getOrRefresh(callable $fetchNewToken): array
    {
        $fp = @fopen($this->path, 'c+');
        if ($fp === false) {
            // Nem tudjuk cache-elni (pl. jogosultsági hiba a data/
            // könyvtáron) — ez nem hiúsíthatja meg magát a NAV-hívást,
            // csak annyit jelent, hogy minden hívás új tokent kér.
            return $fetchNewToken();
        }

        flock($fp, LOCK_EX);
        try {
            $raw = stream_get_contents($fp);
            $cached = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;

            // 30 másodperc biztonsági ráhagyás a lejárat előtt, hogy egy
            // épp-még-érvényes, de a hívás alatt lejáró tokennel ne
            // induljon el a manageInvoice.
            if (
                is_array($cached) && !empty($cached['token']) && !empty($cached['valid_to'])
                && strtotime((string) $cached['valid_to']) > time() + 30
            ) {
                return [
                    'success' => true,
                    'token' => $cached['token'],
                    'valid_from' => $cached['valid_from'] ?? null,
                    'valid_to' => $cached['valid_to'],
                    'http_status' => null,
                    'nav_error_code' => null,
                    'error' => null,
                ];
            }

            $result = $fetchNewToken();
            if ($result['success']) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode([
                    'token' => $result['token'],
                    'valid_from' => $result['valid_from'],
                    'valid_to' => $result['valid_to'],
                ], JSON_UNESCAPED_UNICODE));
                fflush($fp);
            }

            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Explicit érvénytelenítés — ha egy manageInvoice/queryTransactionStatus
     * hívás INVALID_EXCHANGE_TOKEN-t (vagy hasonló, tokenre visszavezethető)
     * hibát kap, a cache-elt tokent el kell dobni, nehogy a KÖVETKEZŐ hívás
     * is ugyanazzal a — nyilvánvalóan érvénytelen — tokennel próbálkozzon.
     */
    public function invalidate(): void
    {
        @unlink($this->path);
    }
}
