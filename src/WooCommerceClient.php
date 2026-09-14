<?php

require_once __DIR__ . '/UrlSafety.php';

/**
 * WooCommerce-hívás hibája, EXPLICIT megkülönböztetve, hogy a hívó
 * (elsősorban WcPushQueueWorker, lásd ott) biztonságosan eldönthesse:
 * érdemes-e újrapróbálkozni, vagy ez egy végleges, üzleti elutasítás.
 * $retryable=true: hálózati/időtúllépési/DNS-hiba, HTTP 5xx, vagy
 * hibásan formázott JSON egy egyébként 2xx válaszon (utóbbinál nem
 * tudható biztosan, mi történt szerver-oldalon, de az updateStock()
 * maga idempotens — egy felesleges újra-push ártalmatlan). $retryable=false:
 * HTTP 4xx (a WooCommerce üzletileg elutasította a kérést — rossz
 * hitelesítő adat, nem létező termék-id, stb. — újrapróbálkozás
 * ugyanazzal a kéréssel garantáltan ugyanazt az eredményt adná).
 */
class WooCommerceRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}

class WooCommerceClient
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $barcodeSource;
    private string $barcodeMetaKey;

    public function __construct(array $cfg)
    {
        $this->baseUrl        = rtrim($cfg['store_url'], '/') . '/wp-json/wc/v3';
        $this->consumerKey    = $cfg['consumer_key'];
        $this->consumerSecret = $cfg['consumer_secret'];
        $this->barcodeSource  = $cfg['barcode_source'] ?? 'sku';
        $this->barcodeMetaKey = $cfg['barcode_meta_key'] ?? '_barcode';
    }

    /**
     * Könnyű, csak-olvasó kapcsolat-teszt (Beállítások → WooCommerce
     * "Kapcsolat tesztelése" gombja) — egyetlen terméket kér le, hogy a
     * hitelesítő adatok hibáját (rossz URL, kulcs, jogosultság) még
     * mentés előtt, egyértelmű hibaüzenettel jelezze, ne csak élesben
     * derüljön ki egy sikertelen szinkronnál.
     */
    public function testConnection(): void
    {
        $this->request('GET', '/products', ['per_page' => 1]);
    }

    /**
     * @return array{products: array, truncated: bool} — a `truncated` igaz,
     *         ha a WooCommerce oldali termékkatalógus nagyobb, mint amit a
     *         lapozási korlát (50 oldal × 100 tétel = 5000 termék) lefed;
     *         ilyenkor a hívónak jeleznie kell a felhasználó felé, hogy a
     *         szinkron nem a teljes katalógust dolgozta fel.
     */
    public function fetchAllProducts(): array
    {
        $all = [];
        $page = 1;
        $truncated = false;

        do {
            $batch = $this->request('GET', '/products', [
                'per_page' => 100,
                'page'     => $page,
            ]);

            foreach ($batch as $p) {
                $all[] = $this->normaliseProduct($p);
            }

            $fullPage = count($batch) === 100;
            $page++;
            if ($fullPage && $page > 50) {
                $truncated = true;
            }
        } while ($fullPage && $page <= 50);

        return ['products' => $all, 'truncated' => $truncated];
    }

    public function getProduct(int $wcProductId): ?array
    {
        try {
            $p = $this->request('GET', '/products/' . $wcProductId);
            return $this->normaliseProduct($p);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->request('PUT', '/products/' . $wcProductId, null, [
            'stock_quantity' => $qty,
            'manage_stock'   => true,
        ]);
    }

    /**
     * A WooCommerce natív márka-taxonómiájának (product_brand) teljes
     * listája — a Beállítások márka-megfeleltető felülete használja.
     */
    public function fetchBrands(): array
    {
        $all = [];
        $page = 1;
        $fullPage = false;
        do {
            $batch = $this->request('GET', '/products/brands', ['per_page' => 100, 'page' => $page]);
            foreach ($batch as $b) {
                $all[] = ['id' => (int) $b['id'], 'name' => $b['name'] ?? ''];
            }
            $fullPage = count($batch) === 100;
            $page++;
        } while ($fullPage && $page <= 20);

        return $all;
    }

    /**
     * Egy márkanév WooCommerce márka-azonosítójára fordítása: megkeresi a
     * meglévő, pontosan egyező nevű márkát, vagy létrehozza, ha még nincs.
     * FONTOS: a WooCommerce REST API "brands" mezője — a "categories"
     * mezővel ellentétben — NEM fogad el név-alapú hozzárendelést, csak
     * numerikus azonosítót; egy `{"name": "..."}` bejegyzést csendben,
     * hiba nélkül figyelmen kívül hagy. Ezért mindenképp fel kell oldani
     * egy valódi ID-ra, mielőtt a terméket frissítenénk.
     */
    public function resolveBrandId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $matches = $this->request('GET', '/products/brands', ['search' => $name, 'per_page' => 100]);
        foreach ($matches as $b) {
            if (isset($b['name']) && strcasecmp($b['name'], $name) === 0) {
                return (int) $b['id'];
            }
        }

        $created = $this->request('POST', '/products/brands', null, ['name' => $name]);
        return isset($created['id']) ? (int) $created['id'] : null;
    }

    /**
     * Egy termék "leíró" mezőinek (név, ár, rövid/hosszú leírás, márka,
     * kép) kiküldése a WooCommerce felé — ezt product-save.php hívja,
     * amikor egy már szinkronizált terméket módosítunk. A márka nevét a
     * hívó oldalnak ID-ra kell fordítania előbb (lásd resolveBrandId()).
     */
    public function pushProduct(int $wcProductId, array $fields): void
    {
        $body = [];
        if (isset($fields['name'])) {
            $body['name'] = $fields['name'];
        }
        if (isset($fields['price'])) {
            $body['regular_price'] = (string) $fields['price'];
        }
        if (isset($fields['short_description'])) {
            $body['short_description'] = $fields['short_description'];
        }
        if (isset($fields['long_description'])) {
            $body['description'] = $fields['long_description'];
        }
        // array_key_exists (nem !empty) kell, hogy a "márka törölve" eset
        // (brand_id === null, lásd product-save.php) is ténylegesen kiküldje
        // a brands: [] üres listát — különben egy helyben letörölt márka
        // csendben ottmaradna a WooCommerce oldalán is.
        if (array_key_exists('brand_id', $fields)) {
            $body['brands'] = $fields['brand_id'] ? [['id' => (int) $fields['brand_id']]] : [];
        }
        if (!empty($fields['image_url'])) {
            $image = ['src' => $fields['image_url']];
            if (!empty($fields['image_alt'])) {
                $image['alt'] = $fields['image_alt'];
            }
            $body['images'] = [$image];
        }

        if (empty($body)) {
            return;
        }

        // A WooCommerce kép esetén letölti, majd több méretben újramintázza
        // a képet — ez jóval tovább tarthat, mint egy sima mezőfrissítés,
        // ezért ilyenkor hosszabb időkorlát kell, nehogy a kliens megszakítsa
        // a kapcsolatot, miközben a szerveren a művelet valójában folytatódik
        // és sikeresen befejeződik.
        $timeout = !empty($body['images']) ? 45 : 20;
        $this->request('PUT', '/products/' . $wcProductId, null, $body, $timeout);
    }

    private function normaliseProduct(array $p): array
    {
        $barcode = null;

        if ($this->barcodeSource === 'sku') {
            $barcode = $p['sku'] ?: null;
        } else {
            foreach ($p['meta_data'] ?? [] as $meta) {
                if (($meta['key'] ?? '') === $this->barcodeMetaKey) {
                    $barcode = (string) $meta['value'];
                    break;
                }
            }
        }

        $brand = null;
        foreach ($p['brands'] ?? [] as $b) {
            if (!empty($b['name'])) {
                $brand = $b['name'];
                break;
            }
        }

        return [
            'wc_product_id' => (int) $p['id'],
            'sku'           => $p['sku'] ?? '',
            'barcode'       => $barcode,
            'name'          => $p['name'] ?? '',
            'price'         => isset($p['price']) && $p['price'] !== '' ? (float) $p['price'] : 0.0,
            'stock_qty'     => isset($p['stock_quantity']) ? (int) $p['stock_quantity'] : 0,
            'short_description' => $p['short_description'] ?? '',
            'long_description'  => $p['description'] ?? '',
            'brand'         => $brand,
        ];
    }

    private function request(string $method, string $path, ?array $query = null, ?array $body = null, int $timeout = 20)
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        // Védelmi mélység: a Beállítások mentésekor (settings.php) és a
        // kapcsolat-tesztnél (wc-test-connection.php) már ellenőriztük,
        // hogy ez a store_url nyilvános cím — ez itt arra az esetre védi
        // az ELÉRHETŐSÉGET, ha a settings.json valaha közvetlenül (a
        // mentési validáció megkerülésével) módosulna, vagy egy még nem
        // validált korábbi mentésből származna az érték. A CURLOPT_RESOLVE
        // a validáláskor feloldott IP-re "pinneli" a kapcsolatot, hogy a
        // validálás és a tényleges kapcsolódás közötti DNS-újrafeloldás
        // (DNS rebinding) se irányíthasson belső célra; az átirányítás-
        // követés pedig explicit ki van kapcsolva.
        [$urlOk, $urlError, $resolvedIp] = UrlSafety::check($url);
        if (!$urlOk) {
            throw new RuntimeException("WooCommerce URL elutasítva: $urlError");
        }

        $curlOpts = UrlSafety::pinnedCurlOptions($url, (string) $resolvedIp);
        return self::executeRequest($url, $method, $body, $timeout, $this->consumerKey, $this->consumerSecret, $curlOpts);
    }

    /**
     * A tényleges cURL-hívás + válasz-osztályozás (retryable/permanent, lásd
     * WooCommerceRequestException) — KÜLÖN metódusban, az SSRF-kapun
     * (UrlSafety::check(), lásd request()) TÚL, hogy a válasz-osztályozási
     * logika (timeout/DNS/4xx/5xx/hibás JSON) valódi HTTP-hívásokkal
     * tesztelhető legyen egy loopback teszt-stub szerver ellen — a
     * UrlSafety::check() SZÁNDÉKOSAN elutasít minden loopback/belső címet
     * (lásd UrlSafetyTest.php a saját, dedikált tesztjéért), tehát a
     * teljes request()-en át sose lehetne éles SSRF-védelem megkerülése
     * nélkül loopback ellen tesztelni — ez a metódus-szétválasztás teszi
     * lehetővé, hogy tests/WooCommerceClientTest.php Reflection-nel
     * KÖZVETLENÜL ezt hívja, az SSRF-kaput érintetlenül hagyva.
     */
    private static function executeRequest(string $url, string $method, ?array $body, int $timeout, string $consumerKey, string $consumerSecret, array $extraCurlOpts = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $consumerKey . ':' . $consumerSecret,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            // A kapcsolódási (DNS-feloldás + TCP/TLS handshake) és a TELJES
            // kérés-időkorlátja SZÁNDÉKOSAN külön — egy elérhetetlen/nagyon
            // lassú DNS/hálózat ne várassa a hívót a teljes $timeout-ig, ha a
            // kapcsolódás maga sem sikerült egy jóval rövidebb idő alatt.
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
        ] + $extraCurlOpts);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            // Minden cURL-szintű átviteli hiba (DNS-feloldás sikertelen,
            // kapcsolódás sikertelen, időtúllépés, TLS-hiba stb.) ÁTMENETI-
            // nek tekintett — lásd WooCommerceRequestException docblockja.
            // Konkrét kódonkénti szöveges megkülönböztetés csak
            // diagnosztikai célt szolgál itt, a retryable=true minden
            // esetben ugyanaz marad.
            $kind = match ($errno) {
                CURLE_COULDNT_RESOLVE_HOST => 'DNS-feloldási hiba',
                CURLE_COULDNT_CONNECT => 'kapcsolódási hiba',
                CURLE_OPERATION_TIMEDOUT => 'időtúllépés',
                default => 'átviteli hiba',
            };
            throw new WooCommerceRequestException("WooCommerce request failed ($kind): $err", retryable: true);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($status >= 400) {
            $msg = $decoded['message'] ?? $response;
            // HTTP 5xx: a WooCommerce/szerver oldali, jellemzően ÁTMENETI
            // hiba (túlterhelt szerver, ideiglenes kiesés) — érdemes
            // újrapróbálkozni. HTTP 4xx: a KÉRÉS maga lett elutasítva
            // (hibás hitelesítő adat, nem létező erőforrás, érvénytelen
            // mező) — ugyanazzal a kéréssel egy újrapróbálkozás
            // garantáltan ugyanezt az eredményt adná, tehát VÉGLEGES.
            throw new WooCommerceRequestException("WooCommerce API error ($status): $msg", retryable: $status >= 500, httpStatus: $status);
        }

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // 2xx HTTP-státusz, de a válasz törzse nem érvényes JSON — nem
            // tudható biztosan, mi történt ténylegesen a WooCommerce
            // oldalán, ezért ÁTMENETI hibaként kezelt (lásd a
            // WooCommerceRequestException osztály docblockja: az
            // updateStock() idempotens, egy felesleges újrapróbálkozás
            // ártalmatlan), NEM csendben elfogadott "sikerként".
            throw new WooCommerceRequestException('WooCommerce válasz nem érvényes JSON (HTTP ' . $status . ')', retryable: true, httpStatus: $status);
        }

        return $decoded;
    }
}
