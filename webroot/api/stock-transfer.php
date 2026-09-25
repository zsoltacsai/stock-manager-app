<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$productId = (int) ($input['product_id'] ?? 0);
$fromLocationId = !empty($input['from_location_id']) ? (int) $input['from_location_id'] : null;
$toLocationId = (int) ($input['to_location_id'] ?? 0);
$qty = (int) ($input['qty'] ?? 0);
// A szerver-oldali, PIN-nel ellenőrzött session-ből, nem a kliens által
// beküldött staff_id-ból — különben bárki más dolgozó nevére írhatná a
// mozgatást, torzítva az elszámoltathatóságot.
$staffId = Auth::currentStaffId();
// N-2: kliens által generált, a mozgatás egy "leadási kísérletéhez" tartozó
// kulcs (dupla kattintás, elveszett válasz utáni újraküldés) — ugyanaz a
// minta, mint sale.php / cash-movement.php: a tényleges atomikus védelmet a
// stock_transfers.idempotency_key UNIQUE indexe adja, a mozgatás
// tranzakciójában. Kulcs nélküli kérés a régi módon működik.
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
if (strlen($idempotencyKey) > 64) {
    send_json(['error' => 'Az idempotency_key túl hosszú.'], 400);
}
$idempotencyFingerprint = $idempotencyKey !== ''
    ? hash('sha256', $productId . '|' . ($fromLocationId ?? 0) . '|' . $toLocationId . '|' . $qty)
    : null;

/** Egy korábbi, ugyanazzal a kulccsal végrehajtott mozgatás eredménye — vagy 409, ha a kulcs egy MÁS tartalmú kéréshez tartozik. */
function transfer_replay_or_reject(array $existing, ?string $fingerprint): void
{
    $stored = $existing['idempotency_fingerprint'] ?? null;
    if ($stored !== null && $fingerprint !== null && !hash_equals((string) $stored, $fingerprint)) {
        send_json(['error' => 'Ez az idempotencia-kulcs már egy másik mozgatáshoz lett használva — töltsd újra az oldalt.'], 409);
    }
    send_json(['ok' => true, 'transfer_id' => (int) $existing['id'], 'replayed' => true]);
}

if (!$productId || !$toLocationId || $qty <= 0) {
    send_json(['error' => 'Hiányzó vagy érvénytelen adatok.'], 400);
}
if ($fromLocationId === $toLocationId) {
    send_json(['error' => 'A forrás és a cél telephely nem lehet ugyanaz.'], 400);
}

// A visszajátszás MEGELŐZI a forrás-készlet előzetes ellenőrzését: egy már
// végrehajtott mozgatás után a forrás készlete természetesen kevesebb — a
// visszajátszásnak ettől függetlenül az eredeti sikert kell visszaadnia.
if ($idempotencyKey !== '') {
    $existing = $db->findStockTransferByIdempotencyKey($idempotencyKey);
    if ($existing) {
        transfer_replay_or_reject($existing, $idempotencyFingerprint);
    }
}

if ($fromLocationId) {
    $fromStock = $db->getLocationStockForProduct($productId);
    foreach ($fromStock as $row) {
        if ((int) $row['location_id'] === $fromLocationId && (int) $row['stock_qty'] < $qty) {
            send_json(['error' => 'A forrás telephelyen nincs elég készlet (' . $row['stock_qty'] . ' db van).'], 400);
        }
    }
}

try {
    $transferId = $db->transferStock($productId, $fromLocationId, $toLocationId, $qty, $staffId, $idempotencyKey, $idempotencyFingerprint);
} catch (PDOException $e) {
    // Egy közel egyidejű, ugyanazzal a kulccsal érkező kérés vesztese a
    // UNIQUE-ütközésnél (vagy egy SQLite-zárütközésnél) jár itt — ha a
    // győztes mozgatás már létezik, annak eredménye jön vissza.
    if ($idempotencyKey !== '') {
        $winner = $db->findStockTransferByIdempotencyKey($idempotencyKey);
        if ($winner) {
            transfer_replay_or_reject($winner, $idempotencyFingerprint);
        }
    }
    // B-12: DB-hiba (lock, constraint, SQL) — NEM üzleti ütközés. A
    // PDOException a RuntimeException leszármazottja, ezért előtte kell állnia.
    send_database_error_response($e, 'stock-transfer.php készletmozgatás sikertelen');
} catch (RuntimeException $e) {
    // A Database::transferStock() saját, kézzel írt, biztonságosan
    // felhasználó elé tárható üzenete (pl. "A forrás telephelyen
    // időközben már nincs elég készlet.") — ez EGY VALÓDI, hasznos
    // üzleti visszajelzés, nem technikai kivétel-részlet, ezért itt
    // szándékosan NEM a generikus üzenettel helyettesítjük.
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'stock-transfer.php készletmozgatás sikertelen');
}

send_json(['ok' => true, 'transfer_id' => $transferId]);
