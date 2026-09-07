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
$staffId = !empty($input['staff_id']) ? (int) $input['staff_id'] : null;

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
    $parsed = CsvImporter::readRows($storedPath, $profile['field_map']);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
} finally {
    @unlink($storedPath);
}

$inserted = 0;
$updated = 0;
$skipped = 0;

$db->beginTransaction();
try {
    foreach ($parsed['rows'] as $row) {
        $normalized = ProductRowNormalizer::normalize($row, $profile);

        if ($normalized['name'] === '') {
            $skipped++;
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
    send_json(['error' => 'Import sikertelen, semmi nem került mentésre: ' . $e->getMessage()], 500);
}

$db->logAudit(
    $staffId,
    'product_import',
    'product',
    null,
    "Forrás: {$profile['label']} — $inserted új, $updated frissítve, $skipped kihagyva (összesen " . count($parsed['rows']) . ' sor)',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json([
    'total'    => count($parsed['rows']),
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
]);
