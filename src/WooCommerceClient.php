<?php

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

    public function fetchAllProducts(): array
    {
        $all = [];
        $page = 1;

        do {
            $batch = $this->request('GET', '/products', [
                'per_page' => 100,
                'page'     => $page,
            ]);

            foreach ($batch as $p) {
                $all[] = $this->normaliseProduct($p);
            }

            $page++;
        } while (count($batch) === 100 && $page <= 50);

        return $all;
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
        do {
            $batch = $this->request('GET', '/products/brands', ['per_page' => 100, 'page' => $page]);
            foreach ($batch as $b) {
                $all[] = ['id' => (int) $b['id'], 'name' => $b['name'] ?? ''];
            }
            $page++;
        } while (count($batch) === 100 && $page <= 20);

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
        if (!empty($fields['brand_id'])) {
            $body['brands'] = [['id' => (int) $fields['brand_id']]];
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $this->consumerKey . ':' . $this->consumerSecret,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("WooCommerce request failed: $err");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($status >= 400) {
            $msg = $decoded['message'] ?? $response;
            throw new RuntimeException("WooCommerce API error ($status): $msg");
        }

        return $decoded;
    }
}
