<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    send_json(['error' => 'Nem érkezett feltöltött fájl.'], 400);
}

$file = $_FILES['image'];

if ($file['size'] > 8 * 1024 * 1024) {
    send_json(['error' => 'A kép legfeljebb 8 MB lehet.'], 400);
}

$allowed = [
    'image/webp' => 'imagecreatefromwebp',
    'image/jpeg' => 'imagecreatefromjpeg',
    'image/gif'  => 'imagecreatefromgif',
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    send_json(['error' => 'Csak WEBP, JPG/JPEG vagy GIF kép tölthető fel.'], 400);
}

$source = @($allowed[$mime])($file['tmp_name']);
if ($source === false) {
    send_json(['error' => 'A képfájl nem olvasható be (sérült lehet).'], 400);
}

$targetSize = max(200, min(4000, (int) ($appSettings['product_image_size'] ?? 1200)));

$srcWidth = imagesx($source);
$srcHeight = imagesy($source);
$side = min($srcWidth, $srcHeight);
$cropX = (int) (($srcWidth - $side) / 2);
$cropY = (int) (($srcHeight - $side) / 2);

$target = imagecreatetruecolor($targetSize, $targetSize);
imagealphablending($target, false);
imagesavealpha($target, true);
$transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
imagefilledrectangle($target, 0, 0, $targetSize, $targetSize, $transparent);

imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetSize, $targetSize, $side, $side);
imagedestroy($source);

$productsDir = __DIR__ . '/../assets/products';
@mkdir($productsDir, 0775, true);

$filename = 'product_' . bin2hex(random_bytes(8)) . '.webp';
$destination = $productsDir . '/' . $filename;

if (!imagewebp($target, $destination, 85)) {
    imagedestroy($target);
    send_json(['error' => 'A kép feldolgozása nem sikerült.'], 500);
}
imagedestroy($target);

// A korábbi kép törlése, ha meg van adva a termék azonosítója.
$productId = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null;
if ($productId) {
    $existingProduct = $db->findProductById($productId);
    if ($existingProduct && !empty($existingProduct['image_filename'])) {
        @unlink($productsDir . '/' . basename($existingProduct['image_filename']));
    }
}

send_json(['image_filename' => $filename, 'image_url' => 'assets/products/' . $filename . '?v=' . time()]);
