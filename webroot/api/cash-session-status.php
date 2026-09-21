<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$cashRegisterId = (int) ($_GET['cash_register_id'] ?? 0);
if (!$cashRegisterId) {
    send_json(['error' => 'A pénztárgép megadása kötelező.'], 400);
}

$session = $db->getOpenCashSession($cashRegisterId);
send_json(['open' => $session !== null, 'session' => $session]);
