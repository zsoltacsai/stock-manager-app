<?php

declare(strict_types=1);

/**
 * Kliens HMAC-kérések nonce-alapú replay-védelme — SZÁNDÉKOSAN KÜLÖN
 * mechanizmus az Auth::checkRateLimit()-től, még ha ugyanazt a fájl-alapú,
 * flock()-védett read-modify-write mintát is követi (host-local store,
 * lásd a Fázis 2 tervdokumentum §5 szakaszát): a rate-limit egy SZÁMLÁLÓ
 * (hányszor próbálkoztak), a nonce-tár egy HALMAZ (mely egyedi kérés-
 * azonosítókat láttunk már) — eltérő jelentés, eltérő élettartam-logika,
 * összekeverésük két, különböző célú védelmet gyengítene egyetlen,
 * félreérthető állapottá.
 *
 * Egy (client_id, nonce) pár csak addig releváns, amíg a HMAC-aláírás
 * saját időablaka (lásd ClientAuthenticator::TIMESTAMP_WINDOW_SECONDS)
 * egyáltalán elfogadná — egy ennél régebbi nonce-ot MAGA az időbélyeg-
 * ellenőrzés úgyis elutasítana, tehát nem kell tovább megjegyezni. Ez
 * korlátozza a tárolt adat méretét: lejárt bejegyzések minden híváskor
 * kigyomlálódnak, a fájl sose nőhet korlátlanul.
 */
final class ClientNonceStore
{
    private static function storeFile(string $clientId): string
    {
        $dir = sys_get_temp_dir() . '/stockmanager-client-nonces';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientId) . '.json';
    }

    /**
     * Atomikusan ellenőrzi ÉS azonnal felhasználtként jelöli a nonce-ot —
     * egyetlen zárolt lépésben, hogy két majdnem egyidejű, ugyanazzal a
     * nonce-szal érkező kérés közül SOSE mehessen át mindkettő (a
     * kódbázis többi atomikus claim-jével — pl. Database::openCashSession()
     * — azonos "check-then-act ugyanabban a lépésben" elv).
     *
     * @return bool true, ha ez a nonce ÚJ (a kérés folytatódhat) — false,
     *              ha már látott bejegyzés (a kérést el kell utasítani).
     */
    public static function claim(string $clientId, string $nonce, int $windowSeconds): bool
    {
        if ($clientId === '' || $nonce === '') {
            return false;
        }

        $file = self::storeFile($clientId);
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            // Fail-closed: ha a fájl valamiért nem nyitható/zárolható,
            // inkább utasítsuk el a kérést, mint hogy replay-védelem
            // nélkül engedjük át.
            return false;
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $entries = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($entries)) {
            $entries = [];
        }

        $now = time();
        $entries = array_filter($entries, static fn ($expiresAt) => is_int($expiresAt) && $expiresAt > $now);

        $claimed = false;
        if (!isset($entries[$nonce])) {
            $entries[$nonce] = $now + max(1, $windowSeconds);
            $claimed = true;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($entries));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $claimed;
    }
}
