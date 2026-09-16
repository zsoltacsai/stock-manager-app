<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$applyCorrections = !empty($input['apply_corrections']);

if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}

// A leltári korrekció ténylegesen alkalmazása a teljes készletet
// felülírhatja — ugyanaz a "vezetői jogszint kell" szabály indokolt rá,
// mint a termék-/vásárlótörlésnél, csak akkor kényszerítve, ha egyáltalán
// van dolgozói PIN-rendszer használatban. A puszta lezárás (korrekció
// nélkül) nem változtat készletet, ahhoz nem szükséges vezetői jogszint.
if ($applyCorrections && $db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
    send_json(['error' => 'A leltári korrekciók alkalmazásához vezetői jogszint szükséges.'], 403);
}

try {
    $updatedProducts = $db->completeStockTake($id, $applyCorrections);
} catch (RuntimeException $e) {
    // A Database::completeStockTake() saját, kézzel írt, biztonságosan
    // felhasználó elé tárható üzenete (pl. "A leltár nem található." /
    // "Ez a leltár már le van zárva." — utóbbi az 1.3.1-ben javított
    // atomikus versenyhelyzet-védelem visszajelzése) — valódi üzleti
    // visszajelzés, nem technikai kivétel-részlet.
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'stock-take-complete.php leltár lezárása sikertelen');
}

// A WC-vel szinkronban lévő, eltéréssel érintett termékek push-a MÁR
// beütemezve a completeStockTake() saját tranzakciójában (lásd
// Database::enqueueWcPush()) — a tényleges kiküldés egy külön, cron-indított
// workerben (WcPushQueueWorker) történik, ASZINKRON, hogy egy lassú/
// elérhetetlen WooCommerce szerver se várassa meg a leltár lezárását.
send_json(['ok' => true, 'wc_push_errors' => []]);
