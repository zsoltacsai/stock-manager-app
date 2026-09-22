<?php

declare(strict_types=1);

/**
 * A Kliens→Szerver HMAC-hitelesítés kanonikus aláírás-építése és
 * ellenőrzése — UGYANEZ a logika fut mind a Kliens oldalán (aláíráskor,
 * `ClientProxy`), mind a Szerver oldalán (ellenőrzéskor,
 * `ClientAuthenticator`), hogy a két fél garantáltan ugyanazt számolja.
 *
 * Kanonikus sztring, pontosan ebben a sorrendben, "\n" (LF, SOSE CRLF)
 * elválasztóval, UTF-8 byte-okon számolva:
 *
 *   METHOD
 *   /path?query
 *   TIMESTAMP
 *   NONCE
 *   BODY_SHA256 (hex, kisbetűs)
 *
 * Az aláírás: HMAC-SHA256(kanonikus_sztring, signing_key), HEX kódolással
 * — ez az EGYETLEN, végig következetesen használt kódolás ebben a teljes
 * mechanizmusban (sose base64).
 *
 * FONTOS KRIPTOGRÁFIAI PONT — miért van `deriveSigningKey()`, nem
 * közvetlenül a nyers `client_secret` a HMAC-kulcs: a Szerver a
 * `registered_clients.secret_hash` oszlopban SOSE a nyers titkot tárolja
 * (lásd Database::registerClient()) — de a HMAC-ellenőrzés MATEMATIKAILAG
 * megköveteli, hogy az ellenőrző fél ugyanazt a kulcsot tudja
 * újraszámolni, amivel az aláírás készült (ellentétben egy jelszó-
 * ellenőrzéssel, ahol egy `password_verify()`-szerű egyirányú hash
 * elegendő). A megoldás: mindkét fél NEM a nyers titkot, hanem annak
 * SHA-256 leképezését ("signing key") használja a HMAC kulcsaként — a
 * Kliens az ismert nyers titokból számolja ki induláskor, a Szerver pedig
 * PONTOSAN ugyanezt az értéket tárolja `secret_hash`-ként (tehát a
 * "_hash" elnevezés szó szerint pontos: ez a titok SHA-256 hash-e, amit a
 * Szerver közvetlenül kulcsként használ fel, a nyers titok ismerete
 * nélkül). A nyers `client_secret` így SOSE kerül a Szerver adatbázisába
 * — pontosan a követelmény szerint —, miközben a HMAC-ellenőrzés
 * kriptográfiailag helyesen működik.
 */
final class ClientHmac
{
    /**
     * A kanonikus sztring "path+query" részét UGYANEZZEL a logikával
     * kell számolnia a Kliensnek (aláíráskor, a SAJÁT $_SERVER-éből, még
     * a Szerverre való továbbítás előtt) ÉS a Szervernek (ellenőrzéskor,
     * a SAJÁT, a ClientProxy által megcélzott $_SERVER-éből) — mivel
     * mindkettő ugyanabból a `/api/<scriptname>[?query]` alakból indul ki,
     * a két oldal eredménye garantáltan megegyezik, drift veszélye nélkül.
     */
    public static function pathAndQueryFromServerSuperglobal(): string
    {
        $script = '/api/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return $script . ($query !== '' ? '?' . $query : '');
    }

    public static function canonicalString(string $method, string $pathAndQuery, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            $pathAndQuery,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * Fázis 2, Checkpoint 4 — multipart/form-data kérések body-hash
     * helyettesítője. A `php://input` MULTIPART kérésnél SEM a Kliens, SEM
     * a Szerver oldalán nem olvasható (PHP mindkét oldalon MÁR feldolgozta
     * $_POST/$_FILES-ba, mielőtt a mi kódunk lefutna — ez a Kliens<->Szerver
     * proxy-hopra IS ugyanúgy igaz, nem csak a böngésző<->Kliens hopra).
     * Emiatt a nyers bájtok byte-pontos hash-elése helyett mindkét oldal
     * UGYANEBBŐL a $_POST/$_FILES-ból számol egy determinisztikus,
     * kulcs-sorrendtől független emésztvényt: a szöveges mezők
     * (kulcs=>érték) és a feltöltött fájlok TARTALMÁNAK (nem az ideiglenes
     * fájl ÚTVONALÁNAK — az a Kliensen és a Szerveren garantáltan más)
     * SHA-256-ja alapján. A visszaadott string a MEGLÉVŐ canonicalString()
     * "$body" paramétereként adható át változatlanul (az ott lévő
     * hash('sha256', $body) hívás ETTŐL FÜGGETLENÜL, változatlanul fut le
     * — a canonicalString() FORMÁTUMA/szerződése nem változik, csak azt,
     * "mit" ad neki a hívó multipart esetén).
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    public static function multipartBodyDigest(array $post, array $files): string
    {
        $normalizedPost = [];
        foreach ($post as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $normalizedPost[(string) $key] = (string) $value;
            }
        }
        ksort($normalizedPost);

        $normalizedFiles = [];
        foreach ($files as $key => $file) {
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $normalizedFiles[(string) $key] = $tmpName !== '' ? (hash_file('sha256', $tmpName) ?: '') : '';
        }
        ksort($normalizedFiles);

        return (string) json_encode(['post' => $normalizedPost, 'files' => $normalizedFiles], JSON_UNESCAPED_UNICODE);
    }

    /** A nyers client_secret-ből a HMAC-kulcsként (és a Szerveren `secret_hash`-ként) használt, levezetett értéket számolja — hex, 64 karakter. */
    public static function deriveSigningKey(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }

    /** @param string $signingKey a MÁR levezetett kulcs (deriveSigningKey() kimenete), SOSE a nyers titok. */
    public static function sign(string $canonical, string $signingKey): string
    {
        return hash_hmac('sha256', $canonical, $signingKey);
    }

    /** @param string $signingKey a MÁR levezetett kulcs — a Szerveren ez pontosan a tárolt secret_hash értéke. */
    public static function verify(string $canonical, string $signature, string $signingKey): bool
    {
        if ($signature === '') {
            return false;
        }
        return hash_equals(self::sign($canonical, $signingKey), $signature);
    }
}
