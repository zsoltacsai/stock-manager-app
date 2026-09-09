<?php

require_once __DIR__ . '/EscPosPrinter.php';

/**
 * Az EscPosPrinter::printReceipt() hívásához szükséges beállítás-
 * összeállítás (header/footer sorok, logó, QR-payload) — KÖZÖS a manuális
 * "Nyomtatás" gomb (webroot/api/print-receipt.php) ÉS az automatikus,
 * eladás utáni nyomtatás (webroot/api/sale.php) között, hogy a két
 * hívási út sose térjen el egymástól.
 */
class ReceiptPrinter
{
    /**
     * @return array{success:bool, error:?string}
     */
    public static function printForSale(array $settings, array $sale, string $assetsDir): array
    {
        if (empty($settings['printer_enabled']) || empty($settings['printer_ip'])) {
            return ['success' => false, 'error' => 'A hálózati nyomtató nincs beállítva vagy nincs engedélyezve (Beállítások → Nyomtató).'];
        }

        $headerLines = array_values(array_filter(array_map('trim', explode("\n", $settings['receipt_header_lines'] ?? ''))));
        $footerLines = array_values(array_filter(array_map('trim', explode("\n", $settings['receipt_footer_lines'] ?? ''))));

        $logoPath = null;
        if (!empty($settings['receipt_show_logo']) && !empty($settings['logo_filename'])) {
            $candidate = rtrim($assetsDir, '/') . '/' . $settings['logo_filename'];
            if (is_file($candidate)) {
                $logoPath = $candidate;
            }
        }

        // A QR-kód (ha be van kapcsolva ÉS van beállított publikus alap-URL)
        // a MÁR MEGLÉVŐ digitális nyugta linkjére mutat (receipt.html?sale_id=
        // ...&token=...) — nincs kitalált/feltételezett üzleti tartalom, lásd
        // src/EscPosPrinter.php::printQrCode() docblockja. Üres alap-URL esetén
        // a QR-t egyszerűen kihagyjuk — SOSE generálunk egy nem működő linket.
        $qrPayload = null;
        if (!empty($settings['printer_qr_enabled']) && !empty($settings['receipt_public_base_url']) && !empty($sale['receipt_token'])) {
            $qrPayload = rtrim($settings['receipt_public_base_url'], '/') . '/receipt.html?sale_id=' . $sale['id'] . '&token=' . $sale['receipt_token'];
        }

        try {
            $printer = new EscPosPrinter(
                $settings['printer_ip'],
                (int) $settings['printer_port'],
                (int) $settings['printer_paper_width'],
                (string) ($settings['printer_encoding'] ?? 'cp852')
            );
            $printer->printReceipt($sale, $headerLines, $footerLines, $logoPath, $qrPayload);
            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
