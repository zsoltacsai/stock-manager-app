<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
if (empty(trim((string) ($input['name'] ?? '')))) {
    send_json(['error' => 'A vásárló neve kötelező.'], 400);
}

$id = $db->saveCustomer($input);
send_json(['id' => $id, 'customer' => $db->findCustomerById($id)]);
