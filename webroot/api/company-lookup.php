<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/NavTaxpayerLookup.php';

$taxNumber = trim((string) ($_GET['tax_number'] ?? ''));
if ($taxNumber === '') {
    send_json(['found' => false, 'error' => 'Adószám megadása kötelező.'], 400);
}

if (empty($appSettings['nav_login']) || empty($appSettings['nav_password']) ||
    empty($appSettings['nav_signer_key']) || empty($appSettings['nav_tax_number'])) {
    send_json([
        'found' => false,
        'error' => 'A cégadat-lekérdezés nincs beállítva (Beállítások → Számlázz.hu → NAV cégadat lekérdezés).',
    ], 400);
}

try {
    $lookup = new NavTaxpayerLookup($appSettings);
    $result = $lookup->lookup($taxNumber);
    send_json($result);
} catch (Throwable $e) {
    // Ezt a végpontot bármelyik bejelentkezett dolgozó eléri (nem csak
    // vezető) — a NAV API nyers hibaüzenete (kapcsolati/technikai
    // részletek) helyett egy stabil, felhasználóbarát üzenetet adunk
    // vissza, a részleteket csak a szerver oldali naplóban hagyva.
    error_log('NAV company-lookup failed: ' . $e->getMessage());
    send_json(['found' => false, 'error' => 'A cégadat-lekérdezés jelenleg nem elérhető.'], 500);
}
