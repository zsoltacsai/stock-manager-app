<?php

declare(strict_types=1);

/**
 * A teljes Kliens-módú (`node_role === 'client'`) API-kódútvonal —
 * `webroot/api/_bootstrap.php` erre adja át az irányítást minden `/api/*.php`
 * kérésnél, MIELŐTT bármelyik tényleges végpont-fájl vagy `src/*.php` üzleti
 * logika lefutna. Sem itt, sem ott nincs `if ($clientMode)` elágazás — ez az
 * EGYETLEN hely, ahol a döntés megtörténik (lásd a Fázis 2 tervdokumentum §2
 * Target architecture / §16 Proposed file structure szakaszát).
 *
 * FONTOS, EBBEN A KÖRBEN SZÁNDÉKOSAN HIÁNYZIK (később készül el, külön
 * lépésben): a kliens-gép HMAC-alapú hitelesítése (`X-Client-Id`/
 * `X-Client-Signature`/`X-Client-Timestamp`/`X-Client-Nonce`), a dolgozói
 * munkamenet-híd (`X-Client-Session-Id`), és a CSRF-híd
 * (`X-Client-Csrf-Token`). Ez az osztály jelenleg TISZTÁN a szállítási
 * réteget bizonyítja: kérés/válasz test-átlátszóság, fejléc-kezelés,
 * bináris válaszok, hálózati hiba kezelése — a fenti fejlécek hozzáadása a
 * `buildOutboundHeaders()`-hez egy kis, additív változtatás lesz, nem
 * architektúra-váltás.
 *
 * ISMERT KORLÁT ebben a körben: a `php://input` nem tartalmazza a
 * `multipart/form-data` kéréstörzset (PHP ezt már feldolgozta `$_FILES`/
 * `$_POST`-ba, mire idáig futunk) — fájlfeltöltéses végpontok (pl.
 * `logo-upload.php`, `product-image-upload.php`) proxyzása emiatt még NEM
 * működik helyesen. Ez a Fázis 2 terv §16 "Proposed files" szakaszában még
 * nem volt explicit módon megnevezve — külön implementációs lépést igényel
 * (multipart újra-összeállítás vagy `CURLFile`-alapú újraküldés), nem ennek
 * a körnek a része.
 */
final class ClientProxy
{
    /** RFC 7230 §6.1 — csak az egyetlen hop-ra (nem a teljes kérés-lánc egészére) érvényes fejlécek, sosem relézve. */
    private const HOP_BY_HOP_HEADERS = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailers', 'transfer-encoding', 'upgrade',
    ];

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 20;

    /** @var array{server_url?: string, client_id?: string, client_secret?: string} */
    private array $clientConfig;

    public function __construct(array $clientConfig)
    {
        $this->clientConfig = $clientConfig;
    }

    /**
     * A teljes kérést továbbítja a Szerverre, és a választ (státusz + szűrt
     * fejlécek + test-átlátszó törzs) közvetlenül a hívó felé küldi ki.
     * SOSE tér vissza rendes esetben — a hívó (`_bootstrap.php`) ezután
     * `exit`-tel zárja a kérést.
     */
    public function forward(): void
    {
        $serverUrl = rtrim((string) ($this->clientConfig['server_url'] ?? ''), '/');
        if ($serverUrl === '') {
            $this->respondWithError(500, 'A Kliens nincs beállítva (hiányzó szerver-cím). Ellenőrizd a telepítést.');
            return;
        }

        $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName === '' || !str_ends_with($scriptName, '.php')) {
            $this->respondWithError(400, 'Érvénytelen kérés.');
            return;
        }
        $queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $targetUrl = $serverUrl . '/api/' . $scriptName . ($queryString !== '' ? '?' . $queryString : '');

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = file_get_contents('php://input') ?: '';

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            $this->respondWithNetworkError('curl_init sikertelen.');
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $this->buildOutboundHeaders(),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->respondWithNetworkError($error);
            return;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        http_response_code($status);
        $this->relayResponseHeaders($rawHeaders);
        echo $responseBody;
    }

    /**
     * A böngésző→Kliens kérés fejléceiből építi fel a Kliens→Szerver
     * kimenő fejléclistát — test-átlátszó, de NEM vak: a `Host`-ot sosem
     * másolja (a curl a valódi célhoz állítja be magától), a hop-by-hop
     * fejléceket kihagyja, a böngésző Kliens-saját (`SameSite=Strict`)
     * session-sütijét pedig SOSE küldi tovább a Szervernek — az a
     * böngésző↔Kliens hop titka, nem a Kliens↔Szerver hopé.
     *
     * @return string[]
     */
    private function buildOutboundHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace('_', '-', substr($key, 5));
            $lower = strtolower($name);
            // A 'content-type'-ot itt SZÁNDÉKOSAN kihagyjuk, hiába HTTP_-
            // prefixű a kulcs — a PHP beépített szervere ($_SERVER-ben
            // MIND 'HTTP_CONTENT_TYPE'-ot, MIND 'CONTENT_TYPE'-ot beteszi)
            // enélkül két külön Content-Type fejléc-sort küldene ki,
            // amit a fogadó oldal "application/json, application/json"
            // alakban összefésülve látna — valódi, élesen megfigyelt hiba
            // volt, nem elméleti. Lentebb, a CONTENT_TYPE ágban egyszer,
            // egyértelműen kerül be.
            if ($lower === 'host' || $lower === 'cookie' || $lower === 'content-type' || in_array($lower, self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }
            $headers[] = $this->headerCaseFromServerKey($name) . ': ' . $value;
        }

        // A Content-Type NEM 'HTTP_'-prefixű $_SERVER-kulcsként érkezik
        // (klasszikus CGI-konvenció) — külön kell átemelni. A
        // Content-Length-et SZÁNDÉKOSAN nem másoljuk: a curl a ténylegesen
        // elküldött CURLOPT_POSTFIELDS hosszából maga számolja ki a
        // helyeset — egy kézzel átmásolt, esetleg eltérő érték csendben
        // csonkított/hibás kéréstörzset eredményezhetne.
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    /** A $_SERVER HTTP_FOO_BAR alakját emberi Foo-Bar fejléc-névvé alakítja vissza (csak megjelenítési célra). */
    private function headerCaseFromServerKey(string $upperDashed): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $upperDashed)
        ));
    }

    /**
     * A Szerver válaszfejléceit adja tovább a böngészőnek — test-átlátszó a
     * válaszra nézve is, de itt is tudatosan szűrve: a hop-by-hop
     * fejléceket kihagyja, a `Content-Length`-et PHP saját maga számolja
     * újra a ténylegesen kiküldött test alapján, és — a legfontosabb —
     * a Szerver saját `Set-Cookie` fejlécét SOSE engedi át. A böngésző
     * kizárólag a Kliens saját eredetéhez tartozó sütiket láthatja; egy
     * Szerver-eredetű süti visszaküldése értelmezhetetlen (és veszélyes)
     * eredet-keveredés lenne.
     */
    private function relayResponseHeaders(string $rawHeaders): void
    {
        foreach ($this->filterResponseHeaderLines($rawHeaders) as $line) {
            header($line, false);
        }
    }

    /**
     * Tiszta, mellékhatás-mentes szűrő-logika — külön a fenti header()-hívó
     * wrappertől, hogy egységtesztben közvetlenül, valódi HTTP-válasz
     * nélkül is ellenőrizhető legyen a Set-Cookie/hop-by-hop/Content-Length
     * kiszűrése.
     *
     * @return string[] a ténylegesen kiküldendő "Név: érték" sorok
     */
    private function filterResponseHeaderLines(string $rawHeaders): array
    {
        // Egy 100 Continue köztes válasz esetén CURLOPT_HEADER=true több
        // fejléc-blokkot is visszaad, üres sorral elválasztva — csak az
        // UTOLSÓ blokk tartozik a ténylegesen kiszolgált végső válaszhoz.
        $blocks = preg_split("/\r\n\r\n|\n\n/", trim($rawHeaders)) ?: [];
        $lastBlock = (string) end($blocks);

        $result = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $lastBlock)) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue; // a HTTP/1.1 200 OK státusz-sor is ide esne — szándékosan kimarad, http_response_code() már beállította
            }
            [$name, $value] = explode(':', $line, 2);
            $lower = strtolower(trim($name));
            if ($lower === 'set-cookie' || $lower === 'content-length' || in_array($lower, self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }
            $result[] = trim($name) . ': ' . trim($value);
        }
        return $result;
    }

    private function respondWithNetworkError(string $detail): void
    {
        error_log('[fountaintrade] ClientProxy: szerver nem elérhető: ' . $detail);
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Szerver nem elérhető.',
            'server_unreachable' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function respondWithError(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
