<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$code = trim((string) ($_GET['code'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);

$card = $code !== '' ? $db->findGiftCardByCode($code) : null;
if (!$card && $id) {
    $card = $db->findGiftCardById($id);
}

if (!$card) {
    send_json(['error' => 'Az ajándékutalvány nem található.'], 404);
}

$card['history'] = $db->getGiftCardHistory((int) $card['id']);

send_json(['gift_card' => $card]);
