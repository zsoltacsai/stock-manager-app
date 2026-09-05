<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$customer = $id ? $db->findCustomerById($id) : null;
if (!$customer) {
    send_json(['error' => 'A vásárló nem található.'], 404);
}

$export = [
    'exported_at' => date('c'),
    'profil' => [
        'id' => $customer['id'],
        'nev' => $customer['name'],
        'telefon' => $customer['phone'],
        'email' => $customer['email'],
        'adoszam' => $customer['tax_number'],
        'iranyitoszam' => $customer['zip'],
        'telepules' => $customer['city'],
        'cim' => $customer['address'],
        'orszag' => $customer['country'],
        'megjegyzes' => $customer['notes'],
        'letrehozva' => $customer['created_at'],
    ],
    'huseg' => [
        'jelenlegi_pontegyenleg' => (int) $customer['loyalty_points'],
        'elettartam_koltes' => (float) $customer['total_spent'],
        'pont_tortenet' => $db->getLoyaltyHistory($id, 10000),
    ],
    'vasarolt_tetelek' => $db->getCustomerPurchasedItems($id, 10000),
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="vasarlo_adatok_' . $id . '.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
