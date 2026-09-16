<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/CsvImporter.php';
require_once __DIR__ . '/../../src/ProductRowNormalizer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$token = $input['token'] ?? '';
$profileKey = $input['profile'] ?? '';
$staffId = Auth::currentStaffId();

if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
    send_json(['error' => 'Érvénytelen import token.'], 400);
}

// Egy import egyetlen kéréssel akár több száz/ezer terméket írhat felül —
// jóval nagyobb hatókör, mint egyetlen termék törlése, amihez az app már
// vezetői jogszintet követel meg (lásd product-save.php/products-bulk.php).
// Ugyanazt a szabályt itt is érvényesítjük, csak akkor, ha egyáltalán van
// dolgozói PIN-rendszer használatban.
if ($db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'Tömeges termékimporthoz vezetői jogszint szükséges.'], 403);
}

$profiles = require __DIR__ . '/../../src/ImportProfiles.php';
if (!isset($profiles[$profileKey]) || empty($profiles[$profileKey]['implemented'])) {
    send_json(['error' => 'Ismeretlen vagy nem támogatott forrás program.'], 400);
}
$profile = $profiles[$profileKey];

$storedPath = __DIR__ . '/../../data/imports/' . $token . '.csv';
if (!is_file($storedPath)) {
    send_json(['error' => 'A feltöltött fájl már nem érhető el — indítsd újra az előnézetet.'], 404);
}

try {
    $parsed = CsvImporter::readRows($storedPath, $profile['field_map'], $profile['skip_lines'] ?? 0);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
} finally {
    @unlink($storedPath);
}

$inserted = 0;
$updated = 0;
$skipped = 0;
$rejected = [];

// SZÁNDÉKOSAN egyetlen tranzakció az EGÉSZ importra (mint korábban is), DE
// egy hibás ÁR miatt rossz sor NEM dobja el a tranzakciót (nem `throw`-ol) —
// egyszerűen kihagyjuk AZT az egy sort, és folytatjuk a többivel, ugyanabban
// a tranzakcióban. Így "98 érvényes + 2 hibás" eredménye 98 importált sor +
// 2 elutasított sor lesz, NEM egy teljes rollback — lásd README "Import
// soronkénti hibakezelés" szakasza az indoklásért (a jelenlegi import-
// szerződés soha nem volt "minden-vagy-semmi" a rossz ADATTARTALOM miatt,
// csak VALÓDI kivétel — pl. DB-hiba — esetén marad az).
$db->beginTransaction();
try {
    foreach ($parsed['rows'] as $i => $row) {
        $normalized = ProductRowNormalizer::normalize($row, $profile);

        if (ProductRowNormalizer::shouldSkip($normalized, $profile)) {
            $skipped++;
            continue;
        }

        $validationError = ProductRowNormalizer::validationError($normalized);
        if ($validationError !== null) {
            $rejected[] = ['row' => $i + 1, 'name' => $normalized['name'], 'reason' => $validationError];
            continue;
        }

        $result = $db->importUpsertProduct($normalized);
        if ($result['action'] === 'inserted') {
            $inserted++;
        } else {
            $updated++;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[fountaintrade] import-commit.php: ' . get_class($e) . ': ' . $e->getMessage());
    send_json(['error' => 'Import sikertelen, semmi nem került mentésre.'], 500);
}

$db->logAudit(
    $staffId,
    'product_import',
    'product',
    null,
    "Forrás: {$profile['label']} — $inserted új, $updated frissítve, $skipped kihagyva, " . count($rejected) . ' elutasítva (összesen ' . count($parsed['rows']) . ' sor)',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json([
    'total'    => count($parsed['rows']),
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'rejected' => $rejected,
]);
