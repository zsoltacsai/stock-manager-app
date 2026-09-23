<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Fázis 7 — a kör 4. pontja: kompakt, biztonságos részlet-nézet EGY
// AI-futáshoz. Kizárólag a MÁR bounded/szűrt mezőket adja vissza (lásd
// Database::decorateAiHistoryRow()) — a nyers technical_detail JSON
// SOSE kerül ki innen sem, és a MEGLÉVŐ AiAuditLogger amúgy sem tárolja
// a teljes modell-választ, tehát ez a végpont NEM kezd el teljes választ
// megjeleníteni csak azért, hogy legyen mit mutatnia (a kör 4. pontja
// explicit tiltása).
require_admin($db);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen azonosító.'], 400);
}

$entry = $db->getAiRunHistoryEntry($id);
if ($entry === null) {
    send_json(['error' => 'A megadott AI-futás nem található.'], 404);
}

send_json(['ok' => true, 'entry' => $entry]);
