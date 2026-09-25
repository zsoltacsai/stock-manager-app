<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$staffId = Auth::currentStaffId();
$notes = trim((string) ($input['notes'] ?? ''));

try {
    $id = $db->startStockTake($staffId, $notes);
} catch (PDOException $e) {
    // B-12: DB-hiba — korábban a Throwable-ág ezt is 409-es "üzleti"
    // hibaként, nyers SQL-lel adta vissza.
    send_database_error_response($e, 'stock-take-start.php leltár indítása sikertelen');
} catch (RuntimeException $e) {
    // Valódi üzleti ütközés (pl. már van nyitott leltár).
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'stock-take-start.php leltár indítása sikertelen');
}
send_json(['id' => $id]);
