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
    $db->enableClient($id);
} catch (PDOException $e) {
    // B-12: DB-hiba (lock, constraint, SQL) — NEM üzleti ütközés. A
    // PDOException a RuntimeException leszármazottja, ezért előtte kell állnia.
    send_database_error_response($e, 'client-enable.php kliens engedélyezése sikertelen');
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'client-enable.php kliens engedélyezése sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    'client_enable',
    'registered_client',
    $id,
    'Kliens újra engedélyezve.',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id, 'is_active' => true]);
