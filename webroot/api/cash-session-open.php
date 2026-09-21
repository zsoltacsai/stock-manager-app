<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$cashRegisterId = (int) ($input['cash_register_id'] ?? 0);
$openingAmount = (float) ($input['opening_amount'] ?? -1);
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
$idempotencyFingerprint = $idempotencyKey !== '' ? build_cash_open_fingerprint($cashRegisterId, $openingAmount) : null;

if (!$cashRegisterId) {
    send_json(['error' => 'A pénztárgép megadása kötelező.'], 400);
}
if ($openingAmount < 0) {
    send_json(['error' => 'A nyitó összeg nem lehet negatív.'], 400);
}

if ($idempotencyKey !== '') {
    $existing = $db->findCashSessionByIdempotencyKey($idempotencyKey);
    if ($existing) {
        cash_open_reject_or_replay($existing, $idempotencyFingerprint);
    }
}

try {
    $staffId = Auth::currentStaffId();
    $id = $db->openCashSession($cashRegisterId, $staffId, $openingAmount, $idempotencyKey ?: null, $idempotencyFingerprint);
} catch (RuntimeException $e) {
    send_json(['error' => $e->getMessage()], 409);
} catch (PDOException $e) {
    // Két majdnem egyidejű nyitási kérés (ugyanazzal a kulccsal) közül a
    // vesztes itt kapja el a UNIQUE-ütközést — ugyanaz a minta, mint
    // sale.php-ban, lásd ott a docblockot.
    if ($idempotencyKey !== '' && str_contains($e->getMessage(), 'idempotency_key')) {
        $winner = $db->findCashSessionByIdempotencyKey($idempotencyKey);
        if ($winner) {
            cash_open_reject_or_replay($winner, $idempotencyFingerprint);
        }
    }
    send_generic_error_response($e, 'cash-session-open.php kasszanyitás sikertelen');
} catch (Throwable $e) {
    send_generic_error_response($e, 'cash-session-open.php kasszanyitás sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    'cash_session_open',
    'cash_session',
    $id,
    'Nyitó összeg: ' . number_format($openingAmount, 0, ',', ' ') . ' Ft',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

$session = $db->getCashSession($id);
send_json(['id' => $id, 'session' => $session]);

/**
 * Az idempotencia-kulcs önmagában csak azt zárja ki, hogy UGYANAZ a kulcs
 * kétszer nyisson műszakot — az ujjlenyomat azt zárja ki, hogy valaki
 * ugyanazt a kulcsot egy MÁSIK, ténylegesen eltérő kéréssel küldje be
 * (pl. eltérő nyitó összeggel) — pontosan a sale.php build_sale_fingerprint()
 * mintája, csak a kasszanyitás üzletileg releváns mezőire szűkítve.
 */
function build_cash_open_fingerprint(int $cashRegisterId, float $openingAmount): string
{
    return hash('sha256', $cashRegisterId . '|' . number_format($openingAmount, 2, '.', ''));
}

function cash_open_reject_or_replay(array $existingSession, ?string $requestFingerprint): void
{
    $storedFingerprint = $existingSession['idempotency_fingerprint'] ?? null;
    if ($storedFingerprint !== null && $requestFingerprint !== null && $storedFingerprint !== $requestFingerprint) {
        send_json(['error' => 'Ez az idempotencia-kulcs már egy másik kéréshez lett használva — töltsd újra az oldalt.'], 409);
    }
    send_json(['id' => (int) $existingSession['id'], 'session' => $existingSession]);
}
