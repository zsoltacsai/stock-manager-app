<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Pagination.php';

// Fázis 7 — AI futás-előzmények (a kör 2/3. pontja): a MEGLÉVŐ
// system_events (category='ai') adatra épülő, lapozható/szűrhető
// admin-nézet — NEM egy második naplózó rendszer. Admin-only, UGYANAZZAL
// az indoklással, mint minden AI-végpont ("egy AI-végpont sem enged
// üzletileg érzékeny adatot egyszerű pénztárosi jogszinttel").
require_admin($db);

$allowedAgents = ['inventory', 'sales', 'anomaly', 'copilot'];
$allowedProviders = ['local', 'anthropic', 'openai'];
$allowedStatuses = ['started', 'success', 'failure'];

$filters = [];
if (isset($_GET['agent']) && in_array($_GET['agent'], $allowedAgents, true)) {
    $filters['agent'] = $_GET['agent'];
}
if (isset($_GET['provider']) && in_array($_GET['provider'], $allowedProviders, true)) {
    $filters['provider'] = $_GET['provider'];
}
if (isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses, true)) {
    $filters['status'] = $_GET['status'];
}
if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

// A kör 3. pontja — "Maximum page size must be bounded", "deterministic
// ordering: newest first" — mindkettőt a Database réteg kényszeríti ki
// (Database::AI_HISTORY_MAX_PAGE_SIZE, ORDER BY created_at DESC, id DESC),
// itt csak a bemenetet normalizáljuk biztonságosan.
// B-15: korlátos, túlcsordulás-mentes lapozás (lásd Pagination) — egy
// extrém page (pl. PHP_INT_MAX) korábban float offsetet és HTTP 500-at adott.
['page' => $page, 'page_size' => $pageSize, 'offset' => $offset] = Pagination::fromQuery($_GET, 20, 100);

$total = $db->countAiRunHistory($filters);
$rows = $db->getAiRunHistory($filters, $pageSize, $offset);

send_json([
    'ok' => true,
    'entries' => $rows,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'has_more' => ($offset + count($rows)) < $total,
]);
