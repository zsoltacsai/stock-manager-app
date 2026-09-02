<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

if (empty($_FILES['print_logo']) || $_FILES['print_logo']['error'] !== UPLOAD_ERR_OK) {
    send_json(['error' => 'Nem érkezett feltöltött fájl.'], 400);
}

$file = $_FILES['print_logo'];

if ($file['size'] > 2 * 1024 * 1024) {
    send_json(['error' => 'A fájl legfeljebb 2 MB lehet.'], 400);
}

$allowed = [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/svg+xml' => 'svg',
    'image/webp'    => 'webp',
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    send_json(['error' => 'Csak PNG, JPG, WEBP vagy SVG fájl tölthető fel.'], 400);
}

$assetsDir = __DIR__ . '/../assets';

// A korábban feltöltött nyomtatási logó törlése (bármilyen kiterjesztése is volt).
foreach (glob($assetsDir . '/print-logo.*') as $old) {
    @unlink($old);
}

$extension = $allowed[$mime];
$filename = 'print-logo.' . $extension;
$destination = $assetsDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    send_json(['error' => 'A fájlt nem sikerült menteni.'], 500);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$settings->save(['print_logo_filename' => $filename]);

send_json(['print_logo_url' => 'assets/' . $filename . '?v=' . time()]);
