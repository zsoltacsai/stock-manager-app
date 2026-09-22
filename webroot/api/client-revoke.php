<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}

try {
    $db->revokeClient($id);
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    send_generic_error_response($e, 'client-revoke.php kliens visszavonása sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    'client_revoke',
    'registered_client',
    $id,
    'Kliens véglegesen visszavonva.',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id, 'revoked' => true]);
