<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/ActionExecutor.php';

// Fázis 8B — a kör 14. pontja: VÉGREHAJTÁS. KRITIKUS — ez a végpont
// KIZÁRÓLAG a javaslat `id`-jét fogadja el a böngészőtől; SEMMILYEN
// egyéb paramétert (típus, mennyiség, beszállító, ár, termékadat) NEM
// fogad el — minden ilyen érték a szerveren, az ActionExecutor/
// ReorderDraftExecutor FRISS lekérdezéseiből származik (lásd a kör 6/9.
// pontja). Admin-only, UGYANAZZAL az indoklással, mint minden AI-
// végpont — a CSRF-ellenőrzést a MEGLÉVŐ _bootstrap.php globális rétege
// már elvégezte.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen azonosító.'], 400);
}

// Auth::currentStaffId() lehet null egy dolgozói PIN-rendszer nélküli
// telepítésen — lásd ai-action-proposal-approve.php azonos indoklása.
$executor = new ActionExecutor($db, $appSettings);
$result = $executor->execute($id, Auth::currentStaffId());

if (!$result['ok']) {
    $reason = (string) ($result['reason'] ?? 'unknown');
    $messages = [
        'not_found' => 'A megadott javaslat nem található.',
        'not_executable' => 'Ez a javaslat-típus jelenleg nem hajtható végre — csak tájékoztató jellegű.',
        'pending' => 'A javaslatot előbb jóvá kell hagyni.',
        'rejected' => 'A javaslatot elutasították.',
        'expired' => 'A javaslat időközben lejárt.',
        'stale' => 'A javaslat alapjául szolgáló adat időközben megváltozott — a javaslat elavulttá vált, nem hajtható végre a régi adat alapján.',
        'already_executing' => 'Ezt a javaslatot jelenleg egy másik kérés hajtja végre — próbáld újra kicsit később.',
        'execution_failed' => (string) ($result['error'] ?? 'A végrehajtás sikertelen.'),
    ];
    send_json([
        'ok' => false,
        'reason' => $reason,
        'error' => $messages[$reason] ?? 'A javaslat jelenleg nem hajtható végre.',
    ], 409);
}

send_json([
    'ok' => true,
    'status' => $result['status'],
    'already_executed' => !empty($result['already_executed']),
    'result' => $result['result'],
]);
