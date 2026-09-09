<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$invoice = $id ? $db->getInvoiceById($id) : null;

// A NAV Online Számla API-nak nincs PDF-fogalma (lásd invoices.pdf_path
// oszlop-kommentje) — csak Számlázz.hu-s soroknak lehet PDF-jük.
if (!$invoice || $invoice['provider'] !== 'szamlazz' || empty($invoice['pdf_path'])) {
    send_json(['error' => 'A számlához nem tartozik PDF.'], 404);
}

// Path-traversal védelem (védelmi mélység — a pdf_path jelenleg mindig a
// szerver saját maga által, SzamlazzClient::postXml()-ben írt érték, de
// sose bízzunk vakon egy DB-ből olvasott fájlrendszer-útvonalban):
// a ténylegesen kiszolgált fájlnak a VÁRT invoices/ könyvtáron belülre
// kell esnie, a realpath()-tal feloldott, szimbolikus linket/".."-t
// nem tartalmazó abszolút útvonalak összevetésével.
$invoicesDir = realpath($config['szamlazz']['pdf_dir'] ?? (__DIR__ . '/../../invoices'));
$requestedPath = realpath($invoice['pdf_path']);

if ($invoicesDir === false || $requestedPath === false) {
    send_json(['error' => 'A PDF fájl nem található.'], 404);
}

$allowedPrefix = rtrim($invoicesDir, '/\\') . DIRECTORY_SEPARATOR;
if (!str_starts_with($requestedPath, $allowedPrefix) || !is_file($requestedPath)) {
    send_json(['error' => 'A PDF fájl nem található.'], 404);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($requestedPath) . '"');
header('Content-Length: ' . (string) filesize($requestedPath));
readfile($requestedPath);
exit;
