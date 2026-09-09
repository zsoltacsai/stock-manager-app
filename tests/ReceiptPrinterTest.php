<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ReceiptPrinter::printForSale() tesztek — a manuális "Nyomtatás" gomb
 * (print-receipt.php) ÉS az automatikus, eladás utáni nyomtatás
 * (sale.php) KÖZÖS segédfüggvénye. A hálózati réteget ugyanaz a helyi
 * TCP-listener helyettesíti, mint tests/EscPosPrinterTest.php-ban.
 */
final class ReceiptPrinterTest extends TestCase
{
    private function baseSettings(array $overrides = []): array
    {
        return array_merge([
            'printer_enabled' => true,
            'printer_ip' => '127.0.0.1',
            'printer_port' => 9100,
            'printer_paper_width' => 42,
            'printer_encoding' => 'cp852',
            'printer_qr_enabled' => false,
            'receipt_public_base_url' => '',
            'receipt_header_lines' => '',
            'receipt_footer_lines' => '',
            'receipt_show_logo' => false,
            'logo_filename' => '',
        ], $overrides);
    }

    private function sampleSale(): array
    {
        return [
            'id' => 7,
            'created_at' => '2026-09-09 12:00:00',
            'total' => 1000.0,
            'payment_method' => 'Készpénz',
            'receipt_token' => 'test-token-abc123',
            'items' => [['name' => 'Termék', 'qty' => 1, 'unit_price' => 1000.0]],
        ];
    }

    // ---- Nincs nyomtató beállítva ----

    public function testFailsGracefullyWhenPrinterDisabled(): void
    {
        $result = ReceiptPrinter::printForSale($this->baseSettings(['printer_enabled' => false]), $this->sampleSale(), __DIR__);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function testFailsGracefullyWhenIpMissing(): void
    {
        $result = ReceiptPrinter::printForSale($this->baseSettings(['printer_ip' => '']), $this->sampleSale(), __DIR__);
        $this->assertFalse($result['success']);
    }

    // ---- Nyomtatási hiba (kapcsolódás) kezelése ----

    public function testConnectionFailureReturnsSoftError(): void
    {
        $result = ReceiptPrinter::printForSale($this->baseSettings(['printer_port' => 1]), $this->sampleSale(), __DIR__);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    // ---- Sikeres nyomtatás, QR nélkül ----

    public function testSuccessfulPrintWithoutQr(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $result = ReceiptPrinter::printForSale($this->baseSettings(['printer_port' => $port]), $this->sampleSale(), __DIR__);

        $conn = stream_socket_accept($server, 2);
        $bytes = stream_get_contents($conn);
        fclose($conn);
        fclose($server);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString("\x1D\x28\x6B", $bytes, 'QR-kód kikapcsolt állapotban NEM szerepelhet.');
    }

    // ---- QR bekapcsolva, alap-URL beállítva ----

    public function testQrIncludedWhenEnabledAndBaseUrlConfigured(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $settings = $this->baseSettings([
            'printer_port' => $port,
            'printer_qr_enabled' => true,
            'receipt_public_base_url' => 'https://shop.example.com/',
        ]);
        $result = ReceiptPrinter::printForSale($settings, $this->sampleSale(), __DIR__);

        $conn = stream_socket_accept($server, 2);
        $bytes = stream_get_contents($conn);
        fclose($conn);
        fclose($server);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString("\x1D\x28\x6B", $bytes, 'QR-kód bekapcsolt állapotban szerepelnie kell.');
        // A base URL végén lévő "/" nem duplikálódhat (rtrim), és a
        // sale_id + receipt_token a MÁR meglévő digitális nyugta útvonalba
        // kerül — nincs kitalált/feltételezett üzleti tartalom.
        $this->assertStringContainsString('https://shop.example.com/receipt.html?sale_id=7&token=test-token-abc123', $bytes);
    }

    // ---- QR bekapcsolva, DE nincs alap-URL — nincs kitalált link ----

    public function testQrSkippedWhenBaseUrlEmptyEvenIfEnabled(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $settings = $this->baseSettings([
            'printer_port' => $port,
            'printer_qr_enabled' => true,
            'receipt_public_base_url' => '',
        ]);
        $result = ReceiptPrinter::printForSale($settings, $this->sampleSale(), __DIR__);

        $conn = stream_socket_accept($server, 2);
        $bytes = stream_get_contents($conn);
        fclose($conn);
        fclose($server);

        $this->assertTrue($result['success'], 'A nyomtatás magának SIKERESNEK kell maradnia, még ha a QR ki is marad.');
        $this->assertStringNotContainsString("\x1D\x28\x6B", $bytes, 'Üres alap-URL esetén NEM szabad (törött) QR-linket generálni.');
    }

    public function testQrSkippedWhenSaleHasNoReceiptToken(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);

        $sale = $this->sampleSale();
        unset($sale['receipt_token']);

        $settings = $this->baseSettings([
            'printer_port' => $port,
            'printer_qr_enabled' => true,
            'receipt_public_base_url' => 'https://shop.example.com',
        ]);
        ReceiptPrinter::printForSale($settings, $sale, __DIR__);

        $conn = stream_socket_accept($server, 2);
        $bytes = stream_get_contents($conn);
        fclose($conn);
        fclose($server);

        $this->assertStringNotContainsString("\x1D\x28\x6B", $bytes);
    }
}
