<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Ai/ActionProposalService.php';

// Fázis 8A — a kör 15. pontja: JÓVÁHAGYÁS. KRITIKUS — ez a végpont
// KIZÁRÓLAG a javaslat ÁLLAPOTÁT változtatja meg (pending → approved),
// SEMMILYEN tényleges üzleti műveletet (készlet/ár/rendelés/kassza/
// vevő/számla) NEM hajt végre, lásd ActionProposal.php osztály-docblokkja.
// Admin-only, UGYANAZZAL az indoklással, mint minden AI-végpont — a CSRF-
// ellenőrzést a MEGLÉVŐ _bootstrap.php globális rétege már elvégezte
// (ez a végpont nincs a $csrfWhitelist-en).
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
// telepítésen (lásd webroot/api/cash-session-open.php UGYANEZEN mintája)
// — ez NEM hitelesítési hiba, require_admin() már elvégezte a tényleges
// jogosultsági ellenőrzést.
$service = new ActionProposalService($db, $appSettings);
$result = $service->approve($id, Auth::currentStaffId());

if (!$result['ok']) {
    $reason = (string) ($result['reason'] ?? 'unknown');
    $messages = [
        'not_found' => 'A megadott javaslat nem található.',
        'expired' => 'A javaslat időközben lejárt — már nem hagyható jóvá.',
        'stale' => 'A javaslat alapjául szolgáló adat időközben megváltozott — a javaslat elavulttá vált, nem hagyható jóvá a régi adat alapján.',
        'approved' => 'A javaslatot már valaki jóváhagyta.',
        'rejected' => 'A javaslatot már valaki elutasította.',
    ];
    send_json([
        'ok' => false,
        'reason' => $reason,
        'error' => $messages[$reason] ?? 'A javaslat jelenleg nem hagyható jóvá.',
    ], 409);
}

send_json([
    'ok' => true,
    'status' => $result['status'],
    'note' => 'A javaslat jóváhagyva. Ez a lépés KIZÁRÓLAG a javaslat állapotát változtatta meg — üzleti művelet nem történt.',
]);
