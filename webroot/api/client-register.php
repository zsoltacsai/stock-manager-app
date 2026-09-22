<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Egy új kliens-gép regisztrálása strukturális, biztonsági döntés —
// ugyanaz a "vezetői jogszint kell" szabály, mint a telephelyeknél.
require_admin($db);

$input = json_input();
$label = trim((string) ($input['label'] ?? ''));
if ($label === '') {
    send_json(['error' => 'A kliens nevének (címkéjének) megadása kötelező.'], 400);
}

$result = $db->registerClient($label);

$db->logAudit(
    Auth::currentStaffId(),
    'client_register',
    'registered_client',
    $result['id'],
    'Kliens regisztrálva: ' . $label . ' (' . $result['client_id'] . ')',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

// A nyers client_secret KIZÁRÓLAG ebben a válaszban jelenik meg — soha
// többé, semmilyen más végpontból (lásd Database::registerClient() docblokkja).
send_json([
    'id'            => $result['id'],
    'client_id'     => $result['client_id'],
    'client_secret' => $result['client_secret'],
    'label'         => $result['label'],
    'secret_shown_once' => true,
]);
