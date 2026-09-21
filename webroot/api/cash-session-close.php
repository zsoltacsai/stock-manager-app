<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$countedAmount = (float) ($input['counted_amount'] ?? -1);

if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}
if ($countedAmount < 0) {
    send_json(['error' => 'A megszámolt összeg nem lehet negatív.'], 400);
}

// SOSE a kliens dönti el, melyik fizetési mód számít készpénznek — az
// admin-szerkeszthető Settings::payment_methods lista 'is_cash' mezője a
// forrás (lásd Database::computeExpectedCash() docblockja).
$cashPaymentMethods = [];
foreach (($appSettings['payment_methods'] ?? []) as $method) {
    if (!empty($method['is_cash']) && !empty($method['value'])) {
        $cashPaymentMethods[] = $method['value'];
    }
}

try {
    $result = $db->closeCashSession($id, $countedAmount, $cashPaymentMethods);
} catch (RuntimeException $e) {
    // Ha a műszak MÁR le van zárva ÉS a most beküldött megszámolt összeg
    // egyezik a korábban ténylegesen elmentett closing_amount-tal, ez egy
    // hálózati-újrapróbálkozás (a válasz veszett el, nem a hívás) — ilyenkor
    // a már meglévő eredményt adjuk vissza sikeresen, nem hibát egy
    // ténylegesen sikeres műveletre.
    $existing = $db->getCashSession($id);
    if ($existing && $existing['status'] === 'closed' && abs((float) $existing['closing_amount'] - $countedAmount) < 0.005) {
        send_json([
            'id'              => $id,
            'expected_amount' => (float) $existing['expected_amount'],
            'variance'        => (float) $existing['variance'],
        ]);
    }
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'cash-session-close.php kasszazárás sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    'cash_session_close',
    'cash_session',
    $id,
    'Számolt összeg: ' . number_format($countedAmount, 0, ',', ' ') . ' Ft, eltérés: ' . number_format($result['variance'], 0, ',', ' ') . ' Ft',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id, 'expected_amount' => $result['expected_amount'], 'variance' => $result['variance']]);
