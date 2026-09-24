<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientHmac.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ClientServerHealth.php';
require_once __DIR__ . '/AppVersion.php';

/**
 * A teljes Kliens-módú (`node_role === 'client'`) API-kódútvonal —
 * `webroot/api/_bootstrap.php` erre adja át az irányítást minden `/api/*.php`
 * kérésnél, MIELŐTT bármelyik tényleges végpont-fájl vagy `src/*.php` üzleti
 * logika lefutna. Sem itt, sem ott nincs `if ($clientMode)` elágazás — ez az
 * EGYETLEN hely, ahol a döntés megtörténik (lásd a Fázis 2 tervdokumentum §2
 * Target architecture / §16 Proposed file structure szakaszát).
 *
 * Minden kimenő kérést HMAC-aláír (`X-Client-Id`/`X-Client-Signature`/
 * `X-Client-Timestamp`/`X-Client-Nonce` — lásd `ClientHmac.php`), és — ha a
 * Kliens saját helyi session-jében már van — továbbítja a dolgozói
 * munkamenet-hidat (`X-Client-Session-Id`/`X-Client-Csrf-Token`) is. A
 * Szerver egy sikeres `staff-login.php`-válaszra ÚJ ilyen fejlécpárt ad
 * vissza (lásd `ClientSessionBridge`) — ezt a Kliens itt, a válasz-
 * fejlécekből olvassa ki és menti el a SAJÁT helyi session-jébe, mielőtt a
 * böngésző felé továbbadná a választ (a böngésző sose látja ezeket a
 * fejléceket, sem a Szerver saját `Set-Cookie`-ját — lásd
 * `filterResponseHeaderLines()`).
 *
 * ISMERT KORLÁT ebben a körben: a `php://input` nem tartalmazza a
 * `multipart/form-data` kéréstörzset (PHP ezt már feldolgozta `$_FILES`/
 * `$_POST`-ba, mire idáig futunk) — fájlfeltöltéses végpontok (pl.
 * `logo-upload.php`, `product-image-upload.php`) proxyzása emiatt még NEM
 * működik helyesen. Külön implementációs lépést igényel (multipart újra-
 * összeállítás vagy `CURLFile`-alapú újraküldés), nem ennek a körnek a része.
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

    /**
     * Fázis 9, kör 8. pontja — a MEGLÉVŐ (fenti, teljesen pufferelt)
     * proxy-útvonal mellé, KIZÁRÓLAG ehhez a whitelisthez tartozó
     * végponthoz, egy streamelés-tudatos relé-útvonal (lásd
     * forwardStreaming()). SOSE a böngésző dönti el, hogy egy kérés
     * streamel-e — ez egy szerver-oldali, fájlnév alapú fehérlista,
     * ugyanaz a garancia, mint amit maga a Szerver oldali
     * `webroot/api/ai-agent-stream.php` is ad ("no arbitrary model/endpoint
     * selection from browser").
     */
    private const STREAMING_SCRIPTS = ['ai-agent-stream.php'];

    /** Egy streamelt AI-válasz (több agent-hívás, hosszabb helyi modell) jóval túlnyúlhat a normál 20s-en. */
    private const STREAMING_TIMEOUT_SECONDS = 300;

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

        // Fázis 2, Checkpoint 4 — verzió-kompatibilitás/health kapu, MINDEN
        // továbbítás előtt. TTL-cache mögött áll (lásd ClientServerHealth —
        // NEM ping minden egyes kérésnél), és PONTOSAN a design 8. pontjának
        // megfelelő három, egymástól élesen elkülönített kimenetet ad: a
        // Szerver ténylegesen elérhetetlen (a MEGLÉVŐ "Szerver nem elérhető"
        // válasz, változatlanul — lásd respondWithNetworkError()) VS a
        // Szerver elérhető, de a verziója NEM kompatibilis (ÚJ,
        // version_mismatch válasz) VS minden rendben, folytatódik a normál
        // HMAC-proxy. Egyik ág SEM állít be hamisan "compatible" állapotot
        // egy ismeretlen/elérhetetlen esetben.
        $health = ClientServerHealth::check($this->clientConfig);
        if (!$health['server_reachable'] || !$health['api_reachable']) {
            $this->respondWithNetworkError('health-check: ' . ($health['last_error'] ?? 'ismeretlen ok'));
            return;
        }
        if ($health['compatible'] === false) {
            $this->respondWithVersionMismatch();
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

        // Fázis 2, Checkpoint 4 — multipart/form-data (fájlfeltöltés)
        // támogatás. A php://input NEM olvasható multipart kéréseknél — PHP
        // a beérkező kéréstörzset MÁR feldolgozta $_FILES/$_POST-ba, mire ez
        // a kód lefut (lásd a PHP dokumentáció php://input szakasza). Ezért
        // egy multipart kérésnél NEM a (üres) php://input-ot továbbítjuk,
        // hanem $_POST/$_FILES-ból ÚJRAÉPÍTJÜK a kéréstörzset, SAJÁT, itt
        // generált boundary-vel (lásd buildMultipartBody()).
        //
        // A HMAC body-hash-hez viszont NEM a nyers (újraépített) bájtokat
        // adjuk — ÉLŐ TESZTELÉSSEL FELFEDEZETT PROBLÉMA: a Szerver oldalán
        // is UGYANEZ a php://input-korlát érvényes a BEÉRKEZŐ (proxyzott)
        // multipart kérésre, tehát a Szerver SOSE tudná visszaellenőrizni
        // egy nyers bájtok feletti hash-t (a Szerver saját php://input-ja
        // is üres lenne). Ehelyett $_POST/$_FILES TARTALMÁBÓL számolt,
        // mindkét oldalon FÜGGETLENÜL, azonosan reprodukálható emésztvényt
        // (lásd ClientHmac::multipartBodyDigest()) adunk a canonicalString()-nek
        // — ez NEM változtatja meg a canonicalString() SAJÁT szerződését
        // (továbbra is hash('sha256', $body) fut le rajta), csak azt, hogy
        // multipart esetén MIT kap "$body"-ként.
        $multipartContentTypeOverride = null;
        $bodyForSigning = null;
        $inboundContentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if ($method === 'POST' && str_starts_with(strtolower($inboundContentType), 'multipart/form-data')) {
            [$body, $multipartContentTypeOverride] = $this->buildMultipartBody();
            $bodyForSigning = ClientHmac::multipartBodyDigest($_POST, $_FILES);
        } else {
            $body = file_get_contents('php://input') ?: '';
        }

        $headers = $this->buildOutboundHeaders($method, $bodyForSigning ?? $body, $multipartContentTypeOverride);

        if (in_array($scriptName, self::STREAMING_SCRIPTS, true)) {
            $this->forwardStreaming($targetUrl, $method, $body, $headers);
            return;
        }

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
            CURLOPT_HTTPHEADER => $headers,
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

        // Fázis 2, Checkpoint 4 — a "authenticated" health-mező a VALÓDI
        // forgalom kimeneteléből frissül (lásd ClientServerHealth docblokkja
        // — ez NEM egy külön, szintetikus próba-hívás). Egy 401 válasz,
        // aminek a törzse pontosan a ClientAuthenticator egységes hibaüzenete
        // (lásd _bootstrap.php/ClientAuthenticator.php), a gépszintű
        // HMAC-hitelesítés tényleges elutasítását jelzi — MINDEN más válasz
        // (2xx, 4xx üzleti hiba, akár egy staff-login.php-s "hibás PIN" 401
        // is, ami NEM a gép-szintű rétegből jön) azt bizonyítja, hogy a
        // Kliens gép-identitása ÉRVÉNYES volt, csak az ÜZLETI kérés végződött
        // másképp.
        $isMachineAuthRejection = $status === 401 && str_contains($responseBody, 'Hitelesítés sikertelen.');
        ClientServerHealth::recordRequestOutcome(
            $this->clientConfig,
            !$isMachineAuthRejection,
            $isMachineAuthRejection ? 'A Szerver elutasította a Kliens gép-szintű hitelesítését.' : null
        );

        http_response_code($status);
        $this->relayResponseHeaders($rawHeaders);
        echo $responseBody;
    }

    /**
     * Fázis 9, kör 8. pontja — streamelés-tudatos relé-útvonal, KIZÁRÓLAG
     * a `self::STREAMING_SCRIPTS` fehérlistán szereplő végpontokhoz (ma
     * egyetlen ilyen van: `ai-agent-stream.php`). A fenti `forward()`
     * `CURLOPT_RETURNTRANSFER => true`-ja a TELJES szerver-választ
     * memóriába pufferelné, mielőtt bármit visszaküldene — ez SSE-nél
     * elfogadhatatlan (a böngésző csak a válasz VÉGÉN kapná meg az összes
     * eseményt, a teljes "élő" progresszió elveszne). Itt ehelyett
     * `CURLOPT_HEADERFUNCTION`/`CURLOPT_WRITEFUNCTION` relézi a Szerver
     * fejléceit/törzsét DARABONKÉNT, ahogy megérkeznek — ugyanaz a minta,
     * mint amit a 3 Provider (`LocalProvider`/`AnthropicProvider`/
     * `OpenAiProvider`) `executeStreamingRequest()` segédfüggvénye is
     * használ a saját (Szerver↔LLM-provider) hopjához.
     *
     * A fejléc-szűrés (`Set-Cookie`/hop-by-hop/session-híd fejlécek
     * kihagyása) SZÁNDÉKOSAN ugyanazt a listát alkalmazza, mint
     * `filterResponseHeaderLines()`/`captureSessionBridgeHeaders()` — nem
     * egy párhuzamos, eltérő szabályrendszer, csak soronkénti (nem egy
     * összegyűjtött string feletti) alkalmazása.
     *
     * A gép-szintű hitelesítés elutasításának (401 + a
     * ClientAuthenticator egységes hibaüzenete) felismerése "best effort":
     * mivel egy IGAZI SSE-válasz sosem 401-gyel kezdődik (a Szerver
     * `require_admin()`-je MÉG a `text/event-stream` fejléc kiküldése
     * ELŐTT fut le — lásd `webroot/api/ai-agent-stream.php` teteje), egy
     * 401-es válasz törzse mindig egy rövid, EGYETLEN darabban megérkező
     * JSON — ezért elég csak az ELSŐ törzs-darabot megvizsgálni, mielőtt
     * bármit kiküldenénk (lásd `$firstChunkBuffer` lent).
     *
     * @param string[] $headers
     */
    private function forwardStreaming(string $targetUrl, string $method, string $body, array $headers): void
    {
        $ch = curl_init($targetUrl);
        if ($ch === false) {
            $this->respondWithNetworkError('curl_init sikertelen.');
            return;
        }

        $statusCode = 200;
        $filteredHeaderLines = [];
        $sessionBridge = ['session_id' => null, 'csrf_token' => null, 'cleared' => false];

        $headerFn = function ($curlHandle, string $headerLine) use (&$statusCode, &$filteredHeaderLines, &$sessionBridge): int {
            $trimmed = trim($headerLine);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
                // Új fejléc-blokk kezdődik (pl. egy 100 Continue köztes válasz után a
                // tényleges válasz) — a korábban gyűjtött sorokat eldobjuk, csak az
                // UTOLSÓ blokk számít, ugyanúgy, mint filterResponseHeaderLines()-ban.
                $statusCode = (int) $m[1];
                $filteredHeaderLines = [];
                return strlen($headerLine);
            }
            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                return strlen($headerLine);
            }
            [$name, $value] = explode(':', $trimmed, 2);
            $lower = strtolower(trim($name));
            switch ($lower) {
                case 'x-client-session-id':
                    $sessionBridge['session_id'] = trim($value);
                    return strlen($headerLine);
                case 'x-client-csrf-token':
                    $sessionBridge['csrf_token'] = trim($value);
                    return strlen($headerLine);
                case 'x-client-session-cleared':
                    $sessionBridge['cleared'] = trim($value) === '1';
                    return strlen($headerLine);
            }
            if ($lower === 'set-cookie' || $lower === 'content-length' || in_array($lower, self::HOP_BY_HOP_HEADERS, true)) {
                return strlen($headerLine);
            }
            $filteredHeaderLines[] = trim($name) . ': ' . trim($value);
            return strlen($headerLine);
        };

        $headersFlushed = false;
        $firstChunkBuffer = '';
        $isMachineAuthRejection = false;
        $flushHeadersOnce = function () use (&$headersFlushed, &$statusCode, &$filteredHeaderLines, &$sessionBridge): void {
            if ($headersFlushed) {
                return;
            }
            $headersFlushed = true;
            if ($sessionBridge['session_id'] !== null && $sessionBridge['csrf_token'] !== null) {
                Auth::ensureSessionStarted();
                $_SESSION['ft_client_session_id'] = $sessionBridge['session_id'];
                $_SESSION['ft_client_csrf_token'] = $sessionBridge['csrf_token'];
            }
            if ($sessionBridge['cleared']) {
                Auth::ensureSessionStarted();
                unset($_SESSION['ft_client_session_id'], $_SESSION['ft_client_csrf_token']);
            }
            http_response_code($statusCode);
            foreach ($filteredHeaderLines as $line) {
                header($line, false);
            }
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        };

        $writeFn = function ($curlHandle, string $chunk) use (&$headersFlushed, &$firstChunkBuffer, &$isMachineAuthRejection, &$statusCode, $flushHeadersOnce): int {
            // Az ELSŐ darabot (max ~8 KB-ig gyűjtve, egy rövid hibaválasz
            // sosem nagyobb ennél) pufferbe vesszük, hogy a gép-szintű
            // 401-elutasítást felismerhessük, MIELŐTT bármit kiküldenénk —
            // utána a normál streamelt esetben (státusz 200) minden
            // további darab azonnal, pufferelés nélkül megy tovább.
            if (!$headersFlushed && $statusCode === 401 && strlen($firstChunkBuffer) < 8192) {
                $firstChunkBuffer .= $chunk;
                if (str_contains($firstChunkBuffer, 'Hitelesítés sikertelen.')) {
                    $isMachineAuthRejection = true;
                }
                if (strlen($firstChunkBuffer) < 8192 && !str_ends_with($chunk, "\n")) {
                    // Még várhatunk egy következő darabra (curl gyakran egyetlen
                    // híváskor adja az egész kis JSON-t, de biztos, ami biztos).
                    return strlen($chunk);
                }
                $flushHeadersOnce();
                echo $firstChunkBuffer;
                @flush();
                return strlen($chunk);
            }

            $flushHeadersOnce();
            echo $chunk;
            @flush();

            // Fázis 9, kör 24. pontja — megszakítás-biztonság: ha a böngésző
            // lezárta a kapcsolatot (pl. a UI "Mégse" gombja AbortController-
            // rel), NE olvassuk tovább a Szerver streamjét a végtelenségig —
            // 0 visszaadása a curl-t azonnali megszakításra kényszeríti,
            // ugyanaz a minta, mint a 3 Provider executeStreamingRequest()-je.
            if (connection_aborted()) {
                return 0;
            }
            return strlen($chunk);
        };

        set_time_limit(self::STREAMING_TIMEOUT_SECONDS + 30);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_WRITEFUNCTION => $writeFn,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::STREAMING_TIMEOUT_SECONDS,
        ]);
        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($ok === false && !$headersFlushed) {
            // A kapcsolat MÉG a válasz-fejlécek előtt szakadt meg — ugyanúgy
            // kezelhető, mint a nem-streamelt forward() hibaága.
            $this->respondWithNetworkError($curlError);
            return;
        }
        // Ha $headersFlushed már igaz volt, a böngésző már kapott valamennyi
        // választ (pl. maga szakította meg, lásd connection_aborted() fent) —
        // ilyenkor MÁR nem küldhetünk új hibaválaszt, csak a health-jelet
        // rögzítjük alább.

        if (!$headersFlushed && strlen($firstChunkBuffer) > 0) {
            // A stream a "várunk egy következő darabra" ágban ért véget
            // (a Szerver lezárta a kapcsolatot, mielőtt elértük a 8 KB-os
            // határt vagy egy sorvéget) — a pufferelt (rövid, biztosan
            // hiba-) választ még mindig ki kell küldeni.
            $flushHeadersOnce();
            echo $firstChunkBuffer;
            @flush();
        }

        ClientServerHealth::recordRequestOutcome(
            $this->clientConfig,
            $curlErrno === 0 && !$isMachineAuthRejection,
            $isMachineAuthRejection
                ? 'A Szerver elutasította a Kliens gép-szintű hitelesítését.'
                : ($curlErrno !== 0 ? 'streaming: ' . $curlError : null)
        );
    }

    /**
     * A Kliens saját helyi session-jéből olvassa ki a korábban (egy
     * sikeres proxyzott staff-login.php-válaszból) elmentett dolgozói
     * munkamenet-hidat, ha van. `Auth::ensureSessionStarted()` UGYANAZT a
     * session-cookie-konfigurációt (SameSite=Strict stb.) garantálja, mint
     * a direkt forgalom — ez a böngésző↔Kliens hop SAJÁT session-je,
     * SOSE kerül tovább a Kliens↔Szerver hopra (lásd buildOutboundHeaders()
     * Cookie-kihagyása).
     *
     * @return array{client_session_id: string, client_csrf_token: string}
     */
    private function localClientSession(): array
    {
        Auth::ensureSessionStarted();
        return [
            'client_session_id' => (string) ($_SESSION['ft_client_session_id'] ?? ''),
            'client_csrf_token' => (string) ($_SESSION['ft_client_csrf_token'] ?? ''),
        ];
    }

    /**
     * Fázis 2, Checkpoint 4 — multipart/form-data kéréstörzs ÚJRAÉPÍTÉSE
     * $_POST/$_FILES-ból, SAJÁT, frissen generált boundary-vel. Erre azért
     * van szükség (és NEM elég a meglévő, `php://input`-ot egyszerűen
     * továbbító kódútvonal), mert PHP a multipart kéréstörzset MÁR
     * feldolgozta $_POST/$_FILES-ba, MIRE ez a kód lefut — a `php://input`
     * ilyenkor üres (ez dokumentált PHP-viselkedés, nem hiba). A törzset
     * KÉZZEL, string-ként építjük fel (NEM egy CURLFile-tömböt adunk át a
     * curl-nek, ami a SAJÁT boundary-jét generálná) — ennek a lényege, hogy
     * a HMAC-aláírás body-hash-e (lásd forward()) PONTOSAN a ténylegesen
     * elküldött bájtok felett számolhasson, még a küldés ELŐTT ismert
     * módon. Csak EGYSZERŰ, egy-fájlos mezőket kezel (logo-upload.php,
     * product-image-upload.php, print-logo-upload.php, backup-restore.php,
     * import-preview.php — az ÖSSZES jelenlegi multipart végpont pontosan
     * ilyen, lásd a Fázis 2 Checkpoint 4 elemzését), NEM tömbös
     * ("files[]") mezőket — ha egy jövőbeli végpont ilyet igényelne, ide
     * kell bővíteni.
     *
     * @return array{0: string, 1: string} [törzs, "multipart/form-data; boundary=..." Content-Type]
     */
    private function buildMultipartBody(): array
    {
        $boundary = '----FountainTradeClientProxy' . bin2hex(random_bytes(16));
        $parts = '';

        foreach ($_POST as $name => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue; // tömbös mezőt (pl. "tags[]") ez a kör szándékosan nem támogat
            }
            $parts .= "--$boundary\r\n"
                . 'Content-Disposition: form-data; name="' . str_replace('"', '\\"', (string) $name) . "\"\r\n\r\n"
                . $value . "\r\n";
        }

        foreach ($_FILES as $name => $file) {
            if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                continue; // hiányzó/hibás fájl — a Szerver-oldali végpont a hiányzó $_FILES-kulcsból ugyanúgy "nincs fájl"-t lát
            }
            if (!is_uploaded_file((string) $file['tmp_name'])) {
                continue; // védelmi mélység — sose olvassunk ki tetszőleges fájlrendszer-útvonalat
            }
            $content = file_get_contents((string) $file['tmp_name']);
            if ($content === false) {
                continue;
            }
            $originalName = str_replace('"', '\\"', (string) ($file['name'] ?? 'file'));
            $mime = (string) ($file['type'] ?: 'application/octet-stream');
            $parts .= "--$boundary\r\n"
                . 'Content-Disposition: form-data; name="' . str_replace('"', '\\"', (string) $name) . '"; filename="' . $originalName . "\"\r\n"
                . "Content-Type: $mime\r\n\r\n"
                . $content . "\r\n";
        }

        $parts .= "--$boundary--\r\n";

        return [$parts, "multipart/form-data; boundary=$boundary"];
    }

    /**
     * A böngésző→Kliens kérés fejléceiből építi fel a Kliens→Szerver
     * kimenő fejléclistát — test-átlátszó, de NEM vak: a `Host`-ot sosem
     * másolja (a curl a valódi célhoz állítja be magától), a hop-by-hop
     * fejléceket kihagyja, a böngésző Kliens-saját (`SameSite=Strict`)
     * session-sütijét pedig SOSE küldi tovább a Szervernek — az a
     * böngésző↔Kliens hop titka, nem a Kliens↔Szerver hopé.
     *
     * A test-átlátszóság mellett HOZZÁTESZI a gépszintű HMAC-hitelesítés
     * fejléceit (§ClientHmac.php), és — ha van elmentve — a dolgozói
     * munkamenet-hidat is.
     *
     * @param ?string $contentTypeOverride Fázis 2, Checkpoint 4 — multipart
     *        kérésnél a saját, ÚJRAÉPÍTETT törzshöz tartozó, ÚJ boundary-t
     *        tartalmazó Content-Type (lásd buildMultipartBody()) — ilyenkor
     *        ez váltja fel a böngésző EREDETI Content-Type-ját (aminek a
     *        boundary-je a mi újraépített törzsünkhöz már nem illik).
     * @return string[]
     */
    private function buildOutboundHeaders(string $method, string $body, ?string $contentTypeOverride = null): array
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
            //
            // A 'content-length' KIHAGYÁSA (Fázis 2 Checkpoint 4 — élő
            // teszteléssel felfedezett hiba javítása): a PHP beépített
            // szervere UGYANÚGY megduplázza ezt is ('HTTP_CONTENT_LENGTH'
            // ÉS 'CONTENT_LENGTH' — lásd fent a content-type analóg esetét).
            // Eddig ez REJTVE maradt, mert minden eddigi kérésnél a
            // továbbított törzs bájt-pontosan MEGEGYEZETT a beérkezővel
            // (php://input változtatás nélkül tovább), így egy átmásolt
            // RÉGI Content-Length véletlenül helyes volt. A multipart
            // ÚJRAÉPÍTETT törzse (lásd buildMultipartBody()) viszont MÁS
            // hosszú, mint az eredeti — egy átmásolt, elavult
            // Content-Length itt már ténylegesen ELTÉRŐ (kisebb) értéket
            // adott volna, mint a ténylegesen kiküldött bájtok száma, amit
            // a fogadó PHP beépített szervere "Malformed HTTP request"-ként
            // utasított el (a kliens szemszögéből "Empty reply from
            // server"-ként jelentkezett). A curl MINDIG a ténylegesen
            // átadott CURLOPT_POSTFIELDS hosszából számol helyes
            // Content-Length-et — sose egy átmásolt fejlécből.
            if ($lower === 'host' || $lower === 'cookie' || $lower === 'content-type' || $lower === 'content-length' || in_array($lower, self::HOP_BY_HOP_HEADERS, true)) {
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
        if ($contentTypeOverride !== null) {
            $headers[] = 'Content-Type: ' . $contentTypeOverride;
        } elseif (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
        }

        // Gépszintű HMAC-hitelesítés — minden kimenő kérésen, kivétel
        // nélkül (lásd ClientAuthenticator a Szerveren, ami ezt a 4
        // fejlécet olvassa).
        $clientId = (string) ($this->clientConfig['client_id'] ?? '');
        $rawSecret = (string) ($this->clientConfig['client_secret'] ?? '');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $pathAndQuery = ClientHmac::pathAndQueryFromServerSuperglobal();
        $canonical = ClientHmac::canonicalString($method, $pathAndQuery, $timestamp, $nonce, $body);
        $signingKey = ClientHmac::deriveSigningKey($rawSecret);
        $signature = ClientHmac::sign($canonical, $signingKey);

        $headers[] = 'X-Client-Id: ' . $clientId;
        $headers[] = 'X-Client-Timestamp: ' . $timestamp;
        $headers[] = 'X-Client-Nonce: ' . $nonce;
        $headers[] = 'X-Client-Signature: ' . $signature;
        // Fázis 2, Checkpoint 4 — TISZTÁN diagnosztikai adat (lásd
        // Database::touchClientLastSeen() docblokkja), NEM része az aláírt
        // kanonikus sztringnek — a Szerver admin "Kliensek" oldalán
        // megjeleníthesse, melyik verziójú Kliens jelentkezett utoljára.
        $headers[] = 'X-Client-App-Version: ' . AppVersion::CURRENT;

        // Dolgozói munkamenet-híd — csak akkor, ha a Kliens saját helyi
        // session-jében már van (egy korábbi sikeres staff-login.php
        // válaszból elmentve, lásd forward() válasz-feldolgozása lentebb).
        $localSession = $this->localClientSession();
        if ($localSession['client_session_id'] !== '') {
            $headers[] = 'X-Client-Session-Id: ' . $localSession['client_session_id'];
        }
        if ($localSession['client_csrf_token'] !== '') {
            $headers[] = 'X-Client-Csrf-Token: ' . $localSession['client_csrf_token'];
        }

        // A curl ~1 KB feletti kéréstörzsnél automatikusan hozzáadna egy
        // "Expect: 100-continue" fejlécet — élő teszteléssel felfedezett
        // hiba: a PHP beépített fejlesztői szervere (SEM a Kliens, SEM a
        // Szerver oldalán) ezt nem kezeli helyesen, "Malformed HTTP
        // request"-et eredményezve (jellemzően a nagyobb multipart
        // fájlfeltöltéseknél éri el ezt a méretet, lásd a Fázis 2
        // Checkpoint 4 multipart-támogatása). Az explicit üres 'Expect:'
        // fejléc ezt kikapcsolja — ártalmatlan a kisebb kéréseknél is.
        $headers[] = 'Expect:';

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
        $this->captureSessionBridgeHeaders($rawHeaders);
        foreach ($this->filterResponseHeaderLines($rawHeaders) as $line) {
            header($line, false);
        }
    }

    /**
     * Egy sikeres proxyzott `staff-login.php`-válasz a Szerver oldalán
     * (lásd `ClientSessionBridge::establishStaffSession()`) az újonnan
     * létrehozott dolgozói munkamenet azonosítóját/CSRF-tokenjét
     * VÁLASZFEJLÉCBEN adja vissza — itt olvassuk ki, és mentjük el a
     * Kliens SAJÁT helyi session-jébe, hogy a KÖVETKEZŐ kéréseknél
     * `buildOutboundHeaders()` továbbküldhesse. `staff-logout.php` egy
     * `X-Client-Session-Cleared` fejléccel jelzi a törlést. Ezek a
     * fejlécek SOSE jutnak el a böngészőig — lásd filterResponseHeaderLines().
     */
    private function captureSessionBridgeHeaders(string $rawHeaders): void
    {
        $blocks = preg_split("/\r\n\r\n|\n\n/", trim($rawHeaders)) ?: [];
        $lastBlock = (string) end($blocks);

        $sessionId = null;
        $csrfToken = null;
        $cleared = false;
        foreach (explode("\n", str_replace("\r\n", "\n", $lastBlock)) as $line) {
            $line = trim($line);
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            switch (strtolower(trim($name))) {
                case 'x-client-session-id':
                    $sessionId = trim($value);
                    break;
                case 'x-client-csrf-token':
                    $csrfToken = trim($value);
                    break;
                case 'x-client-session-cleared':
                    $cleared = trim($value) === '1';
                    break;
            }
        }

        if ($sessionId !== null && $csrfToken !== null) {
            Auth::ensureSessionStarted();
            $_SESSION['ft_client_session_id'] = $sessionId;
            $_SESSION['ft_client_csrf_token'] = $csrfToken;
        }
        if ($cleared) {
            Auth::ensureSessionStarted();
            unset($_SESSION['ft_client_session_id'], $_SESSION['ft_client_csrf_token']);
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
            if (
                $lower === 'set-cookie' || $lower === 'content-length' || in_array($lower, self::HOP_BY_HOP_HEADERS, true)
                || $lower === 'x-client-session-id' || $lower === 'x-client-csrf-token' || $lower === 'x-client-session-cleared'
            ) {
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

    /**
     * Fázis 2, Checkpoint 4 — a design 5. pontjának PONTOS válasz-alakja.
     * SOSE tartalmazza a tényleges Kliens/Szerver verziószámokat (azok a
     * client-health.php diagnosztikai végponton érhetők el, admin/Kliens
     * saját felületén) — a böngésző felé csak az egyértelmű, cselekvésre
     * ösztönző üzenet megy.
     */
    private function respondWithVersionMismatch(): void
    {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'A kliens frissítése szükséges.',
            'version_mismatch' => true,
        ], JSON_UNESCAPED_UNICODE);
    }
}
