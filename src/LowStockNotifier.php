<?php

class LowStockNotifier
{
    public static function notify(array $settings, array $products): void
    {
        if (empty($products)) {
            return;
        }

        if (!empty($settings['low_stock_notify_webhook'])) {
            self::sendWebhook($settings['low_stock_notify_webhook'], $products);
        }
        if (!empty($settings['low_stock_notify_email'])) {
            self::sendEmail($settings['low_stock_notify_email'], $products);
        }
    }

    private static function sendWebhook(string $url, array $products): void
    {
        $payload = json_encode(['event' => 'low_stock', 'products' => $products, 'timestamp' => date('c')], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 8,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    private static function sendEmail(string $to, array $products): void
    {
        $lines = array_map(
            fn($p) => "- {$p['name']} ({$p['barcode']}): {$p['stock_qty']} db (küszöb: {$p['threshold']})",
            $products
        );
        $body = "Alacsony készletszint riasztás:\n\n" . implode("\n", $lines);
        @mail($to, 'Stock Manager — alacsony készlet riasztás', $body, 'Content-Type: text/plain; charset=UTF-8');
    }
}
