<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/ActionProposalService.php';

// Fázis 8A — lapozható/szűrhető admin-lista a jelenleg tárolt AI-
// javaslatokhoz. Admin-only, UGYANAZZAL az indoklással, mint minden
// AI-végpont (lásd ai-history-list.php azonos szakasza).
require_admin($db);

$allowedStatuses = ActionProposal::STATUSES;
$allowedTypes = ActionProposal::TYPES;
$allowedAgents = ActionProposal::AGENTS;

$filters = [];
if (isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses, true)) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['proposal_type']) && in_array($_GET['proposal_type'], $allowedTypes, true)) {
    $filters['proposal_type'] = $_GET['proposal_type'];
}
if (isset($_GET['agent']) && in_array($_GET['agent'], $allowedAgents, true)) {
    $filters['agent'] = $_GET['agent'];
}

$pageSize = isset($_GET['page_size']) ? max(1, min(100, (int) $_GET['page_size'])) : 20;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $pageSize;

$service = new ActionProposalService($db, $appSettings);
$total = $service->countProposals($filters);
$rows = $service->listProposals($filters, $pageSize, $offset);
$proposals = array_map(static fn(array $row) => ActionProposal::fromRow($row)->toArray(), $rows);

send_json([
    'ok' => true,
    'proposals' => $proposals,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'has_more' => ($offset + count($rows)) < $total,
]);
