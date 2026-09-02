<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$customer = $db->findCustomerById($id);

if (!$customer) {
    send_json(['error' => 'A vásárló nem található.'], 404);
}

$customer['loyalty_history'] = $db->getLoyaltyHistory($id);

$goldThreshold = (float) ($appSettings['loyalty_tier_gold_threshold'] ?? 150000);
$silverThreshold = (float) ($appSettings['loyalty_tier_silver_threshold'] ?? 50000);
$totalSpent = (float) $customer['total_spent'];
$tier = $totalSpent >= $goldThreshold ? 'gold' : ($totalSpent >= $silverThreshold ? 'silver' : 'bronze');

send_json([
    'customer' => $customer,
    'tier'     => $tier,
    'stats'    => $db->getCustomerStats($id),
    'items'    => $db->getCustomerPurchasedItems($id, 100),
]);
