<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Fázis 7 — a kör 22. pontja: read-only nézet egy (alapból a
// legutóbb TÉNYLEGESEN elkészült) napi AI-jelentéshez. Admin-only,
// UGYANAZZAL az indoklással, mint minden AI-végpont.
require_admin($db);

if (!empty($_GET['list'])) {
    // Könnyű, dátum-választóhoz elegendő lista (bounded — Database::
    // listAiDailyReports() legfeljebb 90 sort ad) — SOSE a teljes
    // findings_json/report_text mezőket, csak a böngészéshez szükséges
    // metaadatot.
    $reports = array_map(static function (array $r): array {
        return [
            'report_date' => $r['report_date'],
            'status' => $r['status'],
            'has_significant_findings' => (bool) $r['has_significant_findings'],
            'findings_count' => (int) $r['findings_count'],
        ];
    }, $db->listAiDailyReports(30));
    send_json(['ok' => true, 'reports' => $reports]);
}

$requestedDate = (string) ($_GET['date'] ?? '');
if ($requestedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
    send_json(['error' => 'Érvénytelen dátum (ÉÉÉÉ-HH-NN várt).'], 400);
}

$report = $requestedDate !== '' ? $db->getAiDailyReport($requestedDate) : $db->getLatestCompletedAiDailyReport();

if ($report === null) {
    send_json(['ok' => true, 'report' => null]);
}

$findings = [];
if (!empty($report['findings_json'])) {
    $decoded = json_decode((string) $report['findings_json'], true);
    $findings = is_array($decoded) ? $decoded : [];
}

send_json([
    'ok' => true,
    'report' => [
        'report_date' => $report['report_date'],
        'status' => $report['status'],
        'provider' => $report['provider'],
        'model' => $report['model'],
        'has_significant_findings' => (bool) $report['has_significant_findings'],
        'findings_count' => (int) $report['findings_count'],
        'findings' => $findings,
        'report_text' => $report['report_text'],
        'error' => $report['error'],
        'started_at' => $report['started_at'],
        'completed_at' => $report['completed_at'],
    ],
]);
