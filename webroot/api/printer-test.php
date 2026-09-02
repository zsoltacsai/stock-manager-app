<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/EscPosPrinter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();

// Allow testing with unsaved values from the settings form, before hitting "Mentés".
$ip = trim((string) ($input['printer_ip'] ?? ''));
$port = (int) ($input['printer_port'] ?? 9100);
$paperWidth = (int) ($input['printer_paper_width'] ?? 42);

if ($ip === '') {
    send_json(['error' => 'Add meg a nyomtató IP címét.'], 400);
}

$logoPath = null;
if (!empty($appSettings['receipt_show_logo']) && !empty($appSettings['logo_filename'])) {
    $candidate = __DIR__ . '/../assets/' . $appSettings['logo_filename'];
    if (is_file($candidate)) {
        $logoPath = $candidate;
    }
}

try {
    $printer = new EscPosPrinter($ip, $port, $paperWidth);
    $printer->printTestPage($config['shop'], $logoPath);
    send_json(['success' => true]);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
}
