<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Regresszió (1.3.1): telephely létrehozása/szerkesztése strukturális
// beállítás — ugyanaz a "vezetői jogszint kell" szabály indokolt rá,
// mint a többi infrastruktúra-szintű végpontnál.
require_admin($db);

$input = json_input();
if (trim((string) ($input['name'] ?? '')) === '') {
    send_json(['error' => 'A telephely nevének megadása kötelező.'], 400);
}

$id = $db->saveLocation($input);
send_json(['id' => $id]);
