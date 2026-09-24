<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/ActionProposalService.php';
require_once __DIR__ . '/../../src/Ai/ActionExecutor.php';

// Fázis 8A — a kör 22. pontja: read-only részletnézet EGY javaslathoz.
// Admin-only.
require_admin($db);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen azonosító.'], 400);
}

$service = new ActionProposalService($db, $appSettings);
$row = $service->getProposal($id);
if ($row === null) {
    send_json(['error' => 'A megadott javaslat nem található.'], 404);
}

$proposal = ActionProposal::fromRow($row)->toArray();
$proposal['is_executable_type'] = ActionExecutor::isExecutableType($row['proposal_type']);

send_json(['ok' => true, 'proposal' => $proposal]);
