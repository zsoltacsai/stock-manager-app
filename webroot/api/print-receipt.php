<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/EscPosPrinter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$saleId = (int) ($input['sale_id'] ?? 0);

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

if (empty($s['printer_enabled']) || empty($s['printer_ip'])) {
    send_json(['error' => 'A hálózati nyomtató nincs beállítva vagy nincs engedélyezve (Beállítások → Nyomtató).'], 400);
}

$headerLines = array_values(array_filter(array_map('trim', explode("\n", $s['receipt_header_lines'] ?? ''))));
$footerLines = array_values(array_filter(array_map('trim', explode("\n", $s['receipt_footer_lines'] ?? ''))));

$logoPath = null;
if (!empty($s['receipt_show_logo']) && !empty($s['logo_filename'])) {
    $candidate = __DIR__ . '/../assets/' . $s['logo_filename'];
    if (is_file($candidate)) {
        $logoPath = $candidate;
    }
}

try {
    $printer = new EscPosPrinter($s['printer_ip'], (int) $s['printer_port'], (int) $s['printer_paper_width']);
    $printer->printReceipt($sale, $headerLines, $footerLines, $logoPath);
    send_json(['success' => true]);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
}
