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
    $result = $db->rotateClientSecret($id);
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'client-rotate.php kliens-titok cseréje sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    'client_rotate',
    'registered_client',
    $id,
    'Kliens-titok lecserélve.',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

// A nyers, ÚJ client_secret KIZÁRÓLAG ebben a válaszban jelenik meg — a régi
// titok innentől véglegesen érvénytelen.
send_json(['id' => $id, 'client_secret' => $result['client_secret'], 'secret_shown_once' => true]);
