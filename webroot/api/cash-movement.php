<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$cashSessionId = (int) ($input['cash_session_id'] ?? 0);
$type = (string) ($input['type'] ?? '');
$amount = (float) ($input['amount'] ?? -1);
$reason = trim((string) ($input['reason'] ?? ''));
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

if (!$cashSessionId) {
    send_json(['error' => 'A műszak megadása kötelező.'], 400);
}
if (!in_array($type, ['cash_in', 'cash_out'], true)) {
    send_json(['error' => 'Érvénytelen pénzmozgás-típus.'], 400);
}
if ($amount <= 0) {
    send_json(['error' => 'Az összeg csak pozitív lehet.'], 400);
}
if ($reason === '') {
    send_json(['error' => 'Az indoklás megadása kötelező.'], 400);
}

if ($idempotencyKey !== '') {
    $existing = $db->findCashMovementByIdempotencyKey($idempotencyKey);
    if ($existing) {
        send_json(['id' => (int) $existing['id'], 'movement' => $existing]);
    }
}

try {
    $id = $db->recordCashMovement($cashSessionId, Auth::currentStaffId(), $type, $amount, $reason, $idempotencyKey ?: null);
} catch (InvalidArgumentException $e) {
    send_json(['error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 409);
} catch (PDOException $e) {
    if ($idempotencyKey !== '' && str_contains($e->getMessage(), 'idempotency_key')) {
        $winner = $db->findCashMovementByIdempotencyKey($idempotencyKey);
        if ($winner) {
            send_json(['id' => (int) $winner['id'], 'movement' => $winner]);
        }
    }
    send_generic_error_response($e, 'cash-movement.php pénzmozgás rögzítése sikertelen');
} catch (Throwable $e) {
    send_generic_error_response($e, 'cash-movement.php pénzmozgás rögzítése sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    $type === 'cash_in' ? 'cash_in' : 'cash_out',
    'cash_movement',
    $id,
    number_format($amount, 0, ',', ' ') . ' Ft — ' . $reason,
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id]);
