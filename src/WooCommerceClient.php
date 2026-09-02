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

        return [
            'wc_product_id' => (int) $p['id'],
            'sku'           => $p['sku'] ?? '',
            'barcode'       => $barcode,
            'name'          => $p['name'] ?? '',
            'price'         => isset($p['price']) && $p['price'] !== '' ? (float) $p['price'] : 0.0,
            'stock_qty'     => isset($p['stock_quantity']) ? (int) $p['stock_quantity'] : 0,
        ];
    }

    private function request(string $method, string $path, ?array $query = null, ?array $body = null)
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
            CURLOPT_TIMEOUT        => 20,
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
