<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientHmac.php';
require_once __DIR__ . '/ClientNonceStore.php';
require_once __DIR__ . '/AppVersion.php';

/**
 * Egy proxyzott (ClientProxy-n keresztül érkező) kérés gépszintű
 * hitelesítése a Szerveren — a Fázis 2 tervdokumentum §5/§7 szakaszában
 * rögzített, PONTOSAN ebben a sorrendben elvégzett 8 lépés:
 *
 *   1) X-Client-Id jelen van?
 *   2) regisztrált kliens megtalálható?
 *   3) aktív?
 *   4) nincs visszavonva?
 *   5) időbélyeg érvényes (±120s), nonce formailag érvényes?
 *   6) aláírás helyes (constant-time összehasonlítás)?
 *   7) nonce még nem használt? (claim KIZÁRÓLAG érvényes aláírás után)
 *   8) csak ezután folytatódhat a kérés tényleges feldolgozása.
 *
 * Kifelé (a hívó felé, ami végül a böngészőig jut) SOSE ad eltérő,
 * részletes hibát aszerint, hogy MELYIK lépés bukott el — egy konzervatív,
 * egységes hitelesítési hiba elegendő (lásd `authenticate()` docblockja).
 * A tényleges bukási ok ("reason") KIZÁRÓLAG szerver-oldali naplózásra
 * (system_events technical_detail) kerülhet, titok/aláírás/nonce
 * plaintext nélkül.
 */
final class ClientAuthenticator
{
    public const TIMESTAMP_WINDOW_SECONDS = 120;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{ok: bool, registeredClient: ?array, reason: ?string}
     */
    public function authenticate(string $method, string $pathAndQuery, string $body): array
    {
        // 1) X-Client-Id jelen van?
        $clientId = trim((string) ($_SERVER['HTTP_X_CLIENT_ID'] ?? ''));
        if ($clientId === '') {
            return $this->fail('missing_client_id');
        }

        // 2) regisztrált kliens megtalálható?
        $client = $this->db->findRegisteredClientByClientId($clientId);
        if ($client === null) {
            return $this->fail('unknown_client');
        }

        // 3) aktív?
        if (empty($client['is_active'])) {
            return $this->fail('inactive_client');
        }

        // 4) nincs visszavonva?
        if (!empty($client['revoked_at'])) {
            return $this->fail('revoked_client');
        }

        // 5) időbélyeg érvényes?
        $timestamp = (string) ($_SERVER['HTTP_X_CLIENT_TIMESTAMP'] ?? '');
        if ($timestamp === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TIMESTAMP_WINDOW_SECONDS) {
            return $this->fail('invalid_timestamp');
        }

        $nonce = (string) ($_SERVER['HTTP_X_CLIENT_NONCE'] ?? '');
        if ($nonce === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $nonce)) {
            return $this->fail('invalid_nonce_format');
        }

        // 6) aláírás helyes? — a Szerveren a MÁR levezetett secret_hash a
        // kulcs (lásd ClientHmac.php docblockja), sose a nyers titok. A
        // nonce az aláírt kanonikus sztring része, ezért az aláírás-
        // ellenőrzés a nonce-claim ELŐTT futhat.
        $signature = (string) ($_SERVER['HTTP_X_CLIENT_SIGNATURE'] ?? '');
        $canonical = ClientHmac::canonicalString($method, $pathAndQuery, $timestamp, $nonce, $body);
        if (!ClientHmac::verify($canonical, $signature, (string) $client['secret_hash'])) {
            return $this->fail('invalid_signature');
        }

        // 7) nonce még nem használt? — atomikus claim, lásd ClientNonceStore.
        // Csak hitelesen aláírt kérés foglalhat nonce-ot: egy érvénytelen
        // aláírású kérés így sose növelheti a nonce-tárat.
        if (!ClientNonceStore::claim($clientId, $nonce, self::TIMESTAMP_WINDOW_SECONDS)) {
            return $this->fail('reused_nonce');
        }

        // 8) minden ellenőrzés sikeres — a hívó innentől folytathatja.
        // Az X-Client-App-Version fejléc TISZTÁN diagnosztikai adat (lásd
        // Database::touchClientLastSeen() docblokkja) — NEM része az
        // aláírt kanonikus sztringnek, tehát a hitelesítési döntésre
        // semmilyen hatással nincs; egy hiányzó/érvénytelen SemVer-t
        // egyszerűen figyelmen kívül hagyunk.
        $reportedVersion = (string) ($_SERVER['HTTP_X_CLIENT_APP_VERSION'] ?? '');
        $this->db->touchClientLastSeen(
            (int) $client['id'],
            AppVersion::isValidSemver($reportedVersion) ? $reportedVersion : null
        );
        return ['ok' => true, 'registeredClient' => $client, 'reason' => null];
    }

    /**
     * Egységes, konzervatív visszautasítás — a $reason KIZÁRÓLAG a hívó
     * (_bootstrap.php) szerver-oldali naplózásának szól, a böngésző felé
     * adott válasz sose tartalmazza. Lásd a fájl tetején lévő docblockot.
     */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'registeredClient' => null, 'reason' => $reason];
    }
}
