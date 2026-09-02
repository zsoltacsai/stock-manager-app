<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/CsvImporter.php';
require_once __DIR__ . '/../../src/ProductRowNormalizer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$profileKey = $_POST['profile'] ?? '';
$profiles = require __DIR__ . '/../../src/ImportProfiles.php';

if (!isset($profiles[$profileKey])) {
    send_json(['error' => 'Ismeretlen forrás program.'], 400);
}
$profile = $profiles[$profileKey];

if (empty($profile['implemented']) || !$profile['field_map']) {
    send_json(['error' => "A(z) {$profile['label']} import még nincs implementálva."], 400);
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    send_json(['error' => 'Nem érkezett feltöltött fájl.'], 400);
}

$importDir = __DIR__ . '/../../data/imports';
@mkdir($importDir, 0775, true);

$token = bin2hex(random_bytes(8));
$uploadedPath = $importDir . '/' . $token . '.upload';

if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadedPath)) {
    send_json(['error' => 'A fájlt nem sikerült menteni a szerveren.'], 500);
}

try {
    $csvPath = CsvImporter::ensureCsv($uploadedPath);
} catch (Throwable $e) {
    @unlink($uploadedPath);
    send_json(['error' => $e->getMessage()], 400);
}

$storedPath = $importDir . '/' . $token . '.csv';
if ($csvPath !== $storedPath) {
    $tempDir = dirname($csvPath);
    rename($csvPath, $storedPath);
    @rmdir($tempDir);
}
if ($uploadedPath !== $storedPath) {
    @unlink($uploadedPath);
}

try {
    $parsed = CsvImporter::readRows($storedPath, $profile['field_map']);
} catch (Throwable $e) {
    @unlink($storedPath);
    send_json(['error' => $e->getMessage()], 500);
}

if (empty($parsed['rows'])) {
    @unlink($storedPath);
    send_json(['error' => 'A fájlból nem sikerült egyetlen sort sem beolvasni. Ellenőrizd, hogy a fejléc sorok egyeznek-e a várt oszlopnevekkel.'], 400);
}

$existingBarcodes = $db->listBarcodeIndex();

$normalized = [];
foreach ($parsed['rows'] as $row) {
    $normalized[] = ProductRowNormalizer::normalize($row, $profile);
}

$total = count($normalized);
$missingName = 0;
$zeroPrice = 0;
$blankBarcode = 0;
$willUpdate = 0;
$willInsert = 0;
$barcodeCounts = [];

foreach ($normalized as $n) {
    if ($n['name'] === '') {
        $missingName++;
    }
    if ($n['price'] <= 0) {
        $zeroPrice++;
    }
    if ($n['barcode'] === '') {
        $blankBarcode++;
        $willInsert++;
    } else {
        $barcodeCounts[$n['barcode']] = ($barcodeCounts[$n['barcode']] ?? 0) + 1;
        if (isset($existingBarcodes[$n['barcode']])) {
            $willUpdate++;
        } else {
            $willInsert++;
        }
    }
}

$duplicatesInFile = count(array_filter($barcodeCounts, fn($c) => $c > 1));

send_json([
    'token'   => $token,
    'profile' => $profileKey,
    'stats' => [
        'total_rows'         => $total,
        'missing_name'       => $missingName,
        'zero_price'         => $zeroPrice,
        'blank_barcode'      => $blankBarcode,
        'duplicate_barcodes' => $duplicatesInFile,
        'will_insert'        => $willInsert,
        'will_update'        => $willUpdate,
    ],
    'matched_fields'   => $parsed['matched_fields'],
    'unmatched_fields' => $parsed['unmatched_fields'],
    'sample'           => array_slice($normalized, 0, 8),
]);
