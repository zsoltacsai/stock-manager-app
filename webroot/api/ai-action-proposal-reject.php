<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/ActionProposalService.php';

// Fázis 8A — a kör 16. pontja: ELUTASÍTÁS. Admin-only, UGYANAZZAL az
// indoklással, mint minden AI-végpont.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen azonosító.'], 400);
}
$reason = trim((string) ($input['reason'] ?? ''));
if (mb_strlen($reason) > ActionProposal::MAX_REJECTION_REASON_LENGTH) {
    send_json(['error' => 'Az indoklás túl hosszú (legfeljebb ' . ActionProposal::MAX_REJECTION_REASON_LENGTH . ' karakter).'], 400);
}

// Auth::currentStaffId() lehet null egy dolgozói PIN-rendszer nélküli
// telepítésen — lásd ai-action-proposal-approve.php azonos indoklása.
$service = new ActionProposalService($db, $appSettings);
$result = $service->reject($id, Auth::currentStaffId(), $reason !== '' ? $reason : null);

if (!$result['ok']) {
    $reasonCode = (string) ($result['reason'] ?? 'unknown');
    $messages = [
        'not_found' => 'A megadott javaslat nem található.',
        'expired' => 'A javaslat időközben lejárt.',
        'stale' => 'A javaslat időközben elavulttá vált.',
        'approved' => 'A javaslatot már valaki jóváhagyta.',
        'rejected' => 'A javaslatot már valaki elutasította.',
    ];
    send_json([
        'ok' => false,
        'reason' => $reasonCode,
        'error' => $messages[$reasonCode] ?? 'A javaslat jelenleg nem utasítható el.',
    ], 409);
}

send_json(['ok' => true, 'status' => $result['status']]);
